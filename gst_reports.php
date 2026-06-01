<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

$active_tab = $_GET['tab'] ?? 'sales'; // 'sales' | 'purchases'

// --- 1. SALES GST REPORT (HSN Grouped) ---
$sales_query = "
    SELECT COALESCE(f.hsn_code, '3102') as hsn_code, 
           s.fertilizer_name, 
           SUM(s.quantity) as total_qty,
           SUM(s.total_price) as total_amount
    FROM sales s
    LEFT JOIN fertilizers f ON s.fertilizer_name = f.fertilizer_name
    GROUP BY hsn_code, s.fertilizer_name
    ORDER BY hsn_code ASC";
$sales_res = mysqli_query($conn, $sales_query);
$sales_tax_rows = [];
$sales_totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'grand' => 0];

while($r = mysqli_fetch_assoc($sales_res)) {
    $grand = $r['total_amount'];
    $taxable = $grand / 1.18;
    $cgst = $taxable * 0.09;
    $sgst = $taxable * 0.09;
    
    $sales_tax_rows[] = [
        'hsn' => $r['hsn_code'],
        'particulars' => $r['fertilizer_name'],
        'qty' => $r['total_qty'],
        'taxable' => $taxable,
        'cgst' => $cgst,
        'sgst' => $sgst,
        'grand' => $grand
    ];
    
    $sales_totals['taxable'] += $taxable;
    $sales_totals['cgst'] += $cgst;
    $sales_totals['sgst'] += $sgst;
    $sales_totals['grand'] += $grand;
}

// --- 2. PURCHASES GST REPORT (HSN Grouped) ---
$pur_query = "
    SELECT COALESCE(f.hsn_code, '3102') as hsn_code, 
           p.fertilizer_name, 
           SUM(p.quantity) as total_qty,
           SUM(p.cost * p.quantity) as total_amount
    FROM purchases p
    LEFT JOIN fertilizers f ON p.fertilizer_name = f.fertilizer_name
    GROUP BY hsn_code, p.fertilizer_name
    ORDER BY hsn_code ASC";
$pur_res = mysqli_query($conn, $pur_query);
$pur_tax_rows = [];
$pur_totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'grand' => 0];

