<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

$id = $_GET['id'];
mysqli_query($conn, "DELETE FROM suppliers WHERE id = $id");
header('Location: suppliers.php');
exit();
?>