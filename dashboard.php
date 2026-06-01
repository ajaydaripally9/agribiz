<?php
session_start();
if(!isset($_SESSION['admin'])){ header('Location: index.php'); exit(); }
include 'db.php';

// ── Auto-migrate: add settings columns if missing ──────────────────────────
add_column_if_not_exists($conn, 'admin', 'low_stock_threshold', 'INT DEFAULT 10');
add_column_if_not_exists($conn, 'admin', 'default_gst_rate', 'DECIMAL(5,2) DEFAULT 18.00');
add_column_if_not_exists($conn, 'admin', 'points_multiplier', 'INT DEFAULT 1');
add_column_if_not_exists($conn, 'admin', 'shop_name', 'VARCHAR(100) DEFAULT \'AgriBiz Pro\'');

// ── Load admin settings ────────────────────────────────────────────────────
$admin_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM admin LIMIT 1"));
$low_thr   = intval($admin_row['low_stock_threshold'] ?? 10);
$gst_rate  = floatval($admin_row['default_gst_rate']  ?? 18.00);
$pts_mult  = intval($admin_row['points_multiplier']   ?? 1);
$shop_name = htmlspecialchars($admin_row['shop_name'] ?? 'AgriBiz Pro');
$admin_username = htmlspecialchars($admin_row['username'] ?? 'admin');

// ── Handle Settings POST ───────────────────────────────────────────────────
$settings_msg  = '';
$settings_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $new_threshold = max(1, intval($_POST['low_stock_threshold'] ?? 10));
    $new_gst       = max(0, floatval($_POST['default_gst_rate'] ?? 18));
    $new_pts       = max(1, intval($_POST['points_multiplier'] ?? 1));
    $new_shop      = mysqli_real_escape_string($conn, trim($_POST['shop_name'] ?? 'AgriBiz Pro'));
    $new_user      = mysqli_real_escape_string($conn, trim($_POST['admin_username'] ?? $admin_username));
    $new_pass      = trim($_POST['admin_password'] ?? '');

    if ($new_pass !== '') {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE admin SET username='$new_user', password='$hashed', low_stock_threshold=$new_threshold, default_gst_rate=$new_gst, points_multiplier=$new_pts, shop_name='$new_shop' LIMIT 1");
    } else {
        mysqli_query($conn, "UPDATE admin SET username='$new_user', low_stock_threshold=$new_threshold, default_gst_rate=$new_gst, points_multiplier=$new_pts, shop_name='$new_shop' LIMIT 1");
    }

    // Refresh session & variables
    $_SESSION['admin_username'] = $new_user;
    $low_thr   = $new_threshold;
    $gst_rate  = $new_gst;
    $pts_mult  = $new_pts;
    $shop_name = htmlspecialchars($new_shop);
    $admin_username = htmlspecialchars($new_user);
    $settings_msg  = '✅ Settings saved successfully!';
}

// ── Business Metrics ───────────────────────────────────────────────────────
$sales_7day = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$sales_prev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND sale_date < DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$sales_pct  = $sales_prev > 0 ? round((($sales_7day - $sales_prev) / $sales_prev) * 100, 1) : 0;

$pur_7day = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost*quantity),0) as total FROM purchases WHERE purchase_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$pur_prev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost*quantity),0) as total FROM purchases WHERE purchase_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND purchase_date < DATE_SUB(CURDATE(),INTERVAL 7 DAY)"))['total'];
$pur_pct  = $pur_prev > 0 ? round((($pur_7day - $pur_prev) / $pur_prev) * 100, 1) : 0;

$total_orders   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM orders"))['c'];
$new_orders     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM orders WHERE status='Pending'"))['c'];
$low_stock_count= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM fertilizers WHERE quantity < $low_thr"))['c'];
$low_stock      = mysqli_query($conn, "SELECT * FROM fertilizers WHERE quantity < $low_thr ORDER BY quantity ASC");

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

$forecast_res = mysqli_query($conn, "SELECT fertilizer_name, quantity, (quantity / 5) as days_left FROM fertilizers WHERE quantity < 25 ORDER BY quantity ASC LIMIT 4");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgriBiz Dashboard</title>
<script>
  document.documentElement.setAttribute('data-theme', localStorage.getItem('admin-theme') || 'dark');
