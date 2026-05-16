<?php
session_start();
include 'db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$type = isset($data['type']) ? $data['type'] : '';
$identifier = isset($data['identifier']) ? trim($data['identifier']) : '';
$password = isset($data['password']) ? $data['password'] : '';

if (!$identifier || !$password || !in_array($type, ['customer', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit();
}

if ($type === 'customer') {
    $stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE mobile = ?");
    mysqli_stmt_bind_param($stmt, "s", $identifier);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $customer = mysqli_fetch_assoc($result);
        if (password_verify($password, $customer['password']) || $password === $customer['password']) {
            if ($password === $customer['password']) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = mysqli_prepare($conn, "UPDATE customers SET password = ? WHERE id = ?");
                mysqli_stmt_bind_param($upd, "si", $new_hash, $customer['id']);
                mysqli_stmt_execute($upd);
            }
            $_SESSION['customer'] = true;
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['customer_name'] = $customer['customer_name'];
            echo json_encode(['success' => true, 'redirect' => 'customer_shop.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid mobile number or password.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No customer found with that mobile number.']);
    }

} elseif ($type === 'admin') {
    $stmt = mysqli_prepare($conn, "SELECT * FROM admin WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $identifier);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $admin = mysqli_fetch_assoc($result);
        if (password_verify($password, $admin['password']) || $password === $admin['password']) {
            $_SESSION['admin'] = true;
            $_SESSION['admin_username'] = $admin['username'];
            echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No admin found with that username.']);
    }
}
?>
