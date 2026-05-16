<?php
session_start();
if (!isset($_SESSION['customer'])) { header('Location: index.php'); exit(); }
include 'db.php';

$customer_id = intval($_SESSION['customer_id']);
$message = '';
$msg_type = 'success';

// Handle profile update
if (isset($_POST['update_profile'])) {
    $name    = trim($_POST['customer_name']);
    $address = trim($_POST['address']);
    $new_pw  = $_POST['new_password'];
    $conf_pw = $_POST['confirm_password'];

    if (!$name) {
        $message = 'Name cannot be empty.'; $msg_type = 'error';
    } elseif ($new_pw && $new_pw !== $conf_pw) {
        $message = 'Passwords do not match.'; $msg_type = 'error';
    } else {
        if ($new_pw) {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE customers SET customer_name=?, address=?, password=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssi", $name, $address, $hashed, $customer_id);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE customers SET customer_name=?, address=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "ssi", $name, $address, $customer_id);
        }
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['customer_name'] = $name;
            header('Location: customer_profile.php?status=success');
            exit();
        } else {
            $message = 'Update failed. Please try again.'; $msg_type = 'error';
        }
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $message = '✅ Profile updated successfully!'; $msg_type = 'success';
}

// Fetch current customer data
$stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $customer_id);
mysqli_stmt_execute($stmt);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Fetch order stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT invoice_no) as total_orders, COALESCE(SUM(total_price),0) as total_spent FROM orders WHERE customer_id=$customer_id AND status='Accepted'"));
$pending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT invoice_no) as c FROM orders WHERE customer_id=$customer_id AND status='Pending'"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>My Profile — GreenGrow</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
:root { --primary:#10B981; --primary-dark:#059669; --primary-light:#D1FAE5; --bg:#F5F7FA; --white:#fff; --text:#1F2937; --gray:#6B7280; --border:#E5E7EB; }
body { background:var(--bg); color:var(--text); padding-bottom: 80px; min-height:100vh; }

.header { background: linear-gradient(135deg, #059669, #10B981); padding: 20px 16px 60px; }
.header-top { display:flex; align-items:center; gap:12px; margin-bottom:4px; }
.back-btn { color:rgba(255,255,255,0.8); font-size:20px; text-decoration:none; }
.header h1 { font-size:18px; font-weight:700; color:#fff; }
.header p { font-size:12px; color:rgba(255,255,255,0.8); margin-top:2px; }

.avatar-wrap { display:flex; justify-content:center; margin-top:-40px; margin-bottom:16px; }
.avatar { width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg,#059669,#34D399); border:4px solid #fff; display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:800; color:#fff; box-shadow:0 4px 16px rgba(16,185,129,0.3); }

.container { max-width:480px; margin:0 auto; padding:0 16px; }

/* Stats */
.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:20px; }
.stat-box { background:var(--white); border-radius:12px; padding:14px 10px; text-align:center; border:1px solid var(--border); }
.stat-box .val { font-size:20px; font-weight:800; color:var(--primary); }
.stat-box .lbl { font-size:10px; color:var(--gray); margin-top:2px; font-weight:500; }

/* Card */
.card { background:var(--white); border-radius:16px; padding:20px; margin-bottom:16px; border:1px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,0.05); }
.card-title { font-size:14px; font-weight:700; color:var(--text); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--primary); }

/* Form */
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; font-weight:600; color:var(--gray); margin-bottom:6px; }
.form-group input, .form-group textarea { width:100%; border:1px solid var(--border); border-radius:10px; padding:11px 14px; font-size:14px; outline:none; background:#F9FAFB; transition:0.2s; }
.form-group input:focus, .form-group textarea:focus { border-color:var(--primary); background:#fff; box-shadow:0 0 0 3px var(--primary-light); }
.form-group .input-icon { position:relative; }
.form-group .input-icon i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gray); font-size:14px; }
.form-group .input-icon input { padding-left:36px; }
textarea { resize:vertical; min-height:80px; }

.info-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); }
.info-row:last-child { border-bottom:none; }
.info-row i { color:var(--primary); width:20px; font-size:15px; }
.info-row .val { font-size:13px; font-weight:600; }
.info-row .lbl { font-size:11px; color:var(--gray); }

