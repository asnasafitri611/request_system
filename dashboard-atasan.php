
# Simpan file dashboard-atasan.php yang sudah diperbaiki
fixed_dashboard_atasan = '''<?php
require_once 'config.php';
checkRole(['atasan']);

$user = getUserById($conn, $_SESSION['user_id']);
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);

// Stats - HANYA karyawan bawahan atasan ini
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE atasan_id = ? AND role='karyawan' AND status='aktif'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$totalKaryawan = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM absensi a 
                        JOIN users u ON a.user_id = u.id 
                        WHERE u.atasan_id = ? AND a.tanggal = CURDATE() AND a.status='hadir'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$jumlahHadir = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM request_system r 
                        JOIN users u ON r.user_id = u.id 
                        WHERE u.atasan_id = ? AND r.status='pending'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_assoc()['total'];

// Data - HANYA karyawan bawahan atasan ini
$absensiResult = getAbsensiByAtasan($conn, $_SESSION['user_id'], 50);
$requestsResult = getRequestByAtasan($conn, $_SESSION['user_id']);
$kpiResult = getKpiByAtasan($conn, $_SESSION['user_id']);

// Handle Approve/Reject Request dengan validasi bawahan
if (isset($_POST['approve_request'])) {
    $reqId = $_POST['request_id'];
    
    // Validasi: request harus dari karyawan bawahan
    $stmt = $conn->prepare("SELECT r.user_id, u.nama FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.id = ? AND u.atasan_id = ?");
    $stmt->bind_param("ii", $reqId, $_SESSION['user_id']);
    $stmt->execute();
    $reqData = $stmt->get_result()->fetch_assoc();
    
    if (!$reqData) {
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
    
    // Validasi: request harus dari karyawan bawahan
    $stmt = $conn->prepare("SELECT r.user_id, u.nama FROM request_system r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.id = ? AND u.atasan_id = ?");
    $stmt->bind_param("ii", $reqId, $_SESSION['user_id']);
    $stmt->execute();
    $reqData = $stmt->get_result()->fetch_assoc();
    
    if (!$reqData) {
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

// Handle KPI Scoring dengan validasi bawahan
if (isset($_POST['save_kpi'])) {
    $userId = (int) $_POST['karyawan_id'];
    
    // Validasi: karyawan harus bawahan atasan ini
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
// HANDLE PROFILE ATASAN (UPDATE LENGKAP)
// ============================================

$profileMsg = '';
$profileError = '';
$passMsg = '';
$passError = '';

// Handle Profile Update
if (isset($_POST['update_profile'])) {
    $nama = $_POST['nama'];
    $email = $_POST['email'];
    $no_hp = $_POST['no_hp'];
    $username = $_POST['username'];
    $divisi_id = $_POST['divisi_id'];
    $jabatan_id = $_POST['jabatan_id'];
    
    // Cek username sudah dipakai user lain?
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
        
        // Refresh user data
        $user = getUserById($conn, $_SESSION['user_id']);
    }
}

// Handle Password Change
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

// Ambil data divisi & jabatan untuk dropdown
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
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="navbar">
            <div class="nav-left">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="breadcrumb">Dashboard / <?= ucfirst($page) ?></span>
            </div>
            <div class="nav-right">
                <div class="nav-icon" onclick="toggleDarkMode()">
                    <i class="fas fa-moon"></i>
                </div>
            </div>
        </div>

        <div class="content">
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

                <!-- HISTORY PENILAIAN KPI -->
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
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 20px; color: #6b7280;">
                                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        Belum ada history penilaian KPI
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- END HISTORY PENILAIAN KPI -->

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
            <?php endif; ?>
        </div>
    </div>

    <!-- Approve Modal -->
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

    <!-- Reject Modal -->
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

    <!-- Notif Modal -->
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
</body>
</html>
