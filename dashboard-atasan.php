<?php
require_once 'config.php';
checkRole(['atasan']);

$user = getUserById($conn, $_SESSION['user_id']);
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);
if (isset($_GET['action']) && $_GET['action'] == 'mark_all_read') {

                                    $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE user_id = ?");

                                    $stmt->bind_param("i", $_SESSION['user_id']);

                                    $stmt->execute();

                                    header("Location: dashboard-atasan.php?page=notifikasi");

                                    exit;

                                }

// Stats - SEMUA karyawan bawahan (langsung & tidak langsung)
$totalKaryawan = countTotalBawahan($conn, $_SESSION['user_id']);
$jumlahHadir = countHadirHariIni($conn, $_SESSION['user_id']);
$pendingRequests = countPendingRequests($conn, $_SESSION['user_id']);
$avgKpi = getAvgKpiBawahan($conn, $_SESSION['user_id']);

// Data
$absensiResult = getAbsensiByAtasan($conn, $_SESSION['user_id'], 50);
$requestsResult = getRequestByAtasan($conn, $_SESSION['user_id']);
$kpiResult = getKpiByAtasan($conn, $_SESSION['user_id']);

// Handle Approve/Reject Request dengan validasi bawahan (recursive)
if (isset($_POST['approve_request'])) {
    $reqId = $_POST['request_id'];
    
    $stmt = $conn->prepare("SELECT r.user_id, u.nama FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.id = ?");
    $stmt->bind_param("i", $reqId);
    $stmt->execute();
    $reqData = $stmt->get_result()->fetch_assoc();
    
    if (!$reqData || !isBawahanRecursive($conn, $reqData['user_id'], $_SESSION['user_id'])) {
        header("Location: dashboard-atasan.php?page=request&error=unauthorized");
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE request_system SET status='disetujui', approved_by=?, komentar_atasan=? WHERE id=?");
    $stmt->bind_param("isi", $_SESSION['user_id'], $_POST['komentar'], $reqId);
    $stmt->execute();
    
    addNotification($conn, $reqData['user_id'], 'Request Disetujui', 'Request Anda telah disetujui atasan');
    
    header("Location: dashboard-atasan.php?page=request&success=approved");
    exit;
}

if (isset($_POST['reject_request'])) {
    $reqId = $_POST['request_id'];
    
    $stmt = $conn->prepare("SELECT r.user_id, u.nama FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.id = ?");
    $stmt->bind_param("i", $reqId);
    $stmt->execute();
    $reqData = $stmt->get_result()->fetch_assoc();
    
    if (!$reqData || !isBawahanRecursive($conn, $reqData['user_id'], $_SESSION['user_id'])) {
        header("Location: dashboard-atasan.php?page=request&error=unauthorized");
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE request_system SET status='ditolak', approved_by=?, komentar_atasan=? WHERE id=?");
    $stmt->bind_param("isi", $_SESSION['user_id'], $_POST['komentar'], $reqId);
    $stmt->execute();
    
    addNotification($conn, $reqData['user_id'], 'Request Ditolak', 'Request Anda ditolak atasan');
    
    header("Location: dashboard-atasan.php?page=request&success=rejected");
    exit;
}

// Handle KPI Scoring dengan validasi bawahan (recursive)
if (isset($_POST['save_kpi'])) {
    $userId = (int) $_POST['karyawan_id'];
    
    if (!isBawahanRecursive($conn, $userId, $_SESSION['user_id'])) {
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
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
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
        
        $stmt = $conn->prepare("UPDATE users SET nama = ?, email = ?, no_hp = ?, username = ?, divisi_id = ?, jabatan_id = ?, foto = ? WHERE id = ?");
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
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $_SESSION['user_id']);
        $stmt->execute();
        $passMsg = "Password berhasil diubah!";
    } else {
        $passError = "Password lama salah atau konfirmasi tidak cocok!";
    }
}

$divisiList = $conn->query("SELECT * FROM divisi ORDER BY nama_divisi");
$jabatanList = $conn->query("SELECT * FROM jabatan ORDER BY nama_jabatan");

// ============================================
// DATA PENGUMUMAN - SESUAI DATABASE
// ============================================

// Ambil pengumuman yang relevan untuk user ini (semua divisi atau divisi user)
$currentDivisiId = $user['divisi_id'] ?? 0;
$stmtPengumuman = $conn->prepare("
    SELECT p.*, u.nama as created_by_nama 
    FROM pengumuman p 
    LEFT JOIN users u ON p.created_by = u.id 
    WHERE p.tipe_target = 'semua' 
       OR (p.tipe_target = 'divisi' AND p.divisi_id = ?)
    ORDER BY p.created_at DESC
");
$stmtPengumuman->bind_param("i", $currentDivisiId);
$stmtPengumuman->execute();
$pengumumanList = $stmtPengumuman->get_result();

// Hitung pengumuman belum dibaca
$stmtUnreadPengumuman = $conn->prepare("
    SELECT COUNT(*) as unread_count 
    FROM pengumuman p 
    LEFT JOIN pengumuman_read pr ON p.id = pr.pengumuman_id AND pr.user_id = ?
    WHERE pr.id IS NULL 
    AND (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
");
$stmtUnreadPengumuman->bind_param("ii", $_SESSION['user_id'], $currentDivisiId);
$stmtUnreadPengumuman->execute();
$unreadPengumumanCount = $stmtUnreadPengumuman->get_result()->fetch_assoc()['unread_count'];

// Handle mark read pengumuman
if (isset($_GET['mark_pengumuman_read']) && is_numeric($_GET['mark_pengumuman_read'])) {
    $pengumumanId = (int)$_GET['mark_pengumuman_read'];
    $stmt = $conn->prepare("INSERT IGNORE INTO pengumuman_read (pengumuman_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $pengumumanId, $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-atasan.php?page=pengumuman");
    exit;
}

// Data untuk chart absensi mingguan
$days = []; 
$hadirData = []; 
$telatData = []; 
$izinData = [];
$bawahanIds = getAllBawahanIds($conn, $_SESSION['user_id']);

if (!empty($bawahanIds)) {
    $placeholders = implode(',', array_fill(0, count($bawahanIds), '?'));
    $types = str_repeat('i', count($bawahanIds));
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $days[] = date('D', strtotime($date));
        
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM absensi 
                                WHERE user_id IN ($placeholders) 
                                AND tanggal = ? AND status = 'hadir'");
        $params = array_merge($bawahanIds, [$date]);
        $stmt->bind_param($types . "s", ...$params);
        $stmt->execute();
        $hadirData[] = $stmt->get_result()->fetch_assoc()['c'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM absensi 
                                WHERE user_id IN ($placeholders) 
                                AND tanggal = ? AND status = 'telat'");
        $stmt->bind_param($types . "s", ...$params);
        $stmt->execute();
        $telatData[] = $stmt->get_result()->fetch_assoc()['c'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM absensi 
                                WHERE user_id IN ($placeholders) 
                                AND tanggal = ? AND status IN ('izin','sakit')");
        $stmt->bind_param($types . "s", ...$params);
        $stmt->execute();
        $izinData[] = $stmt->get_result()->fetch_assoc()['c'];
    }
}
// ============================================
// HANDLE NOTIFIKASI
// ============================================

if ($page == 'notifikasi') {

    if (isset($_GET['action']) && $_GET['action'] == 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();

        header("Location: dashboard-atasan.php?page=notifikasi");
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'delete_all') {
        $stmt = $conn->prepare("DELETE FROM notifikasi WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();

        header("Location: dashboard-atasan.php?page=notifikasi");
        exit;
    }

    if (isset($_GET['mark_read'])) {
        $notifId = (int) $_GET['mark_read'];

        $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notifId, $_SESSION['user_id']);
        $stmt->execute();

        header("Location: dashboard-atasan.php?page=notifikasi");
        exit;
    }

    if (isset($_GET['delete'])) {
        $notifId = (int) $_GET['delete'];

        $stmt = $conn->prepare("DELETE FROM notifikasi WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notifId, $_SESSION['user_id']);
        $stmt->execute();

        header("Location: dashboard-atasan.php?page=notifikasi");
        exit;
    }
}
// ============================================
// HANDLE PENGUMUMAN ATASAN (CRUD)
// ============================================

$pengumumanMsg = '';
$pengumumanError = '';

// Cek apakah atasan ini memiliki bawahan
$hasBawahan = $totalKaryawan > 0;

// Handle Create Pengumuman (Atasan hanya untuk bawahan/divisi sendiri)
if (isset($_POST['create_pengumuman']) && $hasBawahan) {
    $judul = trim($_POST['judul']);
    $isi = trim($_POST['isi']);
    $tipe_target = $_POST['tipe_target'];
    $divisi_id = !empty($_POST['divisi_id']) ? (int)$_POST['divisi_id'] : null;
    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;
    
    // Validasi: atasan hanya bisa kirim ke divisi sendiri atau semua (tapi hanya untuk bawahan)
    if ($tipe_target == 'divisi' && $divisi_id != $user['divisi_id']) {
        $pengumumanError = "Anda hanya dapat mengirim pengumuman ke divisi Anda sendiri!";
    } elseif (empty($judul) || empty($isi)) {
        $pengumumanError = "Judul dan isi pengumuman wajib diisi!";
    } else {
        // Handle file lampiran
        $file_lampiran = null;
        if (!empty($_FILES['file_lampiran']['name'])) {
            $uploadDir = 'uploads/lampiran/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $fileType = $_FILES['file_lampiran']['type'];
            $fileSize = $_FILES['file_lampiran']['size'];
            
            if (!in_array($fileType, $allowedTypes)) {
                $pengumumanError = "Format file tidak didukung! (PDF, JPG, PNG, DOC, DOCX)";
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $pengumumanError = "Ukuran file maksimal 5MB!";
            } else {
                $ext = pathinfo($_FILES['file_lampiran']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $file_lampiran = $uploadDir . $fileName;
                if (!move_uploaded_file($_FILES['file_lampiran']['tmp_name'], $file_lampiran)) {
                    $pengumumanError = "Gagal mengupload file!";
                    $file_lampiran = null;
                }
            }
        }
        
        if (empty($pengumumanError)) {
            // Untuk atasan, default divisi_id adalah divisi atasan jika tidak dipilih
            if ($tipe_target == 'divisi' && empty($divisi_id)) {
                $divisi_id = $user['divisi_id'];
            }
            
            $stmt = $conn->prepare("INSERT INTO pengumuman (judul, isi, tipe_target, divisi_id, file_lampiran, tanggal_kadaluarsa, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssisss", $judul, $isi, $tipe_target, $divisi_id, $file_lampiran, $tanggal_kadaluarsa, $_SESSION['user_id']);
            $stmt->execute();
            $pengumumanId = $conn->insert_id;
            
            // Kirim notifikasi ke bawahan (semua level bawahan)
            $bawahanIds = getAllBawahanIds($conn, $_SESSION['user_id']);
            if (!empty($bawahanIds)) {
                foreach ($bawahanIds as $bid) {
                    addNotification($conn, $bid, 'Pengumuman dari Atasan', 'Ada pengumuman baru dari atasan: ' . $judul);
                }
            }
            
            $pengumumanMsg = "Pengumuman berhasil dibuat dan dikirim ke " . count($bawahanIds) . " bawahan!";
        }
    }
}

// Handle Delete Pengumuman (hanya bisa hapus yang dibuat sendiri)
if (isset($_GET['delete_pengumuman'])) {
    $id = (int)$_GET['delete_pengumuman'];
    $stmt = $conn->prepare("SELECT created_by FROM pengumuman WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $createdBy = $stmt->get_result()->fetch_assoc()['created_by'] ?? 0;
    
    if ($createdBy == $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM pengumuman WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $pengumumanMsg = "Pengumuman berhasil dihapus!";
    } else {
        $pengumumanError = "Anda hanya dapat menghapus pengumuman yang Anda buat!";
    }
}

// Get pengumuman yang dibuat oleh atasan ini + yang relevan untuk ditampilkan
$pengumumanCreated = $conn->query("
    SELECT p.*, d.nama_divisi, 
    (SELECT COUNT(*) FROM pengumuman_read WHERE pengumuman_id = p.id) as read_count
    FROM pengumuman p 
    LEFT JOIN divisi d ON p.divisi_id = d.id 
    WHERE p.created_by = {$_SESSION['user_id']}
    ORDER BY p.created_at DESC
");

// ============================================
// HANDLE NOTIFIKASI (PERBAIKAN - HAPUS DUPLIKASI)
// ============================================

// Hapus duplikasi: pindahkan semua handler GET ke sini dan hapus yang di bawah
// Hapus blok if ($page == 'notifikasi') yang lama (baris ~160-200an) dan ganti dengan yang ini:
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
            <a href="?page=pengumuman" class="nav-item <?= $page=='pengumuman'?'active':'' ?>">
                <i class="fas fa-bullhorn"></i>
                <span>Pengumuman</span>
                <?php 
                $unreadPengumumanAtasan = countUnreadPengumuman($conn, $_SESSION['user_id'], $user['divisi_id']);
                if ($unreadPengumumanAtasan > 0): 
                ?>
                    <span class="badge" style="background:#3b82f6"><?= $unreadPengumumanAtasan ?></span>
                <?php endif; ?>
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
                <a href="dashboard-atasan.php?page=notifikasi" class="nav-icon" style="text-decoration:none;position:relative;color:inherit">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-badge" style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;font-size:10px;padding:2px 6px;border-radius:10px"><?= $notifCount ?></span>
                    <?php endif; ?>
                </a>
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
                            <h3><?= $avgKpi ?></h3>
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

            <?php elseif ($page == 'karyawan-saya'): ?>
                <h1 class="page-title">Karyawan Saya</h1>
                <p class="page-subtitle">Daftar karyawan yang menjadi bawahan Anda (langsung & tidak langsung)</p>

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
                                    <th>Level</th>
                                    <th>Bergabung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $allBawahan = getAllBawahanRecursive($conn, $_SESSION['user_id']);
                                if (empty($allBawahan)):
                                ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        <p>Belum ada karyawan yang ditugaskan ke Anda</p>
                                        <p style="font-size:12px">Hubungi admin untuk menambahkan karyawan</p>
                                    </td>
                                </tr>
                                <?php else: 
                                    foreach ($allBawahan as $row):
                                        $bLevel = getHierarchyLevel($conn, $row['id']);
                                ?>
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
                                        <td><span class="badge badge-info">Level <?= $bLevel ?></span></td>
                                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
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
                <p class="page-subtitle">Kelola permintaan izin, cuti, sakit, dan lembur karyawan bawahan</p>

                <?php if (isset($_GET['error'])): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-exclamation-circle"></i> <?= $_GET['error'] == 'unauthorized' ? 'Anda tidak memiliki akses ke request tersebut!' : 'Terjadi kesalahan!' ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> <?= $_GET['success'] == 'approved' ? 'Request berhasil disetujui!' : 'Request berhasil ditolak!' ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Daftar Request - Karyawan Bawahan</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Karyawan</th>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $requestsResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= ucfirst($row['jenis_request']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                                    <td><?= htmlspecialchars(substr($row['alasan'], 0, 30)) ?>...</td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='pending'?'warning':($row['status']=='disetujui'?'success':'danger') ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] == 'pending'): ?>
                                        <button class="btn btn-success btn-sm" onclick="openApproveModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama']) ?>', '<?= $row['jenis_request'] ?>')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama']) ?>', '<?= $row['jenis_request'] ?>')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="badge badge-info"><?= $row['komentar_atasan'] ?: 'Tidak ada komentar' ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($requestsResult->num_rows == 0): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada request dari karyawan bawahan
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
                                $allBawahan = getAllBawahanRecursive($conn, $_SESSION['user_id']);
                                foreach ($allBawahan as $k):
                                ?>
                                <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama']) ?> (<?= htmlspecialchars($k['nama_jabatan'] ?? '-') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($allBawahan)): ?>
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

                <div class="card" style="margin-top: 25px;">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-history"></i> History Penilaian KPI</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="historyKpiTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Karyawan</th>
                                    <th>Periode</th>
                                    <th>Target</th>
                                    <th>Nilai</th>
                                    <th>Komentar</th>
                                    <th>Dinilai Oleh</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $historyKpiQuery = "SELECT k.*, u.nama as karyawan_nama, a.nama as atasan_nama 
                                                   FROM kpi k 
                                                   JOIN users u ON k.user_id = u.id 
                                                   LEFT JOIN users a ON k.created_by = a.id 
                                                   ORDER BY k.created_at DESC";
                                $historyKpiResult = $conn->query($historyKpiQuery);
                                $no = 1;
                                if ($historyKpiResult && $historyKpiResult->num_rows > 0):
                                    while ($row = $historyKpiResult->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($row['karyawan_nama']) ?></td>
                                    <td><?= htmlspecialchars($row['periode']) ?></td>
                                    <td><?= $row['target'] ?>%</td>
                                    <td>
                                        <span class="badge badge-<?= $row['nilai']>=80?'success':($row['nilai']>=60?'warning':'danger') ?>">
                                            <?= $row['nilai'] ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['komentar'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['atasan_nama'] ?? 'System') ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px; color: #6b7280;">
                                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        Belum ada history penilaian KPI
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                            <?php elseif ($page == 'pengumuman'): ?>
                <h1 class="page-title">Pengumuman</h1>
                <p class="page-subtitle">Kelola pengumuman untuk karyawan bawahan</p>

                <?php if ($pengumumanMsg): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> <?= $pengumumanMsg ?>
                    </div>
                <?php endif; ?>
                <?php if ($pengumumanError): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-times-circle"></i> <?= $pengumumanError ?>
                    </div>
                <?php endif; ?>

                <?php if ($hasBawahan): ?>
                <!-- Form Buat Pengumuman -->
                <div class="card" style="margin-bottom:25px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-plus-circle"></i> Buat Pengumuman untuk Bawahan</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="padding:20px">
                        <div class="form-group">
                            <label><i class="fas fa-heading"></i> Judul Pengumuman</label>
                            <input type="text" name="judul" class="form-control" required placeholder="Masukkan judul pengumuman...">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Isi Pengumuman</label>
                            <textarea name="isi" class="form-control" rows="5" required placeholder="Tulis isi pengumuman di sini..."></textarea>
                        </div>
                        
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;margin-bottom:15px">
                            <div class="form-group">
                                <label><i class="fas fa-bullseye"></i> Target Pengumuman</label>
                                <select name="tipe_target" id="tipeTargetAtasan" class="form-control" required onchange="toggleDivisiSelectAtasan()">
                                    <option value="semua">Semua Bawahan (Semua Divisi)</option>
                                    <option value="divisi">Divisi Saya Saja (<?= htmlspecialchars($user['nama_divisi'] ?? 'Divisi') ?>)</option>
                                </select>
                            </div>
                            
                            <div class="form-group" id="divisiSelectAtasan" style="display:none">
                                <label><i class="fas fa-building"></i> Divisi</label>
                                <select name="divisi_id" class="form-control">
                                    <option value="<?= $user['divisi_id'] ?>"><?= htmlspecialchars($user['nama_divisi'] ?? 'Divisi Saya') ?></option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-calendar-times"></i> Tanggal Kadaluarsa (Opsional)</label>
                                <input type="date" name="tanggal_kadaluarsa" class="form-control" min="<?= date('Y-m-d') ?>">
                                <small style="color:#6b7280">Pengumuman akan otomatis hilang setelah tanggal ini</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-paperclip"></i> Lampiran File (Opsional)</label>
                            <input type="file" name="file_lampiran" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <small style="color:#6b7280">Format: PDF, JPG, PNG, DOC, DOCX. Max 5MB</small>
                        </div>
                        
                        <button type="submit" name="create_pengumuman" class="btn btn-primary" style="padding:10px 25px">
                            <i class="fas fa-paper-plane"></i> Kirim ke Bawahan
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="card" style="margin-bottom:25px;background:#fef3c7;border-left:4px solid #f59e0b">
                    <div style="padding:20px">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Anda belum memiliki karyawan bawahan.</strong> 
                        Hubungi admin untuk menambahkan karyawan bawahan agar dapat membuat pengumuman.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pengumuman yang Dibuat Atasan Ini -->
                <div class="card" style="margin-bottom:25px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-paper-plane"></i> Pengumuman yang Anda Buat</span>
                        <span class="badge badge-primary"><?= $pengumumanCreated->num_rows ?> pengumuman</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Target</th>
                                    <th>Tanggal</th>
                                    <th>Kadaluarsa</th>
                                    <th>Dibaca</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($p = $pengumumanCreated->fetch_assoc()): 
                                    $isExpired = !empty($p['tanggal_kadaluarsa']) && strtotime($p['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                                ?>
                                <tr style="<?= $isExpired ? 'opacity:0.6;background:#f3f4f6' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($p['judul']) ?></strong>
                                        <?php if (!empty($p['file_lampiran'])): ?>
                                            <br><small><i class="fas fa-paperclip"></i> Ada lampiran</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['tipe_target'] == 'semua'): ?>
                                            <span class="badge" style="background:#8b5cf6;color:#fff">Semua Bawahan</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#3b82f6;color:#fff"><?= htmlspecialchars($p['nama_divisi'] ?? 'Divisi') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
                                    <td>
                                        <?php if ($p['tanggal_kadaluarsa']): ?>
                                            <?= date('d/m/Y', strtotime($p['tanggal_kadaluarsa'])) ?>
                                            <?php if ($isExpired): ?>
                                                <span class="badge badge-secondary">Expired</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#9ca3af">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-success">
                                            <i class="fas fa-eye"></i> <?= $p['read_count'] ?> kali
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isExpired): ?>
                                            <span class="badge badge-secondary">Kadaluarsa</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?page=pengumuman&delete_pengumuman=<?= $p['id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Yakin hapus pengumuman ini?')"
                                           title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($pengumumanCreated->num_rows == 0): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada pengumuman yang Anda buat
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pengumuman yang Diterima (Read-only) -->
                <h2 style="margin-top:30px;margin-bottom:15px;font-size:18px;color:#374151">
                    <i class="fas fa-inbox"></i> Pengumuman yang Diterima
                </h2>
                
                <?php if ($pengumumanList && $pengumumanList->num_rows > 0): ?>
                    <div class="pengumuman-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px;">
                        <?php 
                        $pengumumanList->data_seek(0); // Reset pointer
                        while ($p = $pengumumanList->fetch_assoc()): 
                            $stmtRead = $conn->prepare("SELECT id FROM pengumuman_read WHERE pengumuman_id = ? AND user_id = ?");
                            $stmtRead->bind_param("ii", $p['id'], $_SESSION['user_id']);
                            $stmtRead->execute();
                            $isRead = $stmtRead->get_result()->num_rows > 0;
                            
                            $isExpired = !empty($p['tanggal_kadaluarsa']) && strtotime($p['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                            
                            $borderColor = $p['tipe_target'] == 'semua' ? '#8b5cf6' : '#3b82f6';
                            $badgeClass = $p['tipe_target'] == 'semua' ? 'badge-purple' : 'badge-blue';
                            $badgeText = $p['tipe_target'] == 'semua' ? 'Semua Divisi' : 'Divisi ' . htmlspecialchars($p['nama_divisi'] ?? 'Tertentu');
                        ?>
                        <div class="card" style="border-left: 4px solid <?= $borderColor ?>; <?= $isExpired ? 'opacity: 0.6;' : '' ?> <?= $isRead ? '' : 'background: #fefce8;' ?>">
                            <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                                <span class="card-title" style="font-size: 16px; font-weight: 600;">
                                    <i class="fas fa-bullhorn" style="color: <?= $borderColor ?>"></i>
                                    <?= htmlspecialchars($p['judul']) ?>
                                </span>
                                <div style="display:flex;gap:5px;flex-wrap:wrap">
                                    <span class="badge" style="background:<?= $borderColor ?>;color:#fff"><?= $badgeText ?></span>
                                    <?php if ($isExpired): ?>
                                        <span class="badge badge-secondary"><i class="fas fa-clock"></i> Kadaluarsa</span>
                                    <?php endif; ?>
                                    <?php if (!$isRead): ?>
                                        <span class="badge badge-warning">Baru</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="padding: 15px 0; color: #4b5563; line-height: 1.6;">
                                <?= nl2br(htmlspecialchars($p['isi'])) ?>
                            </div>
                            
                            <?php if (!empty($p['file_lampiran'])): ?>
                            <div style="padding: 10px 0;">
                                <a href="<?= htmlspecialchars($p['file_lampiran']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-paperclip"></i> Lihat Lampiran
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <div style="border-top: 1px solid #e5e7eb; padding-top: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 12px; color: #6b7280;">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($p['created_by_nama'] ?? 'Admin') ?></span>
                                <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></span>
                                <?php if (!empty($p['tanggal_kadaluarsa'])): ?>
                                    <span><i class="fas fa-calendar-times"></i> Kadaluarsa: <?= date('d/m/Y', strtotime($p['tanggal_kadaluarsa'])) ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!$isRead): ?>
                            <div style="margin-top: 12px; text-align: right;">
                                <a href="?page=pengumuman&mark_pengumuman_read=<?= $p['id'] ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-check"></i> Tandai Dibaca
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="card" style="text-align: center; padding: 50px;">
                        <i class="fas fa-bullhorn" style="font-size: 48px; color: #d1d5db; margin-bottom: 15px; display: block;"></i>
                        <h3 style="color: #6b7280; margin-bottom: 8px;">Belum Ada Pengumuman</h3>
                        <p style="color: #9ca3af;">Tidak ada pengumuman untuk divisi Anda saat ini</p>
                    </div>
                <?php endif; ?>

                <script>
                function toggleDivisiSelectAtasan() {
                    const tipe = document.getElementById('tipeTargetAtasan').value;
                    const divisiSelect = document.getElementById('divisiSelectAtasan');
                    divisiSelect.style.display = tipe === 'divisi' ? 'block' : 'none';
                }
                </script>

                        <?php elseif ($page == 'notifikasi'): ?>
                <h1 class="page-title">Notifikasi</h1>
                <p class="page-subtitle">Kelola semua notifikasi Anda</p>

                <div class="card">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                        <span class="card-title"><i class="fas fa-bell"></i> Daftar Notifikasi</span>
                        <div>
                            <a href="?page=notifikasi&action=mark_all_read" class="btn btn-secondary btn-sm">
                                <i class="fas fa-check-double"></i> Tandai Semua Dibaca
                            </a>
                            <a href="?page=notifikasi&action=delete_all" class="btn btn-danger btn-sm" onclick="return confirm('Yakin hapus semua notifikasi?')">
                                <i class="fas fa-trash"></i> Hapus Semua
                            </a>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Judul</th>
                                    <th>Pesan</th>
                                    <th>Waktu</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $conn->prepare("SELECT * FROM notifikasi WHERE user_id = ? ORDER BY created_at DESC");
                                $stmt->bind_param("i", $_SESSION['user_id']);
                                $stmt->execute();
                                $notifs = $stmt->get_result();
                                
                                if ($notifs->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Tidak ada notifikasi
                                    </td>
                                </tr>
                                <?php else: 
                                    while ($n = $notifs->fetch_assoc()):
                                ?>
                                <tr style="<?= $n['is_read'] ? '' : 'background:#f0fdf4' ?>">
                                    <td>
                                        <?php if (!$n['is_read']): ?>
                                            <span class="badge badge-warning">Baru</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Dibaca</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($n['judul']) ?></strong></td>
                                    <td><?= htmlspecialchars($n['pesan']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></td>
                                    <td>
                                        <?php if (!$n['is_read']): ?>
                                        <a href="?page=notifikasi&mark_read=<?= $n['id'] ?>" class="btn btn-success btn-sm" title="Tandai dibaca">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?page=notifikasi&delete=<?= $n['id'] ?>" class="btn btn-danger btn-sm" title="Hapus" onclick="return confirm('Yakin hapus notifikasi ini?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
    
    <?php if ($page == 'dashboard' && !empty($days)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('absensiChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($days) ?>,
                        datasets: [
                            { label: 'Hadir', data: <?= json_encode($hadirData) ?>, backgroundColor: '#10b981' },
                            { label: 'Telat', data: <?= json_encode($telatData) ?>, backgroundColor: '#f59e0b' },
                            { label: 'Izin/Sakit', data: <?= json_encode($izinData) ?>, backgroundColor: '#3b82f6' }
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