<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin') header("Location: dashboard-admin.php");
    elseif ($_SESSION['role'] == 'atasan') header("Location: dashboard-atasan.php");
    else header("Location: dashboard-karyawan.php");
    exit;
}
header("Location: login.php");
exit;
?>