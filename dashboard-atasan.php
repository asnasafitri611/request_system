<?php
require_once 'config.php';
checkRole(['atasan']);

$user = getUserById($conn, $_SESSION['user_id']);
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);

// ============================================
// HANDLE PENGUMUMAN ATASAN
// ============================================

// Tambah Pengumuman
if (isset($_POST['tambah_pengumuman'])) {
    $judul = $_POST['judul'];
    $isi = $_POST['isi'];
    $divisi_id = $user['divisi_id'];
    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;
    
    $file_lampiran = '';
    if (!empty($_FILES['file_lampiran']['name'])) {
        $uploadDir = 'uploads/pengumuman/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $file_lampiran = $uploadDir . time() . '_' . basename($_FILES['file_lampiran']['name']);
        move_uploaded_file($_FILES['file_lampiran']['tmp_name'], $file_lampiran);
    }
    
    $stmt = $conn->prepare("INSERT INTO pengumuman (judul, isi, tipe_target, divisi_id, file_lampiran, tanggal_kadaluarsa, created_by) VALUES (?, ?, 'divisi', ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $judul, $isi, $divisi_id, $file_lampiran, $tanggal_kadaluarsa, $_SESSION['user_id']);
    $stmt->execute();
    
    // Notifikasi ke karyawan bawahan & karyawan di divisi yang sama
    $notifStmt = $conn->prepare("SELECT id FROM users WHERE status='aktif' AND divisi_id = ? AND id != ? AND (role = 'karyawan' OR role = 'atasan')");
    $notifStmt->bind_param("ii", $divisi_id, $_SESSION['user_id']);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result();
    while ($u = $notifResult->fetch_assoc()) {
        addNotification($conn, $u['id'], 'Pengumuman Divisi', 'Ada pengumuman dari atasan: ' . $judul);
    }
    
    header("Location: dashboard-atasan.php?page=pengumuman&success=added");
    exit;
}

// Edit Pengumuman
if (isset($_POST['edit_pengumuman'])) {
    $id = $_POST['pengumuman_id'];
    $judul = $_POST['judul'];
    $isi = $_POST['isi'];
    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;
    
    $stmt = $conn->prepare("SELECT file_lampiran FROM pengumuman WHERE id = ? AND created_by = ?");
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if (!$existing) {
        header("Location: dashboard-atasan.php?page=pengumuman&error=unauthorized");
        exit;
    }
    
    $file_lampiran = $existing['file_lampiran'];
    if (!empty($_FILES['file_lampiran']['name'])) {
        if (!empty($file_lampiran) && file_exists($file_lampiran)) {
            @unlink($file_lampiran);
        }
        $uploadDir = 'uploads/pengumuman/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $file_lampiran = $uploadDir . time() . '_' . basename($_FILES['file_lampiran']['name']);
        move_uploaded_file($_FILES['file_lampiran']['tmp_name'], $file_lampiran);
    }
    
    $stmt = $conn->prepare("UPDATE pengumuman SET judul=?, isi=?, file_lampiran=?, tanggal_kadaluarsa=? WHERE id=? AND created_by=?");
    $stmt->bind_param("ssssii", $judul, $isi, $file_lampiran, $tanggal_kadaluarsa, $id, $_SESSION['user_id']);
    $stmt->execute();
    
    header("Location: dashboard-atasan.php?page=pengumuman&success=updated");
    exit;
}

// Hapus Pengumuman
if (isset($_GET['hapus_pengumuman'])) {
    $id = (int) $_GET['hapus_pengumuman'];
    
    $stmt = $conn->prepare("SELECT file_lampiran FROM pengumuman WHERE id = ? AND created_by = ?");
    $stmt->bind_param("ii", $id, $_SESSION['user_id']);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    
    if ($p) {
        if (!empty($p['file_lampiran']) && file_exists($p['file_lampiran'])) {
            @unlink($p['file_lampiran']);
        }
        $stmt = $conn->prepare("DELETE FROM pengumuman WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM pengumuman_read WHERE pengumuman_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    
    header("Location: dashboard-atasan.php?page=pengumuman&success=deleted");
    exit;
}

// Data pengumuman untuk atasan
$atasanDivisi = $user['divisi_id'];
$pengumumanList = $conn->query("SELECT p.*, u.nama as pengirim, d.nama_divisi, 
                                (SELECT COUNT(*) FROM pengumuman_read pr WHERE pr.pengumuman_id = p.id) as read_count 
                                FROM pengumuman p 
                                LEFT JOIN users u ON p.created_by = u.id 
                                LEFT JOIN divisi d ON p.divisi_id = d.id 
                                WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = $atasanDivisi))
                                AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
                                ORDER BY p.created_at DESC");

$myPengumuman = $conn->query("SELECT p.*, d.nama_divisi, 
                               (SELECT COUNT(*) FROM pengumuman_read pr WHERE pr.pengumuman_id = p.id) as read_count 
                               FROM pengumuman p 
                               LEFT JOIN divisi d ON p.divisi_id = d.id 
                               WHERE p.created_by = " . $_SESSION['user_id'] . "
                               ORDER BY p.created_at DESC");

// ============================================
// STATS & DATA
// ============================================
// ============================================
// STATS & DATA
// ============================================

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE atasan_id = ? AND role='karyawan' AND status='aktif'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$totalKaryawan = $stmt->get_result()->fetch_assoc()['total'];
// ============================================
// STATS & DATA (MULTI-LEVEL HIERARCHY)
// ============================================

// Get all subordinates (direct + indirect)
$allBawahan = getAllBawahan($conn, $_SESSION['user_id']);
$totalKaryawan = count($allBawahan);

// Count direct vs indirect for display
$directBawahan = array_filter($allBawahan, function($b) { return $b['level'] == 0; });
$indirectBawahan = array_filter($allBawahan, function($b) { return $b['level'] > 0; });

// Hadir hari ini dari semua bawahan (direct + indirect)
$bawahanIds = getAllBawahanIds($conn, $_SESSION['user_id']);
if (!empty($bawahanIds)) {
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM absensi a 
                            WHERE a.user_id IN ($placeholders) AND a.tanggal = CURDATE() AND a.status='hadir'");
    $stmt->bind_param($types, ...$bawahanIds);
    $stmt->execute();
    $jumlahHadir = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $jumlahHadir = 0;
}

// Pending requests where I am the current approver
$pendingRequests = getRequestsForApprover($conn, $_SESSION['user_id'])->num_rows;
// ============================================
// DATA GRAFIK ABSENSI MINGGUAN (untuk Chart.js)
// ============================================
$days = []; 
$hadirData = []; 
$telatData = []; 
$izinData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('D', strtotime($date));
    $hadirData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status='hadir' AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
    $telatData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status='telat' AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
    $izinData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status IN ('izin','sakit') AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
}

$absensiResult = getAbsensiByAtasan($conn, $_SESSION['user_id'], 50);
// Get requests where current user is the current approver (multi-level hierarchy)
$requestsResult = getRequestsForApprover($conn, $_SESSION['user_id']);

// Also get all subordinate requests for monitoring view
$allSubordinateRequests = getAllSubordinateRequests($conn, $_SESSION['user_id']);
$kpiResult = getKpiByAtasan($conn, $_SESSION['user_id']);

// ============================================
// HANDLE APPROVE/REJECT REQUEST (MULTI-LEVEL)
// ============================================

if (isset($_POST['approve_request'])) {
    $reqId = $_POST['request_id'];
    
    // Check if current user can approve this request
    if (!canApproveRequest($conn, $reqId, $_SESSION['user_id'])) {
        header("Location: dashboard-atasan.php?page=request&error=unauthorized");
        exit;
    }
    
    $komentar = $_POST['komentar'] ?? '';
    
    // Check if there's a next approver
    $forwarded = forwardRequest($conn, $reqId);
    
    if (!$forwarded) {
        // No more approvers, fully approve
        $stmt = $conn->prepare("UPDATE request_system SET status='disetujui', approved_by=?, komentar_atasan=?, current_approver_id=NULL WHERE id=?");
        $stmt->bind_param("isi", $_SESSION['user_id'], $komentar, $reqId);
        $stmt->execute();
        
        // Get requester info for notification
        $request = getRequestWithHierarchy($conn, $reqId);
        addNotification($conn, $request['user_id'], 'Request Disetujui', 
            'Request ' . $request['jenis_request'] . ' Anda telah disetujui semua atasan');
    } else {
        // Request forwarded, notify current approver they approved but need next level
        $request = getRequestWithHierarchy($conn, $reqId);
        addNotification($conn, $_SESSION['user_id'], 'Request Di-Forward', 
            'Anda telah menyetujui request ' . $request['jenis_request'] . ' dari ' . $request['requester_name'] . '. Menunggu persetujuan atasan berikutnya.');
    }
    
    header("Location: dashboard-atasan.php?page=request&success=approved");
    exit;
}

if (isset($_POST['reject_request'])) {
    $reqId = $_POST['request_id'];
    
    // Check if current user can reject this request
    if (!canApproveRequest($conn, $reqId, $_SESSION['user_id'])) {
        header("Location: dashboard-atasan.php?page=request&error=unauthorized");
        exit;
    }
    
    $komentar = $_POST['komentar'] ?? 'Ditolak';
    
    $stmt = $conn->prepare("UPDATE request_system SET status='ditolak', approved_by=?, komentar_atasan=?, current_approver_id=NULL WHERE id=?");
    $stmt->bind_param("isi", $_SESSION['user_id'], $komentar, $reqId);
    $stmt->execute();
    
    $request = getRequestWithHierarchy($conn, $reqId);
    addNotification($conn, $request['user_id'], 'Request Ditolak', 
        'Request ' . $request['jenis_request'] . ' Anda ditolak oleh ' . $_SESSION['nama']);
    
    header("Location: dashboard-atasan.php?page=request&success=rejected");
    exit;
}

// ============================================
// HANDLE KPI SCORING
// ============================================

if (isset($_POST['save_kpi'])) {
    $userId = (int) $_POST['karyawan_id'];
    
    if (!isBawahan($conn, $userId, $_SESSION['user_id'])) {
        header("Location: dashboard-atasan.php?page=kpi&error=not_bawahan");
        exit;
    }
    
    $periode = $_POST['periode'];
    $target = $_POST['target'];
    $realisasi = $_POST['realisasi'];
    $nilai = $_POST['nilai'];
    $komentar = $_POST['komentar'];
    
    $stmt = $conn->prepare("INSERT INTO kpi (user_id, periode, target, realisasi, nilai, komentar, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdddsi", $userId, $periode, $target, $realisasi, $nilai, $komentar, $_SESSION['user_id']);
    $stmt->execute();
    
    addNotification($conn, $userId, 'KPI Baru', 'Anda mendapat penilaian KPI baru dari atasan');
    header("Location: dashboard-atasan.php?page=kpi&success=saved");
    exit;
}

// ============================================
// HANDLE PROFILE ATASAN
// ============================================

$profileMsg = '';
$profileError = '';
$passMsg = '';
$passError = '';

if (isset($_POST['update_profile'])) {
    $nama = $_POST['nama'];
    $email = $_POST['email'];
    $no_hp = $_POST['no_hp'];
    $username = $_POST['username'];
    $divisi_id = $_POST['divisi_id'];
    $jabatan_id = $_POST['jabatan_id'];
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
    $stmt->bind_param("si", $username, $_SESSION['user_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $profileError = "Username sudah digunakan oleh user lain!";
    } else {
        $foto = $user['foto'];
        if (!empty($_FILES['foto']['name'])) {
            $uploadDir = 'uploads/profile/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $foto = $uploadDir . time() . '_' . basename($_FILES['foto']['name']);
            move_uploaded_file($_FILES['foto']['tmp_name'], $foto);
            $_SESSION['foto'] = $foto;
        }
        
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, no_hp=?, username=?, divisi_id=?, jabatan_id=?, foto=? WHERE id=?");
        $stmt->bind_param("ssssiisi", $nama, $email, $no_hp, $username, $divisi_id, $jabatan_id, $foto, $_SESSION['user_id']);
        $stmt->execute();
        $_SESSION['nama'] = $nama;
        $_SESSION['username'] = $username;
        $profileMsg = "Profile berhasil diperbarui!";
        $user = getUserById($conn, $_SESSION['user_id']);
    }
}

if (isset($_POST['change_password'])) {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    if (password_verify($old, $user['password']) && $new === $confirm) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hash, $_SESSION['user_id']);
        $stmt->execute();
        $passMsg = "Password berhasil diubah!";
    } else {
        $passError = "Password lama salah atau konfirmasi tidak cocok!";
    }
}

$divisiList = $conn->query("SELECT * FROM divisi ORDER BY nama_divisi");
$jabatanList = $conn->query("SELECT * FROM jabatan ORDER BY nama_jabatan");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Atasan - Request System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-building"></i>
            <h2>Request System</h2>
        </div>
        <div class="user-info">
            <img src="<?= $user['foto'] ?? 'https://via.placeholder.com/70' ?>" alt="Profile">
            <h4><?= htmlspecialchars($_SESSION['nama']) ?></h4>
            <span>Atasan</span>
        </div>
        <div class="nav-menu">
            <a href="?page=dashboard" class="nav-item <?= $page=='dashboard'?'active':'' ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="?page=karyawan-saya" class="nav-item <?= $page=='karyawan-saya'?'active':'' ?>">
                <i class="fas fa-users"></i>
                <span>Karyawan Saya</span>
                <?php if ($totalKaryawan > 0): ?>
                    <span class="badge"><?= $totalKaryawan ?></span>
                <?php endif; ?>
            </a>
            <a href="?page=absensi" class="nav-item <?= $page=='absensi'?'active':'' ?>">
                <i class="fas fa-clock"></i>
                <span>Data Absensi</span>
            </a>
            <a href="?page=kpi" class="nav-item <?= $page=='kpi'?'active':'' ?>">
                <i class="fas fa-chart-line"></i>
                <span>Penilaian KPI</span>
            </a>
            <a href="?page=request" class="nav-item <?= $page=='request'?'active':'' ?>">
                <i class="fas fa-file-alt"></i>
                <span>Request System</span>
                <?php if ($pendingRequests > 0): ?>
                    <span class="badge"><?= $pendingRequests ?></span>
                <?php endif; ?>
            </a>
            <a href="?page=profile" class="nav-item <?= $page=='profile'?'active':'' ?>">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            <a href="?page=pengumuman" class="nav-item <?= $page=='pengumuman'?'active':'' ?>">
                <i class="fas fa-bullhorn"></i>
                <span>Pengumuman</span>
                <?php 
                $unreadPengumuman = getUnreadPengumumanCount($conn, $_SESSION['user_id']);
                if ($unreadPengumuman > 0): 
                ?>
                    <span class="badge"><?= $unreadPengumuman ?></span>
                <?php endif; ?>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- NAVBAR -->
        <div class="navbar">
            <div class="nav-left">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="breadcrumb">Dashboard / <?= ucfirst($page) ?></span>
            </div>
            <div class="nav-right">
                <div class="nav-icon" onclick="openModal('notifModal')" style="position:relative">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-count"><?= $notifCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-icon" onclick="openModal('notifModal')" style="position:relative">
                    <i class="fas fa-bullhorn"></i>
                    <?php if ($unreadPengumuman > 0): ?>
                        <span class="notif-count"><?= $unreadPengumuman ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-icon" onclick="toggleDarkMode()">
                    <i class="fas fa-moon"></i>
                </div>
            </div>
        </div>

        <!-- CONTENT AREA -->
        <div class="content">

            <!-- PAGE: DASHBOARD -->
            <?php if ($page == 'dashboard'): ?>
                <h1 class="page-title">Dashboard Atasan</h1>
                <p class="page-subtitle">Overview performa tim dan aktivitas karyawan</p>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawan ?></h3>
                            <p>Total Karyawan Bawahan</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info">
                            <h3><?= $jumlahHadir ?></h3>
                            <p>Hadir Hari Ini</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <div class="stat-info">
                            <h3><?= $pendingRequests ?></h3>
                            <p>Request Pending</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-star"></i></div>
                        <div class="stat-info">
                            <h3><?= round($conn->query("SELECT AVG(k.nilai) as avg FROM kpi k JOIN users u ON k.user_id = u.id WHERE u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['avg'] ?? 0, 2) ?></h3>
                            <p>Rata-rata KPI Bawahan</p>
                        </div>
                    </div>
                </div>

                <div class="card">
    <div class="card-header">
        <span class="card-title"><i class="fas fa-chart-bar"></i> Grafik Absensi Mingguan - Karyawan Bawahan</span>
    </div>
    <div class="chart-container">
        <canvas id="absensiChart"></canvas>
    </div>
</div>

                <?php
                $days = []; $hadirData = []; $telatData = []; $izinData = [];
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $days[] = date('D', strtotime($date));
                    $hadirData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status='hadir' AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
                    $telatData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status='telat' AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
                    $izinData[] = $conn->query("SELECT COUNT(*) as c FROM absensi a JOIN users u ON a.user_id = u.id WHERE a.tanggal='$date' AND a.status IN ('izin','sakit') AND u.atasan_id = " . $_SESSION['user_id'])->fetch_assoc()['c'];
                }
                ?>
                            <!-- PAGE: KARYAWAN SAYA -->
            <?php elseif ($page == 'karyawan-saya'): ?>
                <h1 class="page-title">Karyawan Saya</h1>
                <p class="page-subtitle">Daftar karyawan yang menjadi bawahan Anda</p>

                <?php if (isset($_GET['error'])): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-exclamation-circle"></i> 
                        <?php 
                        switch($_GET['error']) {
                            case 'not_bawahan': echo 'Karyawan tersebut bukan bawahan Anda!'; break;
                            case 'unauthorized': echo 'Anda tidak memiliki akses ke data tersebut!'; break;
                            default: echo 'Terjadi kesalahan!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'approved': echo 'Request berhasil disetujui!'; break;
                            case 'rejected': echo 'Request berhasil ditolak!'; break;
                            case 'saved': echo 'Penilaian KPI berhasil disimpan!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-users"></i> Daftar Karyawan Bawahan</span>
                        <span class="badge badge-success"><?= $totalKaryawan ?> orang</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Nama</th>
                                    <th>Email</th>
                                    <th>Divisi</th>
                                    <th>Jabatan</th>
                                    <th>Status</th>
                                    <th>Bergabung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $karyawanSaya = getKaryawanByAtasan($conn, $_SESSION['user_id']);
                                if ($karyawanSaya->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        <p>Belum ada karyawan yang ditugaskan ke Anda</p>
                                        <p style="font-size:12px">Hubungi admin untuk menambahkan karyawan</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php while ($row = $karyawanSaya->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <img src="<?= $row['foto'] ?? 'https://via.placeholder.com/40' ?>" 
                                                 style="width:40px;height:40px;border-radius:50%;object-fit:cover">
                                        </td>
                                        <td><strong><?= htmlspecialchars($row['nama']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge badge-<?= $row['status']=='aktif'?'success':'secondary' ?>">
                                                <?= $row['status']=='aktif'?'Aktif':'Tidak Aktif' ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- PAGE: ABSENSI -->
            <?php elseif ($page == 'absensi'): ?>
                <h1 class="page-title">Data Absensi Karyawan</h1>
                <p class="page-subtitle">Monitoring kehadiran karyawan bawahan</p>

                <div class="search-filter">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchAbsensi" placeholder="Cari nama karyawan..." onkeyup="searchTable('searchAbsensi', 'absensiTable')">
                    </div>
                    <input type="date" class="form-control" style="width:auto" onchange="filterDate(this.value)">
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Riwayat Absensi - Karyawan Bawahan</span>
                        <button class="btn btn-secondary btn-sm" onclick="exportToCSV('absensiTable', 'absensi_karyawan')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="absensiTable">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Tanggal</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Keluar</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $absensiResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td><?= $row['jam_masuk'] ?: '-' ?></td>
                                    <td><?= $row['jam_keluar'] ?: '-' ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='hadir'?'success':($row['status']=='telat'?'warning':'info') ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($absensiResult->num_rows == 0): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada data absensi dari karyawan bawahan
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

<!-- PAGE: REQUEST -->
<?php elseif ($page == 'request'): ?>
    <h1 class="page-title">Request System</h1>
    <p class="page-subtitle">Kelola permintaan izin, cuti, sakit, dan lembur yang memerlukan persetujuan Anda</p>

    <?php if (isset($_GET['error'])): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
            <i class="fas fa-exclamation-circle"></i> <?= $_GET['error'] == 'unauthorized' ? 'Anda tidak memiliki akses ke request tersebut!' : 'Terjadi kesalahan!' ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
            <i class="fas fa-check-circle"></i> 
            <?= $_GET['success'] == 'approved' ? 'Request berhasil diproses!' : 'Request berhasil ditolak!' ?>
        </div>
    <?php endif; ?>

    <!-- Requests Needing My Approval -->
    <div class="card" style="margin-bottom:25px">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-clipboard-check"></i> Request Menunggu Persetujuan Anda</span>
            <span class="badge badge-warning"><?= $requestsResult->num_rows ?> pending</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Jenis</th>
                        <th>Tanggal</th>
                        <th>Alasan</th>
                        <th>Level</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $requestsResult->fetch_assoc()): 
                        $approvalChain = getApprovalStatus($conn, $row['id']);
                        $currentLevel = '';
                        foreach ($approvalChain as $level) {
                            if ($level['is_current']) {
                                $currentLevel = $level['user']['nama_jabatan'];
                                break;
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['nama']) ?></strong>
                            <div style="font-size:12px;color:#6b7280"><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></div>
                        </td>
                        <td><?= ucfirst($row['jenis_request']) ?></td>
                        <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                        <td><?= htmlspecialchars(substr($row['alasan'], 0, 30)) ?>...</td>
                        <td>
                            <span class="badge badge-info">
                                <i class="fas fa-layer-group"></i> <?= htmlspecialchars($currentLevel) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-warning">Menunggu Anda</span>
                        </td>
                        <td>
                            <button class="btn btn-success btn-sm" onclick="openApproveModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama']) ?>', '<?= $row['jenis_request'] ?>')">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama']) ?>', '<?= $row['jenis_request'] ?>')">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="btn btn-info btn-sm" onclick="viewApprovalChain(<?= $row['id'] ?>)" title="Lihat Rantai Approval">
                                <i class="fas fa-sitemap"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($requestsResult->num_rows == 0): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:30px;color:#6b7280">
                            <i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:10px;color:#10b981"></i>
                            Tidak ada request yang menunggu persetujuan Anda
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- All Subordinate Requests -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-list-alt"></i> Semua Request Bawahan</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Jenis</th>
                        <th>Tanggal</th>
                        <th>Status Approval</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($allSubordinateRequests):
                        while ($row = $allSubordinateRequests->fetch_assoc()): 
                            $approvalChain = getApprovalStatus($conn, $row['id']);
                            $statusHtml = '';
                            foreach ($approvalChain as $level) {
                                $icon = $level['is_approved'] ? 'check' : ($level['is_rejected'] ? 'times' : ($level['is_current'] ? 'clock' : 'ellipsis-h'));
                                $color = $level['is_approved'] ? 'success' : ($level['is_rejected'] ? 'danger' : ($level['is_current'] ? 'warning' : 'secondary'));
                                $statusHtml .= '<span class="badge badge-' . $color . '" style="margin-right:4px" title="' . htmlspecialchars($level['user']['nama']) . '">';
                                $statusHtml .= '<i class="fas fa-' . $icon . '"></i> ' . htmlspecialchars($level['user']['nama_jabatan']) . '</span>';
                            }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['nama']) ?></td>
                        <td><?= ucfirst($row['jenis_request']) ?></td>
                        <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                        <td><?= $statusHtml ?></td>
                        <td>
                            <span class="badge badge-<?= $row['status']=='pending'?'warning':($row['status']=='disetujui'?'success':'danger') ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php 
                        endwhile;
                    endif;
                    ?>
                    <?php if (!$allSubordinateRequests || $allSubordinateRequests->num_rows == 0): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:30px;color:#6b7280">
                            <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                            Belum ada request dari bawahan
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL: View Approval Chain -->
    <div class="modal-overlay" id="chainModal">
        <div class="modal" style="max-width:600px">
            <div class="modal-header">
                <h3><i class="fas fa-sitemap"></i> Rantai Approval</h3>
                <button class="modal-close" onclick="closeModal('chainModal')">&times;</button>
            </div>
            <div class="modal-body" id="chainContent"></div>
        </div>
    </div>

    <script>
    function viewApprovalChain(requestId) {
        fetch('ajax_approval_chain.php?id=' + requestId)
            .then(r => r.text())
            .then(html => {
                document.getElementById('chainContent').innerHTML = html;
                openModal('chainModal');
            });
    }
    </script>
            <!-- PAGE: KPI -->
            <?php elseif ($page == 'kpi'): ?>
                <h1 class="page-title">Penilaian KPI</h1>
                <p class="page-subtitle">Beri penilaian kinerja karyawan bawahan</p>

                <?php if (isset($_GET['error'])): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-exclamation-circle"></i> <?= $_GET['error'] == 'not_bawahan' ? 'Karyawan tersebut bukan bawahan Anda!' : 'Terjadi kesalahan!' ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> Penilaian KPI berhasil disimpan!
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Form Penilaian KPI</span>
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label>Karyawan</label>
                            <select name="karyawan_id" class="form-control" required>
                                <option value="">Pilih Karyawan</option>
                                <?php
                                $karyawan = getKaryawanByAtasan($conn, $_SESSION['user_id']);
                                while ($k = $karyawan->fetch_assoc()):
                                ?>
                                <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama']) ?> (<?= htmlspecialchars($k['nama_jabatan'] ?? '-') ?>)</option>
                                <?php endwhile; ?>
                            </select>
                            <?php if ($karyawan->num_rows == 0): ?>
                            <small style="color:#dc2626"><i class="fas fa-exclamation-triangle"></i> Belum ada karyawan bawahan</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Periode</label>
                            <input type="text" name="periode" class="form-control" placeholder="Contoh: Januari 2026" required>
                        </div>
                        <div class="form-group">
                            <label>Target (%)</label>
                            <input type="number" step="0.01" name="target" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Realisasi (%)</label>
                            <input type="number" step="0.01" name="realisasi" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Nilai</label>
                            <input type="number" step="0.01" name="nilai" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Komentar</label>
                            <textarea name="komentar" class="form-control" rows="3"></textarea>
                        </div>
                        <button type="submit" name="save_kpi" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Penilaian
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Riwayat Penilaian KPI - Karyawan Bawahan</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Karyawan</th>
                                    <th>Periode</th>
                                    <th>Target</th>
                                    <th>Nilai</th>
                                    <th>Komentar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $kpiResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= htmlspecialchars($row['periode']) ?></td>
                                    <td><?= $row['target'] ?>%</td>
                                    <td><span class="badge badge-<?= $row['nilai']>=80?'success':($row['nilai']>=60?'warning':'danger') ?>"><?= $row['nilai'] ?></span></td>
                                    <td><?= htmlspecialchars($row['komentar'] ?? '-') ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($kpiResult->num_rows == 0): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada penilaian KPI
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                            <!-- PAGE: PROFILE -->
            <?php elseif ($page == 'profile'): ?>
                <h1 class="page-title">Profile Atasan</h1>
                <p class="page-subtitle">Kelola informasi akun Anda</p>

                <?php if ($profileMsg): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> <?= $profileMsg ?>
                    </div>
                <?php endif; ?>
                <?php if ($profileError): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-times-circle"></i> <?= $profileError ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-user-circle"></i> Informasi Profile</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="text-align:center;margin-bottom:25px">
                            <img src="<?= $user['foto'] ?? 'https://via.placeholder.com/120' ?>" 
                                 style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid #10b981;margin-bottom:10px"
                                 id="previewFoto">
                            <br>
                            <label class="btn btn-secondary btn-sm" style="cursor:pointer">
                                <i class="fas fa-camera"></i> Ganti Foto
                                <input type="file" name="foto" style="display:none" accept="image/*" onchange="previewImage(this)">
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Nama Lengkap</label>
                            <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($user['nama']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-id-badge"></i> Username</label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                            <small style="color:#6b7280;font-size:12px">Username harus unik</small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> No. HP</label>
                            <input type="text" name="no_hp" class="form-control" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Divisi</label>
                            <select name="divisi_id" class="form-control" required>
                                <option value="">Pilih Divisi</option>
                                <?php 
                                $divisiList->data_seek(0);
                                while ($d = $divisiList->fetch_assoc()): 
                                ?>
                                <option value="<?= $d['id'] ?>" <?= ($user['divisi_id'] == $d['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['nama_divisi']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Jabatan</label>
                            <select name="jabatan_id" class="form-control" required>
                                <option value="">Pilih Jabatan</option>
                                <?php 
                                $jabatanList->data_seek(0);
                                while ($j = $jabatanList->fetch_assoc()): 
                                ?>
                                <option value="<?= $j['id'] ?>" <?= ($user['jabatan_id'] == $j['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($j['nama_jabatan']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Perubahan
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-key"></i> Ganti Password</span>
                    </div>
                    <?php if ($passMsg): ?>
                        <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                            <i class="fas fa-check-circle"></i> <?= $passMsg ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($passError): ?>
                        <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                            <i class="fas fa-times-circle"></i> <?= $passError ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password Lama</label>
                            <div class="input-group" style="position:relative">
                                <input type="password" name="old_password" id="oldPass" class="form-control" required>
                                <i class="fas fa-eye" style="position:absolute;right:15px;top:50%;transform:translateY(-50%);cursor:pointer;color:#9ca3af" onclick="togglePass('oldPass', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password Baru</label>
                            <div class="input-group" style="position:relative">
                                <input type="password" name="new_password" id="newPass" class="form-control" required>
                                <i class="fas fa-eye" style="position:absolute;right:15px;top:50%;transform:translateY(-50%);cursor:pointer;color:#9ca3af" onclick="togglePass('newPass', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Konfirmasi Password Baru</label>
                            <div class="input-group" style="position:relative">
                                <input type="password" name="confirm_password" id="confirmPass" class="form-control" required>
                                <i class="fas fa-eye" style="position:absolute;right:15px;top:50%;transform:translateY(-50%);cursor:pointer;color:#9ca3af" onclick="togglePass('confirmPass', this)"></i>
                            </div>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-warning">
                            <i class="fas fa-key"></i> Ganti Password
                        </button>
                    </form>
                </div>

            <!-- PAGE: PENGUMUMAN -->
            <?php elseif ($page == 'pengumuman'): ?>
                <h1 class="page-title">Pengumuman</h1>
                <p class="page-subtitle">Lihat pengumuman & kirim ke divisi <?= htmlspecialchars($user['nama_divisi']) ?></p>

                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'added': echo 'Pengumuman berhasil dikirim!'; break;
                            case 'updated': echo 'Pengumuman berhasil diperbarui!'; break;
                            case 'deleted': echo 'Pengumuman berhasil dihapus!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Tab Navigation -->
                <div style="display:flex;gap:10px;margin-bottom:20px;border-bottom:2px solid #e2e8f0">
                    <button class="btn btn-<?= !isset($_GET['tab']) || $_GET['tab']=='semua' ? 'primary' : 'secondary' ?>" 
                            onclick="window.location.href='?page=pengumuman&tab=semua'" style="border-radius:8px 8px 0 0">
                        <i class="fas fa-inbox"></i> Semua Pengumuman
                    </button>
                    <button class="btn btn-<?= isset($_GET['tab']) && $_GET['tab']=='kirim' ? 'primary' : 'secondary' ?>" 
                            onclick="window.location.href='?page=pengumuman&tab=kirim'" style="border-radius:8px 8px 0 0">
                        <i class="fas fa-paper-plane"></i> Kirim Pengumuman
                    </button>
                    <button class="btn btn-<?= isset($_GET['tab']) && $_GET['tab']=='saya' ? 'primary' : 'secondary' ?>" 
                            onclick="window.location.href='?page=pengumuman&tab=saya'" style="border-radius:8px 8px 0 0">
                        <i class="fas fa-user-edit"></i> Pengumuman Saya
                    </button>
                </div>

                <?php 
                $tab = $_GET['tab'] ?? 'semua';
                
                if ($tab == 'semua'): 
                    $allPengumuman = getPengumumanForUser($conn, $_SESSION['user_id'], 'atasan', $atasanDivisi);
                ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-inbox"></i> Semua Pengumuman</span>
                    </div>
                    <div style="padding:20px">
                        <?php 
                        $hasData = false;
                        while ($row = $allPengumuman->fetch_assoc()): 
                            $hasData = true;
                            $isRead = isPengumumanRead($conn, $row['id'], $_SESSION['user_id']);
                            $isExpired = $row['tanggal_kadaluarsa'] && strtotime($row['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                        ?>
                        <div style="padding:20px;border-bottom:1px solid #e2e8f0;<?= $isRead ? '' : 'background:#f0fdf4;border-left:4px solid #10b981' ?>">
                            <div style="display:flex;justify-content:space-between;align-items:start;gap:15px">
                                <div style="flex:1">
                                    <h4 style="font-size:16px;margin-bottom:8px">
                                        <?php if (!$isRead): ?><span class="badge badge-success" style="margin-right:8px">Baru</span><?php endif; ?>
                                        <?= htmlspecialchars($row['judul']) ?>
                                    </h4>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                                        <span class="badge badge-<?= $row['tipe_target']=='semua'?'primary':'warning' ?>">
                                            <?= $row['tipe_target']=='semua'?'Semua':'Div: '.htmlspecialchars($row['nama_divisi']) ?>
                                        </span>
                                        <span class="badge badge-info">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($row['pengirim']) ?>
                                        </span>
                                        <span class="badge badge-secondary">
                                            <i class="fas fa-clock"></i> <?= date('d/m/Y', strtotime($row['created_at'])) ?>
                                        </span>
                                        <?php if ($isExpired): ?>
                                        <span class="badge badge-danger">Expired</span>
                                        <?php endif; ?>
                                    </div>
                                    <p style="color:#4b5563;font-size:14px;line-height:1.6">
                                        <?= nl2br(htmlspecialchars(substr($row['isi'], 0, 200))) ?><?= strlen($row['isi']) > 200 ? '...' : '' ?>
                                    </p>
                                </div>
                                <button class="btn btn-info btn-sm" onclick="viewPengumumanDetail(<?= $row['id'] ?>)" style="white-space:nowrap">
                                    <i class="fas fa-eye"></i> Baca
                                </button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php if (!$hasData): ?>
                        <div style="text-align:center;padding:40px;color:#6b7280">
                            <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                            <p>Tidak ada pengumuman</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($tab == 'kirim'): ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-paper-plane"></i> Kirim Pengumuman ke Divisi <?= htmlspecialchars($user['nama_divisi']) ?></span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="padding:25px" onsubmit="return validateForm('formKirim')" id="formKirim">
                        <div class="form-group">
                            <label>Judul Pengumuman</label>
                            <input type="text" name="judul" class="form-control" required placeholder="Masukkan judul pengumuman...">
                        </div>
                        <div class="form-group">
                            <label>Isi Pengumuman</label>
                            <textarea name="isi" class="form-control" rows="8" required placeholder="Tulis isi pengumuman lengkap..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Target</label>
                            <div class="form-control" style="background:#f3f4f6;cursor:not-allowed">
                                <i class="fas fa-building"></i> Divisi <?= htmlspecialchars($user['nama_divisi']) ?> (Karyawan Bawahan & Divisi)
                            </div>
                            <input type="hidden" name="tipe_target" value="divisi">
                        </div>
                        <div class="form-group">
                            <label>Tanggal Kadaluarsa (Opsional)</label>
                            <input type="date" name="tanggal_kadaluarsa" class="form-control">
                            <small style="color:#6b7280">Pengumuman akan otomatis hilang setelah tanggal ini</small>
                        </div>
                        <div class="form-group">
                            <label>Lampiran File (Opsional)</label>
                            <input type="file" name="file_lampiran" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small style="color:#6b7280">Max 5MB</small>
                        </div>
                        <button type="submit" name="tambah_pengumuman" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Kirim Pengumuman
                        </button>
                    </form>
                </div>

                <?php elseif ($tab == 'saya'): ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-list"></i> Pengumuman yang Saya Buat</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Tanggal</th>
                                    <th>Kadaluarsa</th>
                                    <th>Dibaca</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $hasData = false;
                                while ($row = $myPengumuman->fetch_assoc()): 
                                    $hasData = true;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['judul']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?php if ($row['tanggal_kadaluarsa']): ?>
                                            <?= date('d/m/Y', strtotime($row['tanggal_kadaluarsa'])) ?>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Tidak ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-success">
                                            <i class="fas fa-eye"></i> <?= $row['read_count'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="editPengumuman(<?= htmlspecialchars(json_encode($row)) ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?page=pengumuman&tab=saya&hapus_pengumuman=<?= $row['id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Yakin hapus?')"
                                           title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if (!$hasData): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada pengumuman yang Anda buat
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- MODAL: Edit Pengumuman -->
                <div class="modal-overlay" id="editPengumumanModal">
                    <div class="modal" style="max-width:700px">
                        <div class="modal-header">
                            <h3><i class="fas fa-edit"></i> Edit Pengumuman</h3>
                            <button class="modal-close" onclick="closeModal('editPengumumanModal')">&times;</button>
                        </div>
                        <form method="POST" enctype="multipart/form-data" id="editPengumumanForm">
                            <input type="hidden" name="pengumuman_id" id="editPengumumanId">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Judul</label>
                                    <input type="text" name="judul" id="editPengumumanJudul" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Isi</label>
                                    <textarea name="isi" id="editPengumumanIsi" class="form-control" rows="6" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Tanggal Kadaluarsa</label>
                                    <input type="date" name="tanggal_kadaluarsa" id="editTanggalKadaluarsa" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Lampiran Baru (Opsional)</label>
                                    <input type="file" name="file_lampiran" class="form-control">
                                    <div id="editFileInfo" style="margin-top:8px;font-size:13px"></div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('editPengumumanModal')">Batal</button>
                                <button type="submit" name="edit_pengumuman" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php endif; ?>

                <!-- MODAL: Detail Pengumuman -->
                <div class="modal-overlay" id="detailPengumumanModal">
                    <div class="modal" style="max-width:800px">
                        <div class="modal-header">
                            <h3><i class="fas fa-info-circle"></i> Detail Pengumuman</h3>
                            <button class="modal-close" onclick="closeModal('detailPengumumanModal')">&times;</button>
                        </div>
                        <div class="modal-body" id="detailPengumumanContent"></div>
                    </div>
                </div>

                <script>
                function viewPengumumanDetail(id) {
                    fetch('ajax_pengumuman_detail.php?id=' + id)
                        .then(response => response.text())
                        .then(html => {
                            document.getElementById('detailPengumumanContent').innerHTML = html;
                            openModal('detailPengumumanModal');
                        });
                }
                
                function editPengumuman(data) {
                    document.getElementById('editPengumumanId').value = data.id;
                    document.getElementById('editPengumumanJudul').value = data.judul;
                    document.getElementById('editPengumumanIsi').value = data.isi;
                    document.getElementById('editTanggalKadaluarsa').value = data.tanggal_kadaluarsa || '';
                    
                    if (data.file_lampiran) {
                        document.getElementById('editFileInfo').innerHTML = 
                            '<i class="fas fa-paperclip"></i> File: <a href="' + data.file_lampiran + '" target="_blank">' + data.file_lampiran.split('/').pop() + '</a>';
                    } else {
                        document.getElementById('editFileInfo').innerHTML = 'Tidak ada file lampiran';
                    }
                    
                    openModal('editPengumumanModal');
                }
                </script>

            <?php endif; ?>
            <!-- END CONTENT PAGES -->
        </div>
    </div>
        <!-- MODAL: Approve Request -->
    <div class="modal-overlay" id="approveModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Setujui Request</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Anda akan menyetujui request <strong id="approveJenis"></strong> dari <strong id="approveNama"></strong></p>
                    <input type="hidden" name="request_id" id="approveId">
                    <div class="form-group">
                        <label>Komentar (Opsional)</label>
                        <textarea name="komentar" class="form-control" rows="3" placeholder="Tambahkan komentar..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Batal</button>
                    <button type="submit" name="approve_request" class="btn btn-success">
                        <i class="fas fa-check"></i> Setujui
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Reject Request -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Tolak Request</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Anda akan menolak request <strong id="rejectJenis"></strong> dari <strong id="rejectNama"></strong></p>
                    <input type="hidden" name="request_id" id="rejectId">
                    <div class="form-group">
                        <label>Alasan Penolakan</label>
                        <textarea name="komentar" class="form-control" rows="3" placeholder="Berikan alasan..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Batal</button>
                    <button type="submit" name="reject_request" class="btn btn-danger">
                        <i class="fas fa-times"></i> Tolak
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Notifikasi -->
    <div class="modal-overlay" id="notifModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-bell"></i> Notifikasi</h3>
                <button class="modal-close" onclick="closeModal('notifModal')">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                $stmt = $conn->prepare("SELECT * FROM notifikasi WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $notifs = $stmt->get_result();
                if ($notifs->num_rows == 0) echo '<p>Tidak ada notifikasi</p>';
                while ($n = $notifs->fetch_assoc()):
                ?>
                <div style="padding:15px;border-bottom:1px solid #e2e8f0;<?= $n['is_read']?'':'background:#f0fdf4' ?>">
                    <strong><?= htmlspecialchars($n['judul']) ?></strong>
                    <p style="font-size:13px;color:#6b7280"><?= htmlspecialchars($n['pesan']) ?></p>
                    <small style="color:#9ca3af"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></small>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <script src="js/script.js"></script>
    <script>
        function openApproveModal(id, nama, jenis) {
            document.getElementById('approveId').value = id;
            document.getElementById('approveNama').textContent = nama;
            document.getElementById('approveJenis').textContent = jenis;
            openModal('approveModal');
        }
        
        function openRejectModal(id, nama, jenis) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectNama').textContent = nama;
            document.getElementById('rejectJenis').textContent = jenis;
            openModal('rejectModal');
        }
        
        function filterDate(date) {
            const table = document.getElementById('absensiTable');
            if (!table) return;
            const tr = table.getElementsByTagName('tr');
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td')[1];
                if (td) {
                    const txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.includes(date.split('-').reverse().join('/')) ? '' : 'none';
                }
            }
        }
        
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewFoto').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function togglePass(id, icon) {
            const input = document.getElementById(id);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function searchTable(inputId, tableId) {
            var input = document.getElementById(inputId);
            var filter = input.value.toLowerCase();
            var table = document.getElementById(tableId);
            var tr = table.getElementsByTagName('tr');

            for (var i = 1; i < tr.length; i++) {
                var td = tr[i].getElementsByTagName('td');
                var found = false;
                for (var j = 0; j < td.length; j++) {
                    if (td[j]) {
                        var txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                tr[i].style.display = found ? '' : 'none';
            }
        }
    </script>
    
    <?php if ($page == 'dashboard'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('absensiChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($days ?? []) ?>,
                        datasets: [
                            { label: 'Hadir', data: <?= json_encode($hadirData ?? []) ?>, backgroundColor: '#10b981' },
                            { label: 'Telat', data: <?= json_encode($telatData ?? []) ?>, backgroundColor: '#f59e0b' },
                            { label: 'Izin/Sakit', data: <?= json_encode($izinData ?? []) ?>, backgroundColor: '#3b82f6' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'top' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
        <?php if ($page == 'dashboard'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('absensiChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($days) ?>,
                        datasets: [
                            { 
                                label: 'Hadir', 
                                data: <?= json_encode($hadirData) ?>, 
                                backgroundColor: '#10b981' 
                            },
                            { 
                                label: 'Telat', 
                                data: <?= json_encode($telatData) ?>, 
                                backgroundColor: '#f59e0b' 
                            },
                            { 
                                label: 'Izin/Sakit', 
                                data: <?= json_encode($izinData) ?>, 
                                backgroundColor: '#3b82f6' 
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'top' } 
                        },
                        scales: { 
                            y: { beginAtZero: true } 
                        }
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>