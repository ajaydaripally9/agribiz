<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

$message = '';
$msg_type = 'success';

// Handle Voucher Submission
if (isset($_POST['create_voucher'])) {
    $voucher_type = mysqli_real_escape_string($conn, $_POST['voucher_type']);
    $entity_id = intval($_POST['entity_id']);
    $amount = floatval($_POST['amount']);
    $method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $narration = mysqli_real_escape_string($conn, $_POST['narration']);
    $date = mysqli_real_escape_string($conn, $_POST['date'] ?: date('Y-m-d'));

    if (!$entity_id || $amount <= 0) {
        $message = "Please select a customer/supplier and enter a valid amount.";
        $msg_type = 'error';
    } else {
        $entity_type = ($voucher_type === 'Receipt') ? 'Customer' : 'Supplier';
        
        // Generate Voucher No
        $prefix = ($voucher_type === 'Receipt') ? 'RCPT' : 'PMNT';
        $seq_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM vouchers"));
        $seq = str_pad($seq_res['next_id'], 4, '0', STR_PAD_LEFT);
        $voucher_no = $prefix . '-' . date('Ymd', strtotime($date)) . '-' . $seq;

        mysqli_begin_transaction($conn);
        try {
            // 1. Insert Voucher
            $ins_stmt = mysqli_prepare($conn, "INSERT INTO vouchers (voucher_no, voucher_type, entity_type, entity_id, amount, payment_method, narration, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins_stmt, "sssssdss", $voucher_no, $voucher_type, $entity_type, $entity_id, $amount, $method, $narration, $date);
            mysqli_stmt_execute($ins_stmt);

            // 2. FIFO Settlement for Customer Receipts
            if ($voucher_type === 'Receipt') {
                $rem_amount = $amount;
                
                // Fetch unpaid accepted/delivered orders for this customer (Oldest First)
                $order_res = mysqli_query($conn, "
                    SELECT id, total_price, paid_amount 
                    FROM orders 
                    WHERE customer_id = $entity_id 
                      AND (status = 'Accepted' OR status = 'Delivered') 
                      AND (total_price - paid_amount > 0) 
                    ORDER BY id ASC");
                
                while ($ord = mysqli_fetch_assoc($order_res)) {
                    if ($rem_amount <= 0) break;
                    
                    $due = $ord['total_price'] - $ord['paid_amount'];
                    $alloc = min($rem_amount, $due);
                    
                    mysqli_query($conn, "UPDATE orders SET paid_amount = paid_amount + $alloc WHERE id = {$ord['id']}");
                    $rem_amount -= $alloc;
                }
                
                // If there's leftover amount, add it to points/prepayments
                if ($rem_amount > 0) {
                    mysqli_query($conn, "UPDATE customers SET points = points + " . intval($rem_amount / 10) . " WHERE id = $entity_id");
                }
            }

            mysqli_commit($conn);
            $message = "Voucher $voucher_no successfully saved and settled!";
            $msg_type = 'success';
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Error saving voucher: " . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

// Fetch lists for forms
$customers = mysqli_query($conn, "SELECT id, customer_name, mobile FROM customers ORDER BY customer_name ASC");
$suppliers = mysqli_query($conn, "SELECT id, supplier_name, mobile FROM suppliers ORDER BY supplier_name ASC");

// Fetch recent vouchers log
$vouchers_log = mysqli_query($conn, "
    SELECT v.*, 
           CASE WHEN v.entity_type = 'Customer' THEN c.customer_name ELSE s.supplier_name END as entity_name
    FROM vouchers v
    LEFT JOIN customers c ON v.entity_type = 'Customer' AND v.entity_id = c.id
    LEFT JOIN suppliers s ON v.entity_type = 'Supplier' AND v.entity_id = s.id
    ORDER BY v.id DESC LIMIT 30");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vouchers Entry — AgriBiz</title>
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
.topbar{margin-bottom:24px;}
.topbar h1{font-size:22px;font-weight:700;} .topbar h1 span{color:var(--green);}

.layout{display:grid;grid-template-columns:380px 1fr;gap:24px;align-items:start;}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:24px;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;}
.card-header i{color:var(--green);}
.card-body{padding:20px;}

.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;}
select, input, textarea{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:#fff;font-size:13px;outline:none;transition:.2s;}
select:focus, input:focus, textarea:focus{border-color:var(--green);}
textarea{resize:vertical;min-height:60px;}

.btn{width:100%;padding:12px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;border:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;text-decoration:none;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:#16a34a;box-shadow:0 0 20px rgba(34,197,94,0.3);}

.alert{padding:14px;border-radius:12px;margin-bottom:20px;font-size:13px;font-weight:600;}
.alert.success{background:rgba(34,197,94,0.1);color:var(--green);border:1px solid rgba(34,197,94,.2);}
.alert.error{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,.2);}

.table{width:100%;border-collapse:collapse;}
.table th{padding:12px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:rgba(255,255,255,.02);}
.table td{padding:12px 16px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}

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
  <a href="receipts_payments.php" class="nav-item active"><i class="fas fa-money-bill-transfer"></i> Vouchers Entry</a>
  <a href="accounting_books.php" class="nav-item"><i class="fas fa-book"></i> Day/Cash Books</a>
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
    <h1>Accounting <span>Vouchers Entry</span></h1>
    <p style="font-size:12px;color:var(--muted);margin-top:4px;">Record customer receipts, supplier payments, and double-entry adjustments</p>
  </div>

  <?php if ($message): ?>
  <div class="alert <?php echo $msg_type; ?>"><i class="fas fa-info-circle"></i> <?php echo $message; ?></div>
  <?php endif; ?>

  <div class="layout">
    <!-- Left: Entry Card -->
    <div class="card">
      <div class="card-header"><i class="fas fa-edit"></i><h3>New Voucher Entry</h3></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-group">
            <label>Voucher Type</label>
            <select name="voucher_type" id="vType" onchange="toggleEntities()" required>
              <option value="Receipt">📥 Receipt (From Customer)</option>
              <option value="Payment">📤 Payment (To Supplier)</option>
            </select>
          </div>
          
          <div class="form-group" id="custGroup">
            <label>Customer Account</label>
            <select name="entity_id" id="vCustomer">
              <option value="">-- Select Customer --</option>
              <?php mysqli_data_seek($customers, 0); while($c = mysqli_fetch_assoc($customers)): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?> (<?php echo $c['mobile']; ?>)</option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="form-group" id="suppGroup" style="display:none;">
            <label>Supplier Account</label>
            <select name="entity_id" id="vSupplier" disabled>
              <option value="">-- Select Supplier --</option>
              <?php mysqli_data_seek($suppliers, 0); while($s = mysqli_fetch_assoc($suppliers)): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?> (<?php echo $s['mobile']; ?>)</option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Amount (₹)</label>
            <input type="number" step="0.01" name="amount" placeholder="0.00" required>
          </div>

          <div class="form-group">
            <label>Payment Method</label>
            <select name="payment_method" required>
              <option value="Cash">💵 Cash Hand</option>
              <option value="Bank">🏦 Bank Account / UPI</option>
            </select>
          </div>

          <div class="form-group">
            <label>Voucher Date</label>
            <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>">
          </div>

          <div class="form-group">
            <label>Narration / Description</label>
            <textarea name="narration" placeholder="Enter details about this transaction..."></textarea>
          </div>

          <button type="submit" name="create_voucher" class="btn btn-green"><i class="fas fa-save"></i> Save Voucher</button>
        </form>
      </div>
    </div>

    <!-- Right: Vouchers Log -->
    <div class="card">
      <div class="card-header"><i class="fas fa-history"></i><h3>Recent Voucher Transactions</h3></div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>Voucher No</th>
              <th>Type</th>
              <th>Account Details</th>
              <th>Method</th>
              <th>Amount</th>
              <th>Date</th>
              <th>Narration</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($vouchers_log && mysqli_num_rows($vouchers_log) > 0): while($v = mysqli_fetch_assoc($vouchers_log)): 
              $badge = ($v['voucher_type'] === 'Receipt') ? 'badge-green' : 'badge-purple';
              $method_badge = ($v['payment_method'] === 'Cash') ? 'badge-orange' : 'badge-blue';
            ?>
            <tr>
              <td style="font-weight:bold; color:var(--blue); letter-spacing:0.5px;"><?php echo $v['voucher_no']; ?></td>
              <td><span class="badge <?php echo $badge; ?>"><?php echo $v['voucher_type']; ?></span></td>
              <td><strong><?php echo htmlspecialchars($v['entity_name']); ?></strong> <span style="font-size:10px; color:var(--muted);">(<?php echo $v['entity_type']; ?>)</span></td>
              <td><span class="badge <?php echo $method_badge; ?>"><?php echo $v['payment_method']; ?></span></td>
              <td style="font-weight:700; color:<?php echo $v['voucher_type']==='Receipt'?'var(--green)':'var(--red)'; ?>;">
                ₹<?php echo number_format($v['amount'], 2); ?>
              </td>
              <td style="color:var(--muted); font-size:12px;"><?php echo date('d-M-Y', strtotime($v['date'])); ?></td>
              <td style="color:var(--muted); font-size:12px; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($v['narration']); ?>">
                <?php echo htmlspecialchars($v['narration']); ?>
              </td>
            </tr>
            <?php endwhile; else: ?>
            <tr>
              <td colspan="7" class="empty">
                <i class="fas fa-inbox" style="font-size:32px; display:block; margin-bottom:8px; opacity:0.3;"></i>
                No vouchers entered yet.
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function toggleEntities() {
  const type = document.getElementById('vType').value;
  const custGroup = document.getElementById('custGroup');
  const suppGroup = document.getElementById('suppGroup');
  const custSelect = document.getElementById('vCustomer');
  const suppSelect = document.getElementById('vSupplier');

  if (type === 'Receipt') {
    custGroup.style.display = 'block';
    suppGroup.style.display = 'none';
    custSelect.disabled = false;
    suppSelect.disabled = true;
    custSelect.required = true;
    suppSelect.required = false;
  } else {
    custGroup.style.display = 'none';
    suppGroup.style.display = 'block';
    custSelect.disabled = true;
    suppSelect.disabled = false;
    custSelect.required = false;
    suppSelect.required = true;
  }
}
</script>
</body>
</html>
