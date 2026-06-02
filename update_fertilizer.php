<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Billing Staff']);

$id = $_GET['id'];
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fertilizers WHERE id = $id"));

if(isset($_POST['update'])){
    $name = mysqli_real_escape_string($conn, $_POST['fertilizer_name']);
    $company = mysqli_real_escape_string($conn, $_POST['company_name']);
    $quantity = intval($_POST['quantity']);
    $price = floatval($_POST['price']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $batch = mysqli_real_escape_string($conn, $_POST['batch_no'] ?? '');
    $mfg = !empty($_POST['mfg_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['mfg_date']) . "'" : "NULL";
    $expiry = !empty($_POST['expiry_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['expiry_date']) . "'" : "NULL";
    $pur_price = floatval($_POST['purchase_price'] ?? 0);
    $hsn_code = mysqli_real_escape_string($conn, $_POST['hsn_code'] ?? '');
    $reorder_level = intval($_POST['reorder_level'] ?? 10);

    $query = "UPDATE fertilizers SET fertilizer_name='$name', company_name='$company', quantity='$quantity', price='$price', category='$category', batch_no='$batch', mfg_date=$mfg, expiry_date=$expiry, purchase_price='$pur_price', hsn_code='$hsn_code', reorder_level='$reorder_level' WHERE id=$id";
    mysqli_query($conn, $query);
    header('Location: view_fertilizer.php');
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Update Fertilizer</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Update Product</h2>
<form method="POST">
<select name="category" required style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #ccc;">
    <option value="">Select Category</option>
    <option value="Seeds" <?php if($row['category'] == 'Seeds') echo 'selected'; ?>>🌱 Seeds</option>
    <option value="Fertilizers" <?php if($row['category'] == 'Fertilizers') echo 'selected'; ?>>🧪 Fertilizers</option>
    <option value="Pesticides" <?php if($row['category'] == 'Pesticides') echo 'selected'; ?>>🚿 Pesticides</option>
    <option value="Organic" <?php if($row['category'] == 'Organic') echo 'selected'; ?>>🍃 Organic</option>
    <option value="Tools" <?php if($row['category'] == 'Tools') echo 'selected'; ?>>⚙️ Tools</option>
</select><br>
<input type="text" name="fertilizer_name" value="<?php echo htmlspecialchars($row['fertilizer_name']); ?>" required><br><br>
<input type="text" name="company_name" value="<?php echo htmlspecialchars($row['company_name']); ?>" required><br><br>
<input type="number" name="quantity" value="<?php echo htmlspecialchars($row['quantity']); ?>" required><br><br>
<label>Reorder Alert Level:</label><br><input type="number" name="reorder_level" value="<?php echo htmlspecialchars($row['reorder_level'] ?? '10'); ?>" required><br><br>
<input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($row['price']); ?>" required><br><br>
<label>Purchase Price:</label><br><input type="number" step="0.01" name="purchase_price" value="<?php echo htmlspecialchars($row['purchase_price'] ?? '0'); ?>"><br><br>
<label>HSN Code:</label><br><input type="text" name="hsn_code" value="<?php echo htmlspecialchars($row['hsn_code'] ?? ''); ?>"><br><br>
<label>Batch No:</label><br><input type="text" name="batch_no" value="<?php echo htmlspecialchars($row['batch_no'] ?? ''); ?>"><br><br>
<label>Mfg Date:</label><br><input type="date" name="mfg_date" value="<?php echo htmlspecialchars($row['mfg_date'] ?? ''); ?>"><br><br>
<label>Expiry Date:</label><br><input type="date" name="expiry_date" value="<?php echo htmlspecialchars($row['expiry_date'] ?? ''); ?>"><br><br>
<button type="submit" name="update" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Update Product</button>
</form>
<a href="view_fertilizer.php">Back</a>
</body>
</html>