<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Billing Staff']);

$id = $_GET['id'];
mysqli_query($conn, "DELETE FROM fertilizers WHERE id = $id");
header('Location: view_fertilizer.php');
exit();
?>