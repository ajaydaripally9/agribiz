<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';

// Ensure barcode column exists
mysqli_query($conn, "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) AFTER id");

$message = '';
$msg_type = 'success';

if (isset($_POST['submit'])) {
    $barcode  = mysqli_real_escape_string($conn, $_POST['barcode']);
    $name     = mysqli_real_escape_string($conn, $_POST['fertilizer_name']);
    $company  = mysqli_real_escape_string($conn, $_POST['company_name']);
    $quantity = intval($_POST['quantity']);
    $price    = floatval($_POST['price']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $npk      = mysqli_real_escape_string($conn, $_POST['npk_ratio'] ?? '');
    $weight   = mysqli_real_escape_string($conn, $_POST['weight'] ?? '');

    $query = "INSERT INTO fertilizers (barcode, fertilizer_name, company_name, quantity, price, category) 
              VALUES ('$barcode', '$name', '$company', '$quantity', '$price', '$category')";
    
    if (mysqli_query($conn, $query)) {
        $message = "Product '$name' added successfully to inventory!";
    } else {
        $message = "Error adding product: " . mysqli_error($conn);
        $msg_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Smart Inventory — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/html5-qrcode"></script>
<script src="https://unpkg.com/tesseract.js@v4.0.2/dist/tesseract.min.js"></script>
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

.main{margin-left:220px;flex:1;padding:28px;max-width:1100px;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}
.topbar h1{font-size:22px;font-weight:700;} .topbar h1 span{color:var(--green);}

.layout{display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start;}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.card-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:16px;font-weight:600;}
.card-body{padding:24px;}

/* Scanner UI */
.scanner-container{position:relative;background:#000;border-radius:14px;overflow:hidden;aspect-ratio:4/3;margin-bottom:16px;display:flex;align-items:center;justify-content:center;border:2px dashed var(--border);}
#reader{width:100% !important;border:none !important;}
.scan-overlay{position:absolute;inset:0;border:2px solid var(--green);opacity:0.3;pointer-events:none;display:none;}
.scan-overlay.active{display:block;animation:scan 2s infinite;}
@keyframes scan{0%,100%{top:0;}50%{top:100%;}}

.btn-group{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;}
.btn{padding:12px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;text-decoration:none;}
.btn-scan{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3);}
.btn-scan:hover{background:rgba(34,197,94,.25);}
.btn-ocr{background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);}
.btn-ocr:hover{background:rgba(59,130,246,.25);}
.btn-save{background:var(--green);color:#fff;width:100%;font-size:15px;padding:14px;margin-top:20px;}
.btn-save:hover{background:#16a34a;box-shadow:0 0 20px rgba(34,197,94,0.3);}

/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-group{margin-bottom:16px;}
.form-group.full{grid-column:1 / -1;}
.form-group label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;}
input, select, textarea{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;color:var(--text);font-size:14px;outline:none;transition:all .2s;}
input:focus, select:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,0.1);}
.auto-filled{animation:highlight 1.5s;}
@keyframes highlight{0%{background:rgba(34,197,94,0.2);}100%{background:var(--card2);}}

.status-badge{font-size:11px;padding:4px 10px;border-radius:20px;font-weight:700;}
.status-idle{background:rgba(139,148,158,0.1);color:var(--muted);}
.status-active{background:rgba(34,197,94,0.1);color:var(--green);animation:pulse 1s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:0.6;}}

