<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';

$message = '';
$wa_link = '';

// Handle actions
if (isset($_POST['action']) && isset($_POST['invoice_no'])) {
    $invoice_no = $_POST['invoice_no'];
    $action = $_POST['action'];

    if (in_array($action, ['out_for_delivery', 'delivered'])) {
        $new_status = $action === 'out_for_delivery' ? 'Out for Delivery' : 'Delivered';
        mysqli_query($conn, "UPDATE orders SET status='$new_status' WHERE invoice_no='$invoice_no'");
        $message = "Invoice $invoice_no updated to $new_status!";
    }

    if ($action === 'accept') {
        $check = mysqli_query($conn, "SELECT * FROM orders WHERE invoice_no = '$invoice_no' AND status = 'Pending'");
        $items = []; while($r = mysqli_fetch_assoc($check)){ $items[] = $r; }
        
        if (count($items) > 0) {
            $all_available = true;
            foreach ($items as $item) {
                $f = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM fertilizers WHERE id = {$item['fertilizer_id']}"));
                if (!$f || $f['quantity'] < $item['quantity']) { $all_available = false; break; }
            }

            if ($all_available) {
                foreach ($items as $item) {
                    mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity - {$item['quantity']} WHERE id = {$item['fertilizer_id']}");
                    mysqli_query($conn, "INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no) 
                                       VALUES ('{$item['customer_name']}', '{$item['fertilizer_name']}', {$item['quantity']}, {$item['total_price']}, CURDATE(), '$invoice_no')");
                }
                mysqli_query($conn, "UPDATE orders SET status = 'Accepted' WHERE invoice_no = '$invoice_no'");
                $message = "Order $invoice_no Accepted!";
                
                $cust = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE id = {$items[0]['customer_id']}"));
                $mobile = preg_replace('/[^0-9]/', '', $cust['mobile']);
                if (strlen($mobile) == 10) $mobile = '91' . $mobile;
                $wa_msg = rawurlencode("Hello {$cust['customer_name']}, your order $invoice_no has been accepted! 🌱");
                $wa_link = "https://wa.me/$mobile?text=$wa_msg";
            } else {
                $message = "Error: Out of stock!";
            }
        }
    } elseif ($action === 'reject') {
        mysqli_query($conn, "UPDATE orders SET status = 'Rejected' WHERE invoice_no = '$invoice_no'");
        $message = "Order $invoice_no Rejected.";
    }
}

function getOrders($conn, $status) {
    return mysqli_query($conn, "SELECT invoice_no, customer_name, order_date, points_earned, COUNT(id) as ti, SUM(total_price) as gt, GROUP_CONCAT(fertilizer_name SEPARATOR ', ') as pn 
                                FROM orders WHERE status='$status' GROUP BY invoice_no ORDER BY id DESC");
}

$pending   = getOrders($conn, 'Pending');
$accepted  = getOrders($conn, 'Accepted');
$delivery  = getOrders($conn, 'Out for Delivery');
$delivered = getOrders($conn, 'Delivered');
$rejected  = getOrders($conn, 'Rejected');
$p_count   = mysqli_num_rows($pending);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Management — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --bg: #F0F4F8;
  --white: #FFFFFF;
  --primary: #22C55E;
  --primary-dark: #15803D;
  --text: #1E293B;
  --text-light: #64748B;
  --border: #E2E8F0;
  --orange: #F59E0B;
  --blue: #3B82F6;
  --red: #EF4444;
}
* { margin:0; padding:0; box-sizing:border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
body { background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

/* Sidebar */
.sidebar { width: 260px; background: var(--white); border-right: 1px solid var(--border); position: fixed; height: 100vh; padding: 24px; display: flex; flex-direction: column; gap: 30px; }
.logo { font-size: 22px; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 10px; }
.nav-links { display: flex; flex-direction: column; gap: 8px; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: var(--text-light); text-decoration: none; font-weight: 600; transition: 0.2s; }
.nav-item:hover { background: #F8FAFC; color: var(--primary); }
.nav-item.active { background: #DCFCE7; color: var(--primary-dark); }

.main { margin-left: 260px; flex: 1; padding: 40px; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
.header h1 { font-size: 28px; font-weight: 800; }

/* Tabs */
.tabs { display: flex; gap: 10px; margin-bottom: 30px; overflow-x: auto; padding-bottom: 5px; }
.tab { padding: 10px 20px; border-radius: 30px; border: 1px solid var(--border); background: var(--white); color: var(--text-light); font-weight: 700; font-size: 14px; cursor: pointer; white-space: nowrap; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
.tab:hover { border-color: var(--primary); color: var(--primary); }
.tab.active { background: var(--primary); color: var(--white); border-color: var(--primary); box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3); }
.tab-badge { background: var(--red); color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; }

/* Order Cards */
.order-grid { display: grid; gap: 20px; }
.order-card { background: var(--white); border-radius: 24px; padding: 24px; border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); position: relative; transition: 0.3s; }
.order-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px rgba(0,0,0,0.05); }

.order-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; border-bottom: 1px solid #F1F5F9; padding-bottom: 16px; }
.invoice-no { font-size: 14px; color: var(--text-light); font-weight: 600; }
.customer-name { font-size: 18px; font-weight: 800; color: var(--text); margin-top: 4px; }
.order-date { font-size: 12px; color: var(--text-light); }

.order-body { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; align-items: center; margin-bottom: 20px; }
.product-list { font-size: 14px; color: var(--text-light); font-weight: 500; }
.price-box { text-align: center; }
.price-val { font-size: 20px; font-weight: 800; color: var(--primary-dark); }
.coins-val { font-size: 14px; font-weight: 700; color: var(--blue); }

.order-footer { display: flex; justify-content: space-between; align-items: center; }
.status-pill { padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.status-pending { background: #FEF3C7; color: #D97706; }
.status-accepted { background: #DCFCE7; color: #16A34A; }
.status-shipped { background: #DBEAFE; color: #2563EB; }
.status-rejected { background: #FEE2E2; color: #DC2626; }

.action-group { display: flex; gap: 10px; }
.btn { padding: 10px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; transition: 0.2s; text-decoration: none; display: flex; align-items: center; gap: 8px; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-outline { background: transparent; border: 1.5px solid var(--border); color: var(--text-light); }
.btn-outline:hover { background: #F8FAFC; border-color: var(--text-light); }

.alert { background: #DCFCE7; color: #16A34A; padding: 16px; border-radius: 16px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; border: 1px solid #BBF7D0; }
.tab-panel { display: none; }
.tab-panel.active { display: block; animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.empty-state { text-align: center; padding: 60px; color: var(--text-light); }
.empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.2; }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="logo">🌱 AgriBiz</div>
  <nav class="nav-links">
    <a href="dashboard.php" class="nav-item"><i class="fas fa-grid-2"></i> Dashboard</a>
    <a href="manage_orders.php" class="nav-item active"><i class="fas fa-shopping-bag"></i> Orders</a>
    <a href="admin_billing.php" class="nav-item"><i class="fas fa-calculator"></i> Billing</a>
    <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-box"></i> Inventory</a>
    <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  </nav>
  <a href="logout.php" class="nav-item" style="margin-top: auto; color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<main class="main">
  <div class="header">
    <h1>Manage Orders</h1>
    <div style="background: white; padding: 8px 16px; border-radius: 12px; border: 1px solid var(--border); font-size: 13px; font-weight: 600; color: var(--text-light);">
      <i class="fas fa-sync fa-spin"></i> Live Status
    </div>
  </div>

  <?php if ($message): ?>
  <div class="alert">
    <span><i class="fas fa-check-circle"></i> <?php echo $message; ?></span>
    <?php if ($wa_link): ?>
    <a href="<?php echo $wa_link; ?>" target="_blank" class="btn" style="background:#25D366; color:white;"><i class="fab fa-whatsapp"></i> Notify</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab active" data-target="pending"><i class="fas fa-clock"></i> New <?php if($p_count) echo "<span class='tab-badge'>$p_count</span>"; ?></button>
    <button class="tab" data-target="accepted"><i class="fas fa-check-circle"></i> Confirmed</button>
    <button class="tab" data-target="delivery"><i class="fas fa-truck"></i> Shipped</button>
    <button class="tab" data-target="delivered"><i class="fas fa-home"></i> Delivered</button>
    <button class="tab" data-target="rejected"><i class="fas fa-times-circle"></i> Cancelled</button>
  </div>

  <?php 
  $tabs = ['pending' => $pending, 'accepted' => $accepted, 'delivery' => $delivery, 'delivered' => $delivered, 'rejected' => $rejected];
  foreach ($tabs as $id => $res):
    $isActive = ($id === 'pending') ? 'active' : '';
  ?>
  <div class="tab-panel <?php echo $isActive; ?>" id="<?php echo $id; ?>">
    <div class="order-grid">
      <?php if (mysqli_num_rows($res) > 0): while ($o = mysqli_fetch_assoc($res)): ?>
      <div class="order-card">
        <div class="order-header">
          <div>
            <div class="invoice-no">#<?php echo $o['invoice_no']; ?></div>
            <div class="customer-name"><?php echo htmlspecialchars($o['customer_name']); ?></div>
          </div>
          <div class="order-date"><?php echo date('M d, Y • h:i A', strtotime($o['order_date'])); ?></div>
        </div>

        <div class="order-body">
          <div class="product-list">
            <i class="fas fa-seedling" style="margin-right:8px; color:var(--primary);"></i>
            <?php echo htmlspecialchars($o['pn']); ?> (<?php echo $o['ti']; ?> items)
          </div>
          <div class="price-box">
            <div class="price-val">₹<?php echo number_format($o['gt'], 2); ?></div>
            <div style="font-size:10px; color:var(--text-light); text-transform:uppercase; letter-spacing:1px;">Total Price</div>
          </div>
          <div class="price-box">
            <div class="coins-val">💎 <?php echo $o['points_earned']; ?></div>
            <div style="font-size:10px; color:var(--text-light); text-transform:uppercase; letter-spacing:1px;">Agri-Coins</div>
          </div>
        </div>

        <div class="order-footer">
          <div class="status-pill status-<?php echo ($id==='pending'?'pending':($id==='accepted'?'accepted':($id==='delivery'?'shipped':'rejected'))); ?>">
            <?php echo ($id==='pending'?'WAITING':($id==='accepted'?'CONFIRMED':($id==='delivery'?'SHIPPED':strtoupper($id)))); ?>
          </div>
          <div class="action-group">
            <?php if ($id === 'pending'): ?>
              <form method="POST"><input type="hidden" name="invoice_no" value="<?php echo $o['invoice_no']; ?>"><input type="hidden" name="action" value="accept"><button class="btn btn-primary">Accept Order</button></form>
              <form method="POST"><input type="hidden" name="invoice_no" value="<?php echo $o['invoice_no']; ?>"><input type="hidden" name="action" value="reject"><button class="btn btn-outline" style="color:var(--red);">Reject</button></form>
            <?php elseif ($id === 'accepted'): ?>
              <form method="POST"><input type="hidden" name="invoice_no" value="<?php echo $o['invoice_no']; ?>"><input type="hidden" name="action" value="out_for_delivery"><button class="btn btn-primary" style="background:var(--blue);"><i class="fas fa-truck"></i> Ship Order</button></form>
              <a href="view_invoice.php?invoice_no=<?php echo urlencode($o['invoice_no']); ?>" target="_blank" class="btn btn-outline">View Invoice</a>
            <?php elseif ($id === 'delivery'): ?>
              <form method="POST"><input type="hidden" name="invoice_no" value="<?php echo $o['invoice_no']; ?>"><input type="hidden" name="action" value="delivered"><button class="btn btn-primary"><i class="fas fa-check-double"></i> Mark Delivered</button></form>
            <?php else: ?>
              <a href="view_invoice.php?invoice_no=<?php echo urlencode($o['invoice_no']); ?>" target="_blank" class="btn btn-outline">View Receipt</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endwhile; else: ?>
      <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No orders found</h3>
        <p>There are no orders in the "<?php echo ucfirst($id); ?>" category.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</main>

<script>
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById(tab.dataset.target).classList.add('active');
  });
});
</script>
</body>
</html>