while($r = mysqli_fetch_assoc($pur_res)) {
    $grand = $r['total_amount'];
    $taxable = $grand / 1.18;
    $cgst = $taxable * 0.09;
    $sgst = $taxable * 0.09;
    
    $pur_tax_rows[] = [
        'hsn' => $r['hsn_code'],
        'particulars' => $r['fertilizer_name'],
        'qty' => $r['total_qty'],
        'taxable' => $taxable,
        'cgst' => $cgst,
        'sgst' => $sgst,
        'grand' => $grand
    ];
    
    $pur_totals['taxable'] += $taxable;
    $pur_totals['cgst'] += $cgst;
    $pur_totals['sgst'] += $sgst;
    $pur_totals['grand'] += $grand;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GST Tax Reports — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--blue:#3b82f6;--purple:#a855f7;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

.sidebar{width:220px;min-height:100vh;background:var(--bg);border-right:1px solid var(--border);position:fixed;left:0;top:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo h2{font-size:16px;font-weight:700;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;border-left:3px solid transparent;transition:all .2s;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;}

.main{margin-left:220px;flex:1;padding:28px;max-width:1200px;}
.topbar{margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;}
.topbar h1{font-size:22px;font-weight:700;} .topbar h1 span{color:var(--green);}

.tab-nav{display:flex;gap:8px;background:var(--card);border:1px solid var(--border);padding:6px;border-radius:14px;width:fit-content;margin-bottom:24px;}
.tab-btn{padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none;transition:.2s;}
.tab-btn:hover{color:#fff;}
.tab-btn.active{background:var(--green);color:#fff;}

.stats-grid{display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}

.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:14px;font-weight:700;}
.card-header i{color:var(--green);}
.card-body{padding:0;}

.table{width:100%;border-collapse:collapse;}
.table th{padding:12px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:rgba(255,255,255,.02);}
.table td{padding:12px 16px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}

.empty{text-align:center;padding:40px;color:var(--muted);}
</style>
</head>
<body>

<aside class="sidebar">
  <?php $role = $_SESSION['admin_role'] ?? 'Admin'; ?>
  <div class="sidebar-logo"><h2>🌱 AgriBiz</h2><p>Dashboard</p></div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <?php if ($role !== 'Accountant'): ?>
  <a href="admin_billing.php" class="nav-item"><i class="fas fa-file-invoice-dollar" style="color:var(--orange);"></i> Offline Billing</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Manage Orders</a>
  <?php endif; ?>
  <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  <?php if ($role !== 'Billing Staff'): ?>
  <a href="gst_intel.php" class="nav-item"><i class="fas fa-search-dollar"></i> GST Intelligence</a>
  <a href="receipts_payments.php" class="nav-item"><i class="fas fa-money-bill-transfer"></i> Vouchers Entry</a>
  <a href="accounting_books.php" class="nav-item"><i class="fas fa-book"></i> Day/Cash Books</a>
  <a href="gst_reports.php" class="nav-item active"><i class="fas fa-percent"></i> GST Reports</a>
  <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
  <?php endif; ?>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Fertilizers</a>
  <?php if ($role !== 'Billing Staff'): ?>
  <a href="sales.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales</a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-line"></i> Reports</a>
  <a href="master_ledger.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Master Ledger</a>
  <?php endif; ?>
  <div class="sidebar-footer"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <h1>GST <span>Tax Reports</span></h1>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;">Retrieve complete sales/purchase tax sheets categorized by HSN and GST thresholds</p>
    </div>
    <button onclick="window.print()" class="btn btn-blue" style="padding:10px 20px; border:none; border-radius:8px; background:var(--blue); color:#fff; font-weight:700; cursor:pointer;"><i class="fas fa-print"></i> Print Report</button>
  </div>

  <!-- Tabs Navigation -->
  <div class="tab-nav">
    <a href="?tab=sales" class="tab-btn <?php echo $active_tab === 'sales' ? 'active' : ''; ?>"><i class="fas fa-arrow-down-long"></i> GSTR-1 (Sales GST)</a>
    <a href="?tab=purchases" class="tab-btn <?php echo $active_tab === 'purchases' ? 'active' : ''; ?>"><i class="fas fa-arrow-up-long"></i> GSTR-2 (Purchase GST)</a>
  </div>

  <?php if ($active_tab === 'sales'): ?>
    <!-- ==================== GSTR-1 (SALES) ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Taxable Value</div><div class="val">₹<?php echo number_format($sales_totals['taxable'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--purple);"><div class="lbl">Total CGST Collected (9%)</div><div class="val" style="color:var(--purple);">₹<?php echo number_format($sales_totals['cgst'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Total SGST Collected (9%)</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($sales_totals['sgst'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Total Outward Supplies</div><div class="val" style="color:var(--orange);">₹<?php echo number_format($sales_totals['grand'], 2); ?></div></div>
    </div>

    <div class="card">
      <div class="card-header"><h3><i class="fas fa-percentage"></i> Sales GST (GSTR-1) — HSN Summary Sheet</h3></div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>HSN Code</th>
              <th>Particulars (Product Name)</th>
              <th>Total Qty</th>
              <th>Tax Rate (%)</th>
              <th style="text-align:right;">Taxable Value (₹)</th>
              <th style="text-align:right;">CGST Collected (₹)</th>
              <th style="text-align:right;">SGST Collected (₹)</th>
              <th style="text-align:right;">Grand Total (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($sales_tax_rows) > 0): foreach($sales_tax_rows as $row): ?>
            <tr>
              <td style="font-weight:bold; color:var(--blue);"><?php echo $row['hsn']; ?></td>
              <td><strong><?php echo htmlspecialchars($row['particulars']); ?></strong></td>
              <td><span class="badge badge-green"><?php echo $row['qty']; ?> units</span></td>
              <td>18% (9% + 9%)</td>
              <td style="text-align:right; font-weight:600;">₹<?php echo number_format($row['taxable'], 2); ?></td>
              <td style="text-align:right; color:var(--purple);">₹<?php echo number_format($row['cgst'], 2); ?></td>
              <td style="text-align:right; color:var(--blue);">₹<?php echo number_format($row['sgst'], 2); ?></td>
              <td style="text-align:right; font-weight:700; color:var(--green);">₹<?php echo number_format($row['grand'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:rgba(255,255,255,0.03); font-weight:bold;">
              <td colspan="4" style="text-align:right;">Cumulative GSTR-1 Totals:</td>
              <td style="text-align:right;">₹<?php echo number_format($sales_totals['taxable'], 2); ?></td>
              <td style="text-align:right; color:var(--purple);">₹<?php echo number_format($sales_totals['cgst'], 2); ?></td>
              <td style="text-align:right; color:var(--blue);">₹<?php echo number_format($sales_totals['sgst'], 2); ?></td>
              <td style="text-align:right; color:var(--green);">₹<?php echo number_format($sales_totals['grand'], 2); ?></td>
            </tr>
            <?php else: ?>
            <tr><td colspan="8" class="empty">No sales records available for tax reporting.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($active_tab === 'purchases'): ?>
    <!-- ==================== GSTR-2 (PURCHASES) ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Taxable Value</div><div class="val">₹<?php echo number_format($pur_totals['taxable'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--purple);"><div class="lbl">Total CGST Paid (9%)</div><div class="val" style="color:var(--purple);">₹<?php echo number_format($pur_totals['cgst'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Total SGST Paid (9%)</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($pur_totals['sgst'], 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Total Inward Supplies</div><div class="val" style="color:var(--orange);">₹<?php echo number_format($pur_totals['grand'], 2); ?></div></div>
    </div>

    <div class="card">
      <div class="card-header"><h3><i class="fas fa-percentage"></i> Purchases GST (GSTR-2) — HSN Summary Sheet</h3></div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>HSN Code</th>
              <th>Particulars (Product Name)</th>
              <th>Total Qty</th>
              <th>Tax Rate (%)</th>
              <th style="text-align:right;">Taxable Value (₹)</th>
              <th style="text-align:right;">CGST Paid (₹)</th>
              <th style="text-align:right;">SGST Paid (₹)</th>
              <th style="text-align:right;">Grand Total (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($pur_tax_rows) > 0): foreach($pur_tax_rows as $row): ?>
            <tr>
              <td style="font-weight:bold; color:var(--blue);"><?php echo $row['hsn']; ?></td>
              <td><strong><?php echo htmlspecialchars($row['particulars']); ?></strong></td>
              <td><span class="badge badge-purple"><?php echo $row['qty']; ?> units</span></td>
              <td>18% (9% + 9%)</td>
              <td style="text-align:right; font-weight:600;">₹<?php echo number_format($row['taxable'], 2); ?></td>
              <td style="text-align:right; color:var(--purple);">₹<?php echo number_format($row['cgst'], 2); ?></td>
              <td style="text-align:right; color:var(--blue);">₹<?php echo number_format($row['sgst'], 2); ?></td>
              <td style="text-align:right; font-weight:700; color:var(--green);">₹<?php echo number_format($row['grand'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:rgba(255,255,255,0.03); font-weight:bold;">
              <td colspan="4" style="text-align:right;">Cumulative GSTR-2 Totals:</td>
              <td style="text-align:right;">₹<?php echo number_format($pur_totals['taxable'], 2); ?></td>
              <td style="text-align:right; color:var(--purple);">₹<?php echo number_format($pur_totals['cgst'], 2); ?></td>
              <td style="text-align:right; color:var(--blue);">₹<?php echo number_format($pur_totals['sgst'], 2); ?></td>
              <td style="text-align:right; color:var(--green);">₹<?php echo number_format($pur_totals['grand'], 2); ?></td>
            </tr>
            <?php else: ?>
            <tr><td colspan="8" class="empty">No purchases records available for tax reporting.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
