<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

if(isset($_POST['submit'])){
    $name = $_POST['supplier_name'];
    $mobile = $_POST['mobile'];
    $address = $_POST['address'];

    $query = "INSERT INTO suppliers(supplier_name, mobile, address) VALUES('$name','$mobile','$address')";

    mysqli_query($conn, $query);

    echo "Supplier Added Successfully";
}

if(isset($_POST['purchase'])){
    $supplier_id = $_POST['supplier'];
    $fertilizer_id = $_POST['fertilizer'];
    $quantity = $_POST['quantity'];
    $cost = $_POST['cost'];

    // Get supplier name
    $sup_details = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM suppliers WHERE id = $supplier_id"));
    $supplier_name = $sup_details['supplier_name'];

    // Get fertilizer name
    $fert_details = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fertilizers WHERE id = $fertilizer_id"));
    $fertilizer_name = $fert_details['fertilizer_name'];

    $query = "INSERT INTO purchases(supplier_name, fertilizer_name, quantity, cost, purchase_date)
              VALUES('$supplier_name','$fertilizer_name','$quantity','$cost',CURDATE())";

    mysqli_query($conn, $query);

    // Update stock
    $update_query = "UPDATE fertilizers SET quantity = quantity + $quantity WHERE id = $fertilizer_id";
    mysqli_query($conn, $update_query);

    echo "Purchase Added Successfully";
}

$query = "SELECT * FROM suppliers";
$result = mysqli_query($conn, $query);

// Get suppliers and fertilizers for purchase
$sup_query = "SELECT * FROM suppliers";
$sup_result = mysqli_query($conn, $sup_query);
$fert_query = "SELECT * FROM fertilizers";
$fert_result = mysqli_query($conn, $fert_query);
?>

<!DOCTYPE html>
<html>
<head>
<title>Suppliers</title>
<link rel="stylesheet" href="style.css">
<style>
.form-section {
    background-color: #ecf0f1;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}
.message {
    background-color: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
    border-left: 5px solid #28a745;
}
</style>
</head>
<body>
<div class="container">
<header>
<h1>🚚 Supplier Management</h1>
</header>

<?php if(isset($_POST['submit']) && $_POST['submit']) { echo '<div class="message">✓ Supplier Added Successfully</div>'; } ?>
<?php if(isset($_POST['purchase']) && $_POST['purchase']) { echo '<div class="message">✓ Purchase Added Successfully</div>'; } ?>

<h2>Add New Supplier</h2>
<div class="form-section">
<form method="POST">
<input type="text" name="supplier_name" placeholder="Supplier Name" required>
<input type="text" name="mobile" placeholder="Mobile Number" required>
<textarea name="address" placeholder="Address" required></textarea>
<button type="submit" name="submit">➕ Add Supplier</button>
</form>
</div>

<h2>Record Purchase</h2>
<div class="form-section">
<form method="POST">
<select name="supplier" required>
<option value="">Select Supplier</option>
<?php while($sup = mysqli_fetch_assoc($sup_result)) { ?>
<option value="<?php echo $sup['id']; ?>"><?php echo $sup['supplier_name']; ?></option>
<?php } ?>
</select>
<select name="fertilizer" required>
<option value="">Select Fertilizer</option>
<?php while($fert = mysqli_fetch_assoc($fert_result)) { ?>
<option value="<?php echo $fert['id']; ?>"><?php echo $fert['fertilizer_name']; ?></option>
<?php } ?>
</select>
<input type="number" name="quantity" placeholder="Quantity" required>
<input type="number" step="0.01" name="cost" placeholder="Cost per unit" required>
<button type="submit" name="purchase">📥 Add Purchase</button>
</form>
</div>

<h2>Supplier List</h2>
<table>
<tr>
<th style="width:5%;">ID</th>
<th style="width:25%;">Name</th>
<th style="width:20%;">Mobile</th>
<th style="width:35%;">Address</th>
<th style="width:15%;">Actions</th>
</tr>

<?php while($row = mysqli_fetch_assoc($result)) { ?>
<tr>
<td><strong><?php echo $row['id']; ?></strong></td>
<td><?php echo $row['supplier_name']; ?></td>
<td><?php echo $row['mobile']; ?></td>
<td><?php echo $row['address']; ?></td>
<td>
<a href="update_supplier.php?id=<?php echo $row['id']; ?>" class="edit">✏️ Edit</a>
<a href="delete_supplier.php?id=<?php echo $row['id']; ?>" class="delete" onclick="return confirm('Are you sure?')">🗑️ Delete</a>
</td>
</tr>
<?php } ?>

</table>

<div class="nav">
<a href="dashboard.php">← Back to Dashboard</a>
</div>

</div>
</body>
</html>