const { Pool } = require('pg');
const dotenv = require('dotenv');
const path = require('path');

// Load environment variables
dotenv.config({ path: path.join(__dirname, '.env') });

let rawDbUrl = process.env.DATABASE_URL;
let rawHost = process.env.DB_HOST || '127.0.0.1';
const regionDomain = process.env.RENDER_REGION_DOMAIN || 'oregon-postgres.render.com';

// Fix Render short internal hostnames (e.g. dpg-xxxxx-a) that lack domain suffix
if (rawDbUrl && rawDbUrl.match(/@dpg-[a-z0-9]+-a(\/|\?|$)/i)) {
  console.log(`ℹ️ Expanding Render short internal DATABASE_URL hostname to include .${regionDomain}`);
  rawDbUrl = rawDbUrl.replace(/@dpg-([a-z0-9]+)-a(\/|\?|$)/gi, `@dpg-$1-a.${regionDomain}$2`);
}

if (rawHost && rawHost.match(/^dpg-[a-z0-9]+-a$/i)) {
  console.log(`ℹ️ Expanding Render short internal DB_HOST hostname to include .${regionDomain}`);
  rawHost = `${rawHost}.${regionDomain}`;
}

const poolConfig = rawDbUrl
  ? {
      connectionString: rawDbUrl,
      ssl: {
        rejectUnauthorized: false
      }
    }
  : {
      host: rawHost,
      port: parseInt(process.env.DB_PORT || '5432', 10),
      user: process.env.DB_USER || 'postgres',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_NAME || 'fertilizer_shop',
      ssl: (rawHost.includes('render.com') || process.env.NODE_ENV === 'production') ? { rejectUnauthorized: false } : false
    };

const pgPool = new Pool({
  ...poolConfig,
  max: 10, // maximum connection pool limit
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 10000
});

// Helper to translate MySQL queries to PostgreSQL
function translateSql(sql) {
  if (!sql) return sql;
  let index = 1;
  // 1. Convert ? placeholders to $1, $2, $3...
  let translated = sql.replace(/\?/g, () => `$${index++}`);
  
  // 2. Convert MySQL backticks to standard Postgres double quotes
  translated = translated.replace(/`/g, '"');
  
  // 3. Convert MySQL DATE_SUB(date, INTERVAL X DAY) to Postgres: date - INTERVAL 'X days'
  translated = translated.replace(/DATE_SUB\(([^,]+),\s*INTERVAL\s*(\d+)\s*DAY\)/gi, (m, dateExpr, days) => {
    return `${dateExpr} - INTERVAL '${days} days'`;
  });
  
  // 4. Convert MySQL DATE_ADD(date, INTERVAL X DAY) to Postgres: date + INTERVAL 'X days'
  translated = translated.replace(/DATE_ADD\(([^,]+),\s*INTERVAL\s*(\d+)\s*DAY\)/gi, (m, dateExpr, days) => {
    return `${dateExpr} + INTERVAL '${days} days'`;
  });
  
  // 5. Convert CURDATE() to CURRENT_DATE
  translated = translated.replace(/CURDATE\(\)/gi, 'CURRENT_DATE');
  
  // 6. Cast date/timestamp columns like sale_date, purchase_date when used with LIKE to text
  translated = translated.replace(/\b([a-zA-Z0-9_]+_date)\s+LIKE\b/gi, '$1::text LIKE');
  
  return translated;
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

// Wrapper for the pg Pool to match mysql2 interface
const pool = {
  async query(sql, params) {
    const pgSql = translateSql(sql);
    const res = params ? await pgPool.query(pgSql, params) : await pgPool.query(pgSql);
    return [res.rows, res.fields];
  },
  async getConnection() {
    const client = await pgPool.connect();
    return new PostgresConnectionWrapper(client);
  },
  async end() {
    await pgPool.end();
  }
};

// Test connection and seed custom functions on startup
(async () => {
  try {
    const client = await pgPool.connect();
    console.log('✅ PostgreSQL Database Connected successfully on port', process.env.DB_PORT || '5432');
    
    // Create custom helper functions to make MySQL queries compatible
    await client.query(`
      CREATE OR REPLACE FUNCTION curdate() RETURNS date AS $$
        SELECT CURRENT_DATE;
      $$ LANGUAGE SQL;
    `);
    
    await client.query(`
      CREATE OR REPLACE FUNCTION date(timestamp) RETURNS date AS $$
        SELECT $1::date;
      $$ LANGUAGE SQL;
    `);

    await client.query(`
      CREATE OR REPLACE FUNCTION date(timestamptz) RETURNS date AS $$
        SELECT $1::date;
      $$ LANGUAGE SQL;
    `);

    client.release();
  } catch (err) {
    console.error('❌ PostgreSQL database connection/initialization failed:', err.message);
  }
})();

/**
 * Helper to add column to database schema dynamically if missing
 */
async function addColumnIfNotExists(table, column, definition) {
  try {
    // In PostgreSQL, table and column names in information_schema are stored in lowercase by default
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
      console.log(`✅ Added column '${column}' to table '${table}'`);
      return true;
    }
    return false;
  } catch (err) {
    console.error(`❌ Error checking/adding column ${column} to ${table}:`, err.message);
    return false;
  }
}

/**
 * Log audit events
 */
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

/**
 * Middleware for route guard/authorization
 */
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
