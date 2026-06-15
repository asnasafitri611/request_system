<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
    echo '<p>ID request tidak valid</p>';
    exit;
}

$requestId = (int)$_GET['id'];
$chain = getApprovalStatus($conn, $requestId);

if (empty($chain)) {
    echo '<p>Tidak ada data approval</p>';
    exit;
}

echo '<div style="display:flex;flex-direction:column;gap:15px;align-items:center;padding:20px">';

foreach ($chain as $index => $level) {
    $user = $level['user'];
    $status = $level['status'];
    
    $icon = $status == 'approved' ? 'check-circle' : ($status == 'rejected' ? 'times-circle' : ($status == 'current' ? 'clock' : 'ellipsis-h'));
    $color = $status == 'approved' ? '#10b981' : ($status == 'rejected' ? '#ef4444' : ($status == 'current' ? '#f59e0b' : '#9ca3af'));
    $bg = $status == 'approved' ? '#d1fae5' : ($status == 'rejected' ? '#fee2e2' : ($status == 'current' ? '#fef3c7' : '#f3f4f6'));
    
    echo '<div style="display:flex;align-items:center;gap:15px;width:100%;max-width:400px;padding:15px;border-radius:12px;background:' . $bg . ';border-left:4px solid ' . $color . '">';
    echo '<img src="' . ($user['foto'] ?? 'https://via.placeholder.com/50') . '" style="width:50px;height:50px;border-radius:50%;object-fit:cover">';
    echo '<div style="flex:1">';
    echo '<div style="font-weight:600;color:#1f2937">' . htmlspecialchars($user['nama']) . '</div>';
    echo '<div style="font-size:13px;color:#6b7280">' . htmlspecialchars($user['nama_jabatan'] ?? '-') . '</div>';
    echo '</div>';
    echo '<div style="color:' . $color . ';font-size:24px"><i class="fas fa-' . $icon . '"></i></div>';
    echo '</div>';
    
    if ($index < count($chain) - 1) {
        echo '<div style="color:#d1d5db;font-size:20px"><i class="fas fa-arrow-down"></i></div>';
    }
}

echo '</div>';
?>