<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager']);

// Auto-migrate
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_name VARCHAR(100), role VARCHAR(50), action TEXT, ip VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role ENUM('Admin','Manager','Billing Staff','Accountant') DEFAULT 'Billing Staff', full_name VARCHAR(100) DEFAULT '', mobile VARCHAR(15) DEFAULT '', is_active TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$msg = ''; $msg_type = 'success';
$role = $_SESSION['admin_role'] ?? 'Admin';
$current_user = $_SESSION['admin_username'] ?? 'admin';

// ─── Add User ──────────────────────────────────────────────────────────────
if (isset($_POST['add_user'])) {
    $uname   = mysqli_real_escape_string($conn, trim($_POST['username']));
    $fname   = mysqli_real_escape_string($conn, trim($_POST['full_name']));
    $mobile  = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $urole   = in_array($_POST['role'],['Admin','Manager','Billing Staff','Accountant']) ? $_POST['role'] : 'Billing Staff';
    $pass    = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    if ($uname && $_POST['password']) {
        $chk = mysqli_query($conn, "SELECT id FROM users WHERE username='$uname'");
        if (mysqli_num_rows($chk) > 0) {
            $msg = "Username '$uname' already exists."; $msg_type = 'error';
        } else {
            mysqli_query($conn, "INSERT INTO users (username,password,role,full_name,mobile) VALUES ('$uname','$pass','$urole','$fname','$mobile')");
            // Also add to audit log
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            mysqli_query($conn, "INSERT INTO audit_log (user_name,role,action,ip) VALUES ('$current_user','$role','Created user: $uname ($urole)','$ip')");
            $msg = "User '$uname' created successfully with role: $urole";
        }
    } else { $msg = 'Username and password are required.'; $msg_type = 'error'; }
}

// ─── Deactivate / Activate User ───────────────────────────────────────────
if (isset($_POST['toggle_user'])) {
    $uid = intval($_POST['user_id']);
    $active = intval($_POST['current_active']);
    $new_active = $active ? 0 : 1;
    mysqli_query($conn, "UPDATE users SET is_active=$new_active WHERE id=$uid");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $action_txt = $new_active ? 'Activated' : 'Deactivated';
    mysqli_query($conn, "INSERT INTO audit_log (user_name,role,action,ip) VALUES ('$current_user','$role','$action_txt user ID: $uid','$ip')");
    $msg = "User status updated.";
}

// ─── Reset Password ────────────────────────────────────────────────────────
if (isset($_POST['reset_password'])) {
    $uid = intval($_POST['user_id']);
    $new_pass = trim($_POST['new_password']);
    if ($new_pass) {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id=$uid");
        $msg = "Password reset successfully.";
    }
}

