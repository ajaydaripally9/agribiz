<?php
session_start();
if(!isset($_SESSION['admin'])){ header('Location: index.php'); exit(); }
include 'db.php';

$sales_7day = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$sales_prev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND sale_date < DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$sales_pct = $sales_prev > 0 ? round((($sales_7day - $sales_prev) / $sales_prev) * 100, 1) : 0;

$pur_7day = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost*quantity),0) as total FROM purchases WHERE purchase_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$pur_prev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost*quantity),0) as total FROM purchases WHERE purchase_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND purchase_date < DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$pur_pct = $pur_prev > 0 ? round((($pur_7day - $pur_prev) / $pur_prev) * 100, 1) : 0;

$total_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM orders"))['c'];
$new_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM orders WHERE status='Pending'"))['c'];
$low_stock_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM fertilizers WHERE quantity < 10"))['c'];
$low_stock = mysqli_query($conn, "SELECT * FROM fertilizers WHERE quantity < 10 ORDER BY quantity ASC");

$chart_dates = []; $chart_sales = []; $chart_purchases = [];
for($i=6;$i>=0;$i--){
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_dates[] = date('M d', strtotime($date));
    $s = mysqli_prepare($conn,"SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE sale_date=?");
    mysqli_stmt_bind_param($s,"s",$date); mysqli_stmt_execute($s);
    $chart_sales[] = mysqli_fetch_assoc(mysqli_stmt_get_result($s))['t']; mysqli_stmt_close($s);
    $p = mysqli_prepare($conn,"SELECT COALESCE(SUM(cost*quantity),0) as t FROM purchases WHERE purchase_date=?");
    mysqli_stmt_bind_param($p,"s",$date); mysqli_stmt_execute($p);
    $chart_purchases[] = mysqli_fetch_assoc(mysqli_stmt_get_result($p))['t']; mysqli_stmt_close($p);
}

// Stock Forecast (AI)
$forecast_res = mysqli_query($conn, "SELECT fertilizer_name, quantity, (quantity / 5) as days_left FROM fertilizers WHERE quantity < 25 ORDER BY quantity ASC LIMIT 4");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgriBiz Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{
  --bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;
  --green:#22c55e;--green-dark:#16a34a;--purple:#a855f7;--blue:#3b82f6;
  --orange:#f59e0b;--red:#ef4444;--teal:#14b8a6;
  --text:#e6edf3;--text-muted:#8b949e;--border:#30363d;
}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}

