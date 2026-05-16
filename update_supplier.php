<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$id = $_GET['id'];
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM suppliers WHERE id = $id"));

if(isset($_POST['update'])){
    $name = $_POST['supplier_name'];
    $mobile = $_POST['mobile'];
    $address = $_POST['address'];

    $query = "UPDATE suppliers SET supplier_name='$name', mobile='$mobile', address='$address' WHERE id=$id";
    mysqli_query($conn, $query);
    header('Location: suppliers.php');
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Update Supplier</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Update Supplier</h2>
<form method="POST">
<input type="text" name="supplier_name" value="<?php echo $row['supplier_name']; ?>" required><br><br>
<input type="text" name="mobile" value="<?php echo $row['mobile']; ?>" required><br><br>
<textarea name="address" required><?php echo $row['address']; ?></textarea><br><br>
<button type="submit" name="update">Update</button>
</form>
<a href="suppliers.php">Back</a>
</body>
</html>