const express = require('express');
const router = express.Router();
const { pool, logAudit } = require('../db');
const { checkAdminSession } = require('../middleware/auth');
const { loadSidebarStats } = require('../middleware/sidebar');

// ── PRODUCT MANAGEMENT ─────────────────────────────────────────────

router.get('/view_fertilizer', checkAdminSession, loadSidebarStats, async (req, res) => {
  const search = req.query.search || '';
  try {
    const [rows] = await pool.query(
      "SELECT * FROM fertilizers WHERE fertilizer_name LIKE ? OR company_name LIKE ?",
      [`%${search}%`, `%${search}%`]
    );
    res.render('view_fertilizer', {
      fertilizers: rows,
      search: search
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error");
  }
});

router.get('/add_fertilizer', checkAdminSession, loadSidebarStats, (req, res) => {
  res.render('add_fertilizer');
});

router.post('/add_fertilizer', checkAdminSession, async (req, res) => {
  const { barcode, fertilizer_name, company_name, quantity, price, category, npk_ratio, weight, batch_no, mfg_date, expiry_date, purchase_price, hsn_code, reorder_level } = req.body;
  
  const mfg = mfg_date || null;
  const exp = expiry_date || null;
  const qty = parseInt(quantity || '0', 10);
  const prc = parseFloat(price || '0');
  const purPrc = parseFloat(purchase_price || '0');
  const reorder = parseInt(reorder_level || '10', 10);

  try {
    await pool.query(
      `INSERT INTO fertilizers (barcode, fertilizer_name, company_name, quantity, price, category, batch_no, mfg_date, expiry_date, purchase_price, hsn_code, reorder_level) 
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [barcode || '', fertilizer_name, company_name, qty, prc, category || '', batch_no || '', mfg, exp, purPrc, hsn_code || '', reorder]
    );
    await logAudit(req, `Added fertilizer product: ${fertilizer_name} (${qty} units)`);
    req.session.msg = `Product '${fertilizer_name}' added successfully to inventory!`;
    req.session.msgType = 'success';
  } catch (err) {
    console.error(err);
    req.session.msg = "Error adding product: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect('/add_fertilizer');
});

router.get('/update_fertilizer', checkAdminSession, loadSidebarStats, async (req, res) => {
  const id = req.query.id;
  try {
    const [rows] = await pool.query("SELECT * FROM fertilizers WHERE id = ?", [id]);
    if (rows.length === 0) {
      return res.redirect('/view_fertilizer');
    }
    const product = rows[0];
    
    // format dates
    product.mfg_date_formatted = product.mfg_date ? new Date(product.mfg_date).toISOString().split('T')[0] : '';
    product.expiry_date_formatted = product.expiry_date ? new Date(product.expiry_date).toISOString().split('T')[0] : '';
    
    res.render('update_fertilizer', { product });
  } catch (err) {
    console.error(err);
    res.redirect('/view_fertilizer');
  }
});

router.post('/update_fertilizer', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  const { category, fertilizer_name, company_name, quantity, reorder_level, price, purchase_price, hsn_code, batch_no, mfg_date, expiry_date } = req.body;
  
  const mfg = mfg_date || null;
  const exp = expiry_date || null;
  const qty = parseInt(quantity || '0', 10);
  const prc = parseFloat(price || '0');
  const purPrc = parseFloat(purchase_price || '0');
  const reorder = parseInt(reorder_level || '10', 10);

  try {
    await pool.query(
      `UPDATE fertilizers SET category=?, fertilizer_name=?, company_name=?, quantity=?, reorder_level=?, price=?, purchase_price=?, hsn_code=?, batch_no=?, mfg_date=?, expiry_date=? WHERE id=?`,
      [category, fertilizer_name, company_name, qty, reorder, prc, purPrc, hsn_code, batch_no, mfg, exp, id]
    );
    await logAudit(req, `Updated product ID: ${id} (${fertilizer_name})`);
    req.session.message = "Product updated successfully!";
  } catch (err) {
    console.error(err);
    req.session.message = "Error updating product: " + err.message;
  }
  res.redirect('/view_fertilizer');
});

router.get('/delete_fertilizer', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  try {
    await pool.query("DELETE FROM fertilizers WHERE id = ?", [id]);
    await logAudit(req, `Deleted product ID: ${id}`);
  } catch (err) {
    console.error(err);
  }
  res.redirect('/view_fertilizer');
});

// ── INVENTORY CONTROL & ADJUSTMENTS ────────────────────────────────────

router.get('/inventory', checkAdminSession, loadSidebarStats, async (req, res) => {
  const tab = req.query.tab || 'stock';
  try {
    const [adminRows] = await pool.query("SELECT low_stock_threshold FROM admin LIMIT 1");
    const lowThr = adminRows[0]?.low_stock_threshold || 10;

    const [rows] = await pool.query("SELECT * FROM fertilizers ORDER BY id DESC");
    
    // format dates for fertilizers
    const allProducts = rows.map(p => {
      p.mfg_date_formatted = p.mfg_date ? new Date(p.mfg_date).toISOString().split('T')[0] : '';
      p.expiry_date_formatted = p.expiry_date ? new Date(p.expiry_date).toISOString().split('T')[0] : '';
      return p;
    });

    const batchProducts = allProducts.filter(p => p.batch_no && p.batch_no.trim() !== '');

    const now = new Date();
    const expired = allProducts.filter(p => p.expiry_date && new Date(p.expiry_date) < now);
    const expiring30 = allProducts.filter(p => {
      if (!p.expiry_date) return false;
      const expDate = new Date(p.expiry_date);
      const diffDays = (expDate - now) / (1000 * 60 * 60 * 24);
      return diffDays >= 0 && diffDays <= 30;
    });

    const [adjustmentsRows] = await pool.query("SELECT * FROM stock_adjustments ORDER BY id DESC LIMIT 50");
    const adjustments = adjustmentsRows.map(a => {
      try {
        a.created_at_formatted = a.created_at ? new Date(a.created_at).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '—';
      } catch(e) {
        a.created_at_formatted = a.created_at || '—';
      }
      return a;
    });

    // Calculate stats
    const [statsRows] = await pool.query(`
      SELECT 
        COUNT(*) as total_products,
        COALESCE(SUM(quantity), 0) as total_units,
        COALESCE(SUM(quantity * price), 0) as inventory_value
      FROM fertilizers
    `);
    const stats = {
      total_products: parseInt(statsRows[0].total_products || 0, 10),
      total_units: parseInt(statsRows[0].total_units || 0, 10),
      inventory_value: parseFloat(statsRows[0].inventory_value || 0)
    };

    // Calculate low stock count
    const [lowRows] = await pool.query("SELECT COUNT(*) as c FROM fertilizers WHERE quantity <= ?", [lowThr]);
    const low_ct = parseInt(lowRows[0].c || 0, 10);

    // Calculate expiring count (expiring in next 30 days)
    const [expRows] = await pool.query("SELECT COUNT(*) as c FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND expiry_date >= CURRENT_DATE");
    const exp_ct = parseInt(expRows[0].c || 0, 10);

    res.render('inventory', {
      tab,
      allProducts,
      batchProducts,
      expired,
      expiring30,
      adjustments,
      lowThr,
      stats,
      low_ct,
      exp_ct
    });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

router.post('/inventory', checkAdminSession, async (req, res) => {
  const fertilizer_id = req.body.fertilizer_id;
  const adjustment_type = req.body.adjustment_type || req.body.adj_type;
  const quantity = req.body.quantity || req.body.adj_qty;
  const reason = req.body.reason || req.body.adj_reason;
  const qtyChange = parseInt(quantity || '0', 10);

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [fRows] = await conn.query("SELECT fertilizer_name, quantity FROM fertilizers WHERE id = ?", [fertilizer_id]);
    if (fRows.length === 0) throw new Error("Fertilizer not found");
    const fert = fRows[0];
    const qtyBefore = fert.quantity;
    
    let qtyAfter = qtyBefore;
    if (adjustment_type === 'Add') {
      qtyAfter += qtyChange;
    } else if (adjustment_type === 'Remove') {
      qtyAfter -= qtyChange;
    } else if (adjustment_type === 'Correction') {
      qtyAfter = qtyChange;
    }

    // Update product quantity
    await conn.query("UPDATE fertilizers SET quantity = ? WHERE id = ?", [qtyAfter, fertilizer_id]);

    // Insert stock adjustment log entry
    await conn.query(
      `INSERT INTO stock_adjustments (fertilizer_id, fertilizer_name, adjustment_type, qty_before, qty_change, qty_after, reason, adjusted_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [fertilizer_id, fert.fertilizer_name, adjustment_type, qtyBefore, qtyChange, qtyAfter, reason || '', req.session.admin_username || 'Admin']
    );

    await conn.commit();
    req.session.msg = "Inventory stock adjusted successfully!";
    req.session.msgType = 'success';
  } catch (err) {
    await conn.rollback();
    console.error(err);
    req.session.msg = "Adjustment failed: " + err.message;
    req.session.msgType = 'error';
  } finally {
    conn.release();
  }
  res.redirect('/inventory');
});

module.exports = router;
