<?php
require_once 'config.php';
checkRole(['karyawan']);

$user = getUserById($conn, $_SESSION['user_id']);
$today = date('Y-m-d');

// Check today's attendance
$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id=? AND tanggal=?");
$stmt->bind_param("is", $_SESSION['user_id'], $today);
$stmt->execute();
$todayAbsen = $stmt->get_result()->fetch_assoc();

// Attendance History
$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id=? ORDER BY tanggal DESC LIMIT 10");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$absensiHistory = $stmt->get_result();

// Requests
$stmt = $conn->prepare("SELECT * FROM request_system WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$requests = $stmt->get_result();

// KPI
$stmt = $conn->prepare("SELECT * FROM kpi WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$latestKpi = $stmt->get_result()->fetch_assoc();

// Stats
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM absensi WHERE user_id=? AND status='hadir'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$totalHadir = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM request_system WHERE user_id=? AND status='pending'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$pendingReq = $stmt->get_result()->fetch_assoc()['total'];

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
    $stmt = $conn->prepare("UPDATE absensi SET jam_keluar=? WHERE user_id=? AND tanggal=?");
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
    
    $stmt = $conn->prepare("INSERT INTO request_system (user_id, jenis_request, tanggal_mulai, tanggal_selesai, alasan, file_bukti) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $_SESSION['user_id'], $jenis, $tgl_mulai, $tgl_selesai, $alasan, $file_bukti);
    $stmt->execute();
    
    // Notify atasan
    $stmt = $conn->prepare("SELECT id FROM users WHERE role='atasan' LIMIT 1");
    $stmt->execute();
    $atasan = $stmt->get_result()->fetch_assoc();
    if ($atasan) {
        addNotification($conn, $atasan['id'], 'Request Baru', $_SESSION['nama'] . ' mengajukan ' . $jenis);
    }
    
    header("Location: dashboard-karyawan.php");
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
    
    $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, no_hp=?, foto=? WHERE id=?");
    $stmt->bind_param("ssssi", $nama, $email, $no_hp, $foto, $_SESSION['user_id']);
    $stmt->execute();
    $_SESSION['nama'] = $nama;
    header("Location: dashboard-karyawan.php?page=profile");
    exit;
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

$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);
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
    <!-- Loading -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-building"></i>
            <h2>Request System</h2>
        </div>
        <div class="user-info">
            <img src="<?= $user['foto'] ?? 'https://via.placeholder.com/70' ?>" alt="Profile">
            <h4><?= htmlspecialchars($_SESSION['nama']) ?></h4>
            <span>Karyawan</span>
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
            </a>
            <a href="?page=kpi" class="nav-item <?= $page=='kpi'?'active':'' ?>">
                <i class="fas fa-chart-line"></i>
                <span>KPI</span>
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

    <!-- Main Content -->
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
                <h1 class="page-title">Dashboard Karyawan</h1>
                <p class="page-subtitle">Selamat datang, <?= htmlspecialchars($_SESSION['nama']) ?>! Hari ini <?= date('d F Y') ?></p>

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

            <?php elseif ($page == 'absensi'): ?>
                <h1 class="page-title">Absensi</h1>
                <p class="page-subtitle">Kelola absensi harian Anda</p>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Absensi Hari Ini</span>
                        <span class="badge badge-primary" id="clock">--:--:--</span>
                    </div>
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

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Riwayat Absensi</span>
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
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'request'): ?>
                <h1 class="page-title">Request System</h1>
                <p class="page-subtitle">Ajukan permohonan izin, cuti, sakit, atau lembur</p>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Form Request Baru</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm('requestForm')" id="requestForm">
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
                        <span class="card-title">Riwayat Request</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $requests->fetch_assoc()): ?>
                                <tr>
                                    <td><?= ucfirst($row['jenis_request']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                                    <td><?= htmlspecialchars(substr($row['alasan'], 0, 50)) ?>...</td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='pending'?'warning':($row['status']=='disetujui'?'success':'danger') ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
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

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Grafik KPI</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="kpiChart"></canvas>
                    </div>
                </div>

                <?php
                $stmt = $conn->prepare("SELECT periode, nilai FROM kpi WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $kpiData = $stmt->get_result();
                $periods = []; $scores = [];
                while ($row = $kpiData->fetch_assoc()) {
                    $periods[] = $row['periode'];
                    $scores[] = $row['nilai'];
                }
                ?>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Riwayat Penilaian KPI</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Periode</th>
                                    <th>Target</th>
                                    <th>Nilai</th>
                                    <th>Keterangan</th>
                                    <th>Tanggal Penilaian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $conn->prepare("SELECT * FROM kpi WHERE user_id=? ORDER BY created_at DESC");
                                $stmt->bind_param("i", $_SESSION['user_id']);
                                $stmt->execute();
                                $kpiHistory = $stmt->get_result();
                                if ($kpiHistory->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:20px">Belum ada data penilaian KPI</td>
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
                                        <td><?= htmlspecialchars($row['target']) ?></td>
                                        <td><strong><?= $row['nilai'] ?></strong></td>
                                        <td><span class="badge <?= $ketClass ?>"><?= $ket ?></span></td>
                                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
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

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Edit Profile</span>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Foto Profile</label><br>
                            <img src="<?= $user['foto'] ?? 'https://via.placeholder.com/100' ?>" style="width:100px;height:100px;border-radius:50%;object-fit:cover;margin-bottom:10px">
                            <input type="file" name="foto" class="form-control" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($user['nama']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>No. HP</label>
                            <input type="text" name="no_hp" class="form-control" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Perubahan
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Ganti Password</span>
                    </div>
                    <?php if (isset($passMsg)): ?>
                        <div class="badge badge-success" style="margin-bottom:15px"><?= $passMsg ?></div>
                    <?php endif; ?>
                    <?php if (isset($passError)): ?>
                        <div class="badge badge-danger" style="margin-bottom:15px"><?= $passError ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Password Lama</label>
                            <input type="password" name="old_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Password Baru</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Konfirmasi Password Baru</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-warning">
                            <i class="fas fa-key"></i> Ganti Password
                        </button>
                    </form>
                </div>
            <?php endif; ?>
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