</script>
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
[data-theme="light"]{
  --bg:#f8fafc;--sidebar:#ffffff;--card:#ffffff;--card2:#f1f5f9;
  --green:#16a34a;--green-dark:#15803d;--purple:#7c3aed;--blue:#2563eb;
  --orange:#ea580c;--red:#dc2626;--teal:#0d9488;
  --text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;
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

/* ADMIN DROPDOWN */
.admin-wrap{position:relative;user-select:none;}
.admin-btn{display:flex;align-items:center;gap:9px;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:7px 13px;cursor:pointer;transition:all .2s;}
.admin-btn:hover,.admin-btn.active{border-color:var(--green);background:rgba(34,197,94,.08);}
.admin-avatar{width:32px;height:32px;background:linear-gradient(135deg,#22c55e,#14b8a6);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;box-shadow:0 0 0 2px rgba(34,197,94,.3);flex-shrink:0;}
.admin-btn .admin-name{font-size:13px;font-weight:600;color:var(--text);}
.admin-chevron{font-size:11px;color:var(--text-muted);transition:transform .25s ease;margin-left:2px;}
.admin-btn.active .admin-chevron{transform:rotate(180deg);color:var(--green);}
/* Dropdown panel */
.admin-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;min-width:210px;background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 40px rgba(0,0,0,.45);z-index:9999;overflow:hidden;}
.admin-dropdown.open{display:block;animation:ddFadeIn .18s cubic-bezier(.21,1.02,.73,1) forwards;}
@keyframes ddFadeIn{from{opacity:0;transform:translateY(-8px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}
/* Dropdown header */
.dd-profile{padding:14px 16px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;pointer-events:none;}
.dd-avatar-lg{width:38px;height:38px;background:linear-gradient(135deg,#22c55e,#14b8a6);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;box-shadow:0 0 0 3px rgba(34,197,94,.25);flex-shrink:0;}
.dd-info .dd-name{font-size:13px;font-weight:700;color:var(--text);}
.dd-info .dd-role{font-size:11px;color:var(--green);font-weight:600;margin-top:1px;}
/* Items */
.admin-dropdown-item{display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;font-weight:500;color:var(--text);cursor:pointer;text-decoration:none;transition:.15s;border-bottom:1px solid var(--border);}
.admin-dropdown-item:last-child{border-bottom:none;}
.admin-dropdown-item:hover{background:rgba(34,197,94,.08);color:var(--green);padding-left:20px;}
.admin-dropdown-item i{width:16px;font-size:13px;color:var(--text-muted);transition:.15s;}
.admin-dropdown-item:hover i{color:var(--green);}
.admin-dropdown-item.danger{color:var(--red);}
.admin-dropdown-item.danger i{color:var(--red);}
.admin-dropdown-item.danger:hover{background:rgba(239,68,68,.08);color:var(--red);padding-left:20px;}
.admin-dropdown-item.danger:hover i{color:var(--red);}

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
[data-theme="light"] .low-stock-card{background:linear-gradient(135deg,#fffbeb,#fef3c7);border-color:#f59e0b;}
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

/* ── SETTINGS MODAL ─────────────────────────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:400;backdrop-filter:blur(4px);align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:20px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.5);animation:modalIn .25s cubic-bezier(.34,1.56,.64,1);}
@keyframes modalIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.modal-head{padding:22px 26px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.modal-head h2{font-size:17px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:10px;}
.modal-head h2 i{color:var(--green);}
.modal-close{background:none;border:none;font-size:18px;color:var(--text-muted);cursor:pointer;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:.2s;}
.modal-close:hover{background:var(--card2);color:var(--text);}
.modal-body{padding:24px 26px;}
.modal-body .msg-banner{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green);}
.settings-section{margin-bottom:22px;}
.settings-section-title{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.settings-section-title::before{content:'';display:block;width:3px;height:14px;background:var(--green);border-radius:2px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;}
.form-group input{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:10px 14px;color:var(--text);font-size:13px;outline:none;transition:.2s;}
.form-group input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,.12);}
.form-group input::placeholder{color:var(--text-muted);}
.form-group .hint{font-size:11px;color:var(--text-muted);margin-top:4px;}
.modal-footer{padding:18px 26px;border-top:1px solid var(--border);display:flex;gap:12px;justify-content:flex-end;}
.btn-save{background:var(--green);color:#fff;border:none;border-radius:10px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:8px;}
.btn-save:hover{background:var(--green-dark);box-shadow:0 4px 14px rgba(34,197,94,.3);}
.btn-cancel{background:var(--card2);color:var(--text-muted);border:1px solid var(--border);border-radius:10px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;transition:.2s;}
.btn-cancel:hover{border-color:var(--red);color:var(--red);}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <audio id="notifSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:8px;">
      <i class="fas fa-seedling logo-icon"></i>
      <div><h2><?php echo $shop_name; ?></h2><p>Dashboard</p></div>
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
      <h1><?php echo $shop_name; ?> <span>🌱</span></h1>
    </div>
    <div class="topbar-right">
      <!-- Global Search -->
      <div style="position:relative;">
        <input type="text" id="globalSearch" placeholder="🔍 Search orders, customers..." oninput="doSearch(this.value)" style="background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:7px 14px;color:var(--text);font-size:13px;width:220px;outline:none;" onfocus="this.style.borderColor='var(--green)'" onblur="this.style.borderColor='var(--border)'">
        <div id="searchDropdown" style="display:none;position:absolute;top:38px;left:0;right:0;background:var(--card2);border:1px solid var(--border);border-radius:10px;z-index:200;max-height:280px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,0.3);"></div>
      </div>
      <!-- Theme Switcher -->
      <button class="notif-btn" id="themeToggleBtn" onclick="toggleTheme()" title="Toggle Theme" style="border:none;outline:none;background:var(--card2);">
        <i class="fas fa-sun"></i>
      </button>
      <!-- Notification Bell -->
      <div class="notif-btn"><i class="fas fa-bell"></i><?php if($new_orders>0) echo '<span class="notif-badge">'.$new_orders.'</span>'; ?></div>
      <!-- Admin Dropdown -->
      <div class="admin-wrap" id="adminWrap">
        <div class="admin-btn" id="adminBtn" onclick="toggleAdminMenu()">
          <div class="admin-avatar"><?php echo strtoupper(substr($admin_username,0,2)); ?></div>
          <span class="admin-name"><?php echo $admin_username; ?></span>
          <i class="fas fa-chevron-down admin-chevron" id="adminChevron"></i>
        </div>
        <div class="admin-dropdown" id="adminDropdown">
          <!-- Profile header -->
          <div class="dd-profile">
            <div class="dd-avatar-lg"><?php echo strtoupper(substr($admin_username,0,2)); ?></div>
            <div class="dd-info">
              <div class="dd-name"><?php echo $admin_username; ?></div>
              <div class="dd-role">⚡ Super Admin</div>
            </div>
          </div>
          <!-- Items -->
          <div class="admin-dropdown-item" onclick="openSettings()">
            <i class="fas fa-cog"></i> General Settings
          </div>
          <div class="admin-dropdown-item" onclick="openSettings();document.getElementById('adminDropdown').classList.remove('open');document.getElementById('adminBtn').classList.remove('active');">
            <i class="fas fa-palette"></i> Theme & Display
          </div>
          <a href="logout.php" class="admin-dropdown-item danger">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
    <!-- Settings success banner -->
    <?php if($settings_msg): ?>
    <div style="padding:12px 18px;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green);font-size:13px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-check-circle"></i> <?php echo $settings_msg; ?>
    </div>
    <?php endif; ?>

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
        <h3>Low Stock Alerts (Below <?php echo $low_thr; ?> Units)</h3>
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

<!-- ══ SETTINGS MODAL ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="settingsOverlay">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-cog"></i> ERP Configuration</h2>
      <button class="modal-close" onclick="closeSettings()"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?php if($settings_msg): ?>
        <div class="msg-banner"><i class="fas fa-check-circle"></i> <?php echo $settings_msg; ?></div>
        <?php endif; ?>

        <!-- Shop Info -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fas fa-store"></i> Shop Information</div>
          <div class="form-group">
            <label>Shop / Brand Name</label>
            <input type="text" name="shop_name" value="<?php echo $shop_name; ?>" placeholder="e.g. AgriBiz Pro">
          </div>
        </div>

        <!-- Admin Credentials -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fas fa-shield-alt"></i> Admin Credentials</div>
          <div class="form-row">
            <div class="form-group">
              <label>Username</label>
              <input type="text" name="admin_username" value="<?php echo $admin_username; ?>" placeholder="admin">
            </div>
            <div class="form-group">
              <label>New Password</label>
              <input type="password" name="admin_password" placeholder="Leave blank to keep current">
              <div class="hint">Leave blank to keep the existing password.</div>
            </div>
          </div>
        </div>

        <!-- ERP Settings -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fas fa-sliders"></i> ERP Business Rules</div>
          <div class="form-row">
            <div class="form-group">
              <label>Low Stock Alert Threshold (units)</label>
              <input type="number" name="low_stock_threshold" value="<?php echo $low_thr; ?>" min="1" max="1000">
              <div class="hint">Show alert when stock is below this level.</div>
            </div>
            <div class="form-group">
              <label>Default GST Rate (%)</label>
              <input type="number" name="default_gst_rate" value="<?php echo $gst_rate; ?>" min="0" max="100" step="0.01">
              <div class="hint">Applied to offline billing invoices.</div>
            </div>
          </div>
          <div class="form-group" style="max-width:50%;">
            <label>Loyalty Coins per ₹100 Spent</label>
            <input type="number" name="points_multiplier" value="<?php echo $pts_mult; ?>" min="1" max="100">
            <div class="hint">1 coin = ₹1 reward value for customers.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" name="save_settings" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Sparklines ───────────────────────────────────────────────────────────
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

// ── Main Finance Chart ───────────────────────────────────────────────────
const _isLight = () => localStorage.getItem('admin-theme') === 'light';
window.financeChartInstance = new Chart(document.getElementById('financeChart'), {
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
      x:{grid:{color:_isLight()?'rgba(0,0,0,.05)':'rgba(255,255,255,.05)'},ticks:{color:_isLight()?'#64748b':'#8b949e'}},
      y:{grid:{color:_isLight()?'rgba(0,0,0,.05)':'rgba(255,255,255,.05)'},ticks:{color:_isLight()?'#64748b':'#8b949e',callback:v=>'₹'+v.toLocaleString()},beginAtZero:true}
    }
  }
});

// ── Auto-refresh pending orders badge ────────────────────────────────────
let lastCount = <?php echo $new_orders; ?>;
setInterval(() => {
  fetch('api_pending_count.php').then(r=>r.json()).then(d=>{
    const badge = document.querySelector('.notif-badge');
    if (d.count > lastCount) {
      document.getElementById('notifSound').play().catch(()=>{});
      const t = document.createElement('div');
      t.style = "position:fixed;top:20px;right:20px;background:var(--green);color:white;padding:15px 25px;border-radius:10px;z-index:9999;box-shadow:0 10px 30px rgba(0,0,0,0.3);font-weight:700;";
      t.innerHTML = "🔔 NEW ORDER RECEIVED! (#" + d.count + ")";
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 5000);
    }
    lastCount = d.count;
    if (d.count > 0) {
      if (badge) badge.textContent = d.count;
      else {
        const btn = document.querySelector('.notif-btn');
        const nb = document.createElement('span');
        nb.className = 'notif-badge'; nb.textContent = d.count;
        btn.appendChild(nb);
      }
      document.title = '(' + d.count + ') AgriBiz Dashboard';
    }
  }).catch(()=>{});
}, 10000);

// ── Global Search ────────────────────────────────────────────────────────
const searchData = {
  pages:[
    {title:'Dashboard',url:'dashboard.php',icon:'fa-home'},
    {title:'Offline Billing',url:'admin_billing.php',icon:'fa-file-invoice-dollar'},
    {title:'Manage Orders',url:'manage_orders.php',icon:'fa-clipboard-list'},
    {title:'Add Fertilizer',url:'add_fertilizer.php',icon:'fa-plus-circle'},
    {title:'View Fertilizers',url:'view_fertilizer.php',icon:'fa-flask'},
    {title:'Customers',url:'customers.php',icon:'fa-users'},
    {title:'Suppliers',url:'suppliers.php',icon:'fa-truck'},
    {title:'Sales History',url:'sales.php',icon:'fa-chart-bar'},
    {title:'Purchase History',url:'purchases.php',icon:'fa-shopping-cart'},
    {title:'Reports',url:'reports.php',icon:'fa-chart-line'},
    {title:'Settings',url:'dashboard.php',icon:'fa-cog'},
    {title:'Logout',url:'logout.php',icon:'fa-sign-out-alt'},
  ]
};
function doSearch(q) {
  const dd = document.getElementById('searchDropdown');
  if (q.length < 2) {
    const m = searchData.pages.filter(p=>p.title.toLowerCase().includes(q.toLowerCase()));
    if (!q.trim() || !m.length) { dd.style.display='none'; return; }
    dd.innerHTML = m.map(m=>`<a href="${m.url}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:13px;border-bottom:1px solid var(--border);"><i class="fas ${m.icon}" style="color:var(--green);width:16px;"></i>${m.title}</a>`).join('');
    dd.style.display='block'; return;
  }
  fetch('api_search.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(data=>{
    const sm = searchData.pages.filter(p=>p.title.toLowerCase().includes(q.toLowerCase()));
    const all = [...sm,...data];
    dd.innerHTML = all.length
      ? all.map(m=>`<a href="${m.url}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:13px;border-bottom:1px solid var(--border);"><i class="fas ${m.icon}" style="color:${m.icon==='fa-user'?'var(--blue)':m.icon==='fa-file-invoice'?'var(--orange)':'var(--green)'};width:16px;"></i>${m.title}</a>`).join('')
      : '<div style="padding:14px;color:var(--text-muted);font-size:13px;">No results found</div>';
    dd.style.display='block';
  });
}

// ── Theme toggle ─────────────────────────────────────────────────────────
function toggleTheme() {
  const cur = document.documentElement.getAttribute('data-theme') || 'dark';
  const next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('admin-theme', next);
  document.getElementById('themeToggleBtn').querySelector('i').className = next === 'light' ? 'fas fa-moon' : 'fas fa-sun';
  if (window.financeChartInstance) {
    const l = next === 'light';
    const gc = l ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.05)';
    const tc = l ? '#64748b' : '#8b949e';
    window.financeChartInstance.options.scales.x.grid.color = gc;
    window.financeChartInstance.options.scales.x.ticks.color = tc;
    window.financeChartInstance.options.scales.y.grid.color = gc;
    window.financeChartInstance.options.scales.y.ticks.color = tc;
    window.financeChartInstance.update();
  }
}

// ── Admin dropdown ───────────────────────────────────────────────────────
function toggleAdminMenu() {
  const dd  = document.getElementById('adminDropdown');
  const btn = document.getElementById('adminBtn');
  const isOpen = dd.classList.contains('open');
  // Close all first
  dd.classList.remove('open');
  btn.classList.remove('active');
  // Toggle
  if (!isOpen) {
    dd.classList.add('open');
    btn.classList.add('active');
  }
}

// ── Settings modal ───────────────────────────────────────────────────────
function openSettings() {
  document.getElementById('adminDropdown').classList.remove('open');
  document.getElementById('settingsOverlay').classList.add('open');
}
function closeSettings() {
  document.getElementById('settingsOverlay').classList.remove('open');
}

// ── Close dropdown & modal on outside click ──────────────────────────────
document.addEventListener('click', e => {
  if (!e.target.closest('#adminWrap')) {
    document.getElementById('adminDropdown').classList.remove('open');
    document.getElementById('adminBtn').classList.remove('active');
  }
  if (!e.target.closest('#globalSearch') && !e.target.closest('#searchDropdown')) {
    document.getElementById('searchDropdown').style.display = 'none';
  }
  if (e.target === document.getElementById('settingsOverlay')) closeSettings();
});

// ── Init theme icon on load ──────────────────────────────────────────────
(function() {
  const saved = localStorage.getItem('admin-theme') || 'dark';
  document.getElementById('themeToggleBtn').querySelector('i').className = saved === 'light' ? 'fas fa-moon' : 'fas fa-sun';
  <?php if($settings_msg): ?>
  openSettings();
  <?php endif; ?>
})();
</script>
</body>
</html>