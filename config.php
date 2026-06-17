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
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getUnreadNotifCount($conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifikasi WHERE user_id = ? AND is_read = 0");
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
// HIERARKI FUNCTIONS (SAMA PERSIS DENGAN ADMIN)
// ============================================

function getDaftarAtasan($conn) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.status = 'aktif' 
                            AND EXISTS (SELECT 1 FROM users b WHERE b.parent_id = u.id AND b.status = 'aktif')
                            ORDER BY u.nama ASC");
    $stmt->execute();
    return $stmt->get_result();
}

function getKandidatAtasan($conn, $excludeId = null) {
    $sql = "SELECT u.*, d.nama_divisi, j.nama_jabatan 
            FROM users u 
            LEFT JOIN divisi d ON u.divisi_id = d.id 
            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
            WHERE u.status = 'aktif'";
    if ($excludeId) {
        $sql .= " AND u.id != " . (int)$excludeId;
    }
    $sql .= " ORDER BY u.nama ASC";
    return $conn->query($sql);
}

function getKaryawanByAtasanId($conn, $parentId) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.parent_id = ? AND u.status = 'aktif'
                            ORDER BY u.nama ASC");
    $stmt->bind_param("i", $parentId);
    $stmt->execute();
    return $stmt->get_result();
}

function getKaryawanTanpaAtasan($conn) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.status = 'aktif' AND (u.parent_id IS NULL OR u.parent_id = 0)
                            ORDER BY u.nama ASC");
    $stmt->execute();
    return $stmt->get_result();
}

function getAtasanChain($conn, $userId, $chain = []) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.id = (SELECT parent_id FROM users WHERE id = ?)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $atasan = $result->fetch_assoc();
        $chain[] = $atasan;
        return getAtasanChain($conn, $atasan['id'], $chain);
    }
    return $chain;
}

function getAllBawahanRecursive($conn, $parentId, &$allBawahan = []) {
    $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE u.parent_id = ? AND u.status = 'aktif'");
    $stmt->bind_param("i", $parentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $allBawahan[] = $row;
        getAllBawahanRecursive($conn, $row['id'], $allBawahan);
    }
    return $allBawahan;
}

function getAllBawahanIds($conn, $atasanId) {
    $ids = [];
    $all = getAllBawahanRecursive($conn, $atasanId);
    foreach ($all as $b) {
        $ids[] = $b['id'];
    }
    return $ids;
}

