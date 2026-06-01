<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager']);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_name VARCHAR(100), role VARCHAR(50), action TEXT, ip VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$filter_user = trim($_GET['user'] ?? '');
$filter_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_to   = $_GET['to']   ?? date('Y-m-d');

$where = "created_at BETWEEN '$filter_from 00:00:00' AND '$filter_to 23:59:59'";
if ($filter_user) {
    $fu = mysqli_real_escape_string($conn, $filter_user);
    $where .= " AND user_name LIKE '%$fu%'";
}

$logs = mysqli_query($conn, "SELECT * FROM audit_log WHERE $where ORDER BY created_at DESC LIMIT 500");
$total_logs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM audit_log WHERE $where"))['c'];
$unique_users = mysqli_query($conn, "SELECT DISTINCT user_name FROM audit_log ORDER BY user_name");

// Stats
$today_logs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM audit_log WHERE DATE(created_at)=CURDATE()"))['c'];
$week_logs  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM audit_log WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit Logs — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--blue:#2563eb;--orange:#ea580c;--red:#dc2626;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
.sidebar{width:220px;min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;}
.sidebar-logo{padding:18px 16px;border-bottom:1px solid var(--border);}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto;}
.nav-section-label{padding:8px 16px 4px;font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:8px 16px;color:var(--text-muted);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .2s;border-left:3px solid transparent;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:15px;font-size:13px;}
.main{margin-left:220px;flex:1;display:flex;flex-direction:column;}
.topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;}
.content{padding:24px 28px;}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:10px 14px;font-size:12px;border-top:1px solid var(--border);}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-purple{background:rgba(168,85,247,.15);color:#a855f7;}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;}
.filter-bar input,.filter-bar select{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:12px;outline:none;}
.action-text{max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Administration</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-shield-halved" style="color:var(--orange);margin-right:6px;"></i>Audit Logs</h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Total Entries (Period)</div><div class="val" style="color:var(--blue);"><?php echo $total_logs; ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Today's Actions</div><div class="val" style="color:var(--green);"><?php echo $today_logs; ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Last 7 Days</div><div class="val" style="color:var(--orange);"><?php echo $week_logs; ?></div></div>
    </div>

    <form method="GET" class="filter-bar">
      <input type="date" name="from" value="<?php echo $filter_from; ?>">
      <input type="date" name="to" value="<?php echo $filter_to; ?>">
      <select name="user">
        <option value="">All Users</option>
        <?php while($u=mysqli_fetch_assoc($unique_users)): ?>
        <option value="<?php echo htmlspecialchars($u['user_name']); ?>" <?php if($filter_user===$u['user_name']) echo 'selected'; ?>><?php echo htmlspecialchars($u['user_name']); ?></option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="btn" style="background:var(--orange);color:#fff;"><i class="fas fa-filter"></i> Filter</button>
      <a href="audit_log.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
    </form>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-list" style="color:var(--orange);"></i> Audit Trail</h3>
        <span style="font-size:12px;color:var(--text-muted);">Showing <?php echo $total_logs; ?> entries (latest 500)</span>
      </div>
      <table class="table">
        <thead><tr><th>Date & Time</th><th>User</th><th>Role</th><th>Action</th><th>IP Address</th></tr></thead>
        <tbody>
        <?php if ($logs && mysqli_num_rows($logs) > 0):
            while ($log = mysqli_fetch_assoc($logs)):
              $role_class = match($log['role'] ?? '') {
                'Admin'         => 'badge-red',
                'Manager'       => 'badge-purple',
                'Billing Staff' => 'badge-blue',
                'Accountant'    => 'badge-green',
                default         => 'badge-orange'
              };
        ?>
        <tr>
          <td style="color:var(--text-muted);white-space:nowrap;"><?php echo date('d-M-Y H:i:s',strtotime($log['created_at'])); ?></td>
          <td><strong><?php echo htmlspecialchars($log['user_name'] ?? '—'); ?></strong></td>
          <td><?php if ($log['role']): ?><span class="badge <?php echo $role_class; ?>"><?php echo $log['role']; ?></span><?php endif; ?></td>
          <td class="action-text" title="<?php echo htmlspecialchars($log['action']); ?>"><?php echo htmlspecialchars($log['action']); ?></td>
          <td style="color:var(--text-muted);font-size:11px;"><?php echo htmlspecialchars($log['ip'] ?? '—'); ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);"><i class="fas fa-shield-halved" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>No audit logs found for the selected period.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
