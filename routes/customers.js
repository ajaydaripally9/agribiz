const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const { pool, logAudit } = require('../db');
const { checkAdminSession } = require('../middleware/auth');
const { loadSidebarStats } = require('../middleware/sidebar');

// ── CUSTOMER MANAGEMENT ─────────────────────────────────────────────────

router.get('/customers', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [custCountRows] = await pool.query("SELECT COUNT(*) as c FROM customers");
    const totalCust = custCountRows[0].c || 0;

    const [activeRows] = await pool.query("SELECT COUNT(DISTINCT customer_id) as c FROM orders WHERE status='Accepted' OR status='Delivered'");
    const activeCust = activeRows[0].c || 0;

    const [pendingRows] = await pool.query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
    const pendingCount = pendingRows[0].c || 0;

    const [rows] = await pool.query(`
      SELECT c.*,
          COUNT(DISTINCT o.invoice_no) as total_orders,
          COALESCE(SUM(CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.total_price ELSE 0 END), 0) as total_spent,
          COALESCE(SUM(DISTINCT CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.paid_amount ELSE 0 END), 0) as total_paid,
          SUM(CASE WHEN o.status='Pending' THEN 1 ELSE 0 END) as pending_orders,
          (c.points * 2 + COALESCE(SUM(CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.total_price ELSE 0 END), 0) / 500) as credit_score
      FROM customers c
      LEFT JOIN orders o ON o.customer_id = c.id
      GROUP BY c.id
      ORDER BY c.id DESC
    `);

    res.render('customers', {
      totalCust,
      activeCust,
      pendingCount,
      customers: rows
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

router.post('/customers', checkAdminSession, async (req, res) => {
  const { customer_name, mobile, address, gstin } = req.body;
  try {
    const default_pw = await bcrypt.hash('customer123', 10);
    await pool.query(
      "INSERT INTO customers (customer_name, mobile, address, password, gstin) VALUES (?, ?, ?, ?, ?)",
      [customer_name, mobile, address || '', default_pw, (gstin || '').toUpperCase()]
    );
    await logAudit(req, `Added customer: ${customer_name}`);
    req.session.msg = `Customer '${customer_name}' added successfully! Default password: customer123`;
    req.session.msgType = 'success';
  } catch (err) {
    console.error(err);
    req.session.msg = "Error adding customer: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect('/customers');
});

router.get('/update_customer', checkAdminSession, loadSidebarStats, async (req, res) => {
  const id = req.query.id;
  try {
    const [custRows] = await pool.query("SELECT * FROM customers WHERE id = ?", [id]);
    if (custRows.length === 0) {
      return res.redirect('/customers');
    }
    const customer = custRows[0];

    // Stats
    const [statsRows] = await pool.query(`
      SELECT 
          COUNT(DISTINCT invoice_no) as total_orders,
          COALESCE(SUM(CASE WHEN status='Accepted' OR status='Delivered' THEN total_price ELSE 0 END), 0) as total_spent
      FROM orders 
      WHERE customer_id = ?
    `, [id]);
    const stats = statsRows[0] || { total_orders: 0, total_spent: 0 };

    // Recent orders
    const [orderRows] = await pool.query(`
      SELECT invoice_no, order_date, status, SUM(total_price) as total 
      FROM orders 
      WHERE customer_id = ? 
      GROUP BY invoice_no 
      ORDER BY id DESC LIMIT 5
    `, [id]);
    
    const recentOrders = orderRows.map(o => {
      o.order_date_formatted = o.order_date ? new Date(o.order_date).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : '';
      return o;
    });

    res.render('update_customer', {
      customer,
      stats,
      recentOrders,
      msg_type: req.session.msgType || 'success'
    });
  } catch (err) {
    console.error(err);
    res.redirect('/customers');
  }
});

router.post('/update_customer', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  const { customer_name, mobile, gstin, address, new_password } = req.body;
  try {
    if (new_password && new_password.trim() !== '') {
      const hashed = await bcrypt.hash(new_password, 10);
      await pool.query(
        "UPDATE customers SET customer_name=?, mobile=?, gstin=?, address=?, password=? WHERE id=?",
        [customer_name, mobile, (gstin || '').toUpperCase(), address || '', hashed, id]
      );
    } else {
      await pool.query(
        "UPDATE customers SET customer_name=?, mobile=?, gstin=?, address=? WHERE id=?",
        [customer_name, mobile, (gstin || '').toUpperCase(), address || '', id]
      );
    }
    await logAudit(req, `Updated customer ID: ${id} (${customer_name})`);
    req.session.msg = "Customer updated successfully!";
    req.session.msgType = 'success';
  } catch (err) {
    console.error(err);
    req.session.msg = "Error updating customer: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect(`/update_customer?id=${id}`);
});

router.get('/delete_customer', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  try {
    await pool.query("DELETE FROM customers WHERE id = ?", [id]);
    await logAudit(req, `Deleted customer ID: ${id}`);
  } catch (err) {
    console.error(err);
  }
  res.redirect('/customers');
});

// ── OUTSTANDING & WHATSAPP DUNNING ────────────────────────────────────

router.get('/collection_dashboard', checkAdminSession, loadSidebarStats, async (req, res) => {
  const filter = req.query.filter || 'all';
  try {
    const [custRows] = await pool.query(`
      SELECT 
        c.id,
        c.customer_name,
        c.mobile,
        c.address,
        c.due_date,
        c.credit_limit,
        COALESCE(SUM(CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.total_price ELSE 0 END), 0) as total_bill,
        COALESCE(SUM(DISTINCT CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.paid_amount ELSE 0 END), 0) as total_paid
      FROM customers c
      LEFT JOIN orders o ON o.customer_id = c.id
      GROUP BY c.id, c.customer_name, c.mobile, c.address, c.due_date, c.credit_limit
    `);

    const now = new Date();
    const nextWeek = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);

    const allDebtors = custRows.map(r => {
      const total_bill = parseFloat(r.total_bill || 0);
      const total_paid = parseFloat(r.total_paid || 0);
      return {
        id: r.id,
        customer_name: r.customer_name,
        mobile: r.mobile || '',
        address: r.address || '',
        due_date: r.due_date,
        credit_limit: parseFloat(r.credit_limit || 0),
        total_bill,
        total_paid,
        total_due: total_bill - total_paid
      };
    }).filter(d => d.total_due > 0.01);

    const totalOutstanding = allDebtors.reduce((acc, d) => acc + d.total_due, 0);
    const debtorCount = allDebtors.length;
    const overdueCount = allDebtors.filter(d => d.due_date && new Date(d.due_date) < now).length;

    let debtors = allDebtors;
    if (filter === 'overdue') {
      debtors = allDebtors.filter(d => d.due_date && new Date(d.due_date) < now);
    } else if (filter === 'week') {
      debtors = allDebtors.filter(d => d.due_date && new Date(d.due_date) >= now && new Date(d.due_date) <= nextWeek);
    }

    res.render('collection_dashboard', {
      totalOutstanding,
      debtorCount,
      overdueCount,
      filter,
      debtors,
      msg: req.session.msg || null
    });
    delete req.session.msg;
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

router.post('/collection_dashboard', checkAdminSession, async (req, res) => {
  if (req.body.quick_pay !== undefined) {
    const { customer_id, pay_amount, pay_method } = req.body;
    const amt = parseFloat(pay_amount || '0');
    const cid = parseInt(customer_id || '0', 10);
    const vNo = 'VOU-' + Date.now().toString().slice(-6);
    const today = new Date().toISOString().split('T')[0];

    try {
      await pool.query(
        `INSERT INTO vouchers (voucher_no, voucher_type, entity_type, entity_id, amount, payment_method, narration, date)
         VALUES (?, 'Receipt', 'Customer', ?, ?, ?, 'Quick Outstanding Payment', ?)`,
        [vNo, cid, amt, pay_method, today]
      );

      // Update customer points
      await pool.query("UPDATE customers SET points = points + ? WHERE id = ?", [Math.floor(amt / 100), cid]);

      // Credit outstanding orders
      const [orders] = await pool.query(
        "SELECT id, total_price, paid_amount FROM orders WHERE customer_id = ? AND (status='Accepted' OR status='Delivered') ORDER BY order_date ASC, id ASC",
        [cid]
      );
      
      let remainingPay = amt;
      for (const order of orders) {
        const due = parseFloat(order.total_price) - parseFloat(order.paid_amount);
        if (due > 0 && remainingPay > 0) {
          const crediting = Math.min(due, remainingPay);
          await pool.query("UPDATE orders SET paid_amount = paid_amount + ? WHERE id = ?", [crediting, order.id]);
          remainingPay -= crediting;
        }
      }

      // Credit sales invoices
      const [sales] = await pool.query(
        "SELECT id, total_price, paid_amount FROM sales WHERE customer_name = (SELECT customer_name FROM customers WHERE id = ?) AND is_return = 0 ORDER BY sale_date ASC, id ASC",
        [cid]
      );
      remainingPay = amt;
      for (const sale of sales) {
        const due = parseFloat(sale.total_price) - parseFloat(sale.paid_amount);
        if (due > 0 && remainingPay > 0) {
          const crediting = Math.min(due, remainingPay);
          await pool.query("UPDATE sales SET paid_amount = paid_amount + ? WHERE id = ?", [crediting, sale.id]);
          remainingPay -= crediting;
        }
      }

      req.session.msg = `Quick payment of ₹${amt.toFixed(2)} recorded successfully under voucher ${vNo}!`;
    } catch (err) {
      console.error(err);
      req.session.msg = "Error recording payment: " + err.message;
    }
  } else if (req.body.set_due_date !== undefined) {
    const { customer_id, due_date, credit_limit } = req.body;
    const cid = parseInt(customer_id || '0', 10);
    const dDate = due_date || null;
    const clim = parseFloat(credit_limit || '0');

    try {
      await pool.query(
        "UPDATE customers SET due_date = ?, credit_limit = ? WHERE id = ?",
        [dDate, clim, cid]
      );
      req.session.msg = "Due date and credit limit updated successfully!";
    } catch (err) {
      console.error(err);
      req.session.msg = "Error updating settings: " + err.message;
    }
  }
  res.redirect('/collection_dashboard');
});

module.exports = router;
