const { pool, addColumnIfNotExists } = require('./db');
const fs = require('fs');
const path = require('path');

async function runMigrations() {
  console.log('🔄 Running database migrations for PostgreSQL...');
  const results = [];

  // Run base schema from production_database.sql first
  try {
    const sqlPath = path.join(__dirname, 'production_database.sql');
    if (fs.existsSync(sqlPath)) {
      console.log('📄 Found production_database.sql. Running base schema creation...');
      const sqlContent = fs.readFileSync(sqlPath, 'utf8');
      await pool.query(sqlContent);
      console.log('✅ Base schema and seed data loaded successfully.');
      results.push({ sql: 'LOAD base schema (production_database.sql)', ok: true });
    } else {
      console.warn('⚠️ production_database.sql not found at:', sqlPath);
      results.push({ sql: 'LOAD base schema (production_database.sql)', ok: false, err: 'File not found' });
    }
  } catch (err) {
    console.error('❌ Failed to run base schema from production_database.sql:', err.message);
    results.push({ sql: 'LOAD base schema (production_database.sql)', ok: false, err: err.message });
  }
  
  // We can execute column additions safely using addColumnIfNotExists
  const columnMigrations = [
    // Admin settings
    { table: 'admin', column: 'low_stock_threshold', def: 'INT DEFAULT 10' },
    { table: 'admin', column: 'default_gst_rate', def: 'DECIMAL(5,2) DEFAULT 18.00' },
    { table: 'admin', column: 'points_multiplier', def: 'INT DEFAULT 1' },
    { table: 'admin', column: 'shop_name', def: 'VARCHAR(100) DEFAULT \'AgriBiz Pro\'' },
    
    // Sales table
    { table: 'sales', column: 'invoice_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'sales', column: 'paid_amount', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'sales', column: 'bill_type', def: 'VARCHAR(20) DEFAULT \'Cash\'' },
    { table: 'sales', column: 'discount', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'sales', column: 'gst_rate', def: 'DECIMAL(5,2) DEFAULT 18.00' },
    { table: 'sales', column: 'notes', def: 'TEXT' },
    { table: 'sales', column: 'is_return', def: 'SMALLINT DEFAULT 0' },
    { table: 'sales', column: 'return_ref', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'sales', column: 'batch_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'sales', column: 'mfg_date', def: 'DATE NULL' },
    { table: 'sales', column: 'expiry_date', def: 'DATE NULL' },
    
    // Purchases table
    { table: 'purchases', column: 'invoice_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'purchases', column: 'supplier_id', def: 'INT DEFAULT 0' },
    { table: 'purchases', column: 'gst_rate', def: 'DECIMAL(5,2) DEFAULT 18.00' },
    { table: 'purchases', column: 'paid_amount', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'purchases', column: 'bill_type', def: 'VARCHAR(20) DEFAULT \'Cash\'' },
    { table: 'purchases', column: 'notes', def: 'TEXT' },
    { table: 'purchases', column: 'is_return', def: 'SMALLINT DEFAULT 0' },
    
    // Fertilizers (Products)
    { table: 'fertilizers', column: 'hsn_code', def: 'VARCHAR(20) DEFAULT \'\'' },
    { table: 'fertilizers', column: 'category', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'fertilizers', column: 'reorder_level', def: 'INT DEFAULT 10' },
    { table: 'fertilizers', column: 'purchase_price', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'fertilizers', column: 'gst_percent', def: 'DECIMAL(5,2) DEFAULT 18.00' },
    { table: 'fertilizers', column: 'batch_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'fertilizers', column: 'mfg_date', def: 'DATE NULL' },
    { table: 'fertilizers', column: 'expiry_date', def: 'DATE NULL' },
    { table: 'fertilizers', column: 'barcode', def: 'VARCHAR(100) DEFAULT \'\'' },
    
    // Customers
    { table: 'customers', column: 'credit_limit', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'customers', column: 'due_date', def: 'DATE NULL' },
    { table: 'customers', column: 'points', def: 'INT DEFAULT 0' },
    { table: 'customers', column: 'gstin', def: 'VARCHAR(30) DEFAULT \'\'' },
    
    // Suppliers  
    { table: 'suppliers', column: 'email', def: 'VARCHAR(100) DEFAULT \'\'' },
    { table: 'suppliers', column: 'gstin', def: 'VARCHAR(30) DEFAULT \'\'' },
    
    // Orders
    { table: 'orders', column: 'invoice_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'orders', column: 'paid_amount', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'orders', column: 'bill_type', def: 'VARCHAR(20) DEFAULT \'Cash\'' },
    { table: 'orders', column: 'discount', def: 'DECIMAL(10,2) DEFAULT 0' },
    { table: 'orders', column: 'customer_name', def: 'VARCHAR(100) DEFAULT \'\'' },
    { table: 'orders', column: 'fertilizer_name', def: 'VARCHAR(100) DEFAULT \'\'' },
    { table: 'orders', column: 'batch_no', def: 'VARCHAR(50) DEFAULT \'\'' },
    { table: 'orders', column: 'mfg_date', def: 'DATE NULL' },
    { table: 'orders', column: 'expiry_date', def: 'DATE NULL' }
  ];

  for (const m of columnMigrations) {
    const added = await addColumnIfNotExists(m.table, m.column, m.def);
    results.push({ sql: `ALTER TABLE "${m.table}" ADD COLUMN "${m.column}"`, ok: true });
  }

  // Table creations (PostgreSQL-compatible queries)
  const tableQueries = [
    `CREATE TABLE IF NOT EXISTS users (
      id SERIAL PRIMARY KEY,
      username VARCHAR(50) UNIQUE NOT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(50) DEFAULT 'Billing Staff' CHECK (role IN ('Admin','Manager','Billing Staff','Accountant')),
      full_name VARCHAR(100) DEFAULT '',
      mobile VARCHAR(15) DEFAULT '',
      is_active SMALLINT DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )`,
    `CREATE TABLE IF NOT EXISTS audit_log (
      id SERIAL PRIMARY KEY,
      user_name VARCHAR(100),
      role VARCHAR(50),
      action TEXT,
      ip VARCHAR(50),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )`,
    `CREATE TABLE IF NOT EXISTS stock_adjustments (
      id SERIAL PRIMARY KEY,
      fertilizer_id INT,
      fertilizer_name VARCHAR(100),
      adjustment_type VARCHAR(20) DEFAULT 'Add' CHECK (adjustment_type IN ('Add','Remove','Correction')),
      qty_before INT,
      qty_change INT,
      qty_after INT,
      reason TEXT,
      adjusted_by VARCHAR(100),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )`,
    `CREATE TABLE IF NOT EXISTS vouchers (
      id SERIAL PRIMARY KEY,
      voucher_no VARCHAR(50),
      voucher_type VARCHAR(20) DEFAULT 'Receipt' CHECK (voucher_type IN ('Receipt','Payment','Journal','Contra')),
      entity_type VARCHAR(20) DEFAULT 'Customer' CHECK (entity_type IN ('Customer','Supplier','Other')),
      entity_id INT DEFAULT 0,
      amount DECIMAL(10,2) DEFAULT 0,
      payment_method VARCHAR(20) DEFAULT 'Cash',
      narration TEXT,
      date DATE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )`
  ];

  for (const query of tableQueries) {
    try {
      await pool.query(query);
      results.push({ sql: query.trim().split('\n')[0] + '...', ok: true });
    } catch (err) {
      console.error(`❌ Table creation failed:`, err.message);
      results.push({ sql: query.trim().split('\n')[0] + '...', ok: false, err: err.message });
    }
  }
  
  console.log('✅ Database migrations finished.');
  return results;
}

module.exports = { runMigrations };
