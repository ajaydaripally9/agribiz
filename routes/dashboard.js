const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const { pool } = require('../db');
const { checkAdminSession } = require('../middleware/auth');
const { loadSidebarStats } = require('../middleware/sidebar');

router.get('/dashboard', checkAdminSession, loadSidebarStats, async (req, res) => {
  try {
    const [adminRows] = await pool.query("SELECT * FROM admin LIMIT 1");
    const admin_row = adminRows[0] || {};
    const low_thr = parseInt(admin_row.low_stock_threshold || '10', 10);
    const gst_rate = parseFloat(admin_row.default_gst_rate || '18.00');
    const pts_mult = parseInt(admin_row.points_multiplier || '1', 10);
    const shop_name = admin_row.shop_name || 'AgriBiz Pro';
    const admin_username = req.session.admin_username || admin_row.username || 'admin';

    // Metrics
    const [sales7Rows] = await pool.query("SELECT COALESCE(SUM(total_price), 0) as total FROM sales WHERE sale_date >= CURRENT_DATE - INTERVAL '7 days'");
    const sales7day = parseFloat(sales7Rows[0].total || 0);

    const [salesPrevRows] = await pool.query("SELECT COALESCE(SUM(total_price), 0) as total FROM sales WHERE sale_date >= CURRENT_DATE - INTERVAL '14 days' AND sale_date < CURRENT_DATE - INTERVAL '7 days'");
    const sales_prev = parseFloat(salesPrevRows[0].total || 0);
    const salesPct = sales_prev > 0 ? parseFloat((((sales7day - sales_prev) / sales_prev) * 100).toFixed(1)) : 0;

    const [pur7Rows] = await pool.query("SELECT COALESCE(SUM(cost*quantity), 0) as total FROM purchases WHERE purchase_date >= CURRENT_DATE - INTERVAL '7 days'");
    const pur7day = parseFloat(pur7Rows[0].total || 0);

    const [purPrevRows] = await pool.query("SELECT COALESCE(SUM(cost*quantity), 0) as total FROM purchases WHERE purchase_date >= CURRENT_DATE - INTERVAL '14 days' AND purchase_date < CURRENT_DATE - INTERVAL '7 days'");
    const pur_prev = parseFloat(purPrevRows[0].total || 0);
    const purPct = pur_prev > 0 ? parseFloat((((pur7day - pur_prev) / pur_prev) * 100).toFixed(1)) : 0;

    const [totalOrdersRows] = await pool.query("SELECT COUNT(*) as c FROM orders");
    const totalOrders = totalOrdersRows[0].c || 0;

    const [newOrdersRows] = await pool.query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
    const newOrders = newOrdersRows[0].c || 0;

    const [lowStockCountRows] = await pool.query("SELECT COUNT(*) as c FROM fertilizers WHERE quantity < ?", [low_thr]);
    const lowStockCount = lowStockCountRows[0].c || 0;

    const [lowStockItems] = await pool.query("SELECT * FROM fertilizers WHERE quantity < ? ORDER BY quantity ASC", [low_thr]);

    // Financial Chart
    const chartDates = [];
    const chartSales = [];
    const chartPurchases = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      const dateStr = d.toISOString().split('T')[0];
      const mDStr = d.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
      chartDates.push(mDStr);

      const [sRows] = await pool.query("SELECT COALESCE(SUM(total_price), 0) as t FROM sales WHERE sale_date = ?", [dateStr]);
      chartSales.push(parseFloat(sRows[0].t || 0));

      const [pRows] = await pool.query("SELECT COALESCE(SUM(cost*quantity), 0) as t FROM purchases WHERE purchase_date = ?", [dateStr]);
      chartPurchases.push(parseFloat(pRows[0].t || 0));
    }

    const [forecastItems] = await pool.query("SELECT fertilizer_name, quantity, (quantity / 5) as days_left FROM fertilizers WHERE quantity < 25 ORDER BY quantity ASC LIMIT 4");

    res.render('dashboard', {
      shopName: shop_name,
      adminUsername: admin_username,
      newOrders,
      sales7day,
      salesPct,
      pur7day,
      purPct,
      totalOrders,
      lowStockCount,
      lowThr: low_thr,
      lowStockItems,
      chartDates,
      chartSales,
      chartPurchases,
      forecastItems,
      gstRate: gst_rate,
      ptsMult: pts_mult,
      settingsMsg: req.session.settingsMsg || null
    });
    delete req.session.settingsMsg;
  } catch (err) {
    console.error(err);
    res.status(500).send("Internal Server Error");
  }
});

router.post('/dashboard', checkAdminSession, async (req, res) => {
  if (req.body.save_settings) {
    const new_threshold = Math.max(1, parseInt(req.body.low_stock_threshold || '10', 10));
    const new_gst = Math.max(0, parseFloat(req.body.default_gst_rate || '18'));
    const new_pts = Math.max(1, parseInt(req.body.points_multiplier || '1', 10));
    const new_shop = req.body.shop_name || 'AgriBiz Pro';
    const new_user = req.body.admin_username || 'admin';
    const new_pass = req.body.admin_password || '';

    try {
      if (new_pass !== '') {
        const hashed = await bcrypt.hash(new_pass, 10);
        await pool.query("UPDATE admin SET username=?, password=?, low_stock_threshold=?, default_gst_rate=?, points_multiplier=?, shop_name=? LIMIT 1", 
          [new_user, hashed, new_threshold, new_gst, new_pts, new_shop]);
      } else {
        await pool.query("UPDATE admin SET username=?, low_stock_threshold=?, default_gst_rate=?, points_multiplier=?, shop_name=? LIMIT 1", 
          [new_user, new_threshold, new_gst, new_pts, new_shop]);
      }
      req.session.admin_username = new_user;
      req.session.settingsMsg = '✅ Settings saved successfully!';
    } catch (err) {
      console.error(err);
      req.session.settingsMsg = '❌ Failed to save settings: ' + err.message;
    }
  }
  res.redirect('/dashboard');
});

module.exports = router;
