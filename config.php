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
// ============================================
// HELPER FUNCTIONS: HIERARCHICAL ACCESS
// ============================================

/**
 * Get karyawan bawahan atasan tertentu
 */
function getKaryawanByAtasan($conn, $atasanId) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.atasan_id = ? AND u.role = 'karyawan' AND u.status = 'aktif'
                            ORDER BY u.nama ASC");
    $stmt->bind_param("i", $atasanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get daftar atasan (role = 'atasan' yang aktif)
 */
function getDaftarAtasan($conn) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.role = 'atasan' AND u.status = 'aktif'
                            ORDER BY u.nama ASC");
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get karyawan yang belum punya atasan
 */
function getKaryawanTanpaAtasan($conn) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.role = 'karyawan' 
                            AND u.status = 'aktif' 
                            AND (u.atasan_id IS NULL OR u.atasan_id = 0)
                            ORDER BY u.nama ASC");
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get karyawan yang sudah punya atasan tertentu
 */
function getKaryawanByAtasanId($conn, $atasanId) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.atasan_id = ? AND u.role = 'karyawan'
                            ORDER BY u.nama ASC");
    $stmt->bind_param("i", $atasanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Cek apakah karyawan adalah bawahan atasan tertentu
 */
function isBawahan($conn, $karyawanId, $atasanId) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND atasan_id = ? AND role = 'karyawan'");
    $stmt->bind_param("ii", $karyawanId, $atasanId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Get absensi karyawan bawahan atasan
 */
function getAbsensiByAtasan($conn, $atasanId, $limit = 50) {
    $stmt = $conn->prepare("SELECT a.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM absensi a 
                            JOIN users u ON a.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.atasan_id = ? 
                            ORDER BY a.tanggal DESC, a.jam_masuk DESC 
                            LIMIT ?");
    $stmt->bind_param("ii", $atasanId, $limit);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get request system karyawan bawahan atasan
 */
function getRequestByAtasan($conn, $atasanId) {
    $stmt = $conn->prepare("SELECT r.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.atasan_id = ? 
                            ORDER BY r.created_at DESC");
    $stmt->bind_param("i", $atasanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get KPI karyawan bawahan atasan
 */
function getKpiByAtasan($conn, $atasanId) {
    $stmt = $conn->prepare("SELECT k.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM kpi k 
                            JOIN users u ON k.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.atasan_id = ? 
                            ORDER BY k.created_at DESC");
    $stmt->bind_param("i", $atasanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get atasan dari karyawan
 */
function getAtasanByKaryawan($conn, $karyawanId) {
    $stmt = $conn->prepare("SELECT u2.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u1 
                            JOIN users u2 ON u1.atasan_id = u2.id 
                            LEFT JOIN divisi d ON u2.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u2.jabatan_id = j.id 
                            WHERE u1.id = ?");
    $stmt->bind_param("i", $karyawanId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
/**
 * Validasi akses atasan ke data karyawan
 */
function validateAtasanAccess($conn, $atasanId, $karyawanId) {
    if (!isBawahan($conn, $karyawanId, $atasanId)) {
        header("Location: dashboard-atasan.php?page=karyawan-saya&error=unauthorized");
        exit;
    }
}
// ============================================
// HELPER FUNCTIONS: PENGUMUMAN
// ============================================

/**
 * Get pengumuman yang bisa dilihat user (berdasarkan role & divisi)
 */
function getPengumumanForUser($conn, $userId, $role, $divisiId = null) {
    $sql = "SELECT p.*, u.nama as pengirim, d.nama_divisi 
            FROM pengumuman p 
            LEFT JOIN users u ON p.created_by = u.id 
            LEFT JOIN divisi d ON p.divisi_id = d.id 
            WHERE (p.tipe_target = 'semua'";
    
    $params = [];
    $types = "";
    
    // Karyawan & Atasan: bisa lihat pengumuman divisi mereka
    if ($role != 'admin' && $divisiId) {
        $sql .= " OR (p.tipe_target = 'divisi' AND p.divisi_id = ?)";
        $params[] = $divisiId;
        $types .= "i";
    } elseif ($role == 'admin') {
        // Admin bisa lihat semua pengumuman divisi juga
        $sql .= " OR p.tipe_target = 'divisi'";
    }
    
    $sql .= ") AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
             ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get jumlah pengumuman belum dibaca user
 */
function getUnreadPengumumanCount($conn, $userId) {
    $user = getUserById($conn, $userId);
    $role = $user['role'];
    $divisiId = $user['divisi_id'];
    
    $sql = "SELECT COUNT(*) as total FROM pengumuman p 
            WHERE (p.tipe_target = 'semua'";
    
    $params = [];
    $types = "";
    
    if ($role != 'admin' && $divisiId) {
        $sql .= " OR (p.tipe_target = 'divisi' AND p.divisi_id = ?)";
        $params[] = $divisiId;
        $types .= "i";
    } elseif ($role == 'admin') {
        $sql .= " OR p.tipe_target = 'divisi'";
    }
    
    $sql .= ") AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
             AND NOT EXISTS (
                 SELECT 1 FROM pengumuman_read pr 
                 WHERE pr.pengumuman_id = p.id AND pr.user_id = ?
             )";
    
    $params[] = $userId;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

/**
 * Mark pengumuman as read
 */
function markPengumumanRead($conn, $pengumumanId, $userId) {
    $stmt = $conn->prepare("INSERT IGNORE INTO pengumuman_read (pengumuman_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $pengumumanId, $userId);
    return $stmt->execute();
}

/**
 * Cek apakah user sudah baca pengumuman
 */
function isPengumumanRead($conn, $pengumumanId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM pengumuman_read WHERE pengumuman_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $pengumumanId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Get read count untuk pengumuman
 */
function getPengumumanReadCount($conn, $pengumumanId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM pengumuman_read WHERE pengumuman_id = ?");
    $stmt->bind_param("i", $pengumumanId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

/**
 * Get daftar user yang sudah baca pengumuman
 */
function getPengumumanReaders($conn, $pengumumanId) {
    $stmt = $conn->prepare("SELECT u.nama, u.role, d.nama_divisi, pr.read_at 
                            FROM pengumuman_read pr 
                            JOIN users u ON pr.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            WHERE pr.pengumuman_id = ? 
                            ORDER BY pr.read_at DESC");
    $stmt->bind_param("i", $pengumumanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get detail pengumuman dengan cek akses
 */
function getPengumumanDetail($conn, $pengumumanId, $userId) {
    $user = getUserById($conn, $userId);
    $role = $user['role'];
    $divisiId = $user['divisi_id'];
    
    $sql = "SELECT p.*, u.nama as pengirim, d.nama_divisi 
            FROM pengumuman p 
            LEFT JOIN users u ON p.created_by = u.id 
            LEFT JOIN divisi d ON p.divisi_id = d.id 
            WHERE p.id = ? AND (p.tipe_target = 'semua'";
    
    $params = [$pengumumanId];
    $types = "i";
    
    if ($role != 'admin' && $divisiId) {
        $sql .= " OR (p.tipe_target = 'divisi' AND p.divisi_id = ?)";
        $params[] = $divisiId;
        $types .= "i";
    } elseif ($role == 'admin') {
        $sql .= " OR p.tipe_target = 'divisi'";
    }
    
    $sql .= ")";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
?>