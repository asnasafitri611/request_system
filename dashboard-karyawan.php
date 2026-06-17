
<?php
require_once 'config.php';
checkRole(['karyawan']);

$user = getUserById($conn, $_SESSION['user_id']);
$today = date('Y-m-d');
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);

// Get atasan info
$atasanInfo = getAtasanByKaryawan($conn, $_SESSION['user_id']);

// Check today's attendance
$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? AND tanggal = ?");
$stmt->bind_param("is", $_SESSION['user_id'], $today);
$stmt->execute();
$todayAbsen = $stmt->get_result()->fetch_assoc();

// Attendance History
$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY tanggal DESC LIMIT 10");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$absensiHistory = $stmt->get_result();

// Requests
$stmt = $conn->prepare("SELECT * FROM request_system WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$requests = $stmt->get_result();

// KPI
$stmt = $conn->prepare("SELECT * FROM kpi WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$latestKpi = $stmt->get_result()->fetch_assoc();

// Stats
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM absensi WHERE user_id = ? AND status = 'hadir'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$totalHadir = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM request_system WHERE user_id = ? AND status = 'pending'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$pendingReq = $stmt->get_result()->fetch_assoc()['total'];

// Pengumuman untuk karyawan (semua atau divisi sendiri)
$stmt = $conn->prepare("SELECT p.*, u.nama as created_by_name 
                        FROM pengumuman p 
                        LEFT JOIN users u ON p.created_by = u.id 
                        WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
                        AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
                        ORDER BY p.created_at DESC LIMIT 5");
$stmt->bind_param("i", $user['divisi_id']);
$stmt->execute();
$pengumumanList = $stmt->get_result();

// ============================================
// HANDLE SEMUA ACTION DI SINI - SEBELUM OUTPUT
// ============================================

// Handle Check In
if (isset($_POST['checkin'])) {
    $jam = date('H:i:s');
    $status = (strtotime($jam) > strtotime('08:00:00')) ? 'telat' : 'hadir';
    $stmt = $conn->prepare("INSERT INTO absensi (user_id, tanggal, jam_masuk, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $_SESSION['user_id'], $today, $jam, $status);
    $stmt->execute();
    header("Location: dashboard-karyawan.php");
    exit;
}

// Handle Check Out
if (isset($_POST['checkout'])) {
    $jam = date('H:i:s');
    $stmt = $conn->prepare("UPDATE absensi SET jam_keluar = ? WHERE user_id = ? AND tanggal = ?");
    $stmt->bind_param("sis", $jam, $_SESSION['user_id'], $today);
    $stmt->execute();
    header("Location: dashboard-karyawan.php");
    exit;
}

// Handle Request
if (isset($_POST['submit_request'])) {
    $jenis = $_POST['jenis_request'];
    $tgl_mulai = $_POST['tanggal_mulai'];
    $tgl_selesai = $_POST['tanggal_selesai'] ?: $tgl_mulai;
    $alasan = $_POST['alasan'];
    
    $file_bukti = '';
    if (!empty($_FILES['file_bukti']['name'])) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $file_bukti = $uploadDir . time() . '_' . basename($_FILES['file_bukti']['name']);
        move_uploaded_file($_FILES['file_bukti']['tmp_name'], $file_bukti);
    }
    
    $atasanId = $user['parent_id'] ?? null;
    if (!$atasanId) {
        $atasanId = $user['atasan_id'] ?? null;
    }
    
    $stmt = $conn->prepare("INSERT INTO request_system (user_id, atasan_id, jenis_request, tanggal_mulai, tanggal_selesai, alasan, file_bukti) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $_SESSION['user_id'], $atasanId, $jenis, $tgl_mulai, $tgl_selesai, $alasan, $file_bukti);
    $stmt->execute();
    
    if ($atasanId) {
        addNotification($conn, $atasanId, 'Request Baru', $_SESSION['nama'] . ' mengajukan ' . $jenis);
    }
    
    header("Location: dashboard-karyawan.php?page=request&success=submitted");
    exit;
}

// Handle Profile Update
if (isset($_POST['update_profile'])) {
    $nama = $_POST['nama'];
    $email = $_POST['email'];
    $no_hp = $_POST['no_hp'];
    
    $foto = $user['foto'];
    if (!empty($_FILES['foto']['name'])) {
        $uploadDir = 'uploads/profile/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $foto = $uploadDir . time() . '_' . basename($_FILES['foto']['name']);
        move_uploaded_file($_FILES['foto']['tmp_name'], $foto);
        $_SESSION['foto'] = $foto;
    }
    
    $stmt = $conn->prepare("UPDATE users SET nama = ?, email = ?, no_hp = ?, foto = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $nama, $email, $no_hp, $foto, $_SESSION['user_id']);
    $stmt->execute();
    $_SESSION['nama'] = $nama;
    header("Location: dashboard-karyawan.php?page=profile&success=updated");
    exit;
}

// Handle Password Change
$passMsg = '';
$passError = '';
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

// Handle Mark Pengumuman Read
if (isset($_GET['read_pengumuman'])) {
    $pengumumanId = (int) $_GET['read_pengumuman'];
    $stmt = $conn->prepare("INSERT IGNORE INTO pengumuman_read (pengumuman_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $pengumumanId, $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=dashboard");
    exit;
}

// ============================================
// HANDLE NOTIFIKASI ACTION (PINDAH KE ATAS)
// ============================================

if (isset($_GET['action']) && $_GET['action'] == 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=notifikasi");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'delete_all') {
    $stmt = $conn->prepare("DELETE FROM notifikasi WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=notifikasi");
    exit;
}

if (isset($_GET['mark_read'])) {
    $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $_GET['mark_read'], $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=notifikasi");
    exit;
}

if (isset($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM notifikasi WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $_GET['delete'], $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=notifikasi");
    exit;
}

// ============================================
// HANDLE PENGUMUMAN PAGE ACTION
// ============================================

if (isset($_GET['mark_pengumuman']) && is_numeric($_GET['mark_pengumuman'])) {
    $pengumumanId = (int)$_GET['mark_pengumuman'];
    $stmt = $conn->prepare("INSERT IGNORE INTO pengumuman_read (pengumuman_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $pengumumanId, $_SESSION['user_id']);
    $stmt->execute();
    header("Location: dashboard-karyawan.php?page=pengumuman");
    exit;
}

// ============================================
// BARU OUTPUT HTML DI BAWAH SINI
// ============================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Karyawan - Request System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-building"></i>
            <h2>Request System</h2>
        </div>
        <div class="user-info">
            <img src="<?= $user['foto'] ?? 'https://via.placeholder.com/70' ?>" alt="Profile">
            <h4><?= htmlspecialchars($_SESSION['nama']) ?></h4>
            <span>Karyawan</span>
            <?php if ($atasanInfo): ?>
            <small style="color:#6b7280;font-size:11px;margin-top:4px">
                <i class="fas fa-user-tie"></i> Atasan: <?= htmlspecialchars($atasanInfo['nama']) ?>
            </small>
            <?php endif; ?>
        </div>
        <div class="nav-menu">
            <a href="?page=dashboard" class="nav-item <?= $page=='dashboard'?'active':'' ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="?page=absensi" class="nav-item <?= $page=='absensi'?'active':'' ?>">
                <i class="fas fa-clock"></i>
                <span>Absensi</span>
            </a>
            <a href="?page=request" class="nav-item <?= $page=='request'?'active':'' ?>">
                <i class="fas fa-file-alt"></i>
                <span>Request</span>
                <?php if ($pendingReq > 0): ?>
                    <span class="badge"><?= $pendingReq ?></span>
                <?php endif; ?>
            </a>
            <a href="?page=kpi" class="nav-item <?= $page=='kpi'?'active':'' ?>">
                <i class="fas fa-chart-line"></i>
                <span>KPI</span>
            </a>
            <a href="?page=pengumuman" class="nav-item <?= $page=='pengumuman'?'active':'' ?>">
                <i class="fas fa-bullhorn"></i>
                <span>Pengumuman</span>
                <?php 
                $stmt = $conn->prepare("SELECT COUNT(*) as c FROM pengumuman p 
                                        WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
                                        AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
                                        AND NOT EXISTS (SELECT 1 FROM pengumuman_read pr WHERE pr.pengumuman_id = p.id AND pr.user_id = ?)");
                $stmt->bind_param("ii", $user['divisi_id'], $_SESSION['user_id']);
                $stmt->execute();
                $unreadPengumumanKaryawan = $stmt->get_result()->fetch_assoc()['c'];
                if ($unreadPengumumanKaryawan > 0):
                ?>
                    <span class="badge badge-danger"><?= $unreadPengumumanKaryawan ?></span>
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
                <a href="dashboard-karyawan.php?page=notifikasi" class="nav-icon" style="text-decoration:none;position:relative;color:inherit">
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

        <div class="content">
            <?php if ($page == 'dashboard'): ?>
                <h1 class="page-title">Dashboard Karyawan</h1>
                <p class="page-subtitle">Selamat datang, <?= htmlspecialchars($_SESSION['nama']) ?>! Hari ini <?= date('d F Y') ?></p>

                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'submitted': echo 'Request berhasil dikirim!'; break;
                            case 'updated': echo 'Profile berhasil diperbarui!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if ($pengumumanList->num_rows > 0): ?>
                <div class="card" style="margin-bottom:20px;border-left:4px solid #3b82f6">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-bullhorn"></i> Pengumuman Terbaru</span>
                        <a href="?page=pengumuman" class="btn btn-sm btn-secondary">Lihat Semua</a>
                    </div>
                    <div style="padding:15px">
                        <?php while ($p = $pengumumanList->fetch_assoc()): 
                            $isRead = $conn->query("SELECT 1 FROM pengumuman_read WHERE pengumuman_id = {$p['id']} AND user_id = {$_SESSION['user_id']}")->num_rows > 0;
                        ?>
                        <div style="padding:12px;border-bottom:1px solid #e5e7eb;<?= $isRead ? '' : 'background:#eff6ff' ?>">
                            <div style="display:flex;justify-content:space-between;align-items:start">
                                <div>
                                    <strong style="color:#1e40af"><?= htmlspecialchars($p['judul']) ?></strong>
                                    <?php if (!$isRead): ?><span class="badge badge-danger" style="font-size:10px">BARU</span><?php endif; ?>
                                    <p style="font-size:13px;color:#4b5563;margin:4px 0"><?= htmlspecialchars(substr($p['isi'], 0, 100)) ?>...</p>
                                    <small style="color:#9ca3af">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($p['created_by_name'] ?? 'Admin') ?> | 
                                        <i class="fas fa-clock"></i> <?= date('d/m/Y', strtotime($p['created_at'])) ?>
                                        <?php if ($p['tanggal_kadaluarsa']): ?> | 
                                        <i class="fas fa-calendar-times"></i> Kadaluarsa: <?= date('d/m/Y', strtotime($p['tanggal_kadaluarsa'])) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php if (!$isRead): ?>
                                <a href="?read_pengumuman=<?= $p['id'] ?>" class="btn btn-sm btn-primary" style="white-space:nowrap">
                                    <i class="fas fa-check"></i> Tandai Baca
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?> 
                    </div>
                </div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalHadir ?></h3>
                            <p>Total Kehadiran</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <div class="stat-info">
                            <h3><?= $todayAbsen ? ($todayAbsen['jam_keluar'] ? 'Selesai' : 'Bekerja') : 'Belum Absen' ?></h3>
                            <p>Status Hari Ini</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-info">
                            <h3><?= $pendingReq ?></h3>
                            <p>Request Pending</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-star"></i></div>
                        <div class="stat-info">
                            <h3><?= $latestKpi ? $latestKpi['nilai'] : '-' ?></h3>
                            <p>Nilai KPI Terakhir</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-clock"></i> Absensi Hari Ini</span>
                        <span class="badge badge-primary" id="clock">--:--:--</span>
                    </div>
                    <div style="padding:20px">
                        <?php if (!$todayAbsen): ?>
                            <form method="POST">
                                <button type="submit" name="checkin" class="btn btn-success">
                                    <i class="fas fa-sign-in-alt"></i> Check In
                                </button>
                            </form>
                        <?php elseif (!$todayAbsen['jam_keluar']): ?>
                            <p>Jam Masuk: <strong><?= $todayAbsen['jam_masuk'] ?></strong> | Status: <span class="badge badge-<?= $todayAbsen['status']=='hadir'?'success':'warning' ?>"><?= ucfirst($todayAbsen['status']) ?></span></p>
                            <form method="POST" style="margin-top:15px">
                                <button type="submit" name="checkout" class="btn btn-danger">
                                    <i class="fas fa-sign-out-alt"></i> Check Out
                                </button>
                            </form>
                        <?php else: ?>
                            <p>Jam Masuk: <strong><?= $todayAbsen['jam_masuk'] ?></strong> | Jam Keluar: <strong><?= $todayAbsen['jam_keluar'] ?></strong></p>
                            <span class="badge badge-success">Selesai</span>
                        <?php endif; ?> 
                    </div>
                </div>

            <?php elseif ($page == 'absensi'): ?>
                <h1 class="page-title">Absensi</h1>
                <p class="page-subtitle">Kelola absensi harian Anda</p>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-clock"></i> Absensi Hari Ini</span>
                        <span class="badge badge-primary" id="clock">--:--:--</span>
                    </div>
                    <div style="padding:20px">
                        <?php if (!$todayAbsen): ?>
                            <form method="POST">
                                <button type="submit" name="checkin" class="btn btn-success">
                                    <i class="fas fa-sign-in-alt"></i> Check In
                                </button>
                            </form>
                        <?php elseif (!$todayAbsen['jam_keluar']): ?>
                            <p>Jam Masuk: <strong><?= $todayAbsen['jam_masuk'] ?></strong></p>
                            <form method="POST" style="margin-top:15px">
                                <button type="submit" name="checkout" class="btn btn-danger">
                                    <i class="fas fa-sign-out-alt"></i> Check Out
                                </button>
                            </form>
                        <?php else: ?>
                            <p>Jam Masuk: <strong><?= $todayAbsen['jam_masuk'] ?></strong> | Jam Keluar: <strong><?= $todayAbsen['jam_keluar'] ?></strong></p>
                            <span class="badge badge-success">Selesai</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-history"></i> Riwayat Absensi</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="absensiTable">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Keluar</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $absensiHistory->fetch_assoc()): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td><?= $row['jam_masuk'] ?: '-' ?></td>
                                    <td><?= $row['jam_keluar'] ?: '-' ?></td>
                                    <td><span class="badge badge-<?= $row['status']=='hadir'?'success':($row['status']=='telat'?'warning':'info') ?>"><?= ucfirst($row['status']) ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($absensiHistory->num_rows == 0): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada riwayat absensi
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'request'): ?>
                <h1 class="page-title">Request System</h1>
                <p class="page-subtitle">Ajukan permohonan izin, cuti, sakit, atau lembur</p>

                <?php if (isset($_GET['success']) && $_GET['success'] == 'submitted'): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> Request berhasil dikirim ke atasan!
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-plus-circle"></i> Form Request Baru</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="padding:20px">
                        <div class="form-group">
                            <label>Jenis Request</label>
                            <select name="jenis_request" class="form-control" required>
                                <option value="">Pilih Jenis</option>
                                <option value="izin">Izin</option>
                                <option value="cuti">Cuti</option>
                                <option value="sakit">Sakit</option>
                                <option value="lembur">Lembur</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tanggal Mulai</label>
                            <input type="date" name="tanggal_mulai" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Tanggal Selesai (Opsional)</label>
                            <input type="date" name="tanggal_selesai" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Alasan</label>
                            <textarea name="alasan" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Upload Bukti (Opsional)</label>
                            <input type="file" name="file_bukti" class="form-control">
                        </div>
                        <button type="submit" name="submit_request" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Kirim Request
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-history"></i> Riwayat Request</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                    <th>Komentar Atasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $stmt = $conn->prepare("SELECT r.*, u.nama as atasan_nama 
                                                        FROM request_system r 
                                                        LEFT JOIN users u ON r.approved_by = u.id 
                                                        WHERE r.user_id = ? 
                                                        ORDER BY r.created_at DESC");
                                $stmt->bind_param("i", $_SESSION['user_id']);
                                $stmt->execute();
                                $requests = $stmt->get_result();
                                
                                while ($row = $requests->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td><?= ucfirst($row['jenis_request']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                                    <td><?= htmlspecialchars(substr($row['alasan'], 0, 50)) ?>...</td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='pending'?'warning':($row['status']=='disetujui'?'success':'danger') ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['komentar_atasan'] ?? '-') ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($requests->num_rows == 0): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada request
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'kpi'): ?>
                <h1 class="page-title">KPI Penilaian</h1>
                <p class="page-subtitle">Lihat penilaian kinerja Anda</p>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-bullseye"></i></div>
                        <div class="stat-info">
                            <h3><?= $latestKpi ? $latestKpi['target'] : '-' ?></h3>
                            <p>Target</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-check"></i></div>
                        <div class="stat-info">
                            <h3><?= $latestKpi ? $latestKpi['realisasi'] : '-' ?></h3>
                            <p>Realisasi</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-star"></i></div>
                        <div class="stat-info">
                            <h3><?= $latestKpi ? $latestKpi['nilai'] : '-' ?></h3>
                            <p>Nilai</p>
                        </div>
                    </div>
                </div>

                <?php
                $stmt = $conn->prepare("SELECT periode, nilai FROM kpi WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $kpiData = $stmt->get_result();
                $periods = []; $scores = [];
                while ($row = $kpiData->fetch_assoc()) {
                    $periods[] = $row['periode'];
                    $scores[] = $row['nilai'];
                }
                ?>

                <?php if (!empty($periods)): ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-chart-line"></i> Grafik KPI</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="kpiChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-history"></i> Riwayat Penilaian KPI</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Periode</th>
                                    <th>Target</th>
                                    <th>Realisasi</th>
                                    <th>Nilai</th>
                                    <th>Keterangan</th>
                                    <th>Komentar</th>
                                    <th>Tanggal Penilaian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $conn->prepare("SELECT k.*, u.nama as atasan_nama 
                                                        FROM kpi k 
                                                        LEFT JOIN users u ON k.created_by = u.id 
                                                        WHERE k.user_id = ? 
                                                        ORDER BY k.created_at DESC");
                                $stmt->bind_param("i", $_SESSION['user_id']);
                                $stmt->execute();
                                $kpiHistory = $stmt->get_result();
                                
                                if ($kpiHistory->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
                                        Belum ada data penilaian KPI
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php while ($row = $kpiHistory->fetch_assoc()): 
                                        $nilai = floatval($row['nilai']);
                                        if ($nilai >= 90) {
                                            $ketClass = 'badge-success';
                                            $ket = 'Sangat Baik';
                                        } elseif ($nilai >= 75) {
                                            $ketClass = 'badge-primary';
                                            $ket = 'Baik';
                                        } elseif ($nilai >= 60) {
                                            $ketClass = 'badge-warning';
                                            $ket = 'Cukup';
                                        } else {
                                            $ketClass = 'badge-danger';
                                            $ket = 'Kurang';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['periode']) ?></td>
                                        <td><?= htmlspecialchars($row['target']) ?>%</td>
                                        <td><?= htmlspecialchars($row['realisasi']) ?>%</td>
                                        <td><strong><?= $row['nilai'] ?></strong></td>
                                        <td><span class="badge <?= $ketClass ?>"><?= $ket ?></span></td>
                                        <td><?= htmlspecialchars($row['komentar'] ?? '-') ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?> <small style="color:#6b7280">(<?= htmlspecialchars($row['atasan_nama'] ?? '-') ?>)</small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'pengumuman'): ?>
                <h1 class="page-title">Pengumuman</h1>
                <p class="page-subtitle">Informasi dan pengumuman perusahaan</p>

                <?php
                // Get pengumuman untuk karyawan (semua + divisi sendiri)
                $stmt = $conn->prepare("
                    SELECT p.*, u.nama as created_by_nama, d.nama_divisi 
                    FROM pengumuman p 
                    LEFT JOIN users u ON p.created_by = u.id 
                    LEFT JOIN divisi d ON p.divisi_id = d.id 
                    WHERE (p.tipe_target = 'semua' OR (p.tipe_target = 'divisi' AND p.divisi_id = ?))
                    AND (p.tanggal_kadaluarsa IS NULL OR p.tanggal_kadaluarsa >= CURDATE())
                    ORDER BY p.created_at DESC
                ");
                $stmt->bind_param("i", $user['divisi_id']);
                $stmt->execute();
                $allPengumuman = $stmt->get_result();
                ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-bullhorn"></i> Semua Pengumuman</span>
                    </div>
                    <div style="padding:20px">
                        <?php if ($allPengumuman->num_rows == 0): ?>
                        <div style="text-align:center;padding:40px;color:#6b7280">
                            <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                            <p>Tidak ada pengumuman</p>
                        </div>
                        <?php else: ?>
                            <?php while ($p = $allPengumuman->fetch_assoc()): 
                                $stmtRead = $conn->prepare("SELECT id FROM pengumuman_read WHERE pengumuman_id = ? AND user_id = ?");
                                $stmtRead->bind_param("ii", $p['id'], $_SESSION['user_id']);
                                $stmtRead->execute();
                                $isRead = $stmtRead->get_result()->num_rows > 0;
                                
                                $isExpired = !empty($p['tanggal_kadaluarsa']) && strtotime($p['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                                $sourceLabel = $p['tipe_target'] == 'semua' ? 
                                    '<span class="badge" style="background:#8b5cf6;color:#fff"><i class="fas fa-globe"></i> Semua Karyawan</span>' : 
                                    '<span class="badge" style="background:#3b82f6;color:#fff"><i class="fas fa-building"></i> ' . htmlspecialchars($p['nama_divisi'] ?? 'Divisi') . '</span>';
                            ?>
                            <div style="padding:20px;margin-bottom:15px;border-radius:10px;<?= $isRead ? 'background:#f9fafb;border:1px solid #e5e7eb' : 'background:#eff6ff;border:2px solid #3b82f6' ?> <?= $isExpired ? 'opacity:0.6;' : '' ?>">
                                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:10px;flex-wrap:wrap;gap:10px">
                                    <div>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
                                            <?= $sourceLabel ?>
                                            <?php if (!$isRead): ?>
                                                <span class="badge badge-danger">BELUM DIBACA</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">SUDAH DIBACA</span>
                                            <?php endif; ?>
                                            <?php if ($isExpired): ?>
                                                <span class="badge badge-secondary">KADALUARSA</span>
                                            <?php endif; ?>
                                        </div>
                                        <h4 style="margin:0;color:#1e40af"><?= htmlspecialchars($p['judul']) ?></h4>
                                        <small style="color:#6b7280">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($p['created_by_nama'] ?? 'Admin') ?> | 
                                            <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                                            <?php if ($p['tanggal_kadaluarsa']): ?>
                                                | <i class="fas fa-calendar-times"></i> Kadaluarsa: <?= date('d/m/Y', strtotime($p['tanggal_kadaluarsa'])) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php if (!$isRead && !$isExpired): ?>
                                    <a href="?page=pengumuman&mark_pengumuman=<?= $p['id'] ?>" class="btn btn-sm btn-primary" style="white-space:nowrap">
                                        <i class="fas fa-check"></i> Tandai Dibaca
                                    </a>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="color:#374151;line-height:1.6;margin-top:10px;white-space:pre-wrap">
                                    <?= nl2br(htmlspecialchars($p['isi'])) ?>
                                </div>
                                
                                <?php if (!empty($p['file_lampiran'])): 
                                    $ext = pathinfo($p['file_lampiran'], PATHINFO_EXTENSION);
                                    $isImage = in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp']);
                                ?>
                                <div style="margin-top:15px;padding-top:15px;border-top:1px solid #e5e7eb">
                                    <?php if ($isImage): ?>
                                        <strong><i class="fas fa-image"></i> Lampiran:</strong><br>
                                        <img src="<?= htmlspecialchars($p['file_lampiran']) ?>" style="max-width:100%;max-height:300px;border-radius:8px;margin-top:10px;border:1px solid #e5e7eb">
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($p['file_lampiran']) ?>" target="_blank" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-download"></i> Download Lampiran
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                </div>

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
                <h1 class="page-title">Profile</h1>
                <p class="page-subtitle">Kelola informasi akun Anda</p>

                <?php if (isset($_GET['success']) && $_GET['success'] == 'updated'): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> Profile berhasil diperbarui!
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-user-circle"></i> Informasi Profile</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="padding:20px">
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
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                            <small style="color:#6b7280;font-size:12px">Username tidak dapat diubah</small>
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
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['nama_divisi'] ?? '-') ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Jabatan</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['nama_jabatan'] ?? '-') ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Atasan</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($atasanInfo['nama'] ?? 'Belum ditentukan') ?>" disabled>
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
                    <form method="POST" style="padding:20px">
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

    <script src="js/script.js"></script>
    <script>
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', { hour12: false });
            const clocks = document.querySelectorAll('#clock');
            clocks.forEach(c => c.textContent = timeString);
        }
        setInterval(updateClock, 1000);
        updateClock();

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

    <?php if ($page == 'kpi' && !empty($periods)): ?>
    <script>
        new Chart(document.getElementById('kpiChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_reverse($periods)) ?>,
                datasets: [{ 
                    label: 'Nilai KPI',
                    data: <?= json_encode(array_reverse($scores)) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true, 
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { min: 0, max: 100 } }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>