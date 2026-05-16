<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$id = $_GET['id'];
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fertilizers WHERE id = $id"));

if(isset($_POST['update'])){
    $name = $_POST['fertilizer_name'];
    $company = $_POST['company_name'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $category = $_POST['category'];

    $query = "UPDATE fertilizers SET fertilizer_name='$name', company_name='$company', quantity='$quantity', price='$price', category='$category' WHERE id=$id";
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
<input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($row['price']); ?>" required><br><br>
<button type="submit" name="update" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Update Product</button>
</form>
<a href="view_fertilizer.php">Back</a>
</body>
</html>