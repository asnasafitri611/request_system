<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'request_system';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

function checkRole($allowedRoles) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: login.php");
        exit;
    }
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        header("Location: login.php");
        exit;
    }
}

function getUserById($conn, $id) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan FROM users u LEFT JOIN divisi d ON u.divisi_id=d.id LEFT JOIN jabatan j ON u.jabatan_id=j.id WHERE u.id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getUnreadNotifCount($conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifikasi WHERE user_id=? AND is_read=0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function addNotification($conn, $userId, $judul, $pesan) {
    $stmt = $conn->prepare("INSERT INTO notifikasi (user_id, judul, pesan) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $judul, $pesan);
    return $stmt->execute();
}
?>