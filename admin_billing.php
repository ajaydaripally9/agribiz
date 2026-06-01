<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Billing Staff']);

$message = '';
$invoice_html = '';

// Handle POS Submission
if (isset($_POST['process_bill'])) {
    $customer_id = intval($_POST['customer_id']);
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $bill_type = mysqli_real_escape_string($conn, $_POST['bill_type'] ?? 'Cash');
    $items = $_POST['items']; // Array of [id => qty]

    if (!$customer_id || empty($items)) {
        $message = "Please select a customer and at least one product.";
    } else {
        // Fetch customer details
        $cust_stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cust_stmt, "i", $customer_id);
        mysqli_stmt_execute($cust_stmt);
        $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($cust_stmt));

        // Generate Invoice No
        $seq_result = mysqli_query($conn, "SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM orders");
        $seq_row = mysqli_fetch_assoc($seq_result);
        $seq = str_pad($seq_row['next_seq'], 4, '0', STR_PAD_LEFT);
        $invoice_no = 'BILL-' . date('Ymd') . '-' . $seq;

        $success = true;
        $total_sum = 0;
        $processed_items = [];

        foreach ($items as $f_id => $qty) {
            $qty = intval($qty);
            if ($qty <= 0) continue;

            // Check stock and price
            $fert_stmt = mysqli_prepare($conn, "SELECT * FROM fertilizers WHERE id = ?");
            mysqli_stmt_bind_param($fert_stmt, "i", $f_id);
            mysqli_stmt_execute($fert_stmt);
            $fert = mysqli_fetch_assoc(mysqli_stmt_get_result($fert_stmt));

            if (!$fert || $fert['quantity'] < $qty) {
                $success = false;
                $message = "Insufficient stock for " . ($fert ? $fert['fertilizer_name'] : "Product ID $f_id");
                break;
            }

            $price = $fert['price'];
            $item_total = $price * $qty;
            $total_sum += $item_total;

            $processed_items[] = [
                'id' => $f_id,
                'name' => $fert['fertilizer_name'],
                'qty' => $qty,
                'price' => $price,
                'total' => $item_total,
                'batch' => $fert['batch_no'],
                'mfg' => $fert['mfg_date'],
                'expiry' => $fert['expiry_date']
            ];
        }

        if ($success && !empty($processed_items)) {
            mysqli_begin_transaction($conn);
            try {
                foreach ($processed_items as $item) {
                    // Deduct stock
                    mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity - {$item['qty']} WHERE id = {$item['id']}");
                    
                    // Insert Order (Auto-Delivered)
                    $ins_order = mysqli_prepare($conn, "INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Delivered', ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins_order, "isisidsdssss", $customer_id, $customer['customer_name'], $item['id'], $item['name'], $item['qty'], $item['total'], $invoice_no, $paid_amount, $bill_type, $item['batch'], $item['mfg'], $item['expiry']);
                    mysqli_stmt_execute($ins_order);

                    // Insert Sale
                    $ins_sale = mysqli_prepare($conn, "INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins_sale, "ssidsdssss", $customer['customer_name'], $item['name'], $item['qty'], $item['total'], $invoice_no, $paid_amount, $bill_type, $item['batch'], $item['mfg'], $item['expiry']);
                    mysqli_stmt_execute($ins_sale);
                }
                mysqli_commit($conn);
                header("Location: view_invoice.php?invoice_no=" . urlencode($invoice_no) . "&print=1");
                exit();
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Error processing transaction: " . $e->getMessage();
            }
        }
    }
}

// Fetch Customers and Products for dropdowns
$customers = mysqli_query($conn, "SELECT id, customer_name, mobile FROM customers ORDER BY customer_name ASC");
$products = mysqli_query($conn, "SELECT id, barcode, fertilizer_name, price, quantity, batch_no, mfg_date, expiry_date FROM fertilizers WHERE quantity > 0 ORDER BY fertilizer_name ASC");
$catalog_data = [];
while($p = mysqli_fetch_assoc($products)) {
    if (!empty($p['barcode'])) {
        $catalog_data[$p['barcode']] = [
            'id' => intval($p['id']),
            'fertilizer_name' => $p['fertilizer_name'],
            'price' => floatval($p['price']),
            'quantity' => intval($p['quantity']),
            'batch_no' => $p['batch_no'] ?? '',
            'mfg_date' => $p['mfg_date'] ?? '',
            'expiry_date' => $p['expiry_date'] ?? ''
        ];
    }
}
mysqli_data_seek($products, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offline Billing (POS) — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/html5-qrcode"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

.sidebar{width:220px;min-height:100vh;background:var(--bg);border-right:1px solid var(--border);position:fixed;left:0;top:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo h2{font-size:16px;font-weight:700;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;border-left:3px solid transparent;transition:all .2s;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;}

.main{margin-left:220px;flex:1;padding:24px;display:grid;grid-template-columns:1.2fr 1fr;gap:24px;}
.billing-section{display:flex;flex-direction:column;gap:20px;}

.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:12px;}
.card-header h3{font-size:15px;font-weight:600;}

/* Form elements */
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;}
select, input{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;outline:none;transition:.2s;}
select:focus, input:focus{border-color:var(--green);}

