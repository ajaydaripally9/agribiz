const express = require('express');
const session = require('express-session');
const cors = require('cors');
const path = require('path');
const bcrypt = require('bcryptjs');
const dotenv = require('dotenv');

// Load environment variables
dotenv.config();

const { pool, checkRole, logAudit } = require('./db');
const { runMigrations } = require('./migrations');

const app = express();
const PORT = process.env.PORT || 8000;

// EJS Setup
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Middlewares
app.use(cors({
  origin: ['http://localhost:5173', 'http://127.0.0.1:5173'],
  credentials: true
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Serve static assets
app.use('/assets', express.static(path.join(__dirname, 'assets')));
app.use(express.static(path.join(__dirname, '.'))); // serve style.css, favicon.svg etc. from root

// Session Setup
app.use(session({
  secret: process.env.SESSION_SECRET || 'agribiz_secret_key_123',
  resave: false,
  saveUninitialized: false,
  cookie: { maxAge: 24 * 60 * 60 * 1000 } // 24 hours
}));

// Flash message middleware
app.use((req, res, next) => {
  res.locals.message = req.session.message || null;
  delete req.session.message;
  res.locals.msg = req.session.msg || null;
  res.locals.msgType = req.session.msgType || null;
  delete req.session.msg;
  delete req.session.msgType;
  next();
});

// Auth middlewares
const { checkAdminSession, checkCustomerSession } = require('./middleware/auth');
const { loadSidebarStats } = require('./middleware/sidebar');

// Import Routes
const authRoutes = require('./routes/auth');
const dashboardRoutes = require('./routes/dashboard');
const inventoryRoutes = require('./routes/inventory');
const customerRoutes = require('./routes/customers');
const supplierRoutes = require('./routes/suppliers');

// Mount Routes
app.use('/', authRoutes);
app.use('/', dashboardRoutes);
app.use('/', inventoryRoutes);
app.use('/', customerRoutes);
app.use('/', supplierRoutes);
// ── 3. PRODUCT & INVENTORY MANAGEMENT ─────────────────────────────────────
// Moved to routes/inventory.js

// ── 4. CUSTOMER MANAGEMENT ─────────────────────────────────────────────────
// Moved to routes/customers.js

// ── 5. SUPPLIER MANAGEMENT ─────────────────────────────────────────────────
// Moved to routes/suppliers.js

// ── 6. TRANSACTION MODULES (Sales & Purchase Invoices, Returns) ───────────

app.get('/sales_invoices', checkAdminSession, loadSidebarStats, async (req, res) => {
  const viewInv = req.query.inv || null;
  let invoiceDetail = null;
  let invoiceItems = [];

  const filterFrom = req.query.from || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];
  const filterTo = req.query.to || new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toISOString().split('T')[0];
  const filterStatus = req.query.status || '';
  const filterSearch = req.query.q || '';

  try {
    if (viewInv) {
      const [rows] = await pool.query("SELECT * FROM sales WHERE invoice_no = ?", [viewInv]);
      if (rows.length > 0) {
        invoiceDetail = {
          invoice_no: rows[0].invoice_no,
          customer_name: rows[0].customer_name,
          order_date: rows[0].sale_date,
          mobile: rows[0].mobile || '',
          address: rows[0].address || '',
          gstin: rows[0].gstin || '',
          bill_type: rows[0].bill_type || 'Cash',
          status: rows[0].is_return === 1 ? 'Voided' : (rows[0].status || 'Delivered'),
          discount: parseFloat(rows[0].discount || 0),
          paid_amount: parseFloat(rows[0].paid_amount || 0)
        };
        invoiceItems = rows.map(r => ({
          fertilizer_name: r.fertilizer_name,
          quantity: r.quantity,
          unit_price: parseFloat(r.total_price) / Math.max(1, r.quantity),
          total_price: parseFloat(r.total_price)
        }));
      }
    }

    let listQuery = `
      SELECT 
        invoice_no, 
        MAX(customer_name) as customer_name, 
        MAX(sale_date) as order_date, 
        COUNT(*) as item_count, 
        SUM(total_price) as grand_total, 
        MAX(paid_amount) as paid_amount, 
        MAX(bill_type) as bill_type, 
        CASE WHEN MAX(is_return) = 1 THEN 'Voided' ELSE 'Delivered' END as status
      FROM sales
      WHERE 1=1
    `;
    const params = [];
    if (filterFrom) {
      listQuery += ` AND sale_date >= ?`;
      params.push(filterFrom);
    }
    if (filterTo) {
      listQuery += ` AND sale_date <= ?`;
      params.push(filterTo);
    }
    if (filterSearch) {
      listQuery += ` AND (customer_name LIKE ? OR invoice_no LIKE ?)`;
      params.push(`%${filterSearch}%`, `%${filterSearch}%`);
    }
    listQuery += ` GROUP BY invoice_no`;
    
    if (filterStatus) {
      listQuery += ` HAVING status = ?`;
      params.push(filterStatus);
    }
    
    listQuery += ` ORDER BY order_date DESC, invoice_no DESC`;

    const [invoices] = await pool.query(listQuery, params);

    const [statsRows] = await pool.query(`
      SELECT 
        COUNT(DISTINCT invoice_no) as total_inv,
        COALESCE(SUM(total_price), 0) as total_amount,
        COALESCE(SUM(paid_amount), 0) as total_paid
      FROM sales
      WHERE is_return = 0
    `);
    const stats = {
      total_inv: parseInt(statsRows[0].total_inv || 0, 10),
      total_amount: parseFloat(statsRows[0].total_amount || 0),
      total_paid: parseFloat(statsRows[0].total_paid || 0)
    };

    res.render('sales_invoices', {
      viewInv,
      invoiceDetail,
      invoiceItems,
      stats,
      filterFrom,
      filterTo,
      filterStatus,
      filterSearch,
      invoices,
      msg: req.session.msg || null,
      msgType: req.session.msgType || null
    });
    
    req.session.msg = null;
    req.session.msgType = null;
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/sales_invoices', checkAdminSession, async (req, res) => {
  const { invoice_no, pay_amount, mark_paid, void_invoice } = req.body;
  const conn = await pool.getConnection();
  
  try {
    await conn.beginTransaction();

    if (mark_paid !== undefined) {
      const amount = parseFloat(pay_amount);
      if (isNaN(amount) || amount <= 0) throw new Error("Invalid payment amount.");
      
      await conn.query("UPDATE sales SET paid_amount = paid_amount + ? WHERE invoice_no = ?", [amount, invoice_no]);
      
      const vNo = 'V-' + Date.now();
      const [sRows] = await conn.query("SELECT MAX(customer_name) as cname FROM sales WHERE invoice_no=?", [invoice_no]);
      const cname = sRows.length > 0 ? sRows[0].cname : 'Customer';
      
      await conn.query(
        `INSERT INTO vouchers (voucher_no, voucher_type, entity_type, entity_id, amount, payment_method, narration, date)
         VALUES (?, 'Receipt', 'Customer', ?, ?, 'Cash', ?, CURDATE())`,
        [vNo, cname, amount, `Payment received for Invoice ${invoice_no}`]
      );
      
      await logAudit(req, `Recorded payment of ₹${amount} for Invoice ${invoice_no}`);
      req.session.msg = `Payment of ₹${amount} recorded successfully.`;
      req.session.msgType = 'success';
      
    } else if (void_invoice !== undefined) {
      const [items] = await conn.query("SELECT fertilizer_name, quantity FROM sales WHERE invoice_no = ?", [invoice_no]);
      for (let item of items) {
        await conn.query("UPDATE fertilizers SET quantity = quantity + ? WHERE fertilizer_name = ?", [item.quantity, item.fertilizer_name]);
      }
      
      await conn.query("UPDATE sales SET is_return = 1 WHERE invoice_no = ?", [invoice_no]);
      
      await logAudit(req, `Voided Invoice ${invoice_no} and restored stock.`);
      req.session.msg = `Invoice ${invoice_no} has been voided successfully.`;
      req.session.msgType = 'success';
    }

    await conn.commit();
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Action failed: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  
  res.redirect(`/sales_invoices?inv=${encodeURIComponent(invoice_no)}`);
});

app.get('/purchases', checkAdminSession, loadSidebarStats, async (req, res) => {
  const filterFrom = req.query.from || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];
  const filterTo = req.query.to || new Date().toISOString().split('T')[0];
  const filterSup = req.query.supplier_id || '';

  try {
    let listQuery = `
      SELECT 
        p.invoice_no, 
        MAX(COALESCE(s.supplier_name, p.supplier_name)) as supplier_name, 
        MAX(p.purchase_date) as purchase_date, 
        COUNT(*) as item_count, 
        SUM(p.cost * p.quantity) as total_amount, 
        MAX(p.paid_amount) as paid_amount, 
        MAX(p.bill_type) as bill_type
      FROM purchases p
      LEFT JOIN suppliers s ON p.supplier_id = s.id
      WHERE p.is_return = 0
    `;
    const params = [];
    if (filterFrom) {
      listQuery += ` AND p.purchase_date >= ?`;
      params.push(filterFrom);
    }
    if (filterTo) {
      listQuery += ` AND p.purchase_date <= ?`;
      params.push(filterTo);
    }
    if (filterSup) {
      listQuery += ` AND p.supplier_id = ?`;
      params.push(filterSup);
    }
    listQuery += ` GROUP BY p.invoice_no ORDER BY purchase_date DESC, p.invoice_no DESC`;

    const [rows] = await pool.query(listQuery, params);

    // Compute stats
    let statsQuery = `
      SELECT 
        COUNT(DISTINCT p.invoice_no) as c,
        COALESCE(SUM(p.cost * p.quantity), 0) as total
      FROM purchases p
      WHERE p.is_return = 0
    `;
    const statsParams = [];
    if (filterFrom) {
      statsQuery += ` AND p.purchase_date >= ?`;
      statsParams.push(filterFrom);
    }
    if (filterTo) {
      statsQuery += ` AND p.purchase_date <= ?`;
      statsParams.push(filterTo);
    }
    if (filterSup) {
      statsQuery += ` AND p.supplier_id = ?`;
      statsParams.push(filterSup);
    }
    const [statsRows] = await pool.query(statsQuery, statsParams);
    
    const stats = {
      c: parseInt(statsRows[0]?.c || 0, 10),
      total: parseFloat(statsRows[0]?.total || 0)
    };

    const [suppliersList] = await pool.query("SELECT * FROM suppliers ORDER BY supplier_name");
    const [productsList] = await pool.query("SELECT * FROM fertilizers ORDER BY fertilizer_name");
    
    res.render('purchases', { 
      purchases: rows, 
      suppliersList, 
      productsList,
      stats,
      filterFrom,
      filterTo,
      filterSup
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/purchases', checkAdminSession, async (req, res) => {
  const { supplier_id, purchase_date, bill_type, paid_amount, gst_rate, notes } = req.body;
  const paid = parseFloat(paid_amount || '0');
  const gst = parseFloat(gst_rate || '18');
  
  let items = [];
  if (Array.isArray(req.body.items)) {
    items = req.body.items;
  } else if (req.body.items && typeof req.body.items === 'object') {
    items = Object.values(req.body.items);
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    
    // Fetch supplier name
    const [supRows] = await conn.query("SELECT supplier_name FROM suppliers WHERE id = ?", [supplier_id]);
    if (supRows.length === 0) throw new Error("Supplier not found");
    const supplier_name = supRows[0].supplier_name;

    // Generate invoice number
    const invoice_no = 'PUR-' + Date.now().toString().slice(-6);

    for (const item of items) {
      const fId = parseInt(item.fertilizer_id || '0', 10);
      const qty = parseInt(item.qty || '0', 10);
      const cst = parseFloat(item.cost || '0');
      if (!fId || qty <= 0 || cst <= 0) continue;

      // Fetch fertilizer name
      const [fertRows] = await conn.query("SELECT fertilizer_name FROM fertilizers WHERE id = ?", [fId]);
      if (fertRows.length === 0) throw new Error(`Fertilizer with ID ${fId} not found`);
      const fertilizer_name = fertRows[0].fertilizer_name;

      // Insert purchase record
      await conn.query(
        `INSERT INTO purchases (invoice_no, supplier_id, supplier_name, fertilizer_name, quantity, cost, purchase_date, bill_type, paid_amount, gst_rate, notes, is_return)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)`,
        [invoice_no, supplier_id, supplier_name, fertilizer_name, qty, cst, purchase_date, bill_type, paid, gst, notes || '']
      );

      // Update fertilizer stock level and update its purchase price
      await conn.query("UPDATE fertilizers SET quantity = quantity + ?, purchase_price = ? WHERE id = ?", [qty, cst, fId]);
    }

    await conn.commit();
    req.session.msg = `Purchase invoice '${invoice_no}' added successfully and inventory stock updated!`;
    req.session.msgType = 'success';
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Transaction failed: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  res.redirect('/purchases');
});

app.get('/sales_return', checkAdminSession, loadSidebarStats, async (req, res) => {
  const invNo = req.query.inv || '';
  let invoiceDetail = null;
  let invoiceItems = [];

  try {
    if (invNo) {
      // Find original invoice details
      const [rows] = await pool.query(
        "SELECT * FROM sales WHERE invoice_no = ? AND is_return = 0",
        [invNo]
      );
      if (rows.length > 0) {
        invoiceDetail = {
          invoice_no: rows[0].invoice_no,
          customer_name: rows[0].customer_name,
          order_date: rows[0].sale_date
        };
        invoiceItems = rows.map(r => ({
          fertilizer_id: r.id, // primary key from sales table
          fertilizer_name: r.fertilizer_name,
          quantity: r.quantity,
          total_price: parseFloat(r.total_price)
        }));
      }
    }

    const [returns] = await pool.query("SELECT * FROM sales WHERE is_return = 1 ORDER BY id DESC");
    const [products] = await pool.query("SELECT * FROM fertilizers ORDER BY fertilizer_name");
    
    res.render('sales_return', { 
      returns, 
      products, 
      invNo, 
      invoiceDetail, 
      invoiceItems 
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/sales_return', checkAdminSession, async (req, res) => {
  const { orig_invoice, reason, return_items } = req.body;
  
  if (!return_items || typeof return_items !== 'object') {
    req.session.msg = "No items selected for return.";
    req.session.msgType = 'error';
    return res.redirect('/sales_return');
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const retInvoiceNo = 'RET-' + Date.now().toString().slice(-6);

    for (const [salesRowId, returnQtyStr] of Object.entries(return_items)) {
      const rowId = parseInt(salesRowId, 10);
      const qty = parseInt(returnQtyStr || '0', 10);
      if (!rowId || qty <= 0) continue;

      // Query original sales row
      const [rows] = await conn.query("SELECT * FROM sales WHERE id = ? AND invoice_no = ?", [rowId, orig_invoice]);
      if (rows.length === 0) throw new Error(`Original sale record not found for row ID ${rowId}`);
      const origRow = rows[0];

      if (qty > origRow.quantity) throw new Error(`Return quantity (${qty}) exceeds original quantity (${origRow.quantity}) for ${origRow.fertilizer_name}`);

      // Calculate return value proportionally
      const origQty = origRow.quantity;
      const origTotal = parseFloat(origRow.total_price);
      const unitPrice = origTotal / Math.max(1, origQty);
      const returnValue = unitPrice * qty;

      // Insert sales return record into sales table with is_return=1
      await conn.query(
        `INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, paid_amount, bill_type, notes, is_return, return_ref)
         VALUES (?, ?, ?, ?, CURDATE(), ?, 0, 'Return', ?, 1, ?)`,
        [origRow.customer_name, origRow.fertilizer_name, qty, returnValue, retInvoiceNo, reason || 'Customer Return', orig_invoice]
      );

      // Add quantity back to inventory
      await conn.query("UPDATE fertilizers SET quantity = quantity + ? WHERE fertilizer_name = ?", [qty, origRow.fertilizer_name]);
    }

    await conn.commit();
    req.session.msg = `Sales return recorded successfully! Invoice ${retInvoiceNo} created.`;
    req.session.msgType = 'success';
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Error: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  res.redirect('/sales_return');
});

app.get('/purchase_return', checkAdminSession, loadSidebarStats, async (req, res) => {
  const invNo = req.query.inv || '';
  let invoiceDetail = null;
  let invoiceItems = [];
  
  try {
    if (invNo) {
      // Find the purchase invoice details
      const [rows] = await pool.query(
        "SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.invoice_no = ? AND p.is_return = 0",
        [invNo]
      );
      if (rows.length > 0) {
        invoiceDetail = rows[0];
        invoiceItems = rows.map(r => ({
          fertilizer_id: r.id,
          fertilizer_name: r.fertilizer_name,
          quantity: r.quantity,
          cost: parseFloat(r.cost)
        }));
      }
    }

    const [returns] = await pool.query("SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.is_return = 1 ORDER BY p.id DESC");
    const [suppliers] = await pool.query("SELECT * FROM suppliers ORDER BY supplier_name");
    const [products] = await pool.query("SELECT * FROM fertilizers ORDER BY fertilizer_name");
    res.render('purchase_return', { 
      returns, 
      suppliers, 
      products,
      invNo,
      invoiceDetail,
      invoiceItems
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/purchase_return', checkAdminSession, async (req, res) => {
  const { orig_invoice, reason, return_items } = req.body;
  
  if (!return_items || typeof return_items !== 'object') {
    req.session.msg = "No items selected for return.";
    req.session.msgType = 'error';
    return res.redirect('/purchase_return');
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const retInvoiceNo = 'PRET-' + Date.now().toString().slice(-6);

    for (const [purchaseRowId, returnQtyStr] of Object.entries(return_items)) {
      const rowId = parseInt(purchaseRowId, 10);
      const qty = parseInt(returnQtyStr || '0', 10);
      if (!rowId || qty <= 0) continue;

      // Query original purchase row
      const [rows] = await conn.query("SELECT * FROM purchases WHERE id = ? AND invoice_no = ?", [rowId, orig_invoice]);
      if (rows.length === 0) throw new Error(`Original purchase record not found for row ID ${rowId}`);
      const origRow = rows[0];

      if (qty > origRow.quantity) throw new Error(`Return quantity (${qty}) exceeds original quantity (${origRow.quantity}) for ${origRow.fertilizer_name}`);

      // Insert purchase return record (purchases table with is_return=1)
      await conn.query(
        `INSERT INTO purchases (invoice_no, supplier_id, supplier_name, fertilizer_name, quantity, cost, purchase_date, bill_type, paid_amount, notes, is_return)
         VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Return', 0, ?, 1)`,
        [retInvoiceNo, origRow.supplier_id, origRow.supplier_name, origRow.fertilizer_name, qty, origRow.cost, reason || 'Supplier Return']
      );

      // Deduct from inventory
      await conn.query("UPDATE fertilizers SET quantity = quantity - ? WHERE fertilizer_name = ?", [qty, origRow.fertilizer_name]);
    }

    await conn.commit();
    req.session.msg = `Purchase return recorded successfully! Return Invoice ${retInvoiceNo} created.`;
    req.session.msgType = 'success';
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Error: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  res.redirect('/purchase_return');
});

// ── 7. INVENTORY CONTROL & ADJUSTMENTS ────────────────────────────────────
// Moved to routes/inventory.js

// ── 8. VOUCHER ENTRIES, DAYBOOK, & MASTER LEDGER ─────────────────────────

app.get('/receipts_payments', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [vouchers] = await pool.query(`
      SELECT 
        v.*,
        COALESCE(c.customer_name, s.supplier_name, 'Other') as entity_name
      FROM vouchers v
      LEFT JOIN customers c ON v.entity_type = 'Customer' AND v.entity_id = c.id
      LEFT JOIN suppliers s ON v.entity_type = 'Supplier' AND v.entity_id = s.id
      ORDER BY v.date DESC, v.id DESC 
      LIMIT 100
    `);
    const [customers] = await pool.query("SELECT id, customer_name, mobile FROM customers ORDER BY customer_name");
    const [suppliers] = await pool.query("SELECT id, supplier_name, mobile FROM suppliers ORDER BY supplier_name");
    
    res.render('receipts_payments', {
      vouchersLog: vouchers,
      customers,
      suppliers
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/receipts_payments', checkAdminSession, async (req, res) => {
  const { voucher_type, entity_type, entity_id, amount, payment_method, narration, date } = req.body;
  const amt = parseFloat(amount || '0');
  const entId = parseInt(entity_id || '0', 10);
  const vNo = 'VOU-' + Date.now().toString().slice(-6);

  try {
    await pool.query(
      `INSERT INTO vouchers (voucher_no, voucher_type, entity_type, entity_id, amount, payment_method, narration, date)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [vNo, voucher_type, entity_type, entId, amt, payment_method, narration || '', date]
    );

    // If it is customer payment (Receipt), increase customer point balance / update credit score
    if (voucher_type === 'Receipt' && entity_type === 'Customer') {
      await pool.query("UPDATE customers SET points = points + ? WHERE id = ?", [Math.floor(amt / 100), entId]);
    }

    req.session.msg = `Voucher Entry (${voucher_type}) added successfully under voucher no ${vNo}`;
    req.session.msgType = 'success';
  } catch (err) {
    console.error(err);
    req.session.msg = "Error recording entry: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect('/receipts_payments');
});

app.get('/accounting_books', checkAdminSession, loadSidebarStats, async (req, res) => {
  const activeTab = req.query.tab || 'day';
  const dateFilter = req.query.date || new Date().toISOString().split('T')[0];

  try {
    // 1. Day Book: combine sales, purchases, and vouchers
    const [salesRows] = await pool.query(
      "SELECT invoice_no as ref, 'Sale' as type, CONCAT('Sales to ', customer_name) as particulars, bill_type as method, total_price as debit, 0 as credit FROM sales WHERE sale_date = ?", 
      [dateFilter]
    );
    const [purRows] = await pool.query(
      "SELECT p.invoice_no as ref, 'Purchase' as type, CONCAT('Purchase from ', s.supplier_name) as particulars, p.bill_type as method, 0 as debit, (p.cost * p.quantity) as credit FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_date = ?", 
      [dateFilter]
    );
    const [voucherRows] = await pool.query(`
      SELECT 
        v.voucher_no as ref, 
        v.voucher_type as type, 
        v.narration as particulars, 
        v.payment_method as method,
        CASE WHEN v.voucher_type = 'Payment' THEN v.amount ELSE 0 END as debit,
        CASE WHEN v.voucher_type = 'Receipt' THEN v.amount ELSE 0 END as credit
      FROM vouchers v 
      WHERE v.date = ?
    `, [dateFilter]);
    
    // Map objects to float values for safely using toFixed
    const mapDebitCredit = (rows) => rows.map(r => ({
      ref: r.ref || '',
      type: r.type || '',
      particulars: r.particulars || '',
      method: r.method || 'Cash',
      debit: parseFloat(r.debit || 0),
      credit: parseFloat(r.credit || 0)
    }));

    const dayBookItems = [...mapDebitCredit(salesRows), ...mapDebitCredit(purRows), ...mapDebitCredit(voucherRows)];

    // 2. Cash Book (Cash transactions)
    const [cashReceiptsRaw] = await pool.query(`
      SELECT date, voucher_no as ref, narration as particulars, amount FROM vouchers WHERE voucher_type='Receipt' AND payment_method='Cash'
      UNION ALL
      SELECT sale_date as date, invoice_no as ref, CONCAT('Sale: ', customer_name) as particulars, paid_amount as amount FROM sales WHERE bill_type='Cash' AND is_return = 0
      ORDER BY date DESC
    `);
    const [cashPaymentsRaw] = await pool.query(`
      SELECT date, voucher_no as ref, narration as particulars, amount FROM vouchers WHERE voucher_type='Payment' AND payment_method='Cash'
      UNION ALL
      SELECT purchase_date as date, invoice_no as ref, CONCAT('Purchase: ', fertilizer_name) as particulars, paid_amount as amount FROM purchases WHERE bill_type='Cash' AND is_return = 0
      ORDER BY date DESC
    `);

    const mapAmount = (rows) => rows.map(r => ({
      date: r.date,
      ref: r.ref || '',
      particulars: r.particulars || '',
      amount: parseFloat(r.amount || 0)
    }));

    const cashReceipts = mapAmount(cashReceiptsRaw);
    const cashPayments = mapAmount(cashPaymentsRaw);

    const totalCashIn = cashReceipts.reduce((acc, r) => acc + r.amount, 0);
    const totalCashOut = cashPayments.reduce((acc, p) => acc + p.amount, 0);
    const cashBalance = totalCashIn - totalCashOut;

    // 3. Bank Book (Online/UPI transactions)
    const [bankReceiptsRaw] = await pool.query(`
      SELECT date, voucher_no as ref, narration as particulars, amount FROM vouchers WHERE voucher_type='Receipt' AND payment_method != 'Cash'
      UNION ALL
      SELECT sale_date as date, invoice_no as ref, CONCAT('Sale: ', customer_name) as particulars, paid_amount as amount FROM sales WHERE bill_type != 'Cash' AND is_return = 0
      ORDER BY date DESC
    `);
    const [bankPaymentsRaw] = await pool.query(`
      SELECT date, voucher_no as ref, narration as particulars, amount FROM vouchers WHERE voucher_type='Payment' AND payment_method != 'Cash'
      UNION ALL
      SELECT purchase_date as date, invoice_no as ref, CONCAT('Purchase: ', fertilizer_name) as particulars, paid_amount as amount FROM purchases WHERE bill_type != 'Cash' AND is_return = 0
      ORDER BY date DESC
    `);

    const bankReceipts = mapAmount(bankReceiptsRaw);
    const bankPayments = mapAmount(bankPaymentsRaw);

    const totalBankIn = bankReceipts.reduce((acc, r) => acc + r.amount, 0);
    const totalBankOut = bankPayments.reduce((acc, p) => acc + p.amount, 0);
    const bankBalance = totalBankIn - totalBankOut;

    // 4. Subsidiary Ledgers (Debtors and Creditors Accounts)
    const [debtorRows] = await pool.query(`
      SELECT 
        customer_name as name,
        COALESCE(SUM(total_price), 0) as dr,
        COALESCE(SUM(paid_amount), 0) as cr
      FROM sales
      GROUP BY customer_name
    `);
    const debtorAccounts = debtorRows.map(r => ({
      name: r.name,
      dr: Math.max(0, parseFloat(r.dr) - parseFloat(r.cr)),
      cr: Math.max(0, parseFloat(r.cr) - parseFloat(r.dr))
    }));
    const debtorsTotal = debtorAccounts.reduce((acc, c) => acc + c.dr, 0);

    const [creditorRows] = await pool.query(`
      SELECT 
        COALESCE(s.supplier_name, CONCAT('Supplier ID: ', p.supplier_id)) as name,
        COALESCE(SUM(p.cost * p.quantity), 0) as cr,
        COALESCE(SUM(p.paid_amount), 0) as dr
      FROM purchases p
      LEFT JOIN suppliers s ON p.supplier_id = s.id
      GROUP BY s.supplier_name, p.supplier_id
    `);
    const creditorAccounts = creditorRows.map(r => ({
      name: r.name,
      dr: Math.max(0, parseFloat(r.dr) - parseFloat(r.cr)),
      cr: Math.max(0, parseFloat(r.cr) - parseFloat(r.dr))
    }));
    const creditorsTotal = creditorAccounts.reduce((acc, s) => acc + s.cr, 0);

    // 5. Income Statement (Profit & Loss) and Trial Balance metrics
    const [salesStats] = await pool.query("SELECT COALESCE(SUM(total_price), 0) as total FROM sales WHERE is_return = 0");
    const totalSalesVal = parseFloat(salesStats[0]?.total || 0);

    const [purStats] = await pool.query("SELECT COALESCE(SUM(cost * quantity), 0) as total FROM purchases WHERE is_return = 0");
    const totalPurchasesVal = parseFloat(purStats[0]?.total || 0);

    const salesGstCollected = totalSalesVal * 18 / 118;
    const netSalesRevenue = totalSalesVal - salesGstCollected;
    const purchaseGstPaid = totalPurchasesVal * 18 / 118;
    const netPurchaseCost = totalPurchasesVal - purchaseGstPaid;
    const netProfit = netSalesRevenue - netPurchaseCost;
    const profitMarginPct = netSalesRevenue > 0 ? (netProfit / netSalesRevenue) * 100 : 0;

    const cashDr = cashBalance > 0 ? cashBalance : 0;
    const cashCr = cashBalance < 0 ? Math.abs(cashBalance) : 0;
    const bankDr = bankBalance > 0 ? bankBalance : 0;
    const bankCr = bankBalance < 0 ? Math.abs(bankBalance) : 0;
    const debtorsDr = debtorsTotal;
    const debtorsCr = 0;
    const creditorsDr = 0;
    const creditorsCr = creditorsTotal;

    let sumDebits = cashDr + bankDr + debtorsDr + totalPurchasesVal;
    let sumCredits = cashCr + bankCr + creditorsCr + totalSalesVal;

    const suspenseDr = sumCredits > sumDebits ? (sumCredits - sumDebits) : 0;
    const suspenseCr = sumDebits > sumCredits ? (sumDebits - sumCredits) : 0;

    if (suspenseDr > 0) sumDebits += suspenseDr;
    if (suspenseCr > 0) sumCredits += suspenseCr;

    // 6. Balance Sheet assets & liabilities
    const [stockValRows] = await pool.query("SELECT COALESCE(SUM(quantity * price), 0) as val FROM fertilizers");
    const stockValuation = parseFloat(stockValRows[0]?.val || 0);

    const totalAssets = cashBalance + bankBalance + debtorsTotal + stockValuation;
    const retainedEarnings = netProfit;
    const netGstPayable = salesGstCollected - purchaseGstPaid;
    const liabilitiesTotal = creditorsTotal + (netGstPayable > 0 ? netGstPayable : 0);
    const totalEquity = totalAssets - liabilitiesTotal;
    const initialCapital = totalEquity - retainedEarnings;
    const totalLiabEquity = totalEquity + liabilitiesTotal;

    res.render('accounting_books', {
      activeTab,
      dateFilter,
      dayBookItems,
      cashReceipts,
      cashPayments,
      cashBalance,
      bankReceipts,
      bankPayments,
      bankBalance,
      sumDebits,
      sumCredits,
      cashDr,
      cashCr,
      bankDr,
      bankCr,
      totalSalesVal,
      totalPurchasesVal,
      debtorsDr,
      debtorsCr,
      creditorsDr,
      creditorsCr,
      suspenseDr,
      suspenseCr,
      debtorAccounts,
      creditorAccounts,
      netSalesRevenue,
      netPurchaseCost,
      netProfit,
      profitMarginPct,
      salesGstCollected,
      purchaseGstPaid,
      netGstPayable,
      totalAssets,
      totalLiabEquity,
      initialCapital,
      retainedEarnings,
      creditorsTotal,
      debtorsTotal,
      stockValuation,
      totalCashIn,
      totalCashOut,
      totalBankIn,
      totalBankOut
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.get('/master_ledger', checkAdminSession, loadSidebarStats, async (req, res) => {
  const search = (req.query.search || '').trim();
  const dateFrom = req.query.date_from || '';
  const dateTo = req.query.date_to || '';

  try {
    // 1. Fetch detailed transaction log
    let query = `
      SELECT 
        o.order_date as created_at,
        c.customer_name as c_name,
        o.fertilizer_name,
        o.quantity,
        o.total_price,
        o.paid_amount,
        o.bill_type,
        o.invoice_no
      FROM orders o
      LEFT JOIN customers c ON o.customer_id = c.id
      WHERE (o.status='Accepted' OR o.status='Delivered')
    `;
    const params = [];
    if (search) {
      query += " AND (c.customer_name LIKE ? OR c.mobile LIKE ?)";
      params.push(`%${search}%`, `%${search}%`);
    }
    if (dateFrom) {
      query += " AND o.order_date >= ?";
      params.push(dateFrom);
    }
    if (dateTo) {
      query += " AND o.order_date <= ?";
      params.push(`${dateTo} 23:59:59`);
    }
    query += " ORDER BY o.order_date DESC, o.id DESC";
    
    const [detailsRaw] = await pool.query(query, params);
    const details = detailsRaw.map(r => ({
      created_at: r.created_at,
      c_name: r.c_name || 'Walk-in Customer',
      fertilizer_name: r.fertilizer_name || '',
      quantity: parseInt(r.quantity || 0, 10),
      total_price: parseFloat(r.total_price || 0),
      paid_amount: parseFloat(r.paid_amount || 0),
      bill_type: r.bill_type || 'Cash',
      invoice_no: r.invoice_no || ''
    }));

    // 2. Fetch customer summaries
    let sumQuery = `
      SELECT 
        c.id,
        c.customer_name,
        c.mobile,
        COALESCE(SUM(CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.total_price ELSE 0 END), 0) as total_bill,
        COALESCE(SUM(DISTINCT CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.paid_amount ELSE 0 END), 0) as total_paid
      FROM customers c
      LEFT JOIN orders o ON o.customer_id = c.id
      GROUP BY c.id, c.customer_name, c.mobile
    `;
    let sumParams = [];
    if (search) {
      sumQuery = `
        SELECT 
          c.id,
          c.customer_name,
          c.mobile,
          COALESCE(SUM(CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.total_price ELSE 0 END), 0) as total_bill,
          COALESCE(SUM(DISTINCT CASE WHEN o.status='Accepted' OR o.status='Delivered' THEN o.paid_amount ELSE 0 END), 0) as total_paid
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id
        WHERE (c.customer_name LIKE ? OR c.mobile LIKE ?)
        GROUP BY c.id, c.customer_name, c.mobile
      `;
      sumParams.push(`%${search}%`, `%${search}%`);
    }
    
    const [custSummariesRaw] = await pool.query(sumQuery, sumParams);
    const custSummaries = custSummariesRaw.map(r => ({
      id: r.id,
      customer_name: r.customer_name,
      mobile: r.mobile || '',
      total_bill: parseFloat(r.total_bill || 0),
      total_paid: parseFloat(r.total_paid || 0)
    }));

    // 3. Aggregate totals
    let totalBillAll = 0;
    let totalPaidAll = 0;
    custSummaries.forEach(c => {
      totalBillAll += c.total_bill;
      totalPaidAll += c.total_paid;
    });
    const totalDueAll = totalBillAll - totalPaidAll;

    res.render('master_ledger', {
      search,
      dateFrom,
      dateTo,
      totalBillAll,
      totalPaidAll,
      totalDueAll,
      custSummaries,
      details
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.get('/customer_ledger', checkAdminSession, loadSidebarStats, async (req, res) => {
  const id = parseInt(req.query.id || '0', 10);
  try {
    const [custRows] = await pool.query("SELECT * FROM customers WHERE id = ?", [id]);
    if (custRows.length === 0) return res.redirect('/customers');
    const customer = custRows[0];

    // Union sales + receipts vouchers
    const [rows] = await pool.query(`
      SELECT 'Sale' as type, sale_date as date, invoice_no as ref, total_price as debit, paid_amount as credit
      FROM sales 
      WHERE customer_name = ?
      UNION ALL
      SELECT voucher_type as type, date, voucher_no as ref, 0 as debit, amount as credit
      FROM vouchers
      WHERE entity_type = 'Customer' AND entity_id = ?
      ORDER BY date DESC
    `, [customer.customer_name, id]);

    let runningBalance = 0;
    const formattedLedger = rows.map(r => {
      r.debit = parseFloat(r.debit || 0);
      r.credit = parseFloat(r.credit || 0);
      runningBalance += (r.debit - r.credit);
      r.balance = runningBalance;
      try {
        r.date_formatted = r.date ? new Date(r.date).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : '—';
      } catch(e) {
        r.date_formatted = r.date || '—';
      }
      return r;
    });

    res.render('customer_ledger', {
      customer,
      ledger: formattedLedger
    });
  } catch (err) {
    console.error(err);
    res.redirect('/customers');
  }
});

// ── 9. GST COMPLIANCE & INTELLIGENCE ──────────────────────────────────────

app.get('/gst_intel', checkAdminSession, loadSidebarStats, async (req, res) => {
  const gstin = req.query.gstin || '';
  let gstRegistry = null;
  let customer = null;
  let stats = null;
  let orders = [];
  let message = req.session.errorMsg || null;
  let successFlag = req.session.successFlag || false;

  req.session.errorMsg = null;
  req.session.successFlag = false;

  if (gstin && gstin.length >= 10) {
    gstRegistry = {
      gstin: gstin.toUpperCase(),
      legal_name: 'AGRIBIZ SUPPLIES PVT LTD',
      trade_name: 'AgriBiz Store',
      state: 'Maharashtra',
      constitution: 'Private Limited Company',
      reg_date: '12-04-2018',
      taxpayer_type: 'Regular'
    };

    try {
      const [cRows] = await pool.query("SELECT * FROM customers WHERE gstin = ?", [gstin]);
      if (cRows.length > 0) {
        customer = cRows[0];
        
        const [sales] = await pool.query("SELECT * FROM sales WHERE customer_name = ? OR gstin = ?", [customer.customer_name, gstin]);
        let t_pur = 0, t_paid = 0;
        
        const invoiceMap = {};
        for (let s of sales) {
          if (!invoiceMap[s.invoice_no]) {
            invoiceMap[s.invoice_no] = {
              invoice_no: s.invoice_no,
              order_date: s.sale_date,
              item_details: [],
              grand_total: 0,
              paid_amount: parseFloat(s.paid_amount || 0),
              status: s.is_return ? 'Voided' : (s.status || 'Delivered')
            };
          }
          invoiceMap[s.invoice_no].item_details.push(`${s.fertilizer_name} (${s.quantity})`);
          invoiceMap[s.invoice_no].grand_total += parseFloat(s.total_price || 0);
          invoiceMap[s.invoice_no].paid_amount = Math.max(invoiceMap[s.invoice_no].paid_amount, parseFloat(s.paid_amount || 0));
        }
        
        orders = Object.values(invoiceMap).map(o => {
          o.item_details = o.item_details.join(', ');
          t_pur += o.grand_total;
          t_paid += o.paid_amount;
          return o;
        });

        stats = {
          total_purchases: t_pur,
          total_paid: t_paid,
          total_due: t_pur - t_paid,
          total_tax: t_pur - (t_pur / 1.18)
        };
      }
    } catch (e) {
      console.error(e);
      message = "Database error fetching customer info.";
    }
  } else if (gstin) {
    message = "Invalid GSTIN format.";
  }

  res.render('gst_intel', { message, successFlag, gstin, gstRegistry, customer, stats, orders });
});

app.post('/gst_intel', checkAdminSession, async (req, res) => {
  const { reg_gstin, reg_name, reg_mobile, reg_address } = req.body;
  try {
    await pool.query(
      "INSERT INTO customers (customer_name, mobile, address, gstin) VALUES (?, ?, ?, ?)",
      [reg_name, reg_mobile, reg_address, reg_gstin.toUpperCase()]
    );
    req.session.successFlag = true;
  } catch (err) {
    console.error(err);
    req.session.errorMsg = err.message;
  }
  res.redirect('/gst_intel?gstin=' + encodeURIComponent(reg_gstin));
});

app.get('/gst_reports', checkAdminSession, loadSidebarStats, async (req, res) => {
  const filter_year = req.query.year || new Date().getFullYear().toString();
  const filter_month = req.query.month || (new Date().getMonth() + 1).toString().padStart(2, '0');
  const period = `${filter_year}-${filter_month}`;
  const activeTab = req.query.tab || 'sales';

  try {
    let salesTaxRows = [];
    let salesTotals = { taxable: 0, cgst: 0, sgst: 0, grand: 0 };
    let purTaxRows = [];
    let purTotals = { taxable: 0, cgst: 0, sgst: 0, grand: 0 };

    if (activeTab === 'sales') {
      // Outward taxable sales GSTR-1
      const [rows] = await pool.query(
        `SELECT 
          COALESCE(f.hsn_code, '28340000') as hsn,
          s.fertilizer_name as particulars,
          SUM(s.quantity) as qty,
          COALESCE(s.gst_rate, 18.00) as tax_rate,
          SUM(s.total_price) as grand
        FROM sales s
        LEFT JOIN fertilizers f ON s.fertilizer_name = f.fertilizer_name
        WHERE s.is_return = 0 AND s.sale_date LIKE ?
        GROUP BY COALESCE(f.hsn_code, '28340000'), s.fertilizer_name, s.gst_rate`,
        [`${period}%`]
      );
      salesTaxRows = rows.map(r => {
        const rate = parseFloat(r.tax_rate || 18.00);
        const grand = parseFloat(r.grand || 0);
        const taxable = grand / (1 + rate / 100);
        const gst = grand - taxable;
        return {
          hsn: r.hsn,
          particulars: r.particulars,
          qty: parseInt(r.qty || 0, 10),
          taxable,
          cgst: gst / 2,
          sgst: gst / 2,
          grand
        };
      });

      salesTotals = salesTaxRows.reduce((acc, curr) => {
        acc.taxable += curr.taxable;
        acc.cgst += curr.cgst;
        acc.sgst += curr.sgst;
        acc.grand += curr.grand;
        return acc;
      }, { taxable: 0, cgst: 0, sgst: 0, grand: 0 });

    } else if (activeTab === 'purchases') {
      // Inward purchases GSTR-2
      const [rows] = await pool.query(
        `SELECT 
          COALESCE(f.hsn_code, '28340000') as hsn,
          p.fertilizer_name as particulars,
          SUM(p.quantity) as qty,
          COALESCE(p.gst_rate, 18.00) as tax_rate,
          SUM(p.cost * p.quantity) as grand
        FROM purchases p
        LEFT JOIN fertilizers f ON p.fertilizer_name = f.fertilizer_name
        WHERE p.is_return = 0 AND p.purchase_date LIKE ?
        GROUP BY COALESCE(f.hsn_code, '28340000'), p.fertilizer_name, p.gst_rate`,
        [`${period}%`]
      );
      purTaxRows = rows.map(r => {
        const rate = parseFloat(r.tax_rate || 18.00);
        const grand = parseFloat(r.grand || 0);
        const taxable = grand / (1 + rate / 100);
        const gst = grand - taxable;
        return {
          hsn: r.hsn,
          particulars: r.particulars,
          qty: parseInt(r.qty || 0, 10),
          taxable,
          cgst: gst / 2,
          sgst: gst / 2,
          grand
        };
      });

      purTotals = purTaxRows.reduce((acc, curr) => {
        acc.taxable += curr.taxable;
        acc.cgst += curr.cgst;
        acc.sgst += curr.sgst;
        acc.grand += curr.grand;
        return acc;
      }, { taxable: 0, cgst: 0, sgst: 0, grand: 0 });
    }

    res.render('gst_reports', {
      filter_year,
      filter_month,
      activeTab,
      salesTaxRows,
      salesTotals,
      purTaxRows,
      purTotals
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

// ── 10. OFF-LINE BILLING & INVOICING ──────────────────────────────────────

app.get('/admin_billing', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [products] = await pool.query("SELECT * FROM fertilizers WHERE quantity > 0 ORDER BY fertilizer_name");
    const [customers] = await pool.query("SELECT * FROM customers ORDER BY customer_name");
    
    // Build catalog map for POS barcode lookup
    const catalogData = {};
    products.forEach(p => {
      if (p.barcode && p.barcode.trim()) {
        catalogData[p.barcode.trim()] = {
          id: p.id,
          fertilizer_name: p.fertilizer_name,
          price: parseFloat(p.price || 0),
          quantity: parseInt(p.quantity || 0, 10)
        };
      }
    });

    res.render('admin_billing', { products, customers, catalogData });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/admin_billing', checkAdminSession, async (req, res) => {
  const { customer_id, product_id, quantity, discount, bill_type, paid_amount, notes } = req.body;
  const qty = parseInt(quantity || '0', 10);
  const disc = parseFloat(discount || '0');
  const paid = parseFloat(paid_amount || '0');

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // 1. Fetch details
    const [cRows] = await conn.query("SELECT customer_name, points FROM customers WHERE id = ?", [customer_id]);
    const [pRows] = await conn.query("SELECT fertilizer_name, price, quantity, batch_no, mfg_date, expiry_date, gst_percent FROM fertilizers WHERE id = ?", [product_id]);
    
    if (cRows.length === 0 || pRows.length === 0) throw new Error("Customer or Product not found");

    const cust = cRows[0];
    const prod = pRows[0];

    if (prod.quantity < qty) throw new Error(`Insufficient stock for product. Available: ${prod.quantity}`);

    const itemPrice = parseFloat(prod.price);
    const total = (itemPrice * qty) - disc;
    const invNo = 'BILL-' + Date.now().toString().slice(-6);

    // 2. Deduct inventory
    await conn.query("UPDATE fertilizers SET quantity = quantity - ? WHERE id = ?", [qty, product_id]);

    // 3. Create order record
    await conn.query(
      `INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date, discount)
       VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Delivered', ?, ?, ?, ?, ?, ?, ?)`,
      [customer_id, cust.customer_name, product_id, prod.fertilizer_name, qty, total, invNo, paid, bill_type, prod.batch_no || '', prod.mfg_date, prod.expiry_date, disc]
    );

    // 4. Create sale invoice entry
    await conn.query(
      `INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date, discount, gst_rate, notes)
       VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [cust.customer_name, prod.fertilizer_name, qty, total, invNo, paid, bill_type, prod.batch_no || '', prod.mfg_date, prod.expiry_date, disc, prod.gst_percent || 18.00, notes || '']
    );

    // 5. Update customer point rewards
    const [adminRows] = await conn.query("SELECT points_multiplier FROM admin LIMIT 1");
    const mult = adminRows[0]?.points_multiplier || 1;
    const ptsEarned = Math.floor(total / 100) * mult;
    await conn.query("UPDATE customers SET points = points + ? WHERE id = ?", [ptsEarned, customer_id]);

    await conn.commit();
    req.session.msg = `Invoice '${invNo}' generated successfully. Points added: ${ptsEarned}`;
    req.session.msgType = 'success';
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Invoicing failed: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  res.redirect('/admin_billing');
});

app.get('/view_invoice', checkAdminSession, loadSidebarStats, async (req, res) => {
  const invoice_no = req.query.invoice_no;
  try {
    const [shopRows] = await pool.query("SELECT * FROM admin LIMIT 1");
    const shop = shopRows[0] || { shop_name: 'AgriBiz Pro', default_gst_rate: 18.00 };

    const [rows] = await pool.query("SELECT * FROM sales WHERE invoice_no = ?", [invoice_no]);
    if (rows.length === 0) return res.status(404).send("Invoice not found");
    
    // Format dates
    const invoice = rows[0];
    invoice.sale_date_formatted = invoice.sale_date ? new Date(invoice.sale_date).toLocaleDateString('en-IN', { day:'2-digit', month:'long', year:'numeric' }) : '';
    
    res.render('view_invoice', { invoice, shop });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

// ── 11. REPORTS & CSV EXPORTER ─────────────────────────────────────────────

app.get('/reports', checkAdminSession, loadSidebarStats, async (req, res) => {
  const filter_from = req.query.from || new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
  const filter_to = req.query.to || new Date().toISOString().split('T')[0];

  try {
    const [sales] = await pool.query("SELECT * FROM sales WHERE sale_date BETWEEN ? AND ? ORDER BY id DESC", [filter_from, filter_to]);
    const [purchases] = await pool.query("SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_date BETWEEN ? AND ? ORDER BY p.id DESC", [filter_from, filter_to]);

    if (req.query.export === 'sales') {
      res.setHeader('Content-Type', 'text/csv');
      res.setHeader('Content-Disposition', `attachment; filename=sales_report_${filter_from}_to_${filter_to}.csv`);
      let csv = 'ID,Date,Invoice No,Customer,Product,Qty,Total Price,Paid,Bill Type\n';
      sales.forEach(s => {
        csv += `"${s.id}","${s.sale_date}","${s.invoice_no}","${s.customer_name}","${s.fertilizer_name}","${s.quantity}","${s.total_price}","${s.paid_amount}","${s.bill_type}"\n`;
      });
      return res.send(csv);
    }
    if (req.query.export === 'purchases') {
      res.setHeader('Content-Type', 'text/csv');
      res.setHeader('Content-Disposition', `attachment; filename=purchases_report_${filter_from}_to_${filter_to}.csv`);
      let csv = 'ID,Date,Invoice No,Supplier,Product,Qty,Cost,Paid,Bill Type\n';
      purchases.forEach(p => {
        csv += `"${p.id}","${p.purchase_date}","${p.invoice_no}","${p.supplier_name || 'N/A'}","${p.fertilizer_name}","${p.quantity}","${p.cost}","${p.paid_amount}","${p.bill_type}"\n`;
      });
      return res.send(csv);
    }

    const now = new Date();
    const today = now.toISOString().split('T')[0];
    const firstDayOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    const lastDayOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];

    const [dRows] = await pool.query("SELECT * FROM sales WHERE sale_date >= ? AND sale_date <= ? ORDER BY id DESC", [today, today]);
    const dailyResult = dRows.map(r => ({ ...r, total_price: parseFloat(r.total_price || 0) }));
    const dailyTotal = dailyResult.reduce((sum, r) => sum + r.total_price, 0);

    const [mRows] = await pool.query("SELECT * FROM sales WHERE sale_date BETWEEN ? AND ? ORDER BY id DESC", [firstDayOfMonth, lastDayOfMonth]);
    const monthlyResult = mRows.map(r => ({ ...r, total_price: parseFloat(r.total_price || 0) }));
    const monthlyTotal = monthlyResult.reduce((sum, r) => sum + r.total_price, 0);

    const [pRows] = await pool.query("SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_date BETWEEN ? AND ? ORDER BY p.id DESC", [firstDayOfMonth, lastDayOfMonth]);
    const purResult = pRows.map(r => ({ ...r, cost: parseFloat(r.cost || 0), quantity: parseFloat(r.quantity || 0) }));
    const purTotal = purResult.reduce((sum, r) => sum + (r.cost * r.quantity), 0);

    res.render('reports', {
      filter_from,
      filter_to,
      sales,
      purchases,
      dailyResult,
      dailyTotal,
      monthlyResult,
      monthlyTotal,
      purResult,
      purTotal
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

// ── 12. OUTSTANDING & WHATSAPP DUNNING ────────────────────────────────────
// Moved to routes/customers.js

// ── 13. AI ANALYTICS & REGRESSION FORECASTING ────────────────────────────

app.get('/ai_analytics', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    // 1. Load last 30 days of sales
    const [salesRows] = await pool.query(`
      SELECT sale_date, SUM(total_price) as daily_total 
      FROM sales 
      WHERE is_return = 0
      GROUP BY sale_date 
      ORDER BY sale_date DESC 
      LIMIT 30
    `);
    
    // Parse and format dataset
    const dataset = salesRows.map(r => ({
      sale_date: r.sale_date ? new Date(r.sale_date).toISOString().split('T')[0] : '',
      daily_total: parseFloat(r.daily_total || 0)
    })).reverse();

    const days = Math.max(1, dataset.length);

    // 2. Calculate Today's Sales and Yesterday's Sales
    const todayStr = new Date().toISOString().split('T')[0];
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const yesterdayStr = yesterday.toISOString().split('T')[0];

    const todaySales = dataset.find(d => d.sale_date === todayStr)?.daily_total || 0;
    const yesterdaySales = dataset.find(d => d.sale_date === yesterdayStr)?.daily_total || 0;
    const dayChange = yesterdaySales > 0 ? parseFloat((((todaySales - yesterdaySales) / yesterdaySales) * 100).toFixed(1)) : 0;

    // 3. 7-Day Moving Average
    const last7 = dataset.slice(-7);
    const ma7 = last7.length > 0 ? last7.reduce((acc, d) => acc + d.daily_total, 0) / last7.length : 0;

    // 4. Linear Regression
    let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
    const n = dataset.length;
    dataset.forEach((d, idx) => {
      const x = idx + 1;
      const y = d.daily_total;
      sumX += x;
      sumY += y;
      sumXY += x * y;
      sumXX += x * x;
    });

    let slope = 0, intercept = 0;
    if (n > 1) {
      slope = (n * sumXY - sumX * sumY) / (n * sumXX - sumX * sumX);
      intercept = (sumY - slope * sumX) / n;
    }

    // 5. Forecast next 14 days
    const forecastLabels = [];
    const forecastAmounts = [];
    let nextWeekForecast = 0;
    for (let i = 1; i <= 14; i++) {
      const pred = Math.max(0, slope * (n + i) + intercept);
      const fd = new Date();
      fd.setDate(fd.getDate() + i);
      const dateStr = fd.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
      forecastLabels.push(dateStr);
      forecastAmounts.push(parseFloat(pred.toFixed(2)));
      if (i <= 7) {
        nextWeekForecast += pred;
      }
    }
    const nextMonthForecast = (dataset.reduce((acc, d) => acc + d.daily_total, 0) / days) * 30;

    // 6. Top Selling Products (30 Days)
    const [topProdRows] = await pool.query(`
      SELECT fertilizer_name, SUM(quantity) as total_qty
      FROM sales
      WHERE is_return = 0 AND sale_date >= CURRENT_DATE - INTERVAL '30 days'
      GROUP BY fertilizer_name
      ORDER BY total_qty DESC
      LIMIT 8
    `);
    const topProdLabels = topProdRows.map(r => r.fertilizer_name);
    const topProdQty = topProdRows.map(r => parseInt(r.total_qty || 0, 10));

    // 7. Slow Moving Products
    const [slowProdRows] = await pool.query(`
      SELECT f.fertilizer_name, f.quantity, COALESCE(SUM(s.quantity), 0) as sold_30d
      FROM fertilizers f
      LEFT JOIN sales s ON f.fertilizer_name = s.fertilizer_name AND s.is_return = 0 AND s.sale_date >= CURRENT_DATE - INTERVAL '30 days'
      GROUP BY f.id, f.fertilizer_name, f.quantity
      ORDER BY sold_30d ASC, f.quantity DESC
      LIMIT 10
    `);
    const slowProducts = slowProdRows.map(r => ({
      fertilizer_name: r.fertilizer_name,
      quantity: parseInt(r.quantity || 0, 10),
      sold_30d: parseInt(r.sold_30d || 0, 10)
    }));

    const chartLabels = dataset.map(d => d.sale_date);
    const chartActual = dataset.map(d => d.daily_total);

    res.render('ai_analytics', {
      todaySales,
      dayChange,
      ma7,
      nextWeekForecast,
      nextMonthForecast,
      topProdLabels,
      topProdQty,
      slowProducts,
      chartLabels,
      chartActual,
      forecastLabels,
      forecastAmounts,
      days,
      slope
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

// ── 14. STAFF/USER CREDENTIALS MANAGEMENT ────────────────────────────────

app.get('/users', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [userCountRows] = await pool.query("SELECT COUNT(*) as c FROM users");
    const userCount = userCountRows[0].c || 0;
    
    const [usersList] = await pool.query("SELECT * FROM users ORDER BY id DESC");
    
    res.render('users', {
      userCount,
      usersList,
      msg: req.session.msg || null,
      msgType: req.session.msgType || null
    });
    delete req.session.msg;
    delete req.session.msgType;
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

app.post('/users', checkAdminSession, async (req, res) => {
  const { add_user, toggle_user, reset_password, full_name, username, password, role, mobile, user_id, current_active, new_password } = req.body;

  try {
    if (add_user !== undefined) {
      const hashed = await bcrypt.hash(password, 10);
      await pool.query(
        "INSERT INTO users (full_name, username, password, role, mobile, is_active) VALUES (?, ?, ?, ?, ?, 1)",
        [full_name || '', username, hashed, role, mobile || '']
      );
      await logAudit(req, `Created ERP user: ${username} (Role: ${role})`);
      req.session.msg = `User @${username} created successfully!`;
      req.session.msgType = 'success';
      
    } else if (toggle_user !== undefined) {
      const activeState = current_active === '1' ? 0 : 1;
      await pool.query("UPDATE users SET is_active = ? WHERE id = ?", [activeState, user_id]);
      await logAudit(req, `Toggled active state of ERP user ID: ${user_id} to ${activeState}`);
      req.session.msg = "User status updated successfully!";
      req.session.msgType = 'success';
      
    } else if (reset_password !== undefined) {
      const hashed = await bcrypt.hash(new_password, 10);
      await pool.query("UPDATE users SET password = ? WHERE id = ?", [hashed, user_id]);
      await logAudit(req, `Reset password of ERP user ID: ${user_id}`);
      req.session.msg = "Password reset successfully!";
      req.session.msgType = 'success';
    }
  } catch (err) {
    console.error(err);
    req.session.msg = "Action failed: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect('/users');
});

// ── 15. AUDIT TRAILS ───────────────────────────────────────────────────────

app.get('/audit_log', checkAdminSession, loadSidebarStats, async (req, res) => {
  const filter_user = (req.query.user || '').trim();
  const filter_from = req.query.from || new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
  const filter_to = req.query.to || new Date().toISOString().split('T')[0];

  let where = "created_at BETWEEN ? AND ?";
  const params = [`${filter_from} 00:00:00`, `${filter_to} 23:59:59`];

  if (filter_user) {
    where += " AND user_name LIKE ?";
    params.push(`%${filter_user}%`);
  }

  try {
    const [logs] = await pool.query(`SELECT * FROM audit_log WHERE ${where} ORDER BY created_at DESC LIMIT 500`, params);
    const [totalRows] = await pool.query(`SELECT COUNT(*) as c FROM audit_log WHERE ${where}`, params);
    const total_logs = totalRows[0].c || 0;

    const [unique_users] = await pool.query("SELECT DISTINCT user_name FROM audit_log ORDER BY user_name");
    
    // stats
    const [todayRows] = await pool.query("SELECT COUNT(*) as c FROM audit_log WHERE DATE(created_at) = CURDATE()");
    const today_logs = todayRows[0].c || 0;

    const [weekRows] = await pool.query("SELECT COUNT(*) as c FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    const week_logs = weekRows[0].c || 0;

    res.render('audit_log', {
      total_logs,
      today_logs,
      week_logs,
      filter_from,
      filter_to,
      filter_user,
      unique_users,
      logs
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

// ── 16. MANUAL BACKUPS ─────────────────────────────────────────────────────

app.get('/backup', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [tablesRes] = await pool.query(`
      SELECT table_name 
      FROM information_schema.tables 
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
      ORDER BY table_name
    `);
    const table_data = [];
    let total_rows = 0;

    for (const r of tablesRes) {
      const tableName = r.table_name || Object.values(r)[0];
      const [cntRows] = await pool.query(`SELECT COUNT(*) as c FROM "${tableName}"`);
      const count = parseInt(cntRows[0].c || 0, 10);
      total_rows += count;
      table_data.push({ name: tableName, rows: count });
    }

    const [sizeRows] = await pool.query(`
      SELECT pg_database_size(current_database()) / 1024.0 AS size_kb
    `);
    const dbsize = parseFloat(sizeRows[0]?.size_kb || 0).toFixed(2);

    res.render('backup', {
      table_data,
      total_rows,
      dbsize
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

app.post('/backup', checkAdminSession, async (req, res) => {
  if (req.body.download_sql !== undefined) {
    try {
      const [tablesRes] = await pool.query(`
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ORDER BY table_name
      `);
      let sql = "-- AgriBiz ERP Database Backup (PostgreSQL)\n";
      sql += `-- Generated: ${new Date().toISOString()}\n\n`;

      for (const r of tablesRes) {
        const table = r.table_name || Object.values(r)[0];
        sql += `-- Table: "${table}"\n`;
        sql += `TRUNCATE TABLE "${table}" RESTART IDENTITY CASCADE;\n\n`;

        // Fetch columns
        const [colsRes] = await pool.query(`
          SELECT column_name 
          FROM information_schema.columns 
          WHERE table_name = ? AND table_schema = 'public'
          ORDER BY ordinal_position
        `, [table]);
        const columns = colsRes.map(c => `"${c.column_name}"`);

        const [dataRows] = await pool.query(`SELECT * FROM "${table}"`);
        if (dataRows.length > 0) {
          sql += `INSERT INTO "${table}" (${columns.join(', ')}) VALUES\n`;
          const valChunks = dataRows.map(row => {
            const vals = colsRes.map(col => {
              const v = row[col.column_name];
              if (v === null || v === undefined) return 'NULL';
              if (typeof v === 'object' && v instanceof Date) {
                return `'${v.toISOString().split('T')[0]}'`;
              }
              if (typeof v === 'boolean') return v ? 'true' : 'false';
              if (typeof v === 'number') return v;
              return `'${v.toString().replace(/'/g, "''")}'`;
            });
            return '(' + vals.join(',') + ')';
          });
          sql += valChunks.join(",\n") + ";\n\n";
        }

        // If there is an id column, reset sequence
        const hasId = colsRes.some(c => c.column_name === 'id');
        if (hasId) {
          sql += `SELECT setval(pg_get_serial_sequence('"${table}"', 'id'), coalesce(max(id), 1), max(id) IS NOT null) FROM "${table}";\n\n`;
        }
      }

      const filename = `agribiz_postgres_backup_${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.sql`;
      res.setHeader('Content-Type', 'application/octet-stream');
      res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
      res.setHeader('Content-Length', Buffer.byteLength(sql));
      await logAudit(req, `Downloaded PostgreSQL SQL backup: ${filename}`);
      return res.send(sql);
    } catch (err) {
      console.error(err);
      res.status(500).send("Backup generation failed: " + err.message);
    }
  } else {
    res.redirect('/backup');
  }
});


// ── 17. DATABASE MIGRATIONS PAGE ──────────────────────────────────────────

app.get('/erp_migrate', checkAdminSession, async (req, res) => {
  try {
    const results = await runMigrations();
    
    let okCount = results.filter(r => r.ok).length;
    let failCount = results.length - okCount;

    res.send(`
      <!DOCTYPE html>
      <html lang="en">
      <head>
      <meta charset="UTF-8"><title>ERP Migration — AgriBiz</title>
      <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('admin-theme') || 'dark');</script>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
      <style>
      *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
      :root{--bg:#0d1117;--card:#161b22;--green:#22c55e;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
      [data-theme="light"]{--bg:#f8fafc;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;}
      body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px;}
      .box{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:32px;max-width:760px;width:100%;}
      h1{font-size:22px;font-weight:800;margin-bottom:4px;} h1 span{color:var(--green);}
      .sub{font-size:13px;color:var(--muted);margin-bottom:24px;}
      .row{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-radius:8px;margin-bottom:4px;font-size:12px;background:rgba(255,255,255,.03);}
      .ok{color:var(--green);} .fail{color:var(--red);}
      .err-msg{font-size:11px;color:var(--red);margin-top:2px;}
      .btn{display:inline-flex;align-items:center;gap:8px;background:var(--green);color:#fff;padding:10px 22px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;margin-top:20px;}
      .summary{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:14px 18px;margin-bottom:20px;}
      </style>
      </head>
      <body>
      <div class="box">
        <h1>ERP <span>Database Migration</span></h1>
        <p class="sub">Running all schema upgrades — safe to run multiple times.</p>
        <div class="summary">
          <strong style="color:var(--green);">✅ ${okCount} migrations passed</strong>
          ${failCount > 0 ? ` &nbsp;|&nbsp; <strong style="color:var(--red);">❌ ${failCount} failed</strong>` : ''}
        </div>
        ${results.map(r => `
          <div class="row">
            <code style="color:var(--muted);flex:1;">${r.sql}</code>
            <span class="${r.ok ? 'ok' : 'fail'}">${r.ok ? '✅' : '❌'}</span>
          </div>
          ${(!r.ok && r.err) ? `<div class="err-msg">&nbsp;&nbsp;Error: ${r.err}</div>` : ''}
        `).join('')}
        <a href="dashboard" class="btn"><i class="fas fa-home"></i> Back to Dashboard</a>
      </div>
      </body></html>
    `);
  } catch (err) {
    console.error(err);
    res.status(500).send("Migration failed: " + err.message);
  }
});

// ── 18. CUSTOMER PORTAL VIEWS ─────────────────────────────────────────────

app.get('/customer_shop', checkCustomerSession, async (req, res) => {
  const customer_id = req.session.customer_id;
  try {
    const [custRows] = await pool.query("SELECT * FROM customers WHERE id = ?", [customer_id]);
    const customer = custRows[0] || {};
    
    // Load products
    const [products] = await pool.query("SELECT * FROM fertilizers WHERE quantity > 0");

    // Format products for EJS view requirements
    const ferts_arr = products.map(p => {
      let icon = '🧪';
      if (p.category === 'Seeds') icon = '🌱';
      else if (p.category === 'Pesticides') icon = '🚿';
      else if (p.category === 'Organic') icon = '🍃';
      else if (p.category === 'Tools') icon = '⚙️';
      
      return {
        ...p,
        icon: p.icon || icon,
        rating: p.rating || (4.0 + (p.id % 10) * 0.1).toFixed(1),
        reviews: p.reviews || (15 + (p.id % 7) * 12)
      };
    });

    // Load orders history grouped by invoice
    const [orderHistoryRows] = await pool.query(`
      SELECT invoice_no, 
             MAX(status) as status, 
             MAX(order_date) as order_date, 
             SUM(total_price) as grand_total, 
             string_agg(fertilizer_name, ', ') as product_names 
      FROM orders 
      WHERE customer_id = ? 
      GROUP BY invoice_no 
      ORDER BY MAX(id) DESC
    `, [customer_id]);

    const order_history = orderHistoryRows.map(o => {
      o.order_date_formatted = o.order_date ? new Date(o.order_date).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : '';
      return o;
    });

    res.render('customer_shop', {
      customer,
      ferts_arr,
      order_history,
      message: req.query.msg || null,
      msg_type: req.query.status || null,
      invoice: req.query.invoice || null
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

app.post('/customer_shop', checkCustomerSession, async (req, res) => {
  const customer_id = req.session.customer_id;
  const customer_name = req.session.customer_name;
  
  const fertilizerList = req.body.fertilizer || req.body['fertilizer[]'] || [];
  const quantityList = req.body.quantity || req.body['quantity[]'] || [];

  const fIds = Array.isArray(fertilizerList) ? fertilizerList : [fertilizerList];
  const fQties = Array.isArray(quantityList) ? quantityList : [quantityList];

  if (fIds.length === 0) {
    return res.redirect('/customer_shop?status=error&msg=No items selected');
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // Generate serial order invoice no
    const [seqRows] = await conn.query("SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM orders");
    const seq = seqRows[0].next_seq.toString().padStart(4, '0');
    const invoice_no = `ORD-${new Date().getFullYear()}-${(new Date().getMonth()+1).toString().padStart(2, '0')}-${seq}`;

    for (let i = 0; i < fIds.length; i++) {
      const fId = fIds[i];
      const qty = parseInt(fQties[i] || '1', 10);

      const [pRows] = await conn.query("SELECT * FROM fertilizers WHERE id = ?", [fId]);
      if (pRows.length === 0) throw new Error("Product not found");
      const prod = pRows[0];

      if (prod.quantity < qty) {
        throw new Error(`Insufficient stock for ${prod.fertilizer_name}. Only ${prod.quantity} units available.`);
      }

      const totalPrice = parseFloat(prod.price) * qty;

      // Insert order item
      await conn.query(
        `INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date)
         VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Pending', ?, 0, 'Cash', ?, ?, ?)`,
        [customer_id, customer_name, fId, prod.fertilizer_name, qty, totalPrice, invoice_no, prod.batch_no || '', prod.mfg_date, prod.expiry_date]
      );
    }

    await conn.commit();
    res.redirect(`/customer_shop?status=success&invoice=${encodeURIComponent(invoice_no)}`);
  } catch (err) {
    await conn.rollback();
    console.error(err);
    res.redirect(`/customer_shop?status=error&msg=${encodeURIComponent(err.message)}`);
  } finally {
    conn.release();
  }
});

app.get('/customer_register', (req, res) => {
  res.render('customer_register', {
    values: {},
    message: null,
    msg_type: null
  });
});

app.post('/customer_register', async (req, res) => {
  const { customer_name, mobile, address, password, gstin } = req.body;
  try {
    const hashed = await bcrypt.hash(password, 10);
    const [ins] = await pool.query(
      "INSERT INTO customers (customer_name, mobile, address, password, gstin) VALUES (?, ?, ?, ?, ?) RETURNING id",
      [customer_name, mobile, address || '', hashed, (gstin || '').toUpperCase()]
    );
    req.session.customer = true;
    req.session.customer_id = ins[0].id;
    req.session.customer_name = customer_name;
    res.redirect('/customer_shop');
  } catch (err) {
    console.error(err);
    res.render('customer_register', {
      error: 'Mobile number already registered or database error.',
      values: req.body,
      message: 'Mobile number already registered or database error.',
      msg_type: 'error'
    });
  }
});

app.get('/customer_profile', checkCustomerSession, async (req, res) => {
  const customer_id = req.session.customer_id;
  try {
    const [rows] = await pool.query("SELECT * FROM customers WHERE id = ?", [customer_id]);
    
    const [orderStats] = await pool.query(`
      SELECT 
        COUNT(id) as orderCount, 
        COALESCE(SUM(total_price), 0) as totalSpent,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pendingCount
      FROM orders WHERE customer_id = ?`, [customer_id]);
      
    const stat = orderStats[0];
    
    res.render('customer_profile', { 
      customer: rows[0], 
      orderCount: stat.orderCount || 0,
      totalSpent: stat.totalSpent || 0,
      pendingCount: stat.pendingCount || 0,
      message: null, 
      msg_type: null 
    });
  } catch (err) {
    console.error(err);
    res.redirect('/customer_shop');
  }
});

app.post('/customer_profile', checkCustomerSession, async (req, res) => {
  const customer_id = req.session.customer_id;
  const { customer_name, mobile, address, gstin, password } = req.body;
  
  const renderProfile = async (msg, type) => {
    try {
      const [rows] = await pool.query("SELECT * FROM customers WHERE id = ?", [customer_id]);
      const [orderStats] = await pool.query(`
        SELECT 
          COUNT(id) as orderCount, 
          COALESCE(SUM(total_price), 0) as totalSpent,
          SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pendingCount
        FROM orders WHERE customer_id = ?`, [customer_id]);
      const stat = orderStats[0];
      res.render('customer_profile', { 
        customer: rows[0], 
        orderCount: stat.orderCount || 0,
        totalSpent: stat.totalSpent || 0,
        pendingCount: stat.pendingCount || 0,
        message: msg, 
        msg_type: type 
      });
    } catch (e) {
      console.error(e);
      res.redirect('/customer_shop');
    }
  };

  try {
    if (password && password.trim() !== '') {
      const hashed = await bcrypt.hash(password, 10);
      await pool.query(
        "UPDATE customers SET customer_name=?, mobile=?, address=?, gstin=?, password=? WHERE id=?",
        [customer_name, mobile, address || '', (gstin || '').toUpperCase(), hashed, customer_id]
      );
    } else {
      await pool.query(
        "UPDATE customers SET customer_name=?, mobile=?, address=?, gstin=? WHERE id=?",
        [customer_name, mobile, address || '', (gstin || '').toUpperCase(), customer_id]
      );
    }
    req.session.customer_name = customer_name;
    await renderProfile('Profile updated successfully!', 'success');
  } catch (err) {
    console.error(err);
    await renderProfile('Update failed: ' + err.message, 'error');
  }
});

app.get('/reorder', checkCustomerSession, async (req, res) => {
  const customer_id = parseInt(req.session.customer_id, 10);
  const invoice_no = req.query.invoice_no;
  if (!invoice_no) return res.redirect('/customer_shop');

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // Fetch original items
    const [items] = await conn.query("SELECT fertilizer_id, fertilizer_name, quantity FROM orders WHERE customer_id=? AND invoice_no=? AND status='Accepted'", [customer_id, invoice_no]);
    if (items.length === 0) {
      throw new Error("Could not find accepted original order items.");
    }

    // Check stock for all
    for (const item of items) {
      const [fRows] = await conn.query("SELECT quantity, price, batch_no, mfg_date, expiry_date FROM fertilizers WHERE id=?", [item.fertilizer_id]);
      if (fRows.length === 0 || fRows[0].quantity < item.quantity) {
        throw new Error(`Insufficient stock for product ${item.fertilizer_name}`);
      }
    }

    // Generate serial order invoice no
    const [seqRows] = await conn.query("SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM orders");
    const seq = seqRows[0].next_seq.toString().padStart(4, '0');
    const new_invoice = `ORD-${new Date().getFullYear()}-${(new Date().getMonth()+1).toString().padStart(2, '0')}-${seq}`;

    // Place orders
    for (const item of items) {
      const [fRows] = await conn.query("SELECT price, batch_no, mfg_date, expiry_date FROM fertilizers WHERE id=?", [item.fertilizer_id]);
      const fert = fRows[0];
      const totalPrice = parseFloat(fert.price) * item.quantity;
      await conn.query(
        `INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date)
         VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Pending', ?, 0, 'Cash', ?, ?, ?)`,
        [customer_id, req.session.customer_name, item.fertilizer_id, item.fertilizer_name, item.quantity, totalPrice, new_invoice, fert.batch_no || '', fert.mfg_date, fert.expiry_date]
      );
    }

    await conn.commit();
    res.redirect(`/customer_shop?status=success&invoice=${encodeURIComponent(new_invoice)}`);
  } catch (err) {
    await conn.rollback();
    console.error(err);
    res.redirect(`/customer_shop?status=error&msg=${encodeURIComponent(err.message)}`);
  } finally {
    conn.release();
  }
});

// ── 19. ORDER MANAGEMENT (ADMIN MODULE) ──────────────────────────────────

app.get('/manage_orders', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [rows] = await pool.query("SELECT * FROM orders ORDER BY id DESC");
    
    // Group by invoice_no
    const groups = {};
    for (let row of rows) {
      if (!groups[row.invoice_no]) {
        groups[row.invoice_no] = {
          invoice_no: row.invoice_no,
          customer_name: row.customer_name,
          order_date_formatted: row.order_date ? new Date(row.order_date).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : '',
          pn_list: [],
          ti: 0,
          gt: 0,
          status: row.status.toLowerCase(), 
        };
      }
      groups[row.invoice_no].pn_list.push(row.fertilizer_name);
      groups[row.invoice_no].ti += row.quantity;
      groups[row.invoice_no].gt += Number(row.total_price) || 0;
    }

    const ordersByStatus = { pending: [], accepted: [], delivery: [], delivered: [], rejected: [] };
    let p_count = 0;

    const [adminRows] = await pool.query("SELECT points_multiplier FROM admin LIMIT 1");
    const mult = adminRows[0]?.points_multiplier || 1;

    for (let inv in groups) {
      const g = groups[inv];
      g.pn = g.pn_list.join(', ');
      if (g.pn.length > 30) g.pn = g.pn.substring(0, 27) + '...';
      g.points_earned = Math.floor(g.gt / 100) * mult;
      
      let s = g.status;
      if (s === 'out for delivery') s = 'delivery';
      
      if (ordersByStatus[s]) {
        ordersByStatus[s].push(g);
      } else {
        ordersByStatus.pending.push(g);
      }
      
      if (s === 'pending') p_count++;
    }

    res.render('manage_orders', { 
      ordersByStatus, 
      p_count, 
      message: req.session.msg || null, 
      wa_link: req.session.waLink || null 
    });
    
    req.session.msg = null;
    req.session.waLink = null;
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

app.post('/manage_orders', checkAdminSession, async (req, res) => {
  const { invoice_no, action } = req.body;
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [oRows] = await conn.query("SELECT * FROM orders WHERE invoice_no = ?", [invoice_no]);
    if (oRows.length === 0) throw new Error("Order not found");

    if (action === 'accept') {
      for (let order of oRows) {
        if (order.status !== 'Pending') continue;
        
        const [fRows] = await conn.query("SELECT quantity, price, company_name, category, batch_no, mfg_date, expiry_date, gst_percent FROM fertilizers WHERE id = ?", [order.fertilizer_id]);
        if (fRows.length === 0) throw new Error(`Product not found for ${order.fertilizer_name}`);
        const fert = fRows[0];
        
        if (fert.quantity < order.quantity) throw new Error(`Insufficient stock for ${order.fertilizer_name}. Only ${fert.quantity} units available.`);
        
        await conn.query("UPDATE fertilizers SET quantity = quantity - ? WHERE id = ?", [order.quantity, order.fertilizer_id]);

        const gst = fert.gst_percent || 18.00;
        await conn.query(
          `INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date, gst_rate)
           VALUES (?, ?, ?, ?, CURDATE(), ?, 0, 'On Account', ?, ?, ?, ?)`,
          [order.customer_name, order.fertilizer_name, order.quantity, order.total_price, order.invoice_no, fert.batch_no || '', fert.mfg_date, fert.expiry_date, gst]
        );
      }
      
      await conn.query("UPDATE orders SET status='Accepted' WHERE invoice_no=?", [invoice_no]);
      
      let total_price = oRows.reduce((sum, r) => sum + Number(r.total_price || 0), 0);
      const [adminRows] = await conn.query("SELECT points_multiplier FROM admin LIMIT 1");
      const mult = adminRows[0]?.points_multiplier || 1;
      const ptsEarned = Math.floor(total_price / 100) * mult;
      
      await conn.query("UPDATE customers SET points = points + ? WHERE id = ?", [ptsEarned, oRows[0].customer_id]);
      
      await logAudit(req, `Approved customer order Invoice: ${invoice_no}`);
      req.session.msg = `Order ${invoice_no} accepted! Stock deducted.`;
      
      const [cRows] = await conn.query("SELECT mobile FROM customers WHERE id = ?", [oRows[0].customer_id]);
      if (cRows.length > 0 && cRows[0].mobile) {
         const msg = `Hi ${oRows[0].customer_name}, your order #${invoice_no} has been Accepted! Total: Rs.${total_price}. Thank you for choosing AgriBiz!`;
         req.session.waLink = `https://wa.me/91${cRows[0].mobile}?text=${encodeURIComponent(msg)}`;
      }
    } else if (action === 'reject') {
      await conn.query("UPDATE orders SET status='Rejected' WHERE invoice_no=?", [invoice_no]);
      await logAudit(req, `Rejected customer order Invoice: ${invoice_no}`);
      req.session.msg = `Order ${invoice_no} rejected!`;
    } else if (action === 'out_for_delivery') {
      await conn.query("UPDATE orders SET status='Out for Delivery' WHERE invoice_no=?", [invoice_no]);
      req.session.msg = `Order ${invoice_no} is now out for delivery!`;
    } else if (action === 'delivered') {
      await conn.query("UPDATE orders SET status='Delivered' WHERE invoice_no=?", [invoice_no]);
      req.session.msg = `Order ${invoice_no} marked as delivered!`;
    }

    await conn.commit();
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Action failed: " + err.message;
  } finally {
    conn.release();
  }
  res.redirect('/manage_orders');
});

// ── 20. SALES HISTORY ─────────────────────────────────────────────────────

app.get('/sales', checkAdminSession, async (req, res) => {
  res.redirect('/sales_invoices');
});

// ── 21. JSON APIs FOR FRONTEND & LIVE UPDATES ─────────────────────────────

app.get('/api_pending_count', checkAdminSession, async (req, res) => {
  try {
    const [rows] = await pool.query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
    res.json({ count: rows[0].c || 0 });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api_search', checkAdminSession, async (req, res) => {
  const q = req.query.q || '';
  if (q.length < 2) return res.json([]);

  try {
    const results = [];

    // Search Customers
    const [customers] = await pool.query("SELECT id, customer_name, mobile FROM customers WHERE customer_name LIKE ? OR mobile LIKE ? LIMIT 5", [`%${q}%`, `%${q}%`]);
    customers.forEach(row => {
      results.push({
        title: `${row.customer_name} (${row.mobile})`,
        url: `update_customer?id=${row.id}`,
        icon: 'fa-user'
      });
    });

    // Search Invoices
    const [orders] = await pool.query("SELECT DISTINCT invoice_no FROM orders WHERE invoice_no LIKE ? LIMIT 5", [`%${q}%`]);
    orders.forEach(row => {
      results.push({
        title: `Invoice: ${row.invoice_no}`,
        url: `view_invoice?invoice_no=${encodeURIComponent(row.invoice_no)}`,
        icon: 'fa-file-invoice'
      });
    });

    // Search Fertilizers
    const [fertilizers] = await pool.query("SELECT id, fertilizer_name FROM fertilizers WHERE fertilizer_name LIKE ? LIMIT 5", [`%${q}%`]);
    fertilizers.forEach(row => {
      results.push({
        title: `Product: ${row.fertilizer_name}`,
        url: `update_fertilizer?id=${row.id}`,
        icon: 'fa-flask'
      });
    });

    res.json(results);
  } catch (err) {
    console.error(err);
    res.json([]);
  }
});


app.get('/api_weather_advisory', (req, res) => {
  const weather_data = {
    temp: 32,
    humidity: 45,
    wind_speed: 12,
    condition: 'Clear',
    rain_probability: 5,
    location: 'Siddipet, Telangana'
  };

  let advisory = "Perfect time to spray. No rain expected for the next 48 hours.";
  let status = "ideal";
  let color = "#22c55e";

  if (weather_data.rain_probability > 50) {
    advisory = "⚠️ High probability of rain today. Postpone spraying to avoid chemical runoff.";
    status = "danger";
    color = "#ef4444";
  } else if (weather_data.wind_speed > 18) {
    advisory = "🌬️ High wind speeds detected. Spraying might drift to other fields. Exercise caution.";
    status = "caution";
    color = "#f59e0b";
  } else if (weather_data.temp > 38) {
    advisory = "🌡️ Extreme heat alert. Apply fertilizers in early morning or late evening only.";
    status = "caution";
    color = "#f59e0b";
  }

  res.json({
    status: 'success',
    weather: weather_data,
    advisory: {
      text: advisory,
      status: status,
      color: color,
      title: 'SPRAYING ADVISORY'
    },
    telugu: {
      title: 'స్ప్రేయింగ్ సలహా',
      text: (status === 'ideal') ? "ఎరువులు వేయడానికి ఇది సరైన సమయం. వచ్చే 48 గంటల్లో వర్షం సూచన లేదు." : "వాతావరణం అనుకూలంగా లేదు. దయచేసి జాగ్రత్తగా ఉండండి."
    }
  });
});

// Run migrations on startup and launch server
(async () => {
  try {
    await runMigrations();
    app.listen(PORT, () => {
      console.log(`🚀 AgriBiz Express Server is running on http://localhost:${PORT}`);
    });
  } catch (err) {
    console.error("❌ Failed to start server: database migrations crashed.", err);
  }
})();

// Trigger server reload to parse new .env configurations

