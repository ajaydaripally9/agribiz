const express = require('express');
const router = express.Router();
const { pool, logAudit } = require('../db');
const { checkAdminSession } = require('../middleware/auth');
const { loadSidebarStats } = require('../middleware/sidebar');

// ── SUPPLIER MANAGEMENT ─────────────────────────────────────────────────

router.get('/suppliers', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [rows] = await pool.query("SELECT * FROM suppliers ORDER BY id DESC");
    const [fertilizers] = await pool.query("SELECT * FROM fertilizers ORDER BY fertilizer_name");
    res.render('suppliers', { suppliers: rows, fertilizers });
  } catch (err) {
    console.error(err);
    res.status(500).send("Database error: " + err.message);
  }
});

router.post('/suppliers', checkAdminSession, async (req, res) => {
  const { supplier_name, mobile, email, address, gstin } = req.body;
  try {
    await pool.query(
      "INSERT INTO suppliers (supplier_name, mobile, email, address, gstin) VALUES (?, ?, ?, ?, ?)",
      [supplier_name, mobile, email || '', address || '', (gstin || '').toUpperCase()]
    );
    await logAudit(req, `Added supplier: ${supplier_name}`);
    req.session.msg = `Supplier '${supplier_name}' added successfully!`;
    req.session.msgType = 'success';
  } catch (err) {
    console.error(err);
    req.session.msg = "Error adding supplier: " + err.message;
    req.session.msgType = 'error';
  }
  res.redirect('/suppliers');
});

router.get('/update_supplier', checkAdminSession, loadSidebarStats, async (req, res) => {
  const id = req.query.id;
  try {
    const [rows] = await pool.query("SELECT * FROM suppliers WHERE id = ?", [id]);
    if (rows.length === 0) return res.redirect('/suppliers');
    res.render('update_supplier', { supplier: rows[0] });
  } catch (err) {
    console.error(err);
    res.redirect('/suppliers');
  }
});

router.post('/update_supplier', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  const { supplier_name, mobile, email, address, gstin } = req.body;
  try {
    await pool.query(
      "UPDATE suppliers SET supplier_name=?, mobile=?, email=?, address=?, gstin=? WHERE id=?",
      [supplier_name, mobile, email || '', address || '', (gstin || '').toUpperCase(), id]
    );
    await logAudit(req, `Updated supplier ID: ${id}`);
    req.session.msg = "Supplier updated successfully!";
  } catch (err) {
    console.error(err);
    req.session.msg = "Error: " + err.message;
  }
  res.redirect('/suppliers');
});

router.get('/delete_supplier', checkAdminSession, async (req, res) => {
  const id = req.query.id;
  try {
    await pool.query("DELETE FROM suppliers WHERE id = ?", [id]);
    await logAudit(req, `Deleted supplier ID: ${id}`);
  } catch (err) {
    console.error(err);
  }
  res.redirect('/suppliers');
});

module.exports = router;
