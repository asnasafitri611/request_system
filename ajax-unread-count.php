<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$count = getUnreadPengumumanCount($conn, $_SESSION['user_id']);
echo json_encode(['count' => $count]);