/* Product List */
.product-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:12px;max-height:400px;overflow-y:auto;padding-right:5px;}
.p-item{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:.2s;text-align:center;}
.p-item:hover{border-color:var(--green);transform:translateY(-2px);}
.p-item .name{font-size:13px;font-weight:700;margin-bottom:4px;display:block;}
.p-item .price{font-size:12px;color:var(--green);font-weight:600;}
.p-item .stock{font-size:10px;color:var(--muted);display:block;margin-top:2px;}

/* Cart Table */
.cart-table{width:100%;border-collapse:collapse;}
.cart-table th{text-align:left;font-size:11px;color:var(--muted);padding:8px;border-bottom:1px solid var(--border);}
.cart-table td{padding:12px 8px;border-bottom:1px solid var(--border);font-size:13px;}
.qty-input{width:60px !important;padding:5px 8px !important;text-align:center;}
.remove-btn{color:var(--red);cursor:pointer;font-size:14px;}

/* Summary */
.summary-row{display:flex;justify-content:space-between;padding:8px 0;font-size:14px;}
.total-row{font-size:18px;font-weight:800;color:var(--green);border-top:1px dashed var(--border);margin-top:10px;padding-top:10px;}

.btn-process{width:100%;background:var(--green);color:#fff;border:none;padding:14px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;margin-top:20px;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-process:hover{background:#16a34a;box-shadow:0 0 20px rgba(34,197,94,0.2);}

.alert{padding:12px;border-radius:8px;background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,0.2);margin-bottom:16px;font-size:13px;font-weight:600;}