$users_list = mysqli_query($conn, "SELECT * FROM users ORDER BY role, username");
$user_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM users"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Management — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--purple:#a855f7;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--green-dark:#15803d;--purple:#7c3aed;--blue:#2563eb;--orange:#ea580c;--red:#dc2626;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
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
.layout{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.card-body{padding:20px;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:11px 14px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-gray{background:rgba(139,148,158,.15);color:var(--text-muted);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:var(--green-dark);}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.btn-red{background:var(--red);color:#fff;}
.form-group{margin-bottom:12px;}
.form-group label{display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 13px;color:var(--text);font-size:13px;outline:none;transition:.2s;}
.form-control:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,.1);}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.msg-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
.avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
.role-desc{font-size:11px;color:var(--text-muted);margin-top:2px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Administration</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-user-shield" style="color:var(--blue);margin-right:6px;"></i>User Management</h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg msg-<?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <!-- Role Info -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
      <?php
      $roles = [
        ['Admin', 'Full ERP access including user management, backup, settings.', 'var(--red)', 'fa-crown'],
        ['Manager', 'Access to all modules except user management and security settings.', 'var(--purple)', 'fa-user-tie'],
        ['Billing Staff', 'Access to billing, invoices, and inventory only.', 'var(--blue)', 'fa-cash-register'],
        ['Accountant', 'Access to accounting books, ledgers, GST reports only.', 'var(--green)', 'fa-calculator'],
      ];
      foreach ($roles as $r): ?>
      <div class="card" style="margin-bottom:0;padding:14px 16px;border-left:3px solid <?php echo $r[2]; ?>;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><i class="fas <?php echo $r[3]; ?>" style="color:<?php echo $r[2]; ?>;"></i><strong style="font-size:13px;"><?php echo $r[0]; ?></strong></div>
        <p style="font-size:11px;color:var(--text-muted);line-height:1.4;"><?php echo $r[1]; ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="layout">
      <!-- Add User Form -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header"><h3><i class="fas fa-user-plus" style="color:var(--green);"></i> Add New User</h3></div>
        <div class="card-body">
          <form method="POST">
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" placeholder="e.g. Rajesh Kumar"></div>
            <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" placeholder="e.g. rajesh_k" required></div>
            <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required></div>
            <div class="form-group">
              <label>Role *</label>
              <select name="role" class="form-control" required>
                <option value="Billing Staff">Billing Staff</option>
                <option value="Accountant">Accountant</option>
                <option value="Manager">Manager</option>
                <option value="Admin">Admin</option>
              </select>
            </div>
            <div class="form-group"><label>Mobile</label><input type="tel" name="mobile" class="form-control" placeholder="e.g. 9876543210"></div>
            <button type="submit" name="add_user" class="btn btn-green" style="width:100%;justify-content:center;"><i class="fas fa-user-plus"></i> Create User</button>
          </form>
        </div>
      </div>

      <!-- Users Table -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h3><i class="fas fa-users" style="color:var(--blue);"></i> ERP Users (<?php echo $user_count; ?>)</h3>
        </div>
        <table class="table">
          <thead><tr><th>User</th><th>Role</th><th>Mobile</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if ($users_list && mysqli_num_rows($users_list) > 0):
              while ($u = mysqli_fetch_assoc($users_list)):
                  $role_color = match($u['role']) {
                      'Admin'         => 'var(--red)',
                      'Manager'       => 'var(--purple)',
                      'Billing Staff' => 'var(--blue)',
                      'Accountant'    => 'var(--green)',
                      default         => 'var(--text-muted)'
                  };
                  $role_class = match($u['role']) {
                      'Admin'         => 'badge-red',
                      'Manager'       => 'badge-purple',
                      'Billing Staff' => 'badge-blue',
                      'Accountant'    => 'badge-green',
                      default         => 'badge-gray'
                  };
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="avatar" style="background:linear-gradient(135deg,<?php echo $role_color; ?>,rgba(0,0,0,.3));"><?php echo strtoupper(substr($u['username'],0,2)); ?></div>
                <div>
                  <strong><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></strong>
                  <div style="font-size:11px;color:var(--text-muted);">@<?php echo htmlspecialchars($u['username']); ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge <?php echo $role_class; ?>"><?php echo $u['role']; ?></span></td>
            <td style="color:var(--text-muted);font-size:12px;"><?php echo htmlspecialchars($u['mobile']??'—'); ?></td>
            <td><span class="badge <?php echo $u['is_active'] ? 'badge-green' : 'badge-red'; ?>"><?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
            <td style="color:var(--text-muted);font-size:11px;"><?php echo date('d-M-Y',strtotime($u['created_at'])); ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <input type="hidden" name="current_active" value="<?php echo $u['is_active']; ?>">
                <button type="submit" name="toggle_user" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;" title="<?php echo $u['is_active']?'Deactivate':'Activate'; ?>">
                  <i class="fas fa-<?php echo $u['is_active']?'ban':'check'; ?>"></i>
                </button>
              </form>
              <button onclick="showResetPwd(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;" title="Reset Password"><i class="fas fa-key"></i></button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted);">No ERP users created yet. Add one using the form.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:400;backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:24px;max-width:380px;width:100%;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fas fa-key" style="color:var(--orange);"></i> Reset Password: <span id="resetUsername"></span></h3>
    <form method="POST">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" required></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" onclick="document.getElementById('resetModal').style.display='none'" class="btn btn-ghost">Cancel</button>
        <button type="submit" name="reset_password" class="btn btn-green"><i class="fas fa-save"></i> Reset</button>
      </div>
    </form>
  </div>
</div>

<script>
function showResetPwd(id, name) {
  document.getElementById('resetModal').style.display = 'flex';
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetUsername').textContent = name;
}
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