function isCircularReference($conn, $childId, $parentId) {
    if ($childId == $parentId) return true;

    $currentId = $parentId;
    $visited = [];
    while ($currentId !== null) {
        if (in_array($currentId, $visited)) return true;
        $visited[] = $currentId;

        $stmt = $conn->prepare("SELECT parent_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row || $row['parent_id'] === null) break;
        if ($row['parent_id'] == $childId) return true;
        $currentId = $row['parent_id'];
    }
    return false;
}

function getHierarchyLevel($conn, $userId) {
    $level = 0;
    $currentId = $userId;
    $visited = [];

    while ($currentId !== null) {
        if (in_array($currentId, $visited)) break;
        $visited[] = $currentId;

        $stmt = $conn->prepare("SELECT parent_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row || $row['parent_id'] === null || $row['parent_id'] == 0) break;
        $level++;
        $currentId = $row['parent_id'];
    }
    return $level;
}

function isBawahan($conn, $karyawanId, $atasanId) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND parent_id = ?");
    $stmt->bind_param("ii", $karyawanId, $atasanId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function isBawahanRecursive($conn, $karyawanId, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    return in_array($karyawanId, $bawahanIds);
}

// ============================================
// STATS HELPERS
// ============================================

function countTotalBawahan($conn, $atasanId) {
    return count(getAllBawahanIds($conn, $atasanId));
}

function countHadirHariIni($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) return 0;
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM absensi 
                            WHERE user_id IN ($placeholders) 
                            AND tanggal = CURDATE() 
                            AND status = 'hadir'");
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function countPendingRequests($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) return 0;
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM request_system 
                            WHERE user_id IN ($placeholders) 
                            AND status = 'pending'");
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getAvgKpiBawahan($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) return 0;
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT AVG(nilai) as avg FROM kpi 
                            WHERE user_id IN ($placeholders)");
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc()['avg'];
    return $result ? round($result, 2) : 0;
}

function countAtasanAktif($conn) {
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT u.id) as total FROM users u WHERE u.status='aktif' AND EXISTS (SELECT 1 FROM users b WHERE b.parent_id = u.id AND b.status = 'aktif')");
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function countKaryawanTanpaAtasan($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE status='aktif' AND (parent_id IS NULL OR parent_id = 0)");
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function countKaryawanDenganAtasan($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE status='aktif' AND parent_id IS NOT NULL AND parent_id > 0");
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// ============================================
// DATA QUERIES (ATASAN)
// ============================================

function getAbsensiByAtasan($conn, $atasanId, $limit = 50) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) {
        return $conn->query("SELECT * FROM absensi WHERE 1=0");
    }
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT a.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM absensi a 
                            JOIN users u ON a.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE a.user_id IN ($placeholders)
                            ORDER BY a.tanggal DESC, a.jam_masuk DESC 
                            LIMIT ?");
    
    $bawahanIds[] = $limit;
    $types .= 'i';
    
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result();
}

function getRequestByAtasan($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) {
        return $conn->query("SELECT * FROM request_system WHERE 1=0");
    }
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT r.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE r.user_id IN ($placeholders)
                            ORDER BY r.created_at DESC");
    
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result();
}

function getKpiByAtasan($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) {
        return $conn->query("SELECT * FROM kpi WHERE 1=0");
    }
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $stmt = $conn->prepare("SELECT k.*, u.nama, d.nama_divisi, j.nama_jabatan 
                            FROM kpi k 
                            JOIN users u ON k.user_id = u.id 
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE k.user_id IN ($placeholders)
                            ORDER BY k.created_at DESC");
    
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result();
}

function getAtasanByKaryawan($conn, $karyawanId) {
    $stmt = $conn->prepare("SELECT u2.*, d.nama_divisi, j.nama_jabatan 
                            FROM users u1 
                            JOIN users u2 ON u1.parent_id = u2.id 
                            LEFT JOIN divisi d ON u2.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u2.jabatan_id = j.id 
                            WHERE u1.id = ?");
    $stmt->bind_param("i", $karyawanId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function validateAtasanAccess($conn, $atasanId, $karyawanId) {
    if (!isBawahanRecursive($conn, $karyawanId, $atasanId)) {
        header("Location: dashboard-atasan.php?page=karyawan-saya&error=unauthorized");
        exit;
    }
}
// ============================================
// FUNGSI HELPER PENGUMUMAN
// ============================================

function getDivisiName($conn, $divisiId) {
    if (!$divisiId) return 'Semua Divisi';
    $stmt = $conn->prepare("SELECT nama_divisi FROM divisi WHERE id = ?");
    $stmt->bind_param("i", $divisiId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['nama_divisi'] ?? 'Unknown';
}

function canCreatePengumuman($conn, $userId, $role, $targetDivisi = null) {
    // Admin bisa ke semua
    if ($role == 'admin') return true;
    
    // Atasan hanya bisa ke divisi sendiri
    if ($role == 'atasan') {
        $user = getUserById($conn, $userId);
        if ($targetDivisi && $targetDivisi != $user['divisi_id']) {
            return false;
        }
        return true;
    }
    
    // Karyawan tidak bisa buat pengumuman
    return false;
}

function getPengumumanForUser($conn, $userId, $divisiId, $limit = null) {
    $sql = "SELECT p.*, u.nama as created_by_nama, d.nama_divisi 
            FROM pengumuman p 
            LEFT JOIN users u ON p.created_by = u.id 
            LEFT JOIN divisi d ON p.divisi_id = d.id 
            WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
            AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
            ORDER BY p.created_at DESC";
    
    if ($limit) {
        $sql .= " LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $divisiId, $limit);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $divisiId);
    }
    
    $stmt->execute();
    return $stmt->get_result();
}

function isPengumumanRead($conn, $pengumumanId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM pengumuman_read WHERE pengumuman_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $pengumumanId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function markPengumumanRead($conn, $pengumumanId, $userId) {
    $stmt = $conn->prepare("INSERT IGNORE INTO pengumuman_read (pengumuman_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $pengumumanId, $userId);
    $stmt->execute();
}

function countUnreadPengumuman($conn, $userId, $divisiId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as c FROM pengumuman p 
        WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
        AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
        AND NOT EXISTS (SELECT 1 FROM pengumuman_read pr WHERE pr.pengumuman_id = p.id AND pr.user_id = ?)
    ");
    $stmt->bind_param("ii", $divisiId, $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['c'];
}

function deletePengumuman($conn, $pengumumanId, $userId, $role) {
    // Cek kepemilikan
    $stmt = $conn->prepare("SELECT created_by, tipe_target FROM pengumuman WHERE id = ?");
    $stmt->bind_param("i", $pengumumanId);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    
    if (!$p) return false;
    
    // Admin bisa hapus semua, atasan hanya bisa hapus miliknya
    if ($role == 'admin' || $p['created_by'] == $userId) {
        // Hapus read records dulu
        $stmt = $conn->prepare("DELETE FROM pengumuman_read WHERE pengumuman_id = ?");
        $stmt->bind_param("i", $pengumumanId);
        $stmt->execute();
        
        // Hapus pengumuman
        $stmt = $conn->prepare("DELETE FROM pengumuman WHERE id = ?");
        $stmt->bind_param("i", $pengumumanId);
        $stmt->execute();
        return true;
    }
    return false;
}
?>