.btn-primary { width:100%; background:var(--primary); color:#fff; border:none; padding:14px; border-radius:12px; font-size:15px; font-weight:700; cursor:pointer; transition:0.2s; display:flex; align-items:center; justify-content:center; gap:8px; }
.btn-primary:hover { background:var(--primary-dark); }
.btn-danger { width:100%; background:#FEF2F2; color:#DC2626; border:1px solid #FECACA; padding:12px; border-radius:12px; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px; }

.alert { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.alert.success { background:#ECFDF5; color:#059669; border:1px solid #A7F3D0; }
.alert.error { background:#FEF2F2; color:#DC2626; border:1px solid #FECACA; }

/* Bottom Nav */
.bottom-nav { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--border); display:flex; justify-content:space-around; padding:10px 0; z-index:100; }
.nav-item { display:flex; flex-direction:column; align-items:center; gap:4px; color:var(--gray); text-decoration:none; }
.nav-item.active { color:var(--primary); }
.nav-item i { font-size:20px; }
.nav-item span { font-size:10px; font-weight:600; }
</style>
</head>
<body>

<div class="header">
  <div class="header-top">
    <a href="customer_shop.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
    <div>
      <h1>My Profile</h1>
      <p>Manage your account settings</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="avatar-wrap">
    <div class="avatar"><?php echo strtoupper(substr($customer['customer_name'], 0, 1)); ?></div>
  </div>

  <?php if ($message): ?>
  <div class="alert <?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo $message; ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="val"><?php echo $stats['total_orders']; ?></div>
      <div class="lbl">Orders</div>
    </div>
    <div class="stat-box">
      <div class="val">₹<?php echo number_format($stats['total_spent'], 0); ?></div>
      <div class="lbl">Total Spent</div>
    </div>
    <div class="stat-box">
      <div class="val"><?php echo $pending; ?></div>
      <div class="lbl">Pending</div>
    </div>
  </div>

  <!-- Account Info -->
  <div class="card">
    <div class="card-title"><i class="fas fa-id-card"></i> Account Info</div>
    <div class="info-row">
      <i class="fas fa-phone"></i>
      <div><div class="lbl">Mobile Number</div><div class="val"><?php echo htmlspecialchars($customer['mobile']); ?></div></div>
    </div>
    <div class="info-row">
      <i class="fas fa-map-marker-alt"></i>
      <div><div class="lbl">Address</div><div class="val"><?php echo htmlspecialchars($customer['address'] ?: 'Not set'); ?></div></div>
    </div>
  </div>

  <!-- Edit Profile -->
  <div class="card">
    <div class="card-title"><i class="fas fa-edit"></i> Edit Profile</div>
    <form method="POST">
      <div class="form-group">
        <label>Full Name</label>
        <div class="input-icon"><i class="fas fa-user"></i><input type="text" name="customer_name" value="<?php echo htmlspecialchars($customer['customer_name']); ?>" required></div>
      </div>
      <div class="form-group">
        <label>Address</label>
        <textarea name="address"><?php echo htmlspecialchars($customer['address']); ?></textarea>
      </div>
      <div class="form-group">
        <label>New Password <span style="color:var(--gray);font-weight:400;">(leave blank to keep current)</span></label>
        <div class="input-icon"><i class="fas fa-lock"></i><input type="password" name="new_password" placeholder="Enter new password"></div>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <div class="input-icon"><i class="fas fa-lock"></i><input type="password" name="confirm_password" placeholder="Confirm new password"></div>
      </div>
      <button type="submit" name="update_profile" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
    </form>
  </div>

  <!-- Logout -->
  <a href="customer_logout.php" class="btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<div class="bottom-nav">
  <a href="customer_shop.php" class="nav-item"><i class="fas fa-home"></i><span>Home</span></a>
  <a href="#" class="nav-item"><i class="fas fa-search"></i><span>Search</span></a>
  <a href="#" class="nav-item"><i class="fas fa-shopping-cart"></i><span>Cart</span></a>
  <a href="#" class="nav-item"><i class="fas fa-box-open"></i><span>Orders</span></a>
  <a href="customer_profile.php" class="nav-item active"><i class="fas fa-user"></i><span>Profile</span></a>
</div>

</body>
</html>
