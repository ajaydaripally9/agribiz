const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const { pool } = require('../db');

router.get(['/', '/index'], (req, res) => {
  if (req.session.customer) {
    return res.redirect('/customer_shop');
  }
  if (req.session.admin) {
    return res.redirect('/dashboard');
  }
  res.render('index');
});

router.post('/api_login', async (req, res) => {
  const { type, identifier, password } = req.body;
  if (!identifier || !password || !['customer', 'admin'].includes(type)) {
    return res.json({ success: false, message: 'Please fill in all fields.' });
  }

  try {
    if (type === 'customer') {
      const [rows] = await pool.query("SELECT * FROM customers WHERE mobile = ?", [identifier]);
      if (rows.length > 0) {
        const customer = rows[0];
        const isMatch = await bcrypt.compare(password, customer.password).catch(() => false) || password === customer.password;
        
        if (isMatch) {
          // Auto-hash plaintext passwords if matched directly
          if (password === customer.password) {
            const hashedPw = await bcrypt.hash(password, 10);
            await pool.query("UPDATE customers SET password = ? WHERE id = ?", [hashedPw, customer.id]);
          }
          req.session.customer = true;
          req.session.customer_id = customer.id;
          req.session.customer_name = customer.customer_name;
          return res.json({ success: true, redirect: 'customer_shop' });
        }
      }
      return res.json({ success: false, message: 'Invalid mobile number or password.' });
      
    } else if (type === 'admin') {
      const [rows] = await pool.query("SELECT * FROM admin WHERE username = ?", [identifier]);
      if (rows.length > 0) {
        const admin = rows[0];
        const isMatch = await bcrypt.compare(password, admin.password).catch(() => false) || password === admin.password;
        
        if (isMatch) {
          req.session.admin = true;
          req.session.admin_username = admin.username;
          req.session.admin_role = admin.role || 'Admin';
          return res.json({ success: true, redirect: 'dashboard' });
        }
      }
      // Check user table fallback
      const [userRows] = await pool.query("SELECT * FROM users WHERE username = ? AND is_active = 1", [identifier]);
      if (userRows.length > 0) {
        const user = userRows[0];
        const isMatch = await bcrypt.compare(password, user.password).catch(() => false) || password === user.password;
        if (isMatch) {
          req.session.admin = true;
          req.session.admin_username = user.username;
          req.session.admin_role = user.role || 'Billing Staff';
          return res.json({ success: true, redirect: 'dashboard' });
        }
      }
      return res.json({ success: false, message: 'Invalid username or password.' });
    }
  } catch (err) {
    console.error("Login error:", err);
    return res.json({ success: false, message: 'An internal server error occurred: ' + err.message });
  }
});

router.get('/logout', (req, res) => {
  req.session.destroy();
  res.redirect('/index');
});

router.get('/customer_logout', (req, res) => {
  req.session.destroy();
  res.redirect('/index');
});

module.exports = router;
