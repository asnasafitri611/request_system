<?php
require_once 'config.php';
checkRole(['admin']);

$user = getUserById($conn, $_SESSION['user_id']);
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);

// Stats
$totalUsers = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$totalKaryawan = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='karyawan'")->fetch_assoc()['total'];
$totalAtasan = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='atasan'")->fetch_assoc()['total'];
$totalRequests = $conn->query("SELECT COUNT(*) as total FROM request_system")->fetch_assoc()['total'];

// All Users
$usersResult = $conn->query("SELECT u.*, d.nama_divisi, j.nama_jabatan FROM users u LEFT JOIN divisi d ON u.divisi_id=d.id LEFT JOIN jabatan j ON u.jabatan_id=j.id ORDER BY u.created_at DESC");

// All Data
$absensiAll = $conn->query("SELECT a.*, u.nama FROM absensi a JOIN users u ON a.user_id=u.id ORDER BY a.tanggal DESC LIMIT 50");
$requestsAll = $conn->query("SELECT r.*, u.nama FROM request_system r JOIN users u ON r.user_id=u.id ORDER BY r.created_at DESC LIMIT 50");
$kpiAll = $conn->query("SELECT k.*, u.nama FROM kpi k JOIN users u ON k.user_id=u.id ORDER BY k.created_at DESC LIMIT 50");