::-webkit-scrollbar{width:6px;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px;}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><h2>🌱 AgriBiz</h2><p>Admin POS</p></div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <a href="admin_billing.php" class="nav-item active"><i class="fas fa-file-invoice-dollar"></i> Offline Billing</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Online Orders</a>
  <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Inventory</a>
  <div style="margin-top:auto;padding:16px;border-top:1px solid var(--border);">
    <a href="logout.php" style="color:var(--red);text-decoration:none;font-size:13px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<div class="main">
  <!-- Left: Product Selection -->
  <div class="billing-section">
    <div class="card" style="margin-bottom: 20px;">
      <div class="card-header">
        <h3><i class="fas fa-search"></i> Select Products</h3>
        <input type="text" id="prodSearch" placeholder="Search by name..." oninput="filterProds(this.value)" style="width:200px;padding:6px 12px;font-size:12px;">
      </div>
      
      <div style="padding:14px 20px 0; display:flex; gap:10px;">
        <input type="text" id="barcodeInput" placeholder="⚡ Scan Barcode (Gun/Type)..." style="flex:1; background:var(--card2); border:1px dashed var(--orange); padding:8px 12px; font-size:13px; font-weight:700; color:#fff; border-radius:8px; outline:none;" autocomplete="off">
        <button type="button" id="startBillingScan" style="background:var(--card2); border:1px solid var(--border); border-radius:8px; width:44px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--orange);" title="Scan using Camera"><i class="fas fa-barcode"></i></button>
      </div>

      <div id="billingReader" style="display:none; margin: 12px 20px 0; background:#000; border-radius:10px; overflow:hidden; border:2px dashed var(--orange); aspect-ratio:4/3;"></div>
      
      <!-- Barcode Simulator Testing Panel -->
      <div id="barcodeSimulator" style="margin: 12px 20px 12px; background:var(--card2); border:1px solid var(--border); border-radius:10px; padding:15px; text-align:center;">
        <p style="font-size:11px; font-weight:700; color:var(--orange); margin-bottom:10px; text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-barcode"></i> Barcode Testing Panel (Simulator)</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
          <button type="button" onclick="simulateScan('89012345')" style="background:rgba(34,197,94,0.15); color:var(--green); border:1px solid rgba(34,197,94,0.3); border-radius:8px; padding:8px; font-size:11px; font-weight:700; cursor:pointer;">Scan Urea</button>
          <button type="button" onclick="simulateScan('89012346')" style="background:rgba(59,130,246,0.15); color:var(--blue); border:1px solid rgba(59,130,246,0.3); border-radius:8px; padding:8px; font-size:11px; font-weight:700; cursor:pointer;">Scan DAP</button>
          <button type="button" onclick="simulateScan('89012347')" style="background:rgba(245,158,11,0.15); color:var(--orange); border:1px solid rgba(245,158,11,0.3); border-radius:8px; padding:8px; font-size:11px; font-weight:700; cursor:pointer;">Scan Potash</button>
          <button type="button" onclick="simulateScan('89012348')" style="background:rgba(168,85,247,0.15); color:var(--purple); border:1px solid rgba(168,85,247,0.3); border-radius:8px; padding:8px; font-size:11px; font-weight:700; cursor:pointer;">Scan Compost</button>
        </div>
      </div>
      <div class="product-grid" id="productGrid">
        <?php while($p = mysqli_fetch_assoc($products)): ?>
        <div class="p-item" onclick="addToCart(<?php echo htmlspecialchars(json_encode($p)); ?>)">
          <span class="name"><?php echo htmlspecialchars($p['fertilizer_name']); ?></span>
          <span class="price">₹<?php echo number_format($p['price'], 2); ?></span>
          <span class="stock">Stock: <?php echo $p['quantity']; ?></span>
        </div>
        <?php endwhile; ?>
      </div>
    </div>
  </div>

  <!-- Right: Cart & Customer -->
  <form method="POST" class="billing-section">
    <?php if($message): ?><div class="alert"><?php echo $message; ?></div><?php endif; ?>
    
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-user"></i> Customer Details</h3></div>
      <div class="form-group">
        <label>Select Customer</label>
        <select name="customer_id" required>
          <option value="">-- Choose Customer --</option>
          <?php while($c = mysqli_fetch_assoc($customers)): ?>
          <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?> (<?php echo $c['mobile']; ?>)</option>
          <?php endwhile; ?>
        </select>
        <div style="margin-top:12px;">
            <label>Bill Type</label>
            <div style="display:flex; gap:10px;">
                <label style="flex:1; background:var(--card2); border:1px solid var(--border); padding:8px; border-radius:8px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="radio" name="bill_type" value="Cash" checked style="width:auto;"> Cash Bill
                </label>
                <label style="flex:1; background:var(--card2); border:1px solid var(--border); padding:8px; border-radius:8px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="radio" name="bill_type" value="Credit" style="width:auto;"> Credit Bill
                </label>
            </div>
        </div>
        <p style="font-size:11px;color:var(--muted);margin-top:6px;"><i class="fas fa-plus-circle"></i> New customer? <a href="customers.php" style="color:var(--blue);text-decoration:none;">Register here</a></p>
      </div>
    </div>

    <div class="card" style="flex:1;">
      <div class="card-header"><h3><i class="fas fa-shopping-cart"></i> Invoice Items</h3></div>
      <div style="max-height:300px;overflow-y:auto;margin-bottom:16px;">
        <table class="cart-table" id="cartTable">
          <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Total</th><th></th></tr></thead>
          <tbody id="cartBody">
            <!-- Items added via JS -->
          </tbody>
        </table>
      </div>
      
      <div id="summary">
        <div class="summary-row"><span>Subtotal (Excl. GST)</span><span id="subTotal">₹0.00</span></div>
        <div class="summary-row"><span>GST (18%)</span><span id="gstTotal">₹0.00</span></div>
        <div class="summary-row total-row"><span>Grand Total (Incl. GST)</span><span id="grandTotal">₹0.00</span></div>
        <div class="form-group" style="margin-top:15px;">
            <label style="color:var(--green)">Amount Received Today (₹)</label>
            <input type="number" step="0.01" name="paid_amount" id="paidAmount" placeholder="0.00" style="border-color:var(--green); font-weight:bold; font-size:16px;">
        </div>
      </div>

      <button type="submit" name="process_bill" class="btn-process" id="submitBtn" disabled>
        <i class="fas fa-print"></i> Generate & Print Bill
      </button>
    </div>
  </form>
</div>

<script>
let cart = {};

function addToCart(p) {
  if (cart[p.id]) {
    if (cart[p.id].qty < p.quantity) cart[p.id].qty++;
  } else {
    cart[p.id] = { name: p.fertilizer_name, price: p.price, qty: 1, max: p.quantity };
  }
  renderCart();
}

function updateQty(id, val) {
  val = parseInt(val);
  if (val > cart[id].max) val = cart[id].max;
  if (val < 1) val = 1;
  cart[id].qty = val;
  renderCart();
}

function removeFromCart(id) {
  delete cart[id];
  renderCart();
}