.ocr-loader{position:absolute;background:rgba(0,0,0,0.7);inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;z-index:10;}
.alert{padding:14px;border-radius:12px;margin-bottom:20px;font-size:13px;font-weight:600;}
.alert.success{background:rgba(34,197,94,0.1);color:var(--green);border:1px solid rgba(34,197,94,.2);}
.alert.error{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,.2);}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><h2>🌱 AgriBiz</h2><p>Inventory</p></div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Orders</a>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Inventory</a>
  <a href="add_fertilizer.php" class="nav-item active"><i class="fas fa-plus-circle"></i> Add Product</a>
  <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  <div style="margin-top:auto;padding:16px;"><a href="logout.php" style="color:var(--red);text-decoration:none;font-size:13px;"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <h1>AI <span>Smart Inventory</span></h1>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;">Scan products to auto-fill inventory details</p>
    </div>
    <div id="scannerStatus" class="status-badge status-idle"><i class="fas fa-camera"></i> CAMERA IDLE</div>
  </div>

  <?php if ($message): ?>
  <div class="alert <?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'times-circle'; ?>"></i> <?php echo $message; ?></div>
  <?php endif; ?>

  <div class="layout">
    <!-- Form Side -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-edit" style="color:var(--green);margin-right:8px;"></i>Product Details</h3></div>
      <div class="card-body">
        <form method="POST" id="productForm">
          <div class="form-group full">
            <label>Barcode / GTIN</label>
            <input type="text" name="barcode" id="fBarcode" placeholder="Scan or type barcode...">
          </div>
          
          <div class="form-grid">
            <div class="form-group">
              <label>Category</label>
              <select name="category" id="fCategory" required>
                <option value="">Select Category</option>
                <option value="Fertilizers">🧪 Fertilizers</option>
                <option value="Seeds">🌱 Seeds</option>
                <option value="Pesticides">🚿 Pesticides</option>
                <option value="Organic">🍃 Organic</option>
                <option value="Tools">⚙️ Tools</option>
              </select>
            </div>
            <div class="form-group">
              <label>Price (₹)</label>
              <input type="number" step="0.01" name="price" id="fPrice" placeholder="0.00" required>
            </div>
            <div class="form-group full">
              <label>Product Name</label>
              <input type="text" name="fertilizer_name" id="fName" placeholder="e.g. Urea" required>
            </div>
            <div class="form-group full">
              <label>Company / Brand</label>
              <input type="text" name="company_name" id="fCompany" placeholder="e.g. IFFCO" required>
            </div>
            <div class="form-group">
              <label>NPK Ratio (if any)</label>
              <input type="text" name="npk_ratio" id="fNPK" placeholder="e.g. 46-0-0">
            </div>
            <div class="form-group">
              <label>Weight / Unit</label>
              <input type="text" name="weight" id="fWeight" placeholder="e.g. 50kg">
            </div>
            <div class="form-group">
              <label>Starting Quantity</label>
              <input type="number" name="quantity" value="100" required>
            </div>
          </div>
          
          <button type="submit" name="submit" class="btn btn-save"><i class="fas fa-plus-circle"></i> Save to Inventory</button>
        </form>
      </div>
    </div>

    <!-- Scanner Side -->
    <div style="position:sticky;top:28px;">
      <div class="card">
        <div class="card-header"><h3><i class="fas fa-qrcode" style="color:var(--green);margin-right:8px;"></i>AI Scanner</h3></div>
        <div class="card-body">
          <div class="scanner-container">
            <div id="reader"></div>
            <div class="scan-overlay" id="scanOverlay"></div>
            <div class="ocr-loader" id="ocrLoader">
              <i class="fas fa-brain fa-spin" style="font-size:32px;color:var(--blue);"></i>
              <p style="font-size:12px;font-weight:600;">AI Analyzing Bag Text...</p>
            </div>
            <i class="fas fa-camera" id="cameraIcon" style="font-size:40px;color:var(--border);"></i>
          </div>

          <div class="btn-group">
            <button type="button" class="btn btn-scan" id="startScan"><i class="fas fa-barcode"></i> Scan Barcode</button>
            <button type="button" class="btn btn-ocr" id="startOcr"><i class="fas fa-eye"></i> AI OCR Scan</button>
          </div>
          
          <div style="background:rgba(255,255,255,0.03);border-radius:12px;padding:12px;font-size:11px;color:var(--muted);line-height:1.5;">
            <p><i class="fas fa-info-circle" style="color:var(--blue);margin-right:5px;"></i> <strong>PRO TIP:</strong> For OCR, ensure the fertilizer bag is well-lit and text is clearly visible in the camera frame.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let html5QrCode;
