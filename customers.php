<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';

$message = '';

// Add new customer
if (isset($_POST['submit'])) {
    $name    = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $mobile  = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $address = mysqli_real_escape_string($conn, trim($_POST['address']));
    $default_pw = password_hash('customer123', PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "INSERT INTO customers (customer_name, mobile, address, password) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($stmt, "ssss", $name, $mobile, $address, $default_pw);
    mysqli_stmt_execute($stmt);
    $message = "Customer '$name' added successfully! Default password: customer123";
}

// Fetch all customers with stats
$result = mysqli_query($conn, "
    SELECT c.*,
        COUNT(DISTINCT o.invoice_no) as total_orders,
        COALESCE(SUM(CASE WHEN o.status='Accepted' THEN o.total_price ELSE 0 END), 0) as total_spent,
        SUM(CASE WHEN o.status='Pending' THEN 1 ELSE 0 END) as pending_orders,
        (c.points * 2 + COALESCE(SUM(CASE WHEN o.status='Accepted' THEN o.total_price ELSE 0 END), 0) / 500) as credit_score
    FROM customers c
    LEFT JOIN orders o ON o.customer_id = c.id
    GROUP BY c.id
    ORDER BY c.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customers — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--teal:#14b8a6;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

.sidebar{width:220px;min-height:100vh;background:var(--bg);border-right:1px solid var(--border);position:fixed;left:0;top:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo h2{font-size:16px;font-weight:700;color:var(--text);}
.sidebar-logo p{font-size:11px;color:var(--muted);}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;border-left:3px solid transparent;transition:all .2s;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;}
.sidebar-footer{margin-top:auto;padding:16px;border-top:1px solid var(--border);}
.sidebar-footer a{display:flex;align-items:center;gap:8px;color:var(--red);text-decoration:none;font-size:13px;}

.main{margin-left:220px;flex:1;padding:28px;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}
.topbar h1{font-size:22px;font-weight:700;} .topbar h1 span{color:var(--green);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-green{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3);}
.btn-green:hover{background:rgba(34,197,94,.25);}

.alert{padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:600;background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.3);}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;}
.stat-card .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:24px;font-weight:700;}
.gc .lbl{color:var(--green);} .bc .lbl{color:var(--blue);} .oc .lbl{color:var(--orange);}

/* Add Form */
.add-form-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:24px;}
.add-form-card h3{font-size:15px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.add-form-card h3 i{color:var(--green);}
.form-grid{display:grid;grid-template-columns:1fr 1fr 2fr auto;gap:12px;align-items:end;}
.fg label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:5px;}
.fg input,.fg textarea{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;outline:none;transition:all .2s;}
.fg input:focus,.fg textarea:focus{border-color:var(--green);box-shadow:0 0 0 2px rgba(34,197,94,.15);}
.fg textarea{resize:none;height:38px;}