function renderCart() {
  const body = document.getElementById('cartBody');
  body.innerHTML = '';
  let subtotal = 0;
  let hasItems = false;

  for (let id in cart) {
    hasItems = true;
    const item = cart[id];
    const total = item.price * item.qty;
    subtotal += total;
    body.innerHTML += `
      <tr>
        <td>${item.name}</td>
        <td>₹${parseFloat(item.price).toFixed(2)}</td>
        <td>
          <input type="number" name="items[${id}]" value="${item.qty}" class="qty-input" onchange="updateQty(${id}, this.value)">
        </td>
        <td>₹${total.toFixed(2)}</td>
        <td><i class="fas fa-times remove-btn" onclick="removeFromCart(${id})"></i></td>
      </tr>
    `;
  }

  const base_price = subtotal / 1.18;
  const gst = subtotal - base_price;
  const grand = subtotal;

  document.getElementById('subTotal').textContent = '₹' + base_price.toFixed(2);
  document.getElementById('gstTotal').textContent = '₹' + gst.toFixed(2);
  document.getElementById('grandTotal').textContent = '₹' + grand.toFixed(2);
  document.getElementById('submitBtn').disabled = !hasItems;
}

function filterProds(q) {
  const prods = document.querySelectorAll('.p-item');
  prods.forEach(p => {
    const name = p.querySelector('.name').textContent.toLowerCase();
    p.style.display = name.includes(q.toLowerCase()) ? 'block' : 'none';
  });
}

// --- POS Barcode Gun & Camera Scanning Logic ---
const productCatalog = <?php echo json_encode($catalog_data); ?>;
const barcodeInput = document.getElementById('barcodeInput');

barcodeInput.addEventListener('input', function() {
  const code = this.value.trim();
  // Standard product barcodes are EAN-8 (8 digits) or longer
  if (code.length >= 8) {
    handlePOSBarcode(code);
  }
});

barcodeInput.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    handlePOSBarcode(this.value.trim());
  }
});

function handlePOSBarcode(code) {
  if (!code) return;
  const p = productCatalog[code];
  if (p) {
    // Add product to POS billing cart
    addToCart({
      id: p.id,
      fertilizer_name: p.fertilizer_name,
      price: p.price,
      quantity: p.quantity
    });
    // Success feedback
    barcodeInput.value = '';
    barcodeInput.style.borderColor = 'var(--green)';
    barcodeInput.style.backgroundColor = 'rgba(34,197,94,0.08)';
    setTimeout(() => {
      barcodeInput.style.borderColor = 'var(--orange)';
      barcodeInput.style.backgroundColor = 'transparent';
    }, 800);
  } else {
    // Failure feedback (flash red)
    barcodeInput.style.borderColor = 'var(--red)';
    barcodeInput.style.backgroundColor = 'rgba(239,68,68,0.08)';
    setTimeout(() => {
      barcodeInput.style.borderColor = 'var(--orange)';
      barcodeInput.style.backgroundColor = 'transparent';
    }, 800);
  }
}

let billingQrCode;
document.getElementById('startBillingScan').addEventListener('click', function() {
  const readerDiv = document.getElementById('billingReader');
  const scanBtn = this;
  if (billingQrCode) {
    stopBillingScanner();
    return;
  }
  
  readerDiv.style.display = 'block';
  scanBtn.style.color = 'var(--green)';
  scanBtn.style.borderColor = 'var(--green)';
  
  billingQrCode = new Html5Qrcode("billingReader");
  const config = {
    fps: 15,
    qrbox: { width: 280, height: 160 },
    formatsToSupport: [
      Html5QrcodeSupportedFormats.EAN_13,
      Html5QrcodeSupportedFormats.EAN_8,
      Html5QrcodeSupportedFormats.CODE_128,
      Html5QrcodeSupportedFormats.CODE_39,
      Html5QrcodeSupportedFormats.UPC_A,
      Html5QrcodeSupportedFormats.UPC_E,
      Html5QrcodeSupportedFormats.QR_CODE
    ]
  };
  
  billingQrCode.start(
    { facingMode: "environment" }, 
    config,
    (decodedText) => {
      handlePOSBarcode(decodedText);
      stopBillingScanner();
    },
    (errorMessage) => {}
  ).catch(err => {
    alert("Camera error: " + err);
    stopBillingScanner();
  });
});

function stopBillingScanner() {
  const readerDiv = document.getElementById('billingReader');
  const scanBtn = document.getElementById('startBillingScan');
  if (billingQrCode) {
    billingQrCode.stop().then(() => {
      billingQrCode = null;
      readerDiv.style.display = 'none';
      scanBtn.style.color = 'var(--orange)';
      scanBtn.style.borderColor = 'var(--border)';
    });
  }
}

function simulateScan(code) {
  const input = document.getElementById('barcodeInput');
  input.value = code;
  handlePOSBarcode(code);
}
</script>

</body>
</html>
