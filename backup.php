<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin']);

$msg = ''; $msg_type = 'success';

// ─── Handle SQL Backup Download ─────────────────────────────────────────────
if (isset($_POST['download_sql'])) {
    $tables = [];
    $res = mysqli_query($conn, "SHOW TABLES");
    while ($r = mysqli_fetch_array($res)) $tables[] = $r[0];

    $sql = "-- AgriBiz ERP Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Server: " . (getenv('DB_HOST') ?: '127.0.0.1') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Table structure
        $sql .= "-- Table: `{$table}`\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $cr = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `{$table}`"));
        $sql .= $cr[1] . ";\n\n";

        // Table data
        $data = mysqli_query($conn, "SELECT * FROM `{$table}`");
        if ($data && mysqli_num_rows($data) > 0) {
            $sql .= "INSERT INTO `{$table}` VALUES\n";
            $rows = [];
            while ($row = mysqli_fetch_row($data)) {
                $vals = array_map(function($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
                }, $row);
                $rows[] = '(' . implode(',', $vals) . ')';
            }
            $sql .= implode(",\n", $rows) . ";\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'agribiz_backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Content-Length: ' . strlen($sql));
    echo $sql;

    // Log
    $u = $_SESSION['admin_username'] ?? 'admin';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    mysqli_query($conn, "INSERT INTO audit_log (user_name,role,action,ip) VALUES ('$u','Admin','Downloaded SQL backup: $filename','$ip')");
    exit;
}

// ─── Statistics for display ──────────────────────────────────────────────────
$tables = []; $total_rows = 0; $table_data = [];
$res = mysqli_query($conn, "SHOW TABLES");
while ($r = mysqli_fetch_array($res)) {
    $t = $r[0];
    $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM `{$t}`"))['c'];
    $total_rows += $cnt;
    $table_data[] = ['name' => $t, 'rows' => $cnt];
}
sort($table_data);

$dbsize = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT ROUND(SUM(data_length + index_length) / 1024, 2) AS size_kb 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE()"))['size_kb'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backup & Restore — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--green-dark:#15803d;--blue:#2563eb;--orange:#ea580c;--red:#dc2626;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
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
.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.card-body{padding:22px;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;width:100%;justify-content:center;margin-bottom:10px;}.btn-green:hover{background:var(--green-dark);}
.btn-blue{background:var(--blue);color:#fff;width:100%;justify-content:center;margin-bottom:10px;}
.btn-orange{background:var(--orange);color:#fff;width:100%;justify-content:center;margin-bottom:10px;}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.table{width:100%;border-collapse:collapse;}
.table th{padding:9px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);}
.table td{padding:9px 14px;font-size:12px;border-top:1px solid var(--border);}
.warn-box{background:linear-gradient(135deg,rgba(239,68,68,.08),rgba(245,158,11,.06));border:1px solid rgba(239,68,68,.25);border-radius:12px;padding:16px;margin-bottom:16px;}
.warn-box p{font-size:12px;color:var(--text-muted);line-height:1.5;}
.warn-box h4{font-size:13px;font-weight:700;color:var(--orange);margin-bottom:6px;}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 13px;color:var(--text);font-size:13px;outline:none;margin-bottom:10px;}
.progress-ring{display:flex;justify-content:center;margin:16px 0;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Administration</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-database" style="color:var(--blue);margin-right:6px;"></i>Backup & Restore</h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Database Tables</div><div class="val" style="color:var(--blue);"><?php echo count($table_data); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Rows</div><div class="val" style="color:var(--green);"><?php echo number_format($total_rows); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Database Size</div><div class="val" style="color:var(--orange);"><?php echo $dbsize; ?> KB</div></div>
    </div>

    <div class="grid">
      <!-- Backup -->
      <div>
        <div class="card">
          <div class="card-header"><h3><i class="fas fa-download" style="color:var(--green);"></i> Manual Backup</h3></div>
          <div class="card-body">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Download a complete SQL dump of your AgriBiz database. This includes all tables, structure, and data.</p>
            <form method="POST" onsubmit="showProgress()">
              <button type="submit" name="download_sql" class="btn btn-green" id="dlBtn">
                <i class="fas fa-file-code"></i> Download SQL Backup (.sql)
              </button>
            </form>
            <div style="margin-top:16px;padding:14px;background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.2);border-radius:10px;">
              <p style="font-size:12px;color:var(--text-muted);line-height:1.5;">
                <i class="fas fa-info-circle" style="color:var(--green);"></i>
                <strong> Backup includes:</strong> All <?php echo count($table_data); ?> tables with full data.
                File is downloaded directly to your browser — no server storage needed.
                <br><br>
                💡 <strong>Tip:</strong> Schedule weekly backups for data safety.
              </p>
            </div>
          </div>
        </div>

        <!-- Restore Notice -->
        <div class="card">
          <div class="card-header"><h3><i class="fas fa-upload" style="color:var(--orange);"></i> Restore Database</h3></div>
          <div class="card-body">
            <div class="warn-box">
              <h4>⚠️ Restore Warning</h4>
              <p>Restoring will OVERWRITE all current data. This action is irreversible. Make sure you have a recent backup before proceeding.</p>
            </div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">To restore, import the .sql file through your hosting control panel's phpMyAdmin or MySQL console:</p>
            <div style="background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:var(--green);margin-bottom:14px;">
              mysql -u root -p fertilizer_shop &lt; agribiz_backup.sql
            </div>
            <p style="font-size:12px;color:var(--text-muted);">Or use phpMyAdmin → Import → Select .sql file → Execute</p>
          </div>
        </div>
      </div>

      <!-- Table Overview -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3><i class="fas fa-table" style="color:var(--blue);"></i> Database Tables</h3>
          <span style="font-size:12px;color:var(--text-muted);">Last backup: Now</span>
        </div>
        <table class="table">
          <thead><tr><th>Table Name</th><th style="text-align:right;">Rows</th></tr></thead>
          <tbody>
          <?php foreach ($table_data as $t): ?>
          <tr>
            <td><i class="fas fa-table" style="color:var(--blue);margin-right:6px;font-size:11px;"></i><?php echo htmlspecialchars($t['name']); ?></td>
            <td style="text-align:right;font-weight:700;color:<?php echo $t['rows']>0?'var(--green)':'var(--text-muted)'; ?>;"><?php echo number_format($t['rows']); ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
function showProgress() {
  const btn = document.getElementById('dlBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating backup...';
  btn.disabled = true;
  setTimeout(() => {
    btn.innerHTML = '<i class="fas fa-file-code"></i> Download SQL Backup (.sql)';
    btn.disabled = false;
  }, 4000);
}
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