/* Table */
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.section-header h3{font-size:15px;font-weight:600;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:rgba(255,255,255,.02);}
.table td{padding:13px 16px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.table tr:hover td{background:rgba(255,255,255,.02);}
.avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#059669,#34D399);display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-view{background:rgba(59,130,246,.15);color:var(--blue);}
.btn-edit{background:rgba(34,197,94,.15);color:var(--green);}
.btn-del{background:rgba(239,68,68,.15);color:var(--red);}
.btn-view:hover{background:rgba(59,130,246,.25);}
.btn-edit:hover{background:rgba(34,197,94,.25);}
.btn-del:hover{background:rgba(239,68,68,.25);}

/* Profile Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--card);border:1px solid var(--border);border-radius:18px;width:100%;max-width:480px;max-height:85vh;overflow-y:auto;padding:28px;}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.modal-header h3{font-size:17px;font-weight:700;}
.close-btn{background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;}
.close-btn:hover{color:var(--text);}
.profile-avatar{width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#059669,#34D399);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;margin:0 auto 16px;}
.profile-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;}
.psb{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;}
.psb .pv{font-size:18px;font-weight:700;color:var(--green);}
.psb .pl{font-size:10px;color:var(--muted);margin-top:2px;}
.detail-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.detail-row:last-child{border:none;}
.detail-row i{color:var(--green);width:18px;font-size:14px;}
.detail-row .dv{font-size:13px;font-weight:600;}
.detail-row .dl{font-size:11px;color:var(--muted);}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><h2>🌱 AgriBiz</h2><p>Dashboard</p></div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <a href="admin_billing.php" class="nav-item"><i class="fas fa-file-invoice-dollar" style="color:var(--orange);"></i> Offline Billing</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Manage Orders</a>
  <a href="customers.php" class="nav-item active"><i class="fas fa-users"></i> Customers</a>
  <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Fertilizers</a>
  <a href="add_fertilizer.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Fertilizer</a>
  <a href="sales.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales</a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-line"></i> Reports</a>
  <div class="sidebar-footer"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <h1>Customer <span>Management</span></h1>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;">View, add, edit and manage all customer accounts</p>
    </div>
  </div>

  <?php if ($message): ?>
  <div class="alert"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <?php
    $total_cust = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM customers"))['c'];
    $active_cust = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) as c FROM orders WHERE status='Accepted'"))['c'];
    $new_this_month = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM customers"))['c']; // placeholder
  ?>
  <div class="stats-row">
    <div class="stat-card gc"><div class="lbl"><i class="fas fa-users"></i> Total Customers</div><div class="val"><?php echo $total_cust; ?></div></div>
    <div class="stat-card bc"><div class="lbl"><i class="fas fa-user-check"></i> Active Buyers</div><div class="val"><?php echo $active_cust; ?></div></div>
    <div class="stat-card oc"><div class="lbl"><i class="fas fa-clock"></i> Pending Orders</div><div class="val"><?php echo mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT invoice_no) as c FROM orders WHERE status='Pending'"))['c']; ?></div></div>
  </div>

  <!-- Add Customer Form -->
  <div class="add-form-card">
    <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
    <form method="POST">
      <div class="form-grid">
        <div class="fg"><label>Full Name</label><input type="text" name="customer_name" placeholder="e.g. Ravi Kumar" required></div>
        <div class="fg"><label>Mobile Number</label><input type="tel" name="mobile" placeholder="10-digit number" required></div>
        <div class="fg"><label>Address</label><textarea name="address" placeholder="Village / Town / District"></textarea></div>
        <div><button type="submit" name="submit" class="btn btn-green" style="height:38px;"><i class="fas fa-plus"></i> Add</button></div>
      </div>
    </form>
  </div>

  <!-- Customer Table -->
  <div class="section">
    <div class="section-header">
      <div style="display:flex;align-items:center;gap:15px;">
        <h3><i class="fas fa-list" style="color:var(--green);margin-right:8px;"></i>All Customers</h3>
        <input type="text" id="custSearch" placeholder="🔍 Search name or mobile..." oninput="filterCustomers(this.value)" style="background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:6px 14px;color:var(--text);font-size:12px;width:240px;outline:none;">
      </div>
      <span style="font-size:12px;color:var(--muted);"><?php echo $total_cust; ?> registered</span>
    </div>
    <table class="table">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Mobile</th>
          <th>AI Credit</th>
          <th>Orders</th>
          <th>Total Spent</th>
          <th>Pending</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php mysqli_data_seek($result, 0); while ($row = mysqli_fetch_assoc($result)): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="avatar"><?php echo strtoupper(substr($row['customer_name'], 0, 1)); ?></div>
              <div>
                <div style="font-weight:600;"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                <div style="font-size:11px;color:var(--muted);">ID #<?php echo $row['id']; ?></div>
              </div>
            </div>
          </td>
          <td><?php echo htmlspecialchars($row['mobile']); ?></td>
          <td>
            <?php 
              $cs = $row['credit_score'];
              $rating = $cs > 500 ? 'A+' : ($cs > 200 ? 'A' : ($cs > 50 ? 'B' : 'C'));
              $c_color = $cs > 200 ? 'var(--green)' : ($cs > 50 ? 'var(--blue)' : 'var(--muted)');
            ?>
            <div style="text-align:center;">
                <div style="font-size:16px; font-weight:900; color:<?php echo $c_color; ?>;"><?php echo $rating; ?></div>
                <div style="font-size:9px; color:var(--muted); text-transform:uppercase;">Score: <?php echo round($cs); ?></div>
            </div>
          </td>
          <td><span class="badge badge-green"><?php echo $row['total_orders']; ?> orders</span></td>
          <td style="font-weight:600;color:var(--green);">₹<?php echo number_format($row['total_spent'], 0); ?></td>
          <td>
            <?php if ($row['pending_orders'] > 0): ?>
            <span class="badge badge-orange"><?php echo $row['pending_orders']; ?> pending</span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--muted);">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;">
              <button class="action-btn btn-view" onclick='openProfile(<?php echo json_encode($row); ?>)'><i class="fas fa-eye"></i> View</button>
              <a href="update_customer.php?id=<?php echo $row['id']; ?>" class="action-btn btn-edit"><i class="fas fa-edit"></i> Edit</a>
              <a href="delete_customer.php?id=<?php echo $row['id']; ?>" class="action-btn btn-del" onclick="return confirm('Delete <?php echo htmlspecialchars($row['customer_name']); ?>?')"><i class="fas fa-trash"></i></a>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Profile Modal -->
<div class="modal-overlay" id="profileModal">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fas fa-id-card" style="color:var(--green);margin-right:8px;"></i>Customer Profile</h3>
      <button class="close-btn" onclick="closeProfile()"><i class="fas fa-times"></i></button>
    </div>
    <div style="text-align:center;margin-bottom:20px;">
      <div class="profile-avatar" id="mAvatar">R</div>
      <div style="font-size:17px;font-weight:700;" id="mName">-</div>
      <div style="font-size:12px;color:var(--muted);margin-top:3px;" id="mId">-</div>
    </div>
    <div class="profile-stat-grid">
      <div class="psb"><div class="pv" id="mOrders">0</div><div class="pl">Orders</div></div>
      <div class="psb"><div class="pv" id="mSpent">₹0</div><div class="pl">Total Spent</div></div>
      <div class="psb"><div class="pv" id="mPending">0</div><div class="pl">Pending</div></div>
    </div>
    <div class="detail-row"><i class="fas fa-phone"></i><div><div class="dl">Mobile</div><div class="dv" id="mMobile">-</div></div></div>
    <div class="detail-row"><i class="fas fa-map-marker-alt"></i><div><div class="dl">Address</div><div class="dv" id="mAddress">-</div></div></div>
    <div style="margin-top:20px;display:flex;gap:10px;">
      <a id="mEditBtn" href="#" class="btn btn-green" style="flex:1;justify-content:center;"><i class="fas fa-edit"></i> Edit Profile</a>
    </div>
  </div>
</div>

<script>
function openProfile(data) {
  document.getElementById('mAvatar').textContent = data.customer_name.charAt(0).toUpperCase();
  document.getElementById('mName').textContent = data.customer_name;
  document.getElementById('mId').textContent = 'Customer ID: #' + data.id;
  document.getElementById('mMobile').textContent = data.mobile;
  document.getElementById('mAddress').textContent = data.address || 'Not provided';
  document.getElementById('mOrders').textContent = data.total_orders;
  document.getElementById('mSpent').textContent = '₹' + parseFloat(data.total_spent).toLocaleString('en-IN', {maximumFractionDigits: 0});
  document.getElementById('mPending').textContent = data.pending_orders;
  document.getElementById('mEditBtn').href = 'update_customer.php?id=' + data.id;
  document.getElementById('profileModal').classList.add('open');
}
function closeProfile() {
  document.getElementById('profileModal').classList.remove('open');
}
function filterCustomers(q) {
  const rows = document.querySelectorAll('.table tbody tr');
  q = q.toLowerCase();
  rows.forEach(row => {
    const name = row.querySelector('div[style*="font-weight:600"]').textContent.toLowerCase();
    const mobile = row.querySelectorAll('td')[1].textContent.toLowerCase();
    row.style.display = (name.includes(q) || mobile.includes(q)) ? '' : 'none';
  });
}
document.getElementById('profileModal').addEventListener('click', function(e) {
  if (e.target === this) closeProfile();
});
</script>
</body>
</html>