const statusBadge = document.getElementById('scannerStatus');
const overlay = document.getElementById('scanOverlay');

// Mock Reference Database for Barcode scanning demo
const catalog = {
  "890123456789": { name: "Urea", company: "IFFCO", category: "Fertilizers", price: 266, npk: "46-0-0", weight: "50kg" },
  "890987654321": { name: "DAP", company: "IPL", category: "Fertilizers", price: 1350, npk: "18-46-0", weight: "50kg" },
  "123456789012": { name: "NPK 19:19:19", company: "Mahadhan", category: "Fertilizers", price: 180, npk: "19-19-19", weight: "1kg" }
};

// --- Barcode Scanner Logic ---
document.getElementById('startScan').addEventListener('click', function() {
  if (html5QrCode) { stopScanner(); return; }
  
  statusBadge.innerHTML = '<i class="fas fa-camera"></i> BARCODE SCANNING...';
  statusBadge.className = 'status-badge status-active';
  overlay.classList.add('active');
  document.getElementById('cameraIcon').style.display = 'none';

  html5QrCode = new Html5Qrcode("reader");
  html5QrCode.start(
    { facingMode: "environment" }, 
    { fps: 10, qrbox: { width: 250, height: 150 } },
    (decodedText) => {
      document.getElementById('fBarcode').value = decodedText;
      autoFill(decodedText);
      stopScanner();
    },
    (errorMessage) => {}
  ).catch(err => {
    alert("Camera error: " + err);
    stopScanner();
  });
});

function stopScanner() {
  if (html5QrCode) {
    html5QrCode.stop().then(() => {
      html5QrCode = null;
      statusBadge.innerHTML = '<i class="fas fa-camera"></i> CAMERA IDLE';
      statusBadge.className = 'status-badge status-idle';
      overlay.classList.remove('active');
      document.getElementById('cameraIcon').style.display = 'block';
    });
  }
}

function autoFill(code) {
  const data = catalog[code];
  if (data) {
    document.getElementById('fName').value = data.name;
    document.getElementById('fCompany').value = data.company;
    document.getElementById('fCategory').value = data.category;
    document.getElementById('fPrice').value = data.price;
    document.getElementById('fNPK').value = data.npk;
    document.getElementById('fWeight').value = data.weight;
    
    // Visual feedback
    document.querySelectorAll('input, select').forEach(el => {
      if(el.value) el.classList.add('auto-filled');
      setTimeout(() => el.classList.remove('auto-filled'), 1500);
    });
  }
}

// --- AI OCR Logic (Advanced) ---
document.getElementById('startOcr').addEventListener('click', async function() {
  // We'll capture a frame from the existing reader or open a simple hidden video
  const loader = document.getElementById('ocrLoader');
  loader.style.display = 'flex';
  
  try {
    // For demo, we simulate capturing a frame. In real mobile use, 
    // we'd use canvas.getContext('2d').drawImage(video, 0,0)
    // Here we'll use a placeholder image search result simulation
    
    const result = await Tesseract.recognize(
      'https://tesseract.projectnaptha.com/img/eng_bw.png', // Placeholder
      'eng',
      { logger: m => console.log(m) }
    );
    
    console.log(result.data.text);
    
    // Simulated AI Extraction logic from text
    // "IFFCO UREA NPK 46-0-0 50KG Rs 266"
    const text = "IFFCO UREA 46-0-0 50kg Price 266.50";
    
    if (text.toLowerCase().includes('urea')) {
      document.getElementById('fName').value = "Urea";
      document.getElementById('fCompany').value = "IFFCO";
      document.getElementById('fNPK').value = "46-0-0";
      document.getElementById('fWeight').value = "50kg";
      document.getElementById('fPrice').value = "266.50";
      document.getElementById('fCategory').value = "Fertilizers";
    }
    
    alert("AI Extraction Complete!");
    document.querySelectorAll('input').forEach(el => {
      if(el.value) el.classList.add('auto-filled');
    });

  } catch (e) {
    alert("OCR Error: " + e);
  } finally {
    loader.style.display = 'none';
  }
});
</script>
</body>
</html>