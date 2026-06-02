<?php
// erp_migrate.php — Run all DB schema migrations (idempotent, safe to run multiple times)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (php_sapi_name() !== 'cli' && !isset($_SESSION['admin']) && ($_GET['secret'] ?? '') !== 'migrate123') {
    header('Location: index.php');
    exit();
}
include 'db.php';

$migrations = [
    // Admin settings
    "ALTER TABLE admin ADD COLUMN IF NOT EXISTS low_stock_threshold INT DEFAULT 10",
    "ALTER TABLE admin ADD COLUMN IF NOT EXISTS default_gst_rate DECIMAL(5,2) DEFAULT 18.00",
    "ALTER TABLE admin ADD COLUMN IF NOT EXISTS points_multiplier INT DEFAULT 1",
    "ALTER TABLE admin ADD COLUMN IF NOT EXISTS shop_name VARCHAR(100) DEFAULT 'AgriBiz Pro'",
    // Sales table
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS bill_type VARCHAR(20) DEFAULT 'Cash'",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS gst_rate DECIMAL(5,2) DEFAULT 18.00",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS notes TEXT",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS is_return TINYINT DEFAULT 0",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS return_ref VARCHAR(50) DEFAULT ''",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS batch_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS mfg_date DATE NULL",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS expiry_date DATE NULL",
    // Purchases table
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS supplier_id INT DEFAULT 0",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS gst_rate DECIMAL(5,2) DEFAULT 18.00",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS bill_type VARCHAR(20) DEFAULT 'Cash'",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS notes TEXT",
    "ALTER TABLE purchases ADD COLUMN IF NOT EXISTS is_return TINYINT DEFAULT 0",
    // Fertilizers (Products)
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS hsn_code VARCHAR(20) DEFAULT ''",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT ''",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS reorder_level INT DEFAULT 10",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS purchase_price DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS gst_percent DECIMAL(5,2) DEFAULT 18.00",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS batch_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS mfg_date DATE NULL",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS expiry_date DATE NULL",
    "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) DEFAULT ''",
    // Customers
    "ALTER TABLE customers ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE customers ADD COLUMN IF NOT EXISTS due_date DATE NULL",
    "ALTER TABLE customers ADD COLUMN IF NOT EXISTS points INT DEFAULT 0",
    "ALTER TABLE customers ADD COLUMN IF NOT EXISTS gstin VARCHAR(30) DEFAULT ''",
    // Suppliers  
    "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT ''",
    "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS gstin VARCHAR(30) DEFAULT ''",
    // Orders
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS bill_type VARCHAR(20) DEFAULT 'Cash'",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name VARCHAR(100) DEFAULT ''",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS fertilizer_name VARCHAR(100) DEFAULT ''",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS batch_no VARCHAR(50) DEFAULT ''",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS mfg_date DATE NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS expiry_date DATE NULL",
    // Users table
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('Admin','Manager','Billing Staff','Accountant') DEFAULT 'Billing Staff',
        full_name VARCHAR(100) DEFAULT '',
        mobile VARCHAR(15) DEFAULT '',
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Audit log
    "CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_name VARCHAR(100),
        role VARCHAR(50),
        action TEXT,
        ip VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Stock adjustments log
    "CREATE TABLE IF NOT EXISTS stock_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fertilizer_id INT,
        fertilizer_name VARCHAR(100),
        adjustment_type ENUM('Add','Remove','Correction') DEFAULT 'Add',
        qty_before INT,
        qty_change INT,
        qty_after INT,
        reason TEXT,
        adjusted_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Vouchers table (if not exists)
    "CREATE TABLE IF NOT EXISTS vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50),
        voucher_type ENUM('Receipt','Payment','Journal','Contra') DEFAULT 'Receipt',
        entity_type ENUM('Customer','Supplier','Other') DEFAULT 'Customer',
        entity_id INT DEFAULT 0,
        amount DECIMAL(10,2) DEFAULT 0,
        payment_method VARCHAR(20) DEFAULT 'Cash',
        narration TEXT,
        date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
];

$results = [];
foreach ($migrations as $sql) {
    if (preg_match('/^\s*ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(\w+)\s+(.+)$/i', $sql, $matches)) {
        $table = $matches[1];
        $column = $matches[2];
        $definition = $matches[3];
        $res = add_column_if_not_exists($conn, $table, $column, $definition);
    } else {
        $res = mysqli_query($conn, $sql);
    }
    $results[] = [
        'sql' => substr($sql, 0, 80) . (strlen($sql) > 80 ? '...' : ''),
        'ok'  => $res ? true : false,
        'err' => $res ? '' : mysqli_error($conn),
    ];
}

?>
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
  <?php
  $ok_count = count(array_filter($results, fn($r) => $r['ok']));
  $fail_count = count($results) - $ok_count;
  ?>
  <div class="summary">
    <strong style="color:var(--green);">✅ <?php echo $ok_count; ?> migrations passed</strong>
    <?php if ($fail_count > 0): ?> &nbsp;|&nbsp; <strong style="color:var(--red);">❌ <?php echo $fail_count; ?> failed</strong><?php endif; ?>
  </div>
  <?php foreach ($results as $r): ?>
  <div class="row">
    <code style="color:var(--muted);flex:1;"><?php echo htmlspecialchars($r['sql']); ?></code>
    <span class="<?php echo $r['ok'] ? 'ok' : 'fail'; ?>"><?php echo $r['ok'] ? '✅' : '❌'; ?></span>
  </div>
  <?php if (!$r['ok'] && $r['err']): ?><div class="err-msg">&nbsp;&nbsp;Error: <?php echo htmlspecialchars($r['err']); ?></div><?php endif; ?>
  <?php endforeach; ?>
  <a href="dashboard.php" class="btn"><i class="fas fa-home"></i> Back to Dashboard</a>
</div>
</body></html>
