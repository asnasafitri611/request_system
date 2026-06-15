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
                            WHERE u.atasan_id = ? AND u.status = 'aktif'
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
                            WHERE u.atasan_id = ? AND u.status = 'aktif'
                            ORDER BY u.nama ASC");
    $stmt->bind_param("i", $atasanId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Cek apakah karyawan adalah bawahan atasan tertentu
 */
function isBawahan($conn, $karyawanId, $atasanId) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND atasan_id = ? AND status = 'aktif'");
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
   $stmt = $conn->prepare("SELECT COUNT(*) as total FROM  pengumuman_read WHERE pengumuman_id = ?");
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
// ============================================
// HIERARCHY MULTI-LEVEL FUNCTIONS
// ============================================

/**
 * Get chain of superiors from a user up to top
 * Returns array: [user_id => user_data]
 */
function getHierarchyChain($conn, $userId) {
    $chain = [];
    $visited = [];
    $currentId = $userId;
    
    while ($currentId && !in_array($currentId, $visited)) {
        $visited[] = $currentId;
        $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan, j.id as jabatan_id 
                                FROM users u 
                                LEFT JOIN divisi d ON u.divisi_id = d.id 
                                LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                WHERE u.id = ? AND u.status = 'aktif'");
        $stmt->bind_param("i", $currentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) break;
        
        $user = $result->fetch_assoc();
        $chain[] = $user;
        $currentId = $user['atasan_id'];
    }
    
    return $chain;
}

/**
 * Get direct and indirect subordinates (recursive)
 */
/**
 * Get direct and indirect subordinates (recursive) - FIXED
 */
function getAllBawahan($conn, $atasanId) {
    $allBawahan = [];
    $visited = [];
    
    // Gunakan anonymous function/closure untuk recursive
    $getRecursive = function($parentId, $level = 0) use ($conn, &$allBawahan, &$visited, &$getRecursive) {
        if (in_array($parentId, $visited)) return;
        $visited[] = $parentId;
        
        $stmt = $conn->prepare("SELECT u.*, d.nama_divisi, j.nama_jabatan 
                                FROM users u 
                                LEFT JOIN divisi d ON u.divisi_id = d.id 
                                LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                WHERE u.atasan_id = ? AND u.status = 'aktif'
                                ORDER BY u.nama ASC");
        $stmt->bind_param("i", $parentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['level'] = $level;
            $allBawahan[] = $row;
            // Recursive: get their subordinates too
            $getRecursive($row['id'], $level + 1);
        }
    };
    
    $getRecursive($atasanId, 0);
    return $allBawahan;
}

/**
 * Get IDs of all subordinates (for IN queries)
 */
function getAllBawahanIds($conn, $atasanId) {
    $bawahan = getAllBawahan($conn, $atasanId);
    return array_column($bawahan, 'id');
}

/**
 * Get current approver for a request
 * Returns user data or null
 */
function getCurrentApprover($conn, $requestId) {
    $stmt = $conn->prepare("SELECT r.current_approver_id, u.*, d.nama_divisi, j.nama_jabatan 
                            FROM request_system r
                            JOIN users u ON r.current_approver_id = u.id
                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                            WHERE r.id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Check if user can approve/reject a request
 * User can approve if they are the current_approver_id
 */
function canApproveRequest($conn, $requestId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM request_system 
                            WHERE id = ? AND current_approver_id = ? AND status = 'pending'");
    $stmt->bind_param("ii", $requestId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Forward request to next approver in hierarchy
 * Returns true if forwarded, false if fully approved (no more approvers)
 */
function forwardRequest($conn, $requestId) {
    // Get request data
    $stmt = $conn->prepare("SELECT r.*, u.atasan_id as user_atasan_id 
                            FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    
    if (!$request) return false;
    
    // Get current approver's superior
    $currentApproverId = $request['current_approver_id'];
    $stmt = $conn->prepare("SELECT atasan_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $currentApproverId);
    $stmt->execute();
    $currentApprover = $stmt->get_result()->fetch_assoc();
    
    if (!$currentApprover || !$currentApprover['atasan_id']) {
        // No more approvers, fully approve
        return false;
    }
    
    // Forward to next approver
    $nextApproverId = $currentApprover['atasan_id'];
    $stmt = $conn->prepare("UPDATE request_system SET current_approver_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $nextApproverId, $requestId);
    $stmt->execute();
    
    // Notify next approver
    $stmt = $conn->prepare("SELECT nama FROM users WHERE id = ?");
    $stmt->bind_param("i", $request['user_id']);
    $stmt->execute();
    $requester = $stmt->get_result()->fetch_assoc();
    
    addNotification($conn, $nextApproverId, 'Request Perlu Approval', 
        $requester['nama'] . ' mengajukan ' . $request['jenis_request'] . ' - perlu persetujuan Anda');
    
    return true;
}

/**
 * Get request with hierarchy info
 */
function getRequestWithHierarchy($conn, $requestId) {
    $stmt = $conn->prepare("SELECT r.*, 
                            u.nama as requester_name, 
                            u2.nama as current_approver_name,
                            u3.nama as original_atasan_name,
                            d.nama_divisi,
                            j.nama_jabatan
                            FROM request_system r
                            JOIN users u ON r.user_id = u.id
                            LEFT JOIN users u2 ON r.current_approver_id = u2.id
                            LEFT JOIN users u3 ON r.atasan_id = u3.id
                            LEFT JOIN divisi d ON u.divisi_id = d.id
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id
                            WHERE r.id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get approval history/status for a request
 */
function getApprovalStatus($conn, $requestId) {
    $request = getRequestWithHierarchy($conn, $requestId);
    if (!$request) return [];
    
    $chain = getHierarchyChain($conn, $request['user_id']);
    $status = [];
    
    foreach ($chain as $level => $person) {
        $isCurrent = ($request['current_approver_id'] == $person['id'] && $request['status'] == 'pending');
        $isApproved = ($request['status'] == 'disetujui' && $level == 0) || 
                      ($request['status'] == 'disetujui' && $request['approved_by'] == $person['id']);
        $isRejected = ($request['status'] == 'ditolak' && $request['approved_by'] == $person['id']);
        
        $status[] = [
            'level' => $level,
            'user' => $person,
            'status' => $isRejected ? 'rejected' : ($isApproved ? 'approved' : ($isCurrent ? 'current' : 'waiting')),
            'is_current' => $isCurrent,
            'is_approved' => $isApproved,
            'is_rejected' => $isRejected
        ];
        
        if ($isRejected || $isCurrent) break;
    }
    
    return $status;
}

/**
 * Get all requests where user is current approver (direct or indirect)
 */
function getRequestsForApprover($conn, $userId) {
    $stmt = $conn->prepare("SELECT r.*, 
                            u.nama, u.email, 
                            d.nama_divisi, j.nama_jabatan,
                            u2.nama as current_approver_name
                            FROM request_system r
                            JOIN users u ON r.user_id = u.id
                            LEFT JOIN divisi d ON u.divisi_id = d.id
                            LEFT JOIN jabatan j ON u.jabatan_id = j.id
                            LEFT JOIN users u2 ON r.current_approver_id = u2.id
                            WHERE r.current_approver_id = ? AND r.status = 'pending'
                            ORDER BY r.created_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get all requests from user's subordinates (for monitoring)
 */
function getAllSubordinateRequests($conn, $atasanId) {
    $bawahanIds = getAllBawahanIds($conn, $atasanId);
    if (empty($bawahanIds)) return null;
    
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    $sql = "SELECT r.*, 
            u.nama, u.email, 
            d.nama_divisi, j.nama_jabatan,
            u2.nama as current_approver_name,
            u3.nama as original_atasan_name
            FROM request_system r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN divisi d ON u.divisi_id = d.id
            LEFT JOIN jabatan j ON u.jabatan_id = j.id
            LEFT JOIN users u2 ON r.current_approver_id = u2.id
            LEFT JOIN users u3 ON r.atasan_id = u3.id
            WHERE r.user_id IN ($placeholders)
            ORDER BY r.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    return $stmt->get_result();
}
?>