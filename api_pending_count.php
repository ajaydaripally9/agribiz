<?php
session_start();
if (!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
include 'db.php';

$new_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM orders WHERE status='Pending'"))['c'];

echo json_encode(['count' => (int)$new_orders]);
?>
