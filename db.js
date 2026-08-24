const { Pool } = require('pg');
const sqlite3 = require('sqlite3').verbose();
const dotenv = require('dotenv');
const path = require('path');

// Load environment variables
dotenv.config({ path: path.join(__dirname, '.env') });

let rawDbUrl = process.env.DATABASE_URL;
let rawHost = process.env.DB_HOST;
const regionDomain = process.env.RENDER_REGION_DOMAIN || 'oregon-postgres.render.com';

// Fix Render short internal hostnames (e.g. dpg-xxxxx-a) that lack domain suffix
if (rawDbUrl && rawDbUrl.match(/@dpg-[a-z0-9]+-a(\/|\?|$)/i)) {
  rawDbUrl = rawDbUrl.replace(/@dpg-([a-z0-9]+)-a(\/|\?|$)/gi, `@dpg-$1-a.${regionDomain}$2`);
}
if (rawHost && rawHost.match(/^dpg-[a-z0-9]+-a$/i)) {
  rawHost = `${rawHost}.${regionDomain}`;
}

let useSqlite = false;
let sqliteDb = null;
let pgPool = null;

const sqlitePath = path.join(__dirname, 'fertilizer_shop.sqlite');

function translateSql(sql) {
  if (!sql) return sql;
  let index = 1;
  let translated = sql.replace(/\?/g, () => `$${index++}`);
  translated = translated.replace(/`/g, '"');
  translated = translated.replace(/DATE_SUB\(([^,]+),\s*INTERVAL\s*(\d+)\s*DAY\)/gi, (m, dateExpr, days) => `${dateExpr} - INTERVAL '${days} days'`);
  translated = translated.replace(/DATE_ADD\(([^,]+),\s*INTERVAL\s*(\d+)\s*DAY\)/gi, (m, dateExpr, days) => `${dateExpr} + INTERVAL '${days} days'`);
  translated = translated.replace(/CURDATE\(\)/gi, 'CURRENT_DATE');
  translated = translated.replace(/\b([a-zA-Z0-9_]+_date)\s+LIKE\b/gi, '$1::text LIKE');
  return translated;
}

function translateSqlForSqlite(sql) {
  if (!sql) return sql;
  let s = sql;
  s = s.replace(/`/g, '"');
  s = s.replace(/CURRENT_DATE\s*-\s*INTERVAL\s*'(\d+)\s*days'/gi, "date('now', '-$1 days')");
  s = s.replace(/CURRENT_DATE\s*\+\s*INTERVAL\s*'(\d+)\s*days'/gi, "date('now', '+$1 days')");
  s = s.replace(/CURRENT_DATE/gi, "date('now')");
  s = s.replace(/CURDATE\(\)/gi, "date('now')");
  s = s.replace(/::text/gi, "");
  s = s.replace(/SELECT setval\([^;]+\);?/gi, "SELECT 1;");
  return s;
}