// Handle User CRUD
if (isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nama = $_POST['nama'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $divisi_id = $_POST['divisi_id'] ?: null;
    $jabatan_id = $_POST['jabatan_id'] ?: null;
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, nama, email, role, divisi_id, jabatan_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii", $username, $password, $nama, $email, $role, $divisi_id, $jabatan_id);
    $stmt->execute();
    header("Location: dashboard-admin.php?page=users");
    exit;
}

if (isset($_POST['edit_user'])) {
    $id = $_POST['user_id'];
    $nama = $_POST['nama'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $divisi_id = $_POST['divisi_id'] ?: null;
    $jabatan_id = $_POST['jabatan_id'] ?: null;
    
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, role=?, divisi_id=?, jabatan_id=?, password=? WHERE id=?");
        $stmt->bind_param("sssiiis", $nama, $email, $role, $divisi_id, $jabatan_id, $password, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, role=?, divisi_id=?, jabatan_id=? WHERE id=?");
        $stmt->bind_param("sssiii", $nama, $email, $role, $divisi_id, $jabatan_id, $id);
    }
    $stmt->execute();
    header("Location: dashboard-admin.php?page=users");
    exit;
}

if (isset($_GET['delete_user'])) {
    $id = $_GET['delete_user'];
    $conn->query("DELETE FROM users WHERE id=$id");
    header("Location: dashboard-admin.php?page=users");
    exit;
}

$divisiList = $conn->query("SELECT * FROM divisi");
$jabatanList = $conn->query("SELECT * FROM jabatan");

// ============================================
// HANDLE PROFILE ADMIN (UPDATE LENGKAP)
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
    $role = $_POST['role'];
    $divisi_id = $_POST['divisi_id'] ?: null;
    $jabatan_id = $_POST['jabatan_id'] ?: null;
    
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
        
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, no_hp=?, username=?, role=?, divisi_id=?, jabatan_id=?, foto=? WHERE id=?");
        $stmt->bind_param("sssssiisi", $nama, $email, $no_hp, $username, $role, $divisi_id, $jabatan_id, $foto, $_SESSION['user_id']);
        $stmt->execute();
        $_SESSION['nama'] = $nama;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Request System</title>
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
            <span>Administrator</span>
        </div>
        <div class="nav-menu">
            <a href="?page=dashboard" class="nav-item <?= $page=='dashboard'?'active':'' ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="?page=users" class="nav-item <?= $page=='users'?'active':'' ?>">
                <i class="fas fa-users-cog"></i>
                <span>Manajemen User</span>
            </a>
            <a href="?page=karyawan" class="nav-item <?= $page=='karyawan'?'active':'' ?>">
                <i class="fas fa-users"></i>
                <span>Data Karyawan</span>
            </a>
            <a href="?page=monitoring" class="nav-item <?= $page=='monitoring'?'active':'' ?>">
                <i class="fas fa-desktop"></i>
                <span>Monitoring</span>
            </a>
            <a href="?page=laporan" class="nav-item <?= $page=='laporan'?'active':'' ?>">
                <i class="fas fa-file-export"></i>
                <span>Laporan</span>
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
                <div class="nav-icon" onclick="openModal('notifModal')">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-count"><?= $notifCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
            </div>
        </div>

        <div class="content">
            <?php if ($page == 'dashboard'): ?>
                <h1 class="page-title">Dashboard Admin</h1>
                <p class="page-subtitle">Panel kontrol utama sistem</p>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalUsers ?></h3>
                            <p>Total User</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawan ?></h3>
                            <p>Total Karyawan</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-shield"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalAtasan ?></h3>
                            <p>Total Atasan</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalRequests ?></h3>
                            <p>Total Request</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-chart-pie"></i> Distribusi User</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>

            <?php elseif ($page == 'users'): ?>
                <h1 class="page-title">Manajemen User</h1>
                <p class="page-subtitle">Kelola data pengguna sistem</p>

                <button class="btn btn-primary" onclick="openModal('addUserModal')" style="margin-bottom:20px">
                    <i class="fas fa-plus"></i> Tambah User
                </button>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Daftar User</span>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchUser" placeholder="Cari user..." onkeyup="searchTable('searchUser', 'usersTable')">
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="usersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Nama</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Divisi</th>
                                    <th>Jabatan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $usersResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td><span class="badge badge-<?= $row['role']=='admin'?'danger':($row['role']=='atasan'?'warning':'primary') ?>"><?= ucfirst($row['role']) ?></span></td>
                                    <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($row)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?page=users&delete_user=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete()">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'karyawan'): ?>
                <h1 class="page-title">Data Seluruh Karyawan</h1>
                <p class="page-subtitle">Informasi lengkap karyawan perusahaan</p>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Daftar Karyawan</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Email</th>
                                    <th>Divisi</th>
                                    <th>Jabatan</th>
                                    <th>Bergabung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $karyawan = $conn->query("SELECT u.*, d.nama_divisi, j.nama_jabatan FROM users u LEFT JOIN divisi d ON u.divisi_id=d.id LEFT JOIN jabatan j ON u.jabatan_id=j.id WHERE u.role='karyawan'");
                                while ($row = $karyawan->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'monitoring'): ?>
                <h1 class="page-title">Monitoring Sistem</h1>
                <p class="page-subtitle">Pantau semua aktivitas dalam sistem</p>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Semua Absensi</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Tanggal</th>
                                    <th>Masuk</th>
                                    <th>Keluar</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $absensiAll->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
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

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Semua Request</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $requestsAll->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= ucfirst($row['jenis_request']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                                    <td><span class="badge badge-<?= $row['status']=='pending'?'warning':($row['status']=='disetujui'?'success':'danger') ?>"><?= ucfirst($row['status']) ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'laporan'): ?>
                <h1 class="page-title">Laporan</h1>
                <p class="page-subtitle">Export dan cetak laporan sistem</p>

                <div class="stats-grid">
                    <div class="card" style="text-align:center;padding:40px">
                        <i class="fas fa-file-pdf" style="font-size:48px;color:#dc2626;margin-bottom:15px"></i>
                        <h3>Export PDF</h3>
                        <p style="margin:15px 0;color:#6b7280">Download laporan dalam format PDF</p>
                        <button class="btn btn-danger" onclick="window.open('laporan.php?type=pdf', '_blank')">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                    </div>
                    <div class="card" style="text-align:center;padding:40px">
                        <i class="fas fa-file-excel" style="font-size:48px;color:#059669;margin-bottom:15px"></i>
                        <h3>Export Excel</h3>
                        <p style="margin:15px 0;color:#6b7280">Download laporan dalam format Excel/CSV</p>
                        <button class="btn btn-success" onclick="exportToCSV('reportTable', 'laporan_sistem')">
                            <i class="fas fa-download"></i> Download Excel
                        </button>
                    </div>
                    <div class="card" style="text-align:center;padding:40px">
                        <i class="fas fa-print" style="font-size:48px;color:#3b82f6;margin-bottom:15px"></i>
                        <h3>Print Laporan</h3>
                        <p style="margin:15px 0;color:#6b7280">Cetak laporan langsung</p>
                        <button class="btn btn-primary" onclick="printPage()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Ringkasan Data</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="reportTable">
                            <thead>
                                <tr>
                                    <th>Metrik</th>
                                    <th>Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Total User</td><td><?= $totalUsers ?></td></tr>
                                <tr><td>Total Karyawan</td><td><?= $totalKaryawan ?></td></tr>
                                <tr><td>Total Atasan</td><td><?= $totalAtasan ?></td></tr>
                                <tr><td>Total Request</td><td><?= $totalRequests ?></td></tr>
                                <tr><td>Request Pending</td><td><?= $conn->query("SELECT COUNT(*) as c FROM request_system WHERE status='pending'")->fetch_assoc()['c'] ?></td></tr>
                                <tr><td>Request Disetujui</td><td><?= $conn->query("SELECT COUNT(*) as c FROM request_system WHERE status='disetujui'")->fetch_assoc()['c'] ?></td></tr>
                                <tr><td>Request Ditolak</td><td><?= $conn->query("SELECT COUNT(*) as c FROM request_system WHERE status='ditolak'")->fetch_assoc()['c'] ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($page == 'profile'): ?>
                <h1 class="page-title">Profile Admin</h1>
                <p class="page-subtitle">Kelola informasi akun administrator Anda</p>

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
                                 style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid #ef4444;margin-bottom:10px"
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
                            <label><i class="fas fa-shield-alt"></i> Role</label>
                            <select name="role" class="form-control" required>
                                <option value="admin" <?= $user['role']=='admin'?'selected':'' ?>>Admin</option>
                                <option value="atasan" <?= $user['role']=='atasan'?'selected':'' ?>>Atasan</option>
                                <option value="karyawan" <?= $user['role']=='karyawan'?'selected':'' ?>>Karyawan</option>
                            </select>
                            <small style="color:#dc2626;font-size:12px"><i class="fas fa-exclamation-triangle"></i> Hati-hati mengganti role!</small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Divisi</label>
                            <select name="divisi_id" class="form-control">
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
                            <select name="jabatan_id" class="form-control">
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
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Bergabung Sejak</label>
                            <input type="date" name="created_at" class="form-control" value="<?= date('Y-m-d', strtotime($user['created_at'])) ?>">
                            <small style="color:#6b7280;font-size:12px">Tanggal bergabung</small>
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

    <!-- Add User Modal -->
    <div class="modal-overlay" id="addUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Tambah User</h3>
                <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="nama" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" class="form-control" required>
                            <option value="karyawan">Karyawan</option>
                            <option value="atasan">Atasan</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="divisi_id" class="form-control">
                            <option value="">Pilih Divisi</option>
                            <?php while ($d = $divisiList->fetch_assoc()): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="jabatan_id" class="form-control">
                            <option value="">Pilih Jabatan</option>
                            <?php while ($j = $jabatanList->fetch_assoc()): ?>
                            <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['nama_jabatan']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Batal</button>
                    <button type="submit" name="add_user" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="nama" id="editNama" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Password Baru (Kosongkan jika tidak diubah)</label>
                        <input type="password" name="password" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="editRole" class="form-control" required>
                            <option value="karyawan">Karyawan</option>
                            <option value="atasan">Atasan</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Batal</button>
                    <button type="submit" name="edit_user" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update
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
        function editUser(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editNama').value = user.nama;
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editRole').value = user.role;
            openModal('editUserModal');
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
            const ctx = document.getElementById('userChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Admin', 'Atasan', 'Karyawan'],
                        datasets: [{
                            data: [
                                <?= $conn->query("SELECT COUNT(*) as c FROM users WHERE role='admin'")->fetch_assoc()['c'] ?? 0 ?>,
                                <?= $totalAtasan ?>,
                                <?= $totalKaryawan ?>
                            ],
                            backgroundColor: ['#ef4444', '#f59e0b', '#10b981']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>