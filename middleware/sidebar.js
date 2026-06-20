const { pool } = require('../db');

async function loadSidebarStats(req, res, next) {
  if (req.session && req.session.admin) {
    try {
      const [shopRows] = await pool.query("SELECT shop_name FROM admin LIMIT 1");
      const shopName = shopRows[0]?.shop_name || 'AgriBiz Pro';
      
      const [pendingRows] = await pool.query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
      const pending = pendingRows[0]?.c || 0;
      
      const [lowStockRows] = await pool.query("SELECT COUNT(*) as c FROM fertilizers WHERE quantity <= COALESCE(reorder_level, 10)");
      const lowStock = lowStockRows[0]?.c || 0;
      
      const [expiringRows] = await pool.query("SELECT COUNT(*) as c FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
      const expiring = expiringRows[0]?.c || 0;
      
      res.locals.sidebarStats = {
        shopName,
        role: req.session.admin_role || 'Admin',
        pending,
        lowStock,
        expiring,
        activePage: req.path.replace(/^\//, '')
      };
    } catch (err) {
      console.error("Error loading sidebar stats:", err);
      res.locals.sidebarStats = {
        shopName: 'AgriBiz Pro',
        role: req.session.admin_role || 'Admin',
        pending: 0,
        lowStock: 0,
        expiring: 0,
        activePage: req.path.replace(/^\//, '')
      };
    }
  } else {
    res.locals.sidebarStats = null;
  }
  next();
}

module.exports = {
  loadSidebarStats
};