function initSqlite() {
  if (useSqlite) return;
  useSqlite = true;
  console.log('📦 Initializing zero-config SQLite Database at:', sqlitePath);
  sqliteDb = new sqlite3.Database(sqlitePath);

  sqliteDb.serialize(() => {
    sqliteDb.run(`CREATE TABLE IF NOT EXISTS admin (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username VARCHAR(50) UNIQUE,
      password VARCHAR(100),
      low_stock_threshold INT DEFAULT 10,
      default_gst_rate DECIMAL(5,2) DEFAULT 18.00,
      points_multiplier INT DEFAULT 1,
      shop_name VARCHAR(100) DEFAULT 'AgriBiz Pro',
      role VARCHAR(50) DEFAULT 'Admin'
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS fertilizers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      barcode VARCHAR(100) DEFAULT '',
      fertilizer_name VARCHAR(100),
      company_name VARCHAR(100),
      quantity INT,
      price DECIMAL(10,2),
      hsn_code VARCHAR(20) DEFAULT '',
      category VARCHAR(50) DEFAULT '',
      reorder_level INT DEFAULT 10,
      purchase_price DECIMAL(10,2) DEFAULT 0,
      gst_percent DECIMAL(5,2) DEFAULT 18.00,
      batch_no VARCHAR(50) DEFAULT '',
      mfg_date DATE NULL,
      expiry_date DATE NULL
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS customers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_name VARCHAR(100),
      mobile VARCHAR(15) UNIQUE,
      address TEXT,
      password VARCHAR(100) DEFAULT '',
      gstin VARCHAR(30) DEFAULT '',
      points INT DEFAULT 0,
      credit_limit DECIMAL(10,2) DEFAULT 0,
      due_date DATE NULL
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS suppliers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      supplier_name VARCHAR(100),
      mobile VARCHAR(15),
      address TEXT,
      email VARCHAR(100) DEFAULT '',
      gstin VARCHAR(30) DEFAULT ''
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS sales (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_name VARCHAR(100),
      fertilizer_name VARCHAR(100),
      quantity INT,
      total_price DECIMAL(10,2),
      sale_date DATE,
      invoice_no VARCHAR(50) DEFAULT '',
      paid_amount DECIMAL(10,2) DEFAULT 0,
      bill_type VARCHAR(20) DEFAULT 'Cash',
      discount DECIMAL(10,2) DEFAULT 0,
      gst_rate DECIMAL(5,2) DEFAULT 18.00,
      notes TEXT,
      is_return SMALLINT DEFAULT 0,
      return_ref VARCHAR(50) DEFAULT '',
      batch_no VARCHAR(50) DEFAULT '',
      mfg_date DATE NULL,
      expiry_date DATE NULL
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS purchases (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      supplier_name VARCHAR(100),
      fertilizer_name VARCHAR(100),
      quantity INT,
      cost DECIMAL(10,2),
      purchase_date DATE,
      invoice_no VARCHAR(50) DEFAULT '',
      supplier_id INT DEFAULT 0,
      gst_rate DECIMAL(5,2) DEFAULT 18.00,
      paid_amount DECIMAL(10,2) DEFAULT 0,
      bill_type VARCHAR(20) DEFAULT 'Cash',
      notes TEXT,
      is_return SMALLINT DEFAULT 0
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_id INT,
      fertilizer_id INT,
      quantity INT,
      total_price DECIMAL(10,2),
      status VARCHAR(50) DEFAULT 'Pending',
      order_date DATETIME DEFAULT CURRENT_TIMESTAMP,
      points_earned INT DEFAULT 0,
      invoice_no VARCHAR(50) DEFAULT '',
      paid_amount DECIMAL(10,2) DEFAULT 0,
      bill_type VARCHAR(20) DEFAULT 'Cash',
      discount DECIMAL(10,2) DEFAULT 0,
      customer_name VARCHAR(100) DEFAULT '',
      fertilizer_name VARCHAR(100) DEFAULT '',
      batch_no VARCHAR(50) DEFAULT '',
      mfg_date DATE NULL,
      expiry_date DATE NULL
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username VARCHAR(50) UNIQUE NOT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(50) DEFAULT 'Billing Staff',
      is_active SMALLINT DEFAULT 1
    )`);

    sqliteDb.run(`CREATE TABLE IF NOT EXISTS audit_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_name VARCHAR(100),
      role VARCHAR(50),
      action TEXT,
      ip VARCHAR(50),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`);

    // Seed default admin if empty
    sqliteDb.get("SELECT COUNT(*) as c FROM admin", (err, row) => {
      if (!err && row && row.c === 0) {
        sqliteDb.run("INSERT INTO admin (id, username, password, shop_name) VALUES (1, 'admin', 'admin123', 'Venkateshwara Fertilizers')");
      }
    });

    // Seed default products if empty
    sqliteDb.get("SELECT COUNT(*) as c FROM fertilizers", (err, row) => {
      if (!err && row && row.c === 0) {
        sqliteDb.run("INSERT INTO fertilizers (id, barcode, fertilizer_name, company_name, quantity, price) VALUES (1, '89012345', 'Urea', 'ABC Company', 100, 50.00)");
        sqliteDb.run("INSERT INTO fertilizers (id, barcode, fertilizer_name, company_name, quantity, price) VALUES (2, '89012346', 'DAP', 'XYZ Ltd', 50, 80.00)");
        sqliteDb.run("INSERT INTO fertilizers (id, barcode, fertilizer_name, company_name, quantity, price) VALUES (3, '89012347', 'Potash', 'Fertilizer Corp', 75, 60.00)");
        sqliteDb.run("INSERT INTO fertilizers (id, barcode, fertilizer_name, company_name, quantity, price) VALUES (4, '89012348', 'Organic Compost', 'Green Farms', 20, 30.00)");
      }
    });

    // Seed default customer if empty
    sqliteDb.get("SELECT COUNT(*) as c FROM customers", (err, row) => {
      if (!err && row && row.c === 0) {
        sqliteDb.run("INSERT INTO customers (id, customer_name, mobile, address, password) VALUES (1, 'John Doe', '1234567890', '123 Main St', '123456')");
      }
    });

    console.log('✅ SQLite Database schema and seed data ready!');
  });
}

function querySqlite(sql, params = []) {
  return new Promise((resolve, reject) => {
    let s = translateSqlForSqlite(sql);
    const isSelect = /^\s*(SELECT|PRAGMA|EXPLAIN)/i.test(s);
    if (isSelect) {
      sqliteDb.all(s, params, (err, rows) => {
        if (err) return reject(err);
        resolve([rows || [], []]);
      });
    } else {
      sqliteDb.run(s, params, function(err) {
        if (err) return reject(err);
        resolve([[{ insertId: this.lastID, affectedRows: this.changes }], []]);
      });
    }
  });
}

class PostgresConnectionWrapper {
  constructor(client) {
    this.client = client;
  }
  async query(sql, params) {
    const pgSql = translateSql(sql);
    const res = params ? await this.client.query(pgSql, params) : await this.client.query(pgSql);
    return [res.rows, res.fields];
  }
  async beginTransaction() {
    await this.client.query('BEGIN');
  }
  async commit() {
    await this.client.query('COMMIT');
  }
  async rollback() {
    await this.client.query('ROLLBACK');
  }
  release() {
    this.client.release();
  }
}