/* SIDEBAR */
.sidebar{width:220px;min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo .logo-icon{color:var(--green);font-size:22px;}
.sidebar-logo h2{font-size:16px;font-weight:700;color:var(--text);margin-top:2px;}
.sidebar-logo p{font-size:11px;color:var(--text-muted);}
.sidebar-nav{flex:1;padding:12px 0;overflow-y:auto;}
.nav-section-label{padding:8px 16px 4px;font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent;}
.nav-item:hover{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item.active{background:rgba(34,197,94,.12);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;font-size:14px;}
.sidebar-promo{margin:12px;background:linear-gradient(135deg,#064e3b,#065f46);border-radius:12px;padding:16px;border:1px solid #059669;}
.sidebar-promo h4{font-size:13px;font-weight:700;color:#fff;margin-bottom:4px;}
.sidebar-promo p{font-size:11px;color:#6ee7b7;line-height:1.4;}
.sidebar-footer{padding:16px;border-top:1px solid var(--border);}
.sidebar-footer a{display:flex;align-items:center;gap:8px;color:var(--red);text-decoration:none;font-size:13px;font-weight:500;}

/* MAIN */
.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-height:100vh;}
.topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;}
.topbar-left p{font-size:12px;color:var(--text-muted);}
.topbar-left h1{font-size:20px;font-weight:700;color:var(--text);}
.topbar-left h1 span{color:var(--green);}
.topbar-right{display:flex;align-items:center;gap:16px;}
.notif-btn{position:relative;background:var(--card2);border:1px solid var(--border);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-muted);font-size:14px;}
.notif-badge{position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;font-size:9px;font-weight:700;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.admin-btn{display:flex;align-items:center;gap:8px;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:6px 12px;cursor:pointer;}
.admin-avatar{width:28px;height:28px;background:linear-gradient(135deg,var(--green),var(--teal));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;}
.admin-btn span{font-size:13px;font-weight:500;color:var(--text);}

.content{padding:24px 28px;flex:1;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:transform .2s;}
.stat-card:hover{transform:translateY(-2px);}
.stat-card .label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.stat-card .value{font-size:26px;font-weight:700;margin-bottom:6px;}
.stat-card .change{font-size:12px;display:flex;align-items:center;gap:4px;}
.stat-card .sparkline{position:absolute;bottom:0;left:0;right:0;opacity:.4;}
.stat-card.green .label{color:var(--green);} .stat-card.green .value{color:#fff;}
.stat-card.purple .label{color:var(--purple);} .stat-card.purple .value{color:#fff;}
.stat-card.blue .label{color:var(--blue);} .stat-card.blue .value{color:#fff;}
.stat-card.orange .label{color:var(--orange);} .stat-card.orange .value{color:#fff;}

/* LOW STOCK */
.low-stock-card{background:linear-gradient(135deg,#1a1400,#1c1a00);border:1px solid #78350f;border-radius:14px;padding:20px;margin-bottom:20px;display:flex;align-items:center;gap:20px;}
.low-stock-icon{width:52px;height:52px;background:rgba(245,158,11,.15);border:2px solid var(--orange);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--orange);flex-shrink:0;}
.low-stock-content{flex:1;}
.low-stock-content h3{font-size:14px;font-weight:600;color:var(--orange);margin-bottom:10px;}
.low-stock-items{display:flex;gap:12px;flex-wrap:wrap;}
.low-stock-item{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:8px 14px;}
.low-stock-item .name{font-size:13px;font-weight:600;color:var(--text);}
.low-stock-item .stock{font-size:11px;color:var(--text-muted);}
.view-alerts-btn{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:10px 16px;color:var(--text);text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap;display:flex;align-items:center;gap:6px;}
.view-alerts-btn:hover{border-color:var(--orange);color:var(--orange);}

/* CHART */
.chart-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;}
.chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.chart-header h3{font-size:15px;font-weight:600;color:var(--text);}
.chart-legend{display:flex;gap:16px;}
.legend-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);}
.legend-dot{width:10px;height:10px;border-radius:2px;}

/* QUICK NAV */
.section-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section-title::before{content:'';width:3px;height:18px;background:var(--green);border-radius:2px;}
.quick-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;}
.quick-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;display:flex;align-items:center;gap:12px;text-decoration:none;transition:all .2s;position:relative;overflow:hidden;}
.quick-card:hover{transform:translateY(-2px);border-color:var(--card2);}
.quick-card::after{content:'\f054';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-muted);}
.quick-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.quick-card span{font-size:13px;font-weight:600;color:var(--text);}
.qi-orange{background:rgba(249,115,22,.15);color:#f97316;}
.qi-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.qi-green{background:rgba(34,197,94,.15);color:var(--green);}
.qi-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.qi-teal{background:rgba(20,184,166,.15);color:var(--teal);}
.qi-indigo{background:rgba(99,102,241,.15);color:#818cf8;}
.qi-lime{background:rgba(132,204,22,.15);color:#84cc16;}
.qi-rose{background:rgba(244,63,94,.15);color:#f43f5e;}
.qi-red{background:rgba(239,68,68,.15);color:var(--red);}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <audio id="notifSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:8px;">
      <i class="fas fa-seedling logo-icon"></i>
      <div><h2>AgriBiz</h2><p>Dashboard</p></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i> Dashboard</a>
    <div class="nav-section-label">Operations</div>
    <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Manage Orders <?php if($new_orders>0) echo '<span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:auto;">'.$new_orders.'</span>'; ?></a>
    <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
    <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
    <a href="add_fertilizer.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Fertilizer</a>
    <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> View Fertilizers</a>
    <div class="nav-section-label">Transactions</div>
    <a href="sales.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales History</a>
    <a href="purchases.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchase History</a>
    <div class="nav-section-label">Management</div>
    <a href="admin_billing.php" class="nav-item"><i class="fas fa-file-invoice-dollar" style="color:var(--orange);"></i> Offline Billing</a>
    <a href="reports.php" class="nav-item"><i class="fas fa-chart-line"></i> Reports</a>
  </nav>
  <div class="sidebar-promo">
    <h4>Grow More,<br>Manage Smart</h4>
    <p>Empowering your agri business</p>
    <div style="margin-top:10px;display:flex;gap:4px;"><?php for($i=0;$i<3;$i++) echo '<div style="height:4px;border-radius:2px;background:'.($i==0?'#22c55e':'rgba(255,255,255,.2)').';flex:1;"></div>'; ?></div>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <p>Welcome back,</p>
      <h1>AgriBiz Admin <span>🌱</span></h1>
    </div>
    <div class="topbar-right">
      <!-- Global Search -->
      <div style="position:relative;">
        <input type="text" id="globalSearch" placeholder="🔍 Search orders, customers..." oninput="doSearch(this.value)" style="background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:7px 14px;color:var(--text);font-size:13px;width:220px;outline:none;" onfocus="this.style.borderColor='var(--green)'" onblur="this.style.borderColor='var(--border)'">
        <div id="searchDropdown" style="display:none;position:absolute;top:38px;left:0;right:0;background:var(--card2);border:1px solid var(--border);border-radius:10px;z-index:200;max-height:280px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,0.3);"></div>
      </div>
      <div class="notif-btn"><i class="fas fa-bell"></i><?php if($new_orders>0) echo '<span class="notif-badge">'.$new_orders.'</span>'; ?></div>
      <div class="admin-btn">
        <div class="admin-avatar">AA</div>
        <span>Admin</span>
        <i class="fas fa-chevron-down" style="font-size:10px;color:var(--text-muted);"></i>
      </div>
    </div>
  </div>

  <div class="content">
    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card green">
        <div class="label"><i class="fas fa-arrow-trend-up"></i> Total Sales (7 Days)</div>
        <div class="value">₹<?php echo number_format($sales_7day,0); ?></div>
        <div class="change" style="color:<?php echo $sales_pct>=0?'#22c55e':'#ef4444'; ?>">
          <i class="fas fa-arrow-<?php echo $sales_pct>=0?'up':'down'; ?>"></i> <?php echo abs($sales_pct); ?>% vs last 7 days
        </div>
        <canvas class="sparkline" id="sp1" height="45"></canvas>
      </div>
      <div class="stat-card purple">
        <div class="label"><i class="fas fa-shopping-cart"></i> Total Purchases (7 Days)</div>
        <div class="value">₹<?php echo number_format($pur_7day,0); ?></div>
        <div class="change" style="color:<?php echo $pur_pct>=0?'#a855f7':'#ef4444'; ?>">
          <i class="fas fa-arrow-<?php echo $pur_pct>=0?'up':'down'; ?>"></i> <?php echo abs($pur_pct); ?>% vs last 7 days
        </div>
        <canvas class="sparkline" id="sp2" height="45"></canvas>
      </div>
      <div class="stat-card blue">
        <div class="label"><i class="fas fa-box"></i> Total Orders</div>
        <div class="value"><?php echo $total_orders; ?></div>
        <div class="change" style="color:#3b82f6;"><i class="fas fa-arrow-up"></i> <?php echo $new_orders; ?> New Orders</div>
        <canvas class="sparkline" id="sp3" height="45"></canvas>
      </div>
      <div class="stat-card orange">
        <div class="label"><i class="fas fa-triangle-exclamation"></i> Low Stock Alerts</div>
        <div class="value"><?php echo $low_stock_count; ?></div>
        <div class="change" style="color:var(--orange);"><?php echo $low_stock_count>0?'Needs Attention':'All Good'; ?></div>
        <canvas class="sparkline" id="sp4" height="45"></canvas>
      </div>
    </div>

    <!-- LOW STOCK ALERT -->
    <?php if($low_stock_count > 0): ?>
    <div class="low-stock-card">
      <div class="low-stock-icon"><i class="fas fa-bell"></i></div>
      <div class="low-stock-content">
        <h3>Low Stock Alerts (Below 10 Units)</h3>
        <div class="low-stock-items">
          <?php mysqli_data_seek($low_stock, 0); while($ls = mysqli_fetch_assoc($low_stock)): ?>
          <div class="low-stock-item">
            <div class="name"><?php echo htmlspecialchars($ls['fertilizer_name']); ?></div>
            <div class="stock">Stock: <?php echo $ls['quantity']; ?> units</div>
          </div>
          <?php endwhile; ?>
        </div>
      </div>
      <a href="view_fertilizer.php" class="view-alerts-btn">View All Alerts <i class="fas fa-arrow-right"></i></a>
    </div>
    <?php endif; ?>

    <!-- CHART -->
    <div class="chart-card">
      <div class="chart-header">
        <h3>Financial Overview (Last 7 Days)</h3>
        <div class="chart-legend">
          <div class="legend-item"><div class="legend-dot" style="background:#22c55e;"></div> Sales (₹)</div>
          <div class="legend-item"><div class="legend-dot" style="background:#a855f7;"></div> Purchases (₹)</div>
        </div>
      </div>
      <canvas id="financeChart" height="90"></canvas>
    </div>

    <!-- STOCK FORECAST -->
    <div class="chart-card" style="margin-top:20px;">
      <div class="chart-header">
        <h3><i class="fas fa-chart-line" style="color:var(--orange);margin-right:8px;"></i>AI Stock Forecast</h3>
        <p style="font-size:11px;color:var(--text-muted);">Predicting stockout dates based on demand</p>
      </div>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:16px; margin-top:15px;">
        <?php while($f=mysqli_fetch_assoc($forecast_res)): 
          $days = ceil($f['days_left']);
          $color = $days < 2 ? 'var(--red)' : 'var(--orange)';
          $pct = min(100, ($f['quantity']/50)*100);
        ?>
        <div style="background:var(--card2); padding:16px; border-radius:12px; border:1px solid var(--border);">
          <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:12px;">
            <div style="font-size:14px; font-weight:700;"><?php echo $f['fertilizer_name']; ?></div>
            <div style="background:<?php echo $color; ?>; color:#fff; font-size:10px; font-weight:800; padding:3px 8px; border-radius:20px;"><?php echo $days; ?> Days Left</div>
          </div>
          <div style="height:6px; background:rgba(255,255,255,0.1); border-radius:3px; margin-bottom:8px;">
            <div style="height:100%; width:<?php echo $pct; ?>%; background:<?php echo $color; ?>; border-radius:3px;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted);">
            <span>Stock: <?php echo $f['quantity']; ?></span>
            <span>Critical</span>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
    </div>

    <!-- QUICK NAV -->
    <div class="section-title">Quick Navigation</div>
    <div class="quick-grid">
      <a href="manage_orders.php" class="quick-card"><div class="quick-icon qi-orange"><i class="fas fa-clipboard-list"></i></div><span>Manage Orders</span></a>
      <a href="add_fertilizer.php" class="quick-card"><div class="quick-icon qi-blue"><i class="fas fa-plus-circle"></i></div><span>Add Fertilizer</span></a>
      <a href="view_fertilizer.php" class="quick-card"><div class="quick-icon qi-green"><i class="fas fa-flask"></i></div><span>View Fertilizers</span></a>
      <a href="admin_billing.php" class="quick-card"><div class="quick-icon qi-orange"><i class="fas fa-file-invoice-dollar"></i></div><span>Offline Billing</span></a>
      <a href="customers.php" class="quick-card"><div class="quick-icon qi-teal"><i class="fas fa-users"></i></div><span>Customers</span></a>
      <a href="suppliers.php" class="quick-card"><div class="quick-icon qi-indigo"><i class="fas fa-truck"></i></div><span>Suppliers</span></a>
      <a href="sales.php" class="quick-card"><div class="quick-icon qi-lime"><i class="fas fa-chart-bar"></i></div><span>Sales History</span></a>
      <a href="purchases.php" class="quick-card"><div class="quick-icon qi-rose"><i class="fas fa-shopping-cart"></i></div><span>Purchase History</span></a>
      <a href="reports.php" class="quick-card"><div class="quick-icon qi-blue"><i class="fas fa-chart-line"></i></div><span>Reports</span></a>
      <a href="logout.php" class="quick-card"><div class="quick-icon qi-red"><i class="fas fa-sign-out-alt"></i></div><span>Logout</span></a>
    </div>
  </div>
</div>

<script>
// Sparklines
function sparkline(id, data, color) {
  new Chart(document.getElementById(id), {
    type:'line', data:{labels:data.map((_,i)=>i),datasets:[{data,borderColor:color,borderWidth:2,pointRadius:0,fill:true,backgroundColor:color+'22',tension:.4}]},
    options:{responsive:true,plugins:{legend:{display:false},tooltip:{enabled:false}},scales:{x:{display:false},y:{display:false}}}
  });
}
sparkline('sp1', <?php echo json_encode($chart_sales); ?>, '#22c55e');
sparkline('sp2', <?php echo json_encode($chart_purchases); ?>, '#a855f7');
sparkline('sp3', [5,8,12,7,15,10,<?php echo $total_orders; ?>], '#3b82f6');
sparkline('sp4', [3,5,2,4,3,4,<?php echo $low_stock_count; ?>], '#f59e0b');

// Main Chart
new Chart(document.getElementById('financeChart'), {
  type:'bar',
  data:{
    labels: <?php echo json_encode($chart_dates); ?>,
    datasets:[
      {label:'Sales (₹)',data:<?php echo json_encode($chart_sales); ?>,backgroundColor:'rgba(34,197,94,0.75)',borderRadius:6,borderSkipped:false},
      {label:'Purchases (₹)',data:<?php echo json_encode($chart_purchases); ?>,backgroundColor:'rgba(168,85,247,0.75)',borderRadius:6,borderSkipped:false}
    ]
  },
  options:{
    responsive:true,
    plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>'₹'+ctx.raw.toLocaleString()}}},
    scales:{
      x:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#8b949e'}},
      y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#8b949e',callback:v=>'₹'+v.toLocaleString()},beginAtZero:true}
    }
  }
});

// Auto-refresh pending orders badge every 10s
let lastCount = <?php echo $new_orders; ?>;
setInterval(() => {
  fetch('api_pending_count.php').then(r=>r.json()).then(d=>{
    const badge = document.querySelector('.notif-badge');
    if (d.count > lastCount) {
      document.getElementById('notifSound').play().catch(()=>{});
      // Show dynamic toast
      const t = document.createElement('div');
      t.style = "position:fixed;top:20px;right:20px;background:var(--green);color:white;padding:15px 25px;border-radius:10px;z-index:9999;box-shadow:0 10px 30px rgba(0,0,0,0.3);animation:slideIn 0.5s forwards;font-weight:700;";
      t.innerHTML = "🔔 NEW ORDER RECEIVED! (#" + d.count + ")";
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 5000);
    }
    lastCount = d.count;
    if (d.count > 0) {
      if (badge) badge.textContent = d.count;
      else {
          const btn = document.querySelector('.notif-btn');
          const newBadge = document.createElement('span');
          newBadge.className = 'notif-badge';
          newBadge.textContent = d.count;
          btn.appendChild(newBadge);
      }
      document.title = '(' + d.count + ') AgriBiz Dashboard';
    }
  }).catch(()=>{});
}, 10000);

// Global Search
const searchData = {
  pages: [
    {title:'Dashboard', url:'dashboard.php', icon:'fa-home'},
    {title:'Offline Billing', url:'admin_billing.php', icon:'fa-file-invoice-dollar'},
    {title:'Manage Orders', url:'manage_orders.php', icon:'fa-clipboard-list'},
    {title:'Add Fertilizer', url:'add_fertilizer.php', icon:'fa-plus-circle'},
    {title:'View Fertilizers', url:'view_fertilizer.php', icon:'fa-flask'},
    {title:'Customers', url:'customers.php', icon:'fa-users'},
    {title:'Suppliers', url:'suppliers.php', icon:'fa-truck'},
    {title:'Sales History', url:'sales.php', icon:'fa-chart-bar'},
    {title:'Purchase History', url:'purchases.php', icon:'fa-shopping-cart'},
    {title:'Reports', url:'reports.php', icon:'fa-chart-line'},
    {title:'Logout', url:'logout.php', icon:'fa-sign-out-alt'},
  ]
};
function doSearch(q) {
  const dd = document.getElementById('searchDropdown');
  if (q.length < 2) {
    // Show static pages if query is short or empty
    const staticMatches = searchData.pages.filter(p => p.title.toLowerCase().includes(q.toLowerCase()));
    if (!q.trim() || staticMatches.length === 0) { dd.style.display='none'; return; }
    dd.innerHTML = staticMatches.map(m=>`<a href="${m.url}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#e6edf3;text-decoration:none;font-size:13px;border-bottom:1px solid #30363d;"><i class="fas ${m.icon}" style="color:#22c55e;width:16px;"></i>${m.title}</a>`).join('');
    dd.style.display = 'block';
    return;
  }
  
  // Dynamic search for customers/orders/products
  fetch('api_search.php?q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      const staticMatches = searchData.pages.filter(p => p.title.toLowerCase().includes(q.toLowerCase()));
      const combined = [...staticMatches, ...data];
      
      if (!combined.length) {
        dd.innerHTML = '<div style="padding:14px;color:#8b949e;font-size:13px;">No results found</div>';
      } else {
        dd.innerHTML = combined.map(m=>`
          <a href="${m.url}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#e6edf3;text-decoration:none;font-size:13px;border-bottom:1px solid #30363d;">
            <i class="fas ${m.icon}" style="color:${m.icon==='fa-user'?'#3b82f6':m.icon==='fa-file-invoice'?'#f59e0b':'#22c55e'};width:16px;"></i>
            ${m.title}
          </a>
        `).join('');
      }
      dd.style.display = 'block';
    });
}
document.addEventListener('click', e => {
  if (!e.target.closest('#globalSearch') && !e.target.closest('#searchDropdown')) {
    document.getElementById('searchDropdown').style.display='none';
  }
});
</script>
</body>
</html>