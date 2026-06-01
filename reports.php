<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

// CSV Export
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($type === 'sales') {
        fputcsv($out, ['ID', 'Customer', 'Fertilizer', 'Quantity', 'Total (₹)', 'Date']);
        $res = mysqli_query($conn, "SELECT * FROM sales ORDER BY sale_date DESC");
        while ($r = mysqli_fetch_assoc($res)) fputcsv($out, [$r['id'], $r['customer_name'], $r['fertilizer_name'], $r['quantity'], $r['total_price'], $r['sale_date']]);
    } elseif ($type === 'purchases') {
        fputcsv($out, ['ID', 'Supplier', 'Fertilizer', 'Quantity', 'Cost/Unit (₹)', 'Total (₹)', 'Date']);
        $res = mysqli_query($conn, "SELECT * FROM purchases ORDER BY purchase_date DESC");
        while ($r = mysqli_fetch_assoc($res)) fputcsv($out, [$r['id'], $r['supplier_name'], $r['fertilizer_name'], $r['quantity'], $r['cost'], $r['cost'] * $r['quantity'], $r['purchase_date']]);
    }
    fclose($out);
    exit();
}

// Data Queries
$daily_result   = mysqli_query($conn, "SELECT * FROM sales WHERE sale_date = CURDATE()");
$daily_total    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE sale_date = CURDATE()"))['t'];
$monthly_result = mysqli_query($conn, "SELECT * FROM sales WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())");
$monthly_total  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())"))['t'];
$pur_result     = mysqli_query($conn, "SELECT * FROM purchases ORDER BY purchase_date DESC LIMIT 50");
$pur_total      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost*quantity),0) as t FROM purchases WHERE MONTH(purchase_date)=MONTH(CURDATE()) AND YEAR(purchase_date)=YEAR(CURDATE())"))['t'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--card:#161b22;--green:#22c55e;--blue:#3b82f6;--purple:#a855f7;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
body{background:var(--bg);color:var(--text);min-height:100vh;}
.sidebar{width:220px;min-height:100vh;background:var(--bg);border-right:1px solid var(--border);position:fixed;left:0;top:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo h2{font-size:16px;font-weight:700;color:var(--text);}
.sidebar-logo p{font-size:11px;color:var(--muted);}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;border-left:3px solid transparent;transition:all .2s;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;}
.sidebar-footer{margin-top:auto;padding:16px;border-top:1px solid var(--border);}
.sidebar-footer a{display:flex;align-items:center;gap:8px;color:var(--red);text-decoration:none;font-size:13px;}
.main{margin-left:220px;padding:28px;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;}
.topbar h1{font-size:22px;font-weight:700;}
.topbar h1 span{color:var(--green);}
.topbar-actions{display:flex;gap:10px;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-green{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3);}
.btn-green:hover{background:rgba(34,197,94,.25);}
.btn-blue{background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);}
.btn-blue:hover{background:rgba(59,130,246,.25);}
.btn-purple{background:rgba(168,85,247,.15);color:var(--purple);border:1px solid rgba(168,85,247,.3);}
.btn-purple:hover{background:rgba(168,85,247,.25);}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;}
.stat-card .lbl{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.stat-card .val{font-size:24px;font-weight:700;}
.green-card .lbl{color:var(--green);} .purple-card .lbl{color:var(--purple);} .blue-card .lbl{color:var(--blue);} .orange-card .lbl{color:var(--orange);}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:24px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.section-header h3{font-size:15px;font-weight:600;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:12px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:rgba(255,255,255,.02);}
.table td{padding:12px 16px;font-size:13px;border-top:1px solid var(--border);}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.empty{text-align:center;padding:40px;color:var(--muted);}

@media print {
  .sidebar, .topbar-actions, .section-header .btn, .no-print { display: none !important; }
  .main { margin-left: 0; padding: 16px; }
  body { background: #fff; color: #000; }
  .section, .stat-card { border: 1px solid #ddd; background: #fff; }
  .stat-card .val, .section-header h3 { color: #000 !important; }
  .table td, .table th { color: #333; border-top: 1px solid #eee; }
  .badge-green { background: #d1fae5; color: #059669; }
  .badge-purple { background: #ede9fe; color: #7c3aed; }
}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">
    <h2>🌱 AgriBiz</h2><p>Dashboard</p>
  </div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Manage Orders</a>
  <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  <a href="gst_intel.php" class="nav-item"><i class="fas fa-search-dollar"></i> GST Intelligence</a>
  <a href="receipts_payments.php" class="nav-item"><i class="fas fa-money-bill-transfer"></i> Vouchers Entry</a>
  <a href="accounting_books.php" class="nav-item"><i class="fas fa-book"></i> Day/Cash Books</a>
  <a href="gst_reports.php" class="nav-item"><i class="fas fa-percent"></i> GST Reports</a>
  <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Fertilizers</a>
  <a href="sales.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales History</a>
  <a href="purchases.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchases</a>
  <a href="reports.php" class="nav-item active"><i class="fas fa-chart-line"></i> Reports</a>
  <div class="sidebar-footer">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <h1>Business <span>Reports</span></h1>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;">Financial overview and export tools</p>
    </div>
    <div class="topbar-actions no-print">
      <a href="?export=sales" class="btn btn-green"><i class="fas fa-file-csv"></i> Export Sales CSV</a>
      <a href="?export=purchases" class="btn btn-purple"><i class="fas fa-file-csv"></i> Export Purchases CSV</a>
      <button onclick="window.print()" class="btn btn-blue"><i class="fas fa-print"></i> Print Report</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="stats-grid">
    <div class="stat-card green-card">
      <div class="lbl"><i class="fas fa-arrow-up"></i> Today's Sales</div>
      <div class="val">₹<?php echo number_format($daily_total, 0); ?></div>
    </div>
    <div class="stat-card purple-card">
      <div class="lbl"><i class="fas fa-calendar"></i> Monthly Sales</div>
      <div class="val">₹<?php echo number_format($monthly_total, 0); ?></div>
    </div>
    <div class="stat-card blue-card">
      <div class="lbl"><i class="fas fa-shopping-cart"></i> Monthly Purchases</div>
      <div class="val">₹<?php echo number_format($pur_total, 0); ?></div>
    </div>
    <div class="stat-card orange-card">
      <div class="lbl"><i class="fas fa-chart-pie"></i> Net Profit (Month)</div>
      <div class="val" style="color:<?php echo ($monthly_total - $pur_total) >= 0 ? '#22c55e' : '#ef4444'; ?>">₹<?php echo number_format($monthly_total - $pur_total, 0); ?></div>
    </div>
  </div>

  <!-- Daily Sales -->
  <div class="section">
    <div class="section-header">
      <h3><i class="fas fa-sun" style="color:var(--orange);margin-right:8px;"></i>Today's Sales</h3>
      <span class="badge badge-green"><?php echo mysqli_num_rows($daily_result); ?> records</span>
    </div>
    <table class="table">
      <thead><tr><th>Customer</th><th>Fertilizer</th><th>Qty</th><th>Amount</th><th>Date</th></tr></thead>
      <tbody>
        <?php if(mysqli_num_rows($daily_result) > 0): while($r = mysqli_fetch_assoc($daily_result)): ?>
        <tr>
          <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
          <td><?php echo htmlspecialchars($r['fertilizer_name']); ?></td>
          <td><span class="badge badge-green"><?php echo $r['quantity']; ?> units</span></td>
          <td style="font-weight:700;color:var(--green);">₹<?php echo number_format($r['total_price'], 2); ?></td>
          <td style="color:var(--muted);"><?php echo $r['sale_date']; ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="5" class="empty"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>No sales today</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Monthly Sales -->
  <div class="section">
    <div class="section-header">
      <h3><i class="fas fa-chart-line" style="color:var(--green);margin-right:8px;"></i>Monthly Sales — <?php echo date('F Y'); ?></h3>
      <span class="badge badge-green"><?php echo mysqli_num_rows($monthly_result); ?> records</span>
    </div>
    <table class="table">
      <thead><tr><th>Customer</th><th>Fertilizer</th><th>Qty</th><th>Amount</th><th>Date</th></tr></thead>
      <tbody>
        <?php if(mysqli_num_rows($monthly_result) > 0): while($r = mysqli_fetch_assoc($monthly_result)): ?>
        <tr>
          <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
          <td><?php echo htmlspecialchars($r['fertilizer_name']); ?></td>
          <td><span class="badge badge-green"><?php echo $r['quantity']; ?> units</span></td>
          <td style="font-weight:700;color:var(--green);">₹<?php echo number_format($r['total_price'], 2); ?></td>
          <td style="color:var(--muted);"><?php echo $r['sale_date']; ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="5" class="empty">No sales this month</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Monthly Purchases -->
  <div class="section">
    <div class="section-header">
      <h3><i class="fas fa-shopping-cart" style="color:var(--purple);margin-right:8px;"></i>Monthly Purchases — <?php echo date('F Y'); ?></h3>
      <span class="badge badge-purple"><?php echo mysqli_num_rows($pur_result); ?> records</span>
    </div>
    <table class="table">
      <thead><tr><th>Supplier</th><th>Fertilizer</th><th>Qty</th><th>Unit Cost</th><th>Total</th><th>Date</th></tr></thead>
      <tbody>
        <?php if(mysqli_num_rows($pur_result) > 0): while($r = mysqli_fetch_assoc($pur_result)): ?>
        <tr>
          <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
          <td><?php echo htmlspecialchars($r['fertilizer_name']); ?></td>
          <td><span class="badge badge-purple"><?php echo $r['quantity']; ?> units</span></td>
          <td>₹<?php echo number_format($r['cost'], 2); ?></td>
          <td style="font-weight:700;color:var(--purple);">₹<?php echo number_format($r['cost'] * $r['quantity'], 2); ?></td>
          <td style="color:var(--muted);"><?php echo $r['purchase_date']; ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="empty">No purchases this month</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>