const pool = {
  async query(sql, params) {
    if (useSqlite) {
      return querySqlite(sql, params);
    }
    const pgSql = translateSql(sql);
    const res = params ? await pgPool.query(pgSql, params) : await pgPool.query(pgSql);
    return [res.rows, res.fields];
  },
  async getConnection() {
    if (useSqlite) {
      return {
        query: (sql, params) => querySqlite(sql, params),
        beginTransaction: async () => querySqlite('BEGIN TRANSACTION'),
        commit: async () => querySqlite('COMMIT'),
        rollback: async () => querySqlite('ROLLBACK'),
        release: () => {}
      };
    }
    const client = await pgPool.connect();
    return new PostgresConnectionWrapper(client);
  },
  async end() {
    if (useSqlite && sqliteDb) {
      sqliteDb.close();
    } else if (pgPool) {
      await pgPool.end();
    }
  }
};

// Connection Initialization
if (rawDbUrl || process.env.DB_HOST) {
  try {
    const poolConfig = rawDbUrl
      ? { connectionString: rawDbUrl, ssl: { rejectUnauthorized: false } }
      : {
          host: rawHost || '127.0.0.1',
          port: parseInt(process.env.DB_PORT || '5432', 10),
          user: process.env.DB_USER || 'postgres',
          password: process.env.DB_PASSWORD || '',
          database: process.env.DB_NAME || 'fertilizer_shop',
          ssl: (rawHost && rawHost.includes('render.com') || process.env.NODE_ENV === 'production') ? { rejectUnauthorized: false } : false
        };

    pgPool = new Pool({
      ...poolConfig,
      max: 10,
      idleTimeoutMillis: 30000,
      connectionTimeoutMillis: 5000
    });

    pgPool.connect((err, client, release) => {
      if (err) {
        console.warn('⚠️ PostgreSQL connection failed:', err.message);
        console.log('🔄 Falling back to zero-config SQLite Database...');
        initSqlite();
      } else {
        console.log('✅ PostgreSQL Database Connected successfully');
        release();
      }
    });
  } catch (err) {
    initSqlite();
  }
} else {
  console.log('ℹ️ No PostgreSQL environment variables detected.');
  initSqlite();
}

async function addColumnIfNotExists(table, column, definition) {
  try {
    if (useSqlite) {
      const [rows] = await pool.query(`PRAGMA table_info("${table}")`);
      const exists = rows.some(r => r.name.toLowerCase() === column.toLowerCase());
      if (!exists) {
        let sqliteDef = definition.replace(/SERIAL/gi, 'INTEGER').replace(/TINYINT/gi, 'INTEGER');
        await pool.query(`ALTER TABLE "${table}" ADD COLUMN "${column}" ${sqliteDef}`);
        return true;
      }
      return false;
    }
    const [rows] = await pool.query(
      `SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?`,
      [table.toLowerCase(), column.toLowerCase()]
    );
    if (rows.length === 0) {
      let pgDefinition = definition
        .replace(/TINYINT/gi, 'SMALLINT')
        .replace(/DATETIME/gi, 'TIMESTAMP')
        .replace(/INT AUTO_INCREMENT/gi, 'SERIAL');

      await pool.query(`ALTER TABLE "${table}" ADD COLUMN "${column}" ${pgDefinition}`);
      return true;
    }
    return false;
  } catch (err) {
    return false;
  }
}

async function logAudit(req, action) {
  const user = req.session?.admin_username || req.session?.admin || 'unknown';
  const role = req.session?.admin_role || 'Admin';
  const ip = req.ip || req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown';
  
  try {
    await pool.query(
      'INSERT INTO audit_log (user_name, role, action, ip) VALUES (?, ?, ?, ?)',
      [user, role, action, ip]
    );
  } catch (err) {
    console.error('❌ Failed to log audit record:', err.message);
  }
}

function checkRole(allowedRoles) {
  return (req, res, next) => {
    if (!req.session || !req.session.admin) {
      return res.redirect('/index');
    }
    const role = req.session.admin_role || 'Admin';
    if (!allowedRoles.includes(role)) {
      return res.status(403).send(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
            <style>
                body { background: #0d1117; color: #e6edf3; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .card { background: #161b22; border: 1px solid #30363d; padding: 40px; border-radius: 18px; text-align: center; max-width: 450px; }
                h1 { color: #ef4444; font-size: 24px; margin-bottom: 12px; }
                p { color: #8b949e; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
                .btn { background: #22c55e; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13px; }
            </style>
        </head>
        <body>
            <div class='card'>
                <h1>🔒 Access Denied</h1>
                <p>Your current role <strong>"${role}"</strong> does not have permission to access this screen.</p>
                <a href='/dashboard' class='btn'>Back to Dashboard</a>
            </div>
        </body>
        </html>
      `);
    }
    next();
  };
}

module.exports = {
  pool,
  query: (sql, params) => pool.query(sql, params),
  addColumnIfNotExists,
  logAudit,
  checkRole
};
