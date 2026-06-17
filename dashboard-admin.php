<?php
require_once 'config.php';
checkRole(['admin']);

$user = getUserById($conn, $_SESSION['user_id']);
$page = $_GET['page'] ?? 'dashboard';
$notifCount = getUnreadNotifCount($conn, $_SESSION['user_id']);

// ============================================
// FUNGSI HIERARKI PARENT_ID
// ============================================

// Stats
$totalUsers = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$totalKaryawan = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='karyawan'")->fetch_assoc()['total'];
$totalAtasan = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='atasan'")->fetch_assoc()['total'];
$totalRequests = $conn->query("SELECT COUNT(*) as total FROM request_system")->fetch_assoc()['total'];
$totalUsersAktif = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='aktif'")->fetch_assoc()['total'];
$totalUsersTidakAktif = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='tidak_aktif'")->fetch_assoc()['total'];

// Stats Hierarki (menggunakan parent_id)
$totalAtasanAktif = $conn->query("SELECT COUNT(DISTINCT u.id) as total FROM users u WHERE u.status='aktif' AND EXISTS (SELECT 1 FROM users b WHERE b.parent_id = u.id AND b.status = 'aktif')")->fetch_assoc()['total'];
$totalKaryawanTanpaAtasan = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='aktif' AND (parent_id IS NULL OR parent_id = 0)")->fetch_assoc()['total'];
$totalKaryawanDenganAtasan = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='aktif' AND parent_id IS NOT NULL AND parent_id > 0")->fetch_assoc()['total'];

// All Users
$usersResult = $conn->query("SELECT u.*, d.nama_divisi, j.nama_jabatan FROM users u LEFT JOIN divisi d ON u.divisi_id=d.id LEFT JOIN jabatan j ON u.jabatan_id=j.id ORDER BY u.status ASC, u.created_at DESC");

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
    $status = $_POST['status'] ?? 'aktif';

    $stmt = $conn->prepare("INSERT INTO users (username, password, nama, email, role, divisi_id, jabatan_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssiis", $username, $password, $nama, $email, $role, $divisi_id, $jabatan_id, $status);
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
    $status = $_POST['status'] ?? 'aktif';

    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, role=?, divisi_id=?, jabatan_id=?, password=?, status=? WHERE id=?");
        $stmt->bind_param("sssiissi", $nama, $email, $role, $divisi_id, $jabatan_id, $password, $status, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, role=?, divisi_id=?, jabatan_id=?, status=? WHERE id=?");
        $stmt->bind_param("sssiisi", $nama, $email, $role, $divisi_id, $jabatan_id, $status, $id);
    }
    $stmt->execute();
    header("Location: dashboard-admin.php?page=users");
    exit;
}

if (isset($_GET['nonaktifkan_user'])) {
    $id = (int) $_GET['nonaktifkan_user'];
    $stmt = $conn->prepare("UPDATE users SET status='tidak_aktif' WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: dashboard-admin.php?page=users");
    exit;
}

if (isset($_GET['aktifkan_user'])) {
    $id = (int) $_GET['aktifkan_user'];
    $stmt = $conn->prepare("UPDATE users SET status='aktif' WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: dashboard-admin.php?page=users");
    exit;
}

// ============================================
// HANDLE MANAJEMEN HIERARKI (PARENT_ID)
// ============================================

// Assign user ke atasan (menggunakan parent_id)
if (isset($_POST['assign_karyawan'])) {
    $karyawanId = (int) $_POST['karyawan_id'];
    $atasanId = (int) $_POST['atasan_id'];

    // Cek circular reference
    if (isCircularReference($conn, $karyawanId, $atasanId)) {
        header("Location: dashboard-admin.php?page=manajemen-atasan&error=circular");
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET parent_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $atasanId, $karyawanId);
    $stmt->execute();

    // Update atasan_id juga untuk backward compatibility
    $stmt = $conn->prepare("UPDATE users SET atasan_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $atasanId, $karyawanId);
    $stmt->execute();

    // Notifikasi ke atasan
    $stmt = $conn->prepare("SELECT nama FROM users WHERE id = ?");
    $stmt->bind_param("i", $karyawanId);
    $stmt->execute();
    $karyawan = $stmt->get_result()->fetch_assoc();

    addNotification($conn, $atasanId, 'Karyawan Baru', $karyawan['nama'] . ' telah ditugaskan sebagai bawahan Anda');

    header("Location: dashboard-admin.php?page=manajemen-atasan&success=assigned");
    exit;
}

// Hapus bawahan dari atasan (reset parent_id)
if (isset($_GET['hapus_bawahan']) && isset($_GET['atasan_id'])) {
    $karyawanId = (int) $_GET['hapus_bawahan'];
    $atasanId = (int) $_GET['atasan_id'];

    $stmt = $conn->prepare("UPDATE users SET parent_id = NULL, atasan_id = NULL WHERE id = ? AND parent_id = ?");
    $stmt->bind_param("ii", $karyawanId, $atasanId);
    $stmt->execute();

    header("Location: dashboard-admin.php?page=manajemen-atasan&success=removed");
    exit;
}

// Pindah karyawan ke atasan lain
if (isset($_POST['pindah_karyawan'])) {
    $karyawanId = (int) $_POST['karyawan_id'];
    $atasanIdBaru = (int) $_POST['atasan_id_baru'];

    if (isCircularReference($conn, $karyawanId, $atasanIdBaru)) {
        header("Location: dashboard-admin.php?page=manajemen-atasan&error=circular");
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET parent_id = ?, atasan_id = ? WHERE id = ?");
    $stmt->bind_param("iii", $atasanIdBaru, $atasanIdBaru, $karyawanId);
    $stmt->execute();

    header("Location: dashboard-admin.php?page=manajemen-atasan&success=moved");
    exit;
}

$divisiList = $conn->query("SELECT * FROM divisi");
$jabatanList = $conn->query("SELECT * FROM jabatan");

// ============================================
// HANDLE PROFILE ADMIN
// ============================================

$profileMsg = '';
$profileError = '';
$passMsg = '';
$passError = '';

if (isset($_POST['update_profile'])) {
    $nama = trim($_POST['nama']);
    $email = trim($_POST['email']) ?: null;
    $no_hp = trim($_POST['no_hp']) ?: null;
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    $divisi_id = !empty($_POST['divisi_id']) ? (int)$_POST['divisi_id'] : null;
    $jabatan_id = !empty($_POST['jabatan_id']) ? (int)$_POST['jabatan_id'] : null;
    if (empty($username)) {
        $profileError = "Username tidak boleh kosong!";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $username, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $profileError = "Username sudah digunakan oleh user lain!";
        } else {
            $foto = $user['foto'] ?? null;

            if (!empty($_FILES['foto']['name'])) {
                $uploadDir = 'uploads/profile/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = $_FILES['foto']['type'];
                $fileSize = $_FILES['foto']['size'];

                if (!in_array($fileType, $allowedTypes)) {
                    $profileError = "Format file tidak didukung! (JPG, PNG, GIF, WEBP)";
                } elseif ($fileSize > 2 * 1024 * 1024) {
                    $profileError = "Ukuran file maksimal 2MB!";
                } else {
                    $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                    $fotoName = time() . '_' . uniqid() . '.' . $ext;
                    $fotoPath = $uploadDir . $fotoName;

                    if (move_uploaded_file($_FILES['foto']['tmp_name'], $fotoPath)) {
                        if (!empty($user['foto']) && file_exists($user['foto']) && strpos($user['foto'], 'placeholder') === false) {
                            @unlink($user['foto']);
                        }
                        $foto = $fotoPath;
                        $_SESSION['foto'] = $foto;
                    } else {
                        $profileError = "Gagal mengupload foto!";
                    }
                }
            }

            if (empty($profileError)) {
                $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, no_hp=?, username=?, role=?, divisi_id=?, jabatan_id=?, foto=? WHERE id=?");
                $stmt->bind_param("sssssiiii", $nama, $email, $no_hp, $username, $role, $divisi_id, $jabatan_id, $foto, $_SESSION['user_id']);

                if ($stmt->execute()) {
                    $_SESSION['nama'] = $nama;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;
                    $profileMsg = "Profile berhasil diperbarui!";
                    $user = getUserById($conn, $_SESSION['user_id']);
                } else {
                    $profileError = "Gagal memperbarui profile: " . $stmt->error;
                }
            }
        }
    }
}

// Handle ganti password
if (isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $passError = "Semua field password harus diisi!";
    } elseif ($new_password !== $confirm_password) {
        $passError = "Password baru dan konfirmasi tidak cocok!";
    } elseif (strlen($new_password) < 6) {
        $passError = "Password minimal 6 karakter!";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result->fetch_assoc();

        if (!password_verify($old_password, $current['password'])) {
            $passError = "Password lama tidak benar!";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $passMsg = "Password berhasil diubah!";
            } else {
                $passError = "Gagal mengubah password!";
            }
        }
    }
}
// ============================================
// HANDLE GANTI PASSWORD PROFILE
// ============================================

if (isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi password lama
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!password_verify($old_password, $result['password'])) {
        $passError = "Password lama salah!";
    } elseif ($new_password !== $confirm_password) {
        $passError = "Password baru dan konfirmasi tidak cocok!";
    } elseif (strlen($new_password) < 6) {
        $passError = "Password minimal 6 karakter!";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $_SESSION['user_id']);
        $stmt->execute();
        $passMsg = "Password berhasil diubah!";
    }
}
// ============================================
// HANDLE LAPORAN REQUEST SYSTEM PDF & CSV
// ============================================

if (isset($_GET['generate_laporan']) && $_GET['generate_laporan'] == 'pdf') {
    $filter_tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
    $filter_tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
    $filter_nama = $_GET['nama_karyawan'] ?? '';
    $filter_status = $_GET['status'] ?? '';
    $filter_jenis = $_GET['jenis_request'] ?? '';

    $where = ["1=1"];
    $params = [];
    $types = "";

    if (!empty($filter_tanggal_mulai)) {
        $where[] = "r.tanggal_mulai >= ?";
        $params[] = $filter_tanggal_mulai;
        $types .= "s";
    }
    if (!empty($filter_tanggal_selesai)) {
        $where[] = "r.tanggal_mulai <= ?";
        $params[] = $filter_tanggal_selesai;
        $types .= "s";
    }
    if (!empty($filter_nama)) {
        $where[] = "u.nama LIKE ?";
        $params[] = "%$filter_nama%";
        $types .= "s";
    }
    if (!empty($filter_status)) {
        $where[] = "r.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    if (!empty($filter_jenis)) {
        $where[] = "r.jenis_request = ?";
        $params[] = $filter_jenis;
        $types .= "s";
    }

    $whereClause = implode(" AND ", $where);

    $sql = "SELECT r.*, u.nama as nama_karyawan, u.email, d.nama_divisi, j.nama_jabatan 
            FROM request_system r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN divisi d ON u.divisi_id = d.id 
            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
            WHERE $whereClause 
            ORDER BY r.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $totalRequest = $result->num_rows;
    $pending = 0; $disetujui = 0; $ditolak = 0;
    $dataRows = [];
    while ($row = $result->fetch_assoc()) {
        $dataRows[] = $row;
        if ($row['status'] == 'pending') $pending++;
        elseif ($row['status'] == 'disetujui') $disetujui++;
        elseif ($row['status'] == 'ditolak') $ditolak++;
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Request System</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { text-align: center; border-bottom: 3px solid #2563eb; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #1e40af; margin: 0; font-size: 24px; }
        .header p { color: #6b7280; margin: 5px 0; }
        .info-box { background: #f3f4f6; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .info-box table { width: 100%; }
        .info-box td { padding: 5px 10px; font-size: 13px; }
        .summary { display: flex; gap: 15px; margin-bottom: 25px; }
        .summary-box { flex: 1; text-align: center; padding: 15px; border-radius: 8px; color: white; }
        .summary-box.total { background: #3b82f6; }
        .summary-box.pending { background: #f59e0b; }
        .summary-box.disetujui { background: #10b981; }
        .summary-box.ditolak { background: #ef4444; }
        .summary-box h3 { margin: 0; font-size: 28px; }
        .summary-box p { margin: 5px 0 0; font-size: 12px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        table.data th { background: #1e40af; color: white; padding: 10px; text-align: left; }
        table.data td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; }
        table.data tr:nth-child(even) { background: #f9fafb; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-disetujui { background: #d1fae5; color: #065f46; }
        .badge-ditolak { background: #fee2e2; color: #991b1b; }
        .footer { margin-top: 40px; text-align: right; font-size: 12px; color: #6b7280; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN REQUEST SYSTEM</h1>
        <p>PT. Nama Perusahaan</p>
        <p>Periode: <?= !empty($filter_tanggal_mulai) ? date('d/m/Y', strtotime($filter_tanggal_mulai)) : 'Semua' ?> - <?= !empty($filter_tanggal_selesai) ? date('d/m/Y', strtotime($filter_tanggal_selesai)) : 'Semua' ?></p>
    </div>

    <div class="info-box no-print">
        <table>
            <tr>
                <td><strong>Filter Tanggal Mulai:</strong> <?= !empty($filter_tanggal_mulai) ? date('d/m/Y', strtotime($filter_tanggal_mulai)) : '-' ?></td>
                <td><strong>Filter Tanggal Selesai:</strong> <?= !empty($filter_tanggal_selesai) ? date('d/m/Y', strtotime($filter_tanggal_selesai)) : '-' ?></td>
            </tr>
            <tr>
                <td><strong>Nama Karyawan:</strong> <?= !empty($filter_nama) ? htmlspecialchars($filter_nama) : 'Semua' ?></td>
                <td><strong>Status Request:</strong> <?= !empty($filter_status) ? ucfirst($filter_status) : 'Semua' ?></td>
            </tr>
            <tr>
                <td><strong>Jenis Request:</strong> <?= !empty($filter_jenis) ? ucfirst($filter_jenis) : 'Semua' ?></td>
                <td></td>
            </tr>
        </table>
    </div>

    <div class="summary">
        <div class="summary-box total">
            <h3><?= $totalRequest ?></h3>
            <p>TOTAL REQUEST</p>
        </div>
        <div class="summary-box pending">
            <h3><?= $pending ?></h3>
            <p>PENDING</p>
        </div>
        <div class="summary-box disetujui">
            <h3><?= $disetujui ?></h3>
            <p>DISETUJUI</p>
        </div>
        <div class="summary-box ditolak">
            <h3><?= $ditolak ?></h3>
            <p>DITOLAK</p>
        </div>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Karyawan</th>
                <th>Divisi</th>
                <th>Jabatan</th>
                <th>Jenis Request</th>
                <th>Tanggal Mulai</th>
                <th>Tanggal Selesai</th>
                <th>Alasan</th>
                <th>Status</th>
                <th>Tanggal Pengajuan</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($dataRows as $row): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><strong><?= htmlspecialchars($row['nama_karyawan']) ?></strong></td>
                <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                <td><?= ucfirst(htmlspecialchars($row['jenis_request'])) ?></td>
                <td><?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></td>
                <td><?= $row['tanggal_selesai'] ? date('d/m/Y', strtotime($row['tanggal_selesai'])) : '-' ?></td>
                <td><?= htmlspecialchars($row['alasan'] ?? '-') ?></td>
                <td>
                    <span class="badge badge-<?= $row['status'] ?>">
                        <?= ucfirst($row['status']) ?>
                    </span>
                </td>
                <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($dataRows)): ?>
            <tr>
                <td colspan="10" style="text-align:center;padding:30px;color:#6b7280">Tidak ada data yang sesuai dengan filter</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        <p>Dicetak pada: <?= date('d F Y H:i:s') ?> oleh <?= htmlspecialchars($_SESSION['nama']) ?></p>
    </div>

    <div class="no-print" style="margin-top:30px;text-align:center">
        <button onclick="window.print()" style="padding:10px 30px;background:#2563eb;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px">
            Print / Save as PDF
        </button>
        <button onclick="window.close()" style="padding:10px 30px;background:#6b7280;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;margin-left:10px">
            Tutup
        </button>
    </div>
</body>
</html>
<?php
    exit;
}

if (isset($_GET['generate_laporan']) && $_GET['generate_laporan'] == 'csv') {
    $filter_tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
    $filter_tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
    $filter_nama = $_GET['nama_karyawan'] ?? '';
    $filter_status = $_GET['status'] ?? '';
    $filter_jenis = $_GET['jenis_request'] ?? '';

    $where = ["1=1"];
    $params = [];
    $types = "";

    if (!empty($filter_tanggal_mulai)) {
        $where[] = "r.tanggal_mulai >= ?";
        $params[] = $filter_tanggal_mulai;
        $types .= "s";
    }
    if (!empty($filter_tanggal_selesai)) {
        $where[] = "r.tanggal_mulai <= ?";
        $params[] = $filter_tanggal_selesai;
        $types .= "s";
    }
    if (!empty($filter_nama)) {
        $where[] = "u.nama LIKE ?";
        $params[] = "%$filter_nama%";
        $types .= "s";
    }
    if (!empty($filter_status)) {
        $where[] = "r.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    if (!empty($filter_jenis)) {
        $where[] = "r.jenis_request = ?";
        $params[] = $filter_jenis;
        $types .= "s";
    }

    $whereClause = implode(" AND ", $where);

    $sql = "SELECT r.*, u.nama as nama_karyawan, u.email, d.nama_divisi, j.nama_jabatan 
            FROM request_system r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN divisi d ON u.divisi_id = d.id 
            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
            WHERE $whereClause 
            ORDER BY r.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $dataRows = [];
    while ($row = $result->fetch_assoc()) {
        $dataRows[] = $row;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_request_'.date('Ymd').'.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Nama Karyawan', 'Divisi', 'Jabatan', 'Jenis Request', 'Tanggal Mulai', 'Tanggal Selesai', 'Alasan', 'Status', 'Tanggal Pengajuan']);
    $no = 1;
    foreach ($dataRows as $row) {
        fputcsv($output, [
            $no++,
            $row['nama_karyawan'],
            $row['nama_divisi'],
            $row['nama_jabatan'],
            $row['jenis_request'],
            date('d/m/Y', strtotime($row['tanggal_mulai'])),
            $row['tanggal_selesai'] ? date('d/m/Y', strtotime($row['tanggal_selesai'])) : '-',
            $row['alasan'],
            $row['status'],
            date('d/m/Y H:i', strtotime($row['created_at']))
        ]);
    }
    fclose($output);
    exit;
}

// ============================================
// HANDLE LAPORAN ABSENSI PDF
// ============================================

if (isset($_GET['generate_laporan_absensi']) && $_GET['generate_laporan_absensi'] == 'pdf') {
    $tanggal_mulai   = $_GET['tanggal_mulai']   ?? '';
    $tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
    $karyawan_id     = $_GET['karyawan_id']     ?? '';
    $status_absensi  = $_GET['status_absensi']  ?? '';

    $where = [];
    $params = [];
    $types = '';

    if ($tanggal_mulai) {
        $where[] = "a.tanggal >= ?";
        $params[] = $tanggal_mulai;
        $types .= 's';
    }
    if ($tanggal_selesai) {
        $where[] = "a.tanggal <= ?";
        $params[] = $tanggal_selesai;
        $types .= 's';
    }
    if ($karyawan_id) {
        $where[] = "a.user_id = ?";
        $params[] = $karyawan_id;
        $types .= 'i';
    }
    if ($status_absensi) {
        $where[] = "a.status = ?";
        $params[] = $status_absensi;
        $types .= 's';
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "SELECT a.*, u.nama as nama_karyawan, u.email, d.nama_divisi, j.nama_jabatan 
              FROM absensi a 
              JOIN users u ON a.user_id = u.id 
              LEFT JOIN divisi d ON u.divisi_id = d.id 
              LEFT JOIN jabatan j ON u.jabatan_id = j.id 
              $whereClause 
              ORDER BY a.tanggal DESC, a.jam_masuk DESC";

    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $karyawanName = 'Semua Karyawan';
    if ($karyawan_id) {
        $k = $conn->query("SELECT nama FROM users WHERE id=" . (int)$karyawan_id)->fetch_assoc();
        $karyawanName = $k['nama'] ?? 'Karyawan';
    }

    $periodeText = '';
    if ($tanggal_mulai && $tanggal_selesai) {
        $periodeText = date('d F Y', strtotime($tanggal_mulai)) . ' s/d ' . date('d F Y', strtotime($tanggal_selesai));
    } elseif ($tanggal_mulai) {
        $periodeText = 'Mulai ' . date('d F Y', strtotime($tanggal_mulai));
    } elseif ($tanggal_selesai) {
        $periodeText = 'Sampai ' . date('d F Y', strtotime($tanggal_selesai));
    } else {
        $periodeText = 'Semua Periode';
    }

    $statusText = $status_absensi ? ucfirst($status_absensi) : 'Semua Status';

    $totalData = $result->num_rows;
    $hadir = 0; $telat = 0; $izin = 0; $sakit = 0; $cuti = 0;

    $dataRows = [];
    while ($row = $result->fetch_assoc()) {
        $dataRows[] = $row;
        switch ($row['status']) {
            case 'hadir': $hadir++; break;
            case 'telat': $telat++; break;
            case 'izin': $izin++; break;
            case 'sakit': $sakit++; break;
            case 'cuti': $cuti++; break;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo generateLaporanHTML($dataRows, $periodeText, $karyawanName, $statusText, $totalData, $hadir, $telat, $izin, $sakit, $cuti);
    exit;
}

// ============================================
// FUNGSI GENERATE HTML LAPORAN ABSENSI
// ============================================
function generateLaporanHTML($data, $periode, $karyawan, $status, $total, $hadir, $telat, $izin, $sakit, $cuti) {
    $companyName    = 'PT. KREATOR SOLUSI INFORMASI';
    $companyAddress = 'Alamat Perusahaan, Indonesia';

    $rows = '';
    $no = 1;
    foreach ($data as $row) {
        $statusColors = [
            'hadir' => ['bg' => '#d1fae5', 'text' => '#065f46'],
            'telat' => ['bg' => '#fef3c7', 'text' => '#92400e'],
            'izin'  => ['bg' => '#dbeafe', 'text' => '#1e40af'],
            'sakit' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
            'cuti'  => ['bg' => '#ede9fe', 'text' => '#5b21b6'],
        ];
        $sc = $statusColors[$row['status']] ?? ['bg' => '#f3f4f6', 'text' => '#374151'];

        $rows .= "
        <tr>
            <td style='text-align:center;padding:10px 8px;border:1px solid #d1d5db;font-size:12px;'>{$no}</td>
            <td style='padding:10px 8px;border:1px solid #d1d5db;font-size:12px;font-weight:600;color:#1f2937;'>".htmlspecialchars($row['nama_karyawan'])."</td>
            <td style='padding:10px 8px;border:1px solid #d1d5db;font-size:12px;color:#4b5563;'>".htmlspecialchars($row['nama_divisi'] ?? '-')."</td>
            <td style='padding:10px 8px;border:1px solid #d1d5db;font-size:12px;color:#4b5563;'>".htmlspecialchars($row['nama_jabatan'] ?? '-')."</td>
            <td style='text-align:center;padding:10px 8px;border:1px solid #d1d5db;font-size:12px;'>".date('d/m/Y', strtotime($row['tanggal']))."</td>
            <td style='text-align:center;padding:10px 8px;border:1px solid #d1d5db;font-size:12px;'>".($row['jam_masuk'] ?: '-')."</td>
            <td style='text-align:center;padding:10px 8px;border:1px solid #d1d5db;font-size:12px;'>".($row['jam_keluar'] ?: '-')."</td>
            <td style='text-align:center;padding:10px 8px;border:1px solid #d1d5db;'>
                <span style='background:{$sc['bg']};color:{$sc['text']};padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;display:inline-block;'>".ucfirst($row['status'])."</span>
            </td>
            <td style='padding:10px 8px;border:1px solid #d1d5db;font-size:11px;color:#6b7280;'>".htmlspecialchars($row['keterangan'] ?? '-')."</td>
        </tr>";
        $no++;
    }

    if (empty($data)) {
        $rows = "
        <tr>
            <td colspan='9' style='text-align:center;padding:40px;border:1px solid #d1d5db;color:#9ca3af;font-size:14px;'>
                <i class='fas fa-inbox' style='font-size:32px;display:block;margin-bottom:10px;'></i>
                Tidak ada data absensi untuk periode yang dipilih
            </td>
        </tr>";
    }

    $printDate = date('d F Y H:i:s');

    return "<!DOCTYPE html>
<html lang='id'>
<head>
    <meta charset='UTF-8'>
    <title>Laporan Absensi - {$periode}</title>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' rel='stylesheet'>
    <style>
        @page { size: A4 landscape; margin: 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1f2937; line-height: 1.5; background: #fff; padding: 20px; }
        .header { text-align: center; border-bottom: 3px solid #059669; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { font-size: 24px; color: #059669; margin-bottom: 5px; font-weight: 800; letter-spacing: 1px; }
        .header .company { font-size: 13px; color: #4b5563; font-weight: 600; }
        .header .date { font-size: 11px; color: #6b7280; margin-top: 5px; }
        .info-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 15px; margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 4px 8px; font-size: 11px; vertical-align: top; }
        .info-label { color: #374151; font-weight: 600; width: 120px; white-space: nowrap; }
        .info-value { color: #1f2937; font-weight: 500; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11px; }
        table.data thead th { background: #059669; color: white; padding: 10px 8px; text-align: center; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid #047857; }
        table.data tbody td { border: 1px solid #d1d5db; }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        table.data tbody tr:hover { background: #f0fdf4; }
        .summary { margin-top: 20px; border: 1px solid #d1d5db; border-radius: 8px; padding: 15px; background: #fafafa; }
        .summary-title { font-size: 13px; font-weight: 800; color: #1f2937; margin-bottom: 12px; border-bottom: 2px solid #059669; padding-bottom: 6px; }
        .summary-grid { display: table; width: 100%; }
        .summary-row { display: table-row; }
        .summary-cell { display: table-cell; padding: 6px 12px; font-size: 12px; }
        .summary-label { font-weight: 600; color: #4b5563; }
        .summary-value { font-weight: 700; color: #059669; }
        .footer { margin-top: 25px; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .print-btn { position: fixed; top: 15px; right: 15px; padding: 10px 20px; background: #059669; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 8px rgba(5,150,105,0.3); }
        .print-btn:hover { background: #047857; }
        @media print { .print-btn { display: none !important; } body { padding: 0; } .summary { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <button class='print-btn' onclick='window.print()'><i class='fas fa-print'></i> Cetak / Simpan PDF</button>
    <div class='header'>
        <h1>LAPORAN ABSENSI KARYAWAN</h1>
        <div class='company'>{$companyName}</div>
        <div class='date'>Dicetak pada: {$printDate}</div>
    </div>
    <div class='info-box'>
        <table class='info-table'>
            <tr>
                <td class='info-label'><i class='fas fa-calendar-alt'></i> Periode</td>
                <td class='info-value'>: {$periode}</td>
                <td class='info-label'><i class='fas fa-user'></i> Karyawan</td>
                <td class='info-value'>: {$karyawan}</td>
            </tr>
            <tr>
                <td class='info-label'><i class='fas fa-filter'></i> Status Filter</td>
                <td class='info-value'>: {$status}</td>
                <td class='info-label'><i class='fas fa-database'></i> Total Data</td>
                <td class='info-value'>: {$total} Records</td>
            </tr>
        </table>
    </div>
    <table class='data'>
        <thead>
            <tr>
                <th style='width:4%'>No</th>
                <th style='width:18%'>Nama Karyawan</th>
                <th style='width:12%'>Divisi</th>
                <th style='width:12%'>Jabatan</th>
                <th style='width:10%'>Tanggal</th>
                <th style='width:8%'>Masuk</th>
                <th style='width:8%'>Keluar</th>
                <th style='width:10%'>Status</th>
                <th style='width:18%'>Keterangan</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
    <div class='summary'>
        <div class='summary-title'><i class='fas fa-chart-pie'></i> RINGKASAN STATUS KEHADIRAN</div>
        <div class='summary-grid'>
            <div class='summary-row'>
                <div class='summary-cell summary-label'>Hadir</div>
                <div class='summary-cell summary-value'>: {$hadir} kali</div>
                <div class='summary-cell summary-label'>Telat</div>
                <div class='summary-cell summary-value'>: {$telat} kali</div>
                <div class='summary-cell summary-label'>Izin</div>
                <div class='summary-cell summary-value'>: {$izin} kali</div>
                <div class='summary-cell summary-label'>Sakit</div>
                <div class='summary-cell summary-value'>: {$sakit} kali</div>
                <div class='summary-cell summary-label'>Cuti</div>
                <div class='summary-cell summary-value'>: {$cuti} kali</div>
            </div>
        </div>
    </div>
    <div class='footer'>
        <strong>Dokumen ini dicetak secara otomatis dari sistem Request System.</strong><br>
        Untuk informasi lebih lanjut, hubungi bagian HRD / Administrator.
    </div>
</body>
</html>";
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
    <style>
        .input-group { position: relative; }
        .input-group .form-control { padding-right: 40px; }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 14px;
            padding: 5px;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: #4b5563; }
        .password-match { color: #059669; font-size: 12px; margin-top: 4px; }
        .password-mismatch { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .input-error { border-color: #dc2626 !important; }
        .input-success { border-color: #059669 !important; }
        .btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        /* Tree hierarchy styles */
        .hierarchy-tree { padding: 10px; }
        .hierarchy-node {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            margin-bottom: 8px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 4px solid #3b82f6;
            transition: all 0.2s;
        }
        .hierarchy-node:hover { background: #e0f2fe; }
        .hierarchy-node .node-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .hierarchy-node .node-info { flex: 1; }
        .hierarchy-node .node-name {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }
        .hierarchy-node .node-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .hierarchy-node .node-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            color: white;
        }
        .hierarchy-node .node-level {
            font-size: 12px;
            color: #9ca3af;
            margin-left: 10px;
        }
        .hierarchy-children {
            margin-left: 30px;
            border-left: 2px dashed #cbd5e1;
            padding-left: 15px;
        }
    </style>
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
            <a href="?page=manajemen-atasan" class="nav-item <?= $page=='manajemen-atasan'?'active':'' ?>">
                <i class="fas fa-sitemap"></i>
                <span>Manajemen Hierarki</span>
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
         <a href="?page=pengumuman" class="nav-item <?= $page=='pengumuman'?'active':'' ?>">
                <i class="fas fa-bullhorn"></i>
                <span>Pengumuman</span>
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

            <!-- PAGE: USERS -->
            <?php elseif ($page == 'users'): ?>
                <h1 class="page-title">Manajemen User</h1>
                <p class="page-subtitle">Kelola data pengguna sistem</p>

                <div class="stats-grid" style="margin-bottom:20px">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalUsersAktif ?></h3>
                            <p>Karyawan Aktif</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gray"><i class="fas fa-user-slash"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalUsersTidakAktif ?></h3>
                            <p>Karyawan Resign</p>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary" onclick="openModal('addUserModal')" style="margin-bottom:20px">
                    <i class="fas fa-plus"></i> Tambah User
                </button>

                <div style="margin-bottom:15px">
                    <label>Filter Status:</label>
                    <select id="filterStatus" onchange="filterByStatus()" class="form-control" style="width:200px;display:inline-block">
                        <option value="all">Semua Status</option>
                        <option value="aktif">Aktif</option>
                        <option value="tidak_aktif">Tidak Aktif (Resign)</option>
                    </select>
                </div>

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
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $usersResult->fetch_assoc()): ?>
                                <tr data-status="<?= $row['status'] ?>">
                                    <td><?= $row['id'] ?></td>
                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td><span class="badge badge-<?= $row['role']=='admin'?'danger':($row['role']=='atasan'?'warning':'primary') ?>"><?= ucfirst($row['role']) ?></span></td>
                                    <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='aktif'?'success':'secondary' ?>">
                                            <?= $row['status']=='aktif'?'Aktif':'Tidak Aktif (Resign)' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($row)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($row['status'] == 'aktif'): ?>
                                            <a href="?page=users&nonaktifkan_user=<?= $row['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('Nonaktifkan user ini? Data tetap tersimpan.')">
                                                <i class="fas fa-user-slash"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?page=users&aktifkan_user=<?= $row['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Aktifkan kembali user ini?')">
                                                <i class="fas fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                            <!-- PAGE: MANAJEMEN ATASAN -->
            <?php elseif ($page == 'manajemen-atasan'): ?>
                <h1 class="page-title">Manajemen Atasan</h1>
                <p class="page-subtitle">Atur karyawan bawahan untuk setiap atasan</p>

                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'assigned': echo 'Karyawan berhasil ditugaskan ke atasan!'; break;
                            case 'removed': echo 'Karyawan berhasil dihapus dari daftar bawahan!'; break;
                            case 'moved': echo 'Karyawan berhasil dipindahkan ke atasan lain!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="stats-grid" style="margin-bottom:20px">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-user-shield"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalAtasanAktif ?></h3>
                            <p>Total Atasan Aktif</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawanDenganAtasan ?></h3>
                            <p>Karyawan Punya Atasan</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-plus"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawanTanpaAtasan ?></h3>
                            <p>Karyawan Belum Punya Atasan</p>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:25px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-user-plus"></i> Assign Karyawan ke Atasan</span>
                    </div>
                    <form method="POST" style="padding:20px">
                        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:15px;align-items:end">
                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Pilih Atasan</label>
                                <select name="atasan_id" class="form-control" required style="width:100%">
                                    <option value="">-- Pilih Atasan --</option>
                                    <?php 
                                    $atasanList = getDaftarAtasan($conn);
                                    while ($a = $atasanList->fetch_assoc()): 
                                    ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= htmlspecialchars($a['nama']) ?> 
                                        (<?= htmlspecialchars($a['nama_jabatan'] ?? '-') ?> - <?= htmlspecialchars($a['nama_divisi'] ?? '-') ?>)
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Pilih Karyawan</label>
                                <select name="karyawan_id" class="form-control" required style="width:100%">
                                    <option value="">-- Pilih Karyawan --</option>
                                    <optgroup label="Karyawan Belum Punya Atasan">
                                        <?php 
                                        $karyawanFree = getKaryawanTanpaAtasan($conn);
                                        while ($k = $karyawanFree->fetch_assoc()): 
                                        ?>
                                        <option value="<?= $k['id'] ?>">
                                            <?= htmlspecialchars($k['nama']) ?> 
                                            (<?= htmlspecialchars($k['nama_jabatan'] ?? '-') ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </optgroup>
                                    <optgroup label="Karyawan Sudah Punya Atasan (Pindah)">
                                        <?php 
                                        $karyawanAssigned = $conn->query("SELECT u.*, d.nama_divisi, j.nama_jabatan, a.nama as nama_atasan 
                                            FROM users u 
                                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                            LEFT JOIN users a ON u.atasan_id = a.id 
                                            WHERE u.role = 'karyawan' AND u.status = 'aktif' AND u.atasan_id IS NOT NULL AND u.atasan_id > 0
                                            ORDER BY u.nama ASC");
                                        while ($k = $karyawanAssigned->fetch_assoc()): 
                                        ?>
                                        <option value="<?= $k['id'] ?>">
                                            <?= htmlspecialchars($k['nama']) ?> 
                                            (Saat ini: <?= htmlspecialchars($k['nama_atasan'] ?? '-') ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </optgroup>
                                </select>
                            </div>
                            <button type="submit" name="assign_karyawan" class="btn btn-primary" style="padding:10px 20px">
                                <i class="fas fa-link"></i> Assign
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-sitemap"></i> Struktur Atasan & Karyawan</span>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchAtasan" placeholder="Cari atasan..." onkeyup="searchTable('searchAtasan', 'atasanTable')">
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="atasanTable">
                            <thead>
                                <tr>
                                    <th>Atasan</th>
                                    <th>Jabatan</th>
                                    <th>Divisi</th>
                                    <th>Karyawan Bawahan</th>
                                    <th>Jumlah</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $atasanList = getDaftarAtasan($conn);
                                while ($atasan = $atasanList->fetch_assoc()):
                                    $bawahan = getKaryawanByAtasanId($conn, $atasan['id']);
                                    $jumlahBawahan = $bawahan->num_rows;
                                    $bawahanNames = [];
                                    while ($b = $bawahan->fetch_assoc()) {
                                        $bawahanNames[] = $b['nama'];
                                    }
                                    $bawahan->data_seek(0);
                                ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px">
                                            <img src="<?= $atasan['foto'] ?? 'https://via.placeholder.com/40' ?>" 
                                                 style="width:40px;height:40px;border-radius:50%;object-fit:cover">
                                            <div>
                                                <strong><?= htmlspecialchars($atasan['nama']) ?></strong>
                                                <div style="font-size:12px;color:#6b7280"><?= htmlspecialchars($atasan['email'] ?? '-') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($atasan['nama_jabatan'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($atasan['nama_divisi'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($jumlahBawahan > 0): ?>
                                            <div style="display:flex;flex-wrap:wrap;gap:5px">
                                                <?php while ($b = $bawahan->fetch_assoc()): ?>
                                                <span style="background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:12px;font-size:12px;display:inline-flex;align-items:center;gap:5px">
                                                    <img src="<?= $b['foto'] ?? 'https://via.placeholder.com/20' ?>" style="width:20px;height:20px;border-radius:50%;object-fit:cover">
                                                    <?= htmlspecialchars($b['nama']) ?>
                                                    <a href="?page=manajemen-atasan&hapus_bawahan=<?= $b['id'] ?>&atasan_id=<?= $atasan['id'] ?>" 
                                                       style="color:#dc2626;text-decoration:none;margin-left:3px"
                                                       onclick="return confirm('Hapus <?= htmlspecialchars($b['nama']) ?> dari daftar bawahan?')"
                                                       title="Hapus">
                                                        <i class="fas fa-times-circle"></i>
                                                    </a>
                                                </span>
                                                <?php endwhile; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;font-size:13px"><i class="fas fa-exclamation-circle"></i> Belum ada karyawan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $jumlahBawahan > 0 ? 'success' : 'warning' ?>">
                                            <?= $jumlahBawahan ?> orang
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="openAssignModal(<?= $atasan['id'] ?>, '<?= htmlspecialchars($atasan['nama']) ?>')">
                                            <i class="fas fa-plus"></i> Tambah
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                
                                <?php if ($totalAtasanAktif == 0): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada atasan yang aktif
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-overlay" id="assignModal">
                    <div class="modal">
                        <div class="modal-header">
                            <h3><i class="fas fa-user-plus"></i> Tambah Karyawan ke <span id="modalAtasanName"></span></h3>
                            <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="atasan_id" id="modalAtasanId">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Pilih Karyawan</label>
                                    <select name="karyawan_id" class="form-control" required>
                                        <option value="">-- Pilih Karyawan --</option>
                                        <?php 
                                        $karyawanFree = getKaryawanTanpaAtasan($conn);
                                        while ($k = $karyawanFree->fetch_assoc()): 
                                        ?>
                                        <option value="<?= $k['id'] ?>">
                                            <?= htmlspecialchars($k['nama']) ?> (<?= htmlspecialchars($k['nama_jabatan'] ?? '-') ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Batal</button>
                                <button type="submit" name="assign_karyawan" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function openAssignModal(atasanId, atasanName) {
                    document.getElementById('modalAtasanId').value = atasanId;
                    document.getElementById('modalAtasanName').textContent = atasanName;
                    openModal('assignModal');
                }
                </script>

            <!-- PAGE: KARYAWAN -->
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
                                    <th>Status</th>
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
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='aktif'?'success':'secondary' ?>">
                                            <?= $row['status']=='aktif'?'Aktif':'Resign' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- PAGE: MONITORING -->
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

                                        <?php elseif ($page == 'manajemen-atasan'): ?>
                <h1 class="page-title">Manajemen Hierarki</h1>
                <p class="page-subtitle">Atur struktur organisasi berdasarkan parent_id</p>

                <!-- Alert Messages -->
                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'assigned': echo 'Karyawan berhasil ditugaskan!'; break;
                            case 'removed': echo 'Karyawan berhasil dilepas dari atasan!'; break;
                            case 'moved': echo 'Karyawan berhasil dipindahkan!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-times-circle"></i> 
                        <?php 
                        switch($_GET['error']) {
                            case 'circular': echo 'Gagal! Terdeteksi referensi melingkar (circular reference).'; break;
                            default: echo 'Terjadi kesalahan!';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Hierarki -->
                <div class="stats-grid" style="margin-bottom:20px">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-sitemap"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalAtasanAktif ?></h3>
                            <p>Atasan Aktif (Punya Bawahan)</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawanDenganAtasan ?></h3>
                            <p>Karyawan Dengan Atasan</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                        <div class="stat-info">
                            <h3><?= $totalKaryawanTanpaAtasan ?></h3>
                            <p>Karyawan Tanpa Atasan</p>
                        </div>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
                    <!-- Card: Assign Karyawan ke Atasan -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><i class="fas fa-user-plus"></i> Assign Karyawan ke Atasan</span>
                        </div>
                        <div style="padding:20px">
                            <form method="POST">
                                <div class="form-group">
                                    <label><i class="fas fa-user"></i> Pilih Karyawan</label>
                                    <select name="karyawan_id" class="form-control" required>
                                        <option value="">-- Pilih Karyawan --</option>
                                        <?php
                                        $karyawanQuery = $conn->query("
                                            SELECT u.id, u.nama, u.email, d.nama_divisi, j.nama_jabatan 
                                            FROM users u 
                                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                            WHERE u.status = 'aktif' 
                                            ORDER BY u.nama
                                        ");
                                        while ($k = $karyawanQuery->fetch_assoc()):
                                            $hasParent = $conn->query("SELECT parent_id FROM users WHERE id = " . $k['id'])->fetch_assoc()['parent_id'];
                                            $label = $hasParent ? ' (Sudah punya atasan)' : ' (Belum punya atasan)';
                                        ?>
                                        <option value="<?= $k['id'] ?>">
                                            <?= htmlspecialchars($k['nama']) ?> - <?= htmlspecialchars($k['nama_jabatan'] ?? 'Tanpa Jabatan') ?> 
                                            [<?= htmlspecialchars($k['nama_divisi'] ?? 'Tanpa Divisi') ?>]<?= $label ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-user-tie"></i> Pilih Atasan</label>
                                    <select name="atasan_id" class="form-control" required>
                                        <option value="">-- Pilih Atasan --</option>
                                        <?php
                                        $atasanQuery = $conn->query("
                                            SELECT u.id, u.nama, d.nama_divisi, j.nama_jabatan 
                                            FROM users u 
                                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                            WHERE u.status = 'aktif' 
                                            ORDER BY u.nama
                                        ");
                                        while ($a = $atasanQuery->fetch_assoc()):
                                            $bawahanCount = $conn->query("SELECT COUNT(*) as c FROM users WHERE parent_id = " . $a['id'])->fetch_assoc()['c'];
                                        ?>
                                        <option value="<?= $a['id'] ?>">
                                            <?= htmlspecialchars($a['nama']) ?> - <?= htmlspecialchars($a['nama_jabatan'] ?? 'Tanpa Jabatan') ?> 
                                            [<?= htmlspecialchars($a['nama_divisi'] ?? 'Tanpa Divisi') ?>] (<?= $bawahanCount ?> bawahan)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <button type="submit" name="assign_karyawan" class="btn btn-primary" style="width:100%">
                                    <i class="fas fa-link"></i> Assign Karyawan ke Atasan
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Card: Pindah Karyawan ke Atasan Lain -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><i class="fas fa-exchange-alt"></i> Pindah Karyawan ke Atasan Lain</span>
                        </div>
                        <div style="padding:20px">
                            <form method="POST">
                                <div class="form-group">
                                    <label><i class="fas fa-user"></i> Pilih Karyawan (Yang sudah punya atasan)</label>
                                    <select name="karyawan_id" class="form-control" required>
                                        <option value="">-- Pilih Karyawan --</option>
                                        <?php
                                        $karyawanPindah = $conn->query("
                                            SELECT u.id, u.nama, u.parent_id, p.nama as nama_atasan, d.nama_divisi, j.nama_jabatan 
                                            FROM users u 
                                            LEFT JOIN users p ON u.parent_id = p.id
                                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                            WHERE u.status = 'aktif' AND u.parent_id IS NOT NULL
                                            ORDER BY u.nama
                                        ");
                                        while ($kp = $karyawanPindah->fetch_assoc()):
                                        ?>
                                        <option value="<?= $kp['id'] ?>">
                                            <?= htmlspecialchars($kp['nama']) ?> [Atasan sekarang: <?= htmlspecialchars($kp['nama_atasan'] ?? '-') ?>]
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-user-tie"></i> Pilih Atasan Baru</label>
                                    <select name="atasan_id_baru" class="form-control" required>
                                        <option value="">-- Pilih Atasan Baru --</option>
                                        <?php
                                        $atasanBaru = $conn->query("
                                            SELECT u.id, u.nama, d.nama_divisi, j.nama_jabatan 
                                            FROM users u 
                                            LEFT JOIN divisi d ON u.divisi_id = d.id 
                                            LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                            WHERE u.status = 'aktif' 
                                            ORDER BY u.nama
                                        ");
                                        while ($ab = $atasanBaru->fetch_assoc()):
                                            $bawahanCount = $conn->query("SELECT COUNT(*) as c FROM users WHERE parent_id = " . $ab['id'])->fetch_assoc()['c'];
                                        ?>
                                        <option value="<?= $ab['id'] ?>">
                                            <?= htmlspecialchars($ab['nama']) ?> - <?= htmlspecialchars($ab['nama_jabatan'] ?? 'Tanpa Jabatan') ?> 
                                            (<?= $bawahanCount ?> bawahan)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <button type="submit" name="pindah_karyawan" class="btn btn-warning" style="width:100%">
                                    <i class="fas fa-exchange-alt"></i> Pindah ke Atasan Baru
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Visualisasi Tree Hierarki -->
                <div class="card" style="margin-bottom:20px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-sitemap"></i> Visualisasi Struktur Hierarki</span>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchHierarchy" placeholder="Cari nama..." onkeyup="searchHierarchy()">
                        </div>
                    </div>
                    <div style="padding:20px">
                        <?php
                        function renderHierarchyTree($conn, $parentId = null, $level = 0) {
                            $sql = "
                                SELECT u.id, u.nama, u.email, u.role, u.foto, u.status,
                                       d.nama_divisi, j.nama_jabatan,
                                       (SELECT COUNT(*) FROM users WHERE parent_id = u.id) as jumlah_bawahan
                                FROM users u
                                LEFT JOIN divisi d ON u.divisi_id = d.id
                                LEFT JOIN jabatan j ON u.jabatan_id = j.id
                                WHERE u.status = 'aktif' AND u.parent_id " . ($parentId === null ? "IS NULL" : "= $parentId") . "
                                ORDER BY j.nama_jabatan DESC, u.nama ASC
                            ";
                            $result = $conn->query($sql);

                            if ($result->num_rows == 0) return '';

                            $html = '<div class="hierarchy-children" style="' . ($level == 0 ? 'margin-left:0;border-left:none;padding-left:0;' : '') . '">';

                            while ($row = $result->fetch_assoc()) {
                                $indent = $level * 30;
                                $avatar = $row['foto'] && $row['foto'] != 'default.png' ? htmlspecialchars($row['foto']) : 'https://via.placeholder.com/40';
                                $jabatanLabel = $row['nama_jabatan'] ?? 'Tanpa Jabatan';
                                $divisiLabel = $row['nama_divisi'] ?? 'Tanpa Divisi';
                                $roleBadge = $row['role'] == 'admin' ? 'admin' : ($row['role'] == 'atasan' ? 'warning' : 'primary');
                                $roleText = ucfirst($row['role']);

                                $html .= '
                                <div class="hierarchy-node" data-nama="' . strtolower(htmlspecialchars($row['nama'])) . '" style="margin-left:' . $indent . 'px">
                                    <img src="' . $avatar . '" class="node-avatar" alt="' . htmlspecialchars($row['nama']) . '">
                                    <div class="node-info">
                                        <div class="node-name">' . htmlspecialchars($row['nama']) . '</div>
                                        <div class="node-meta">
                                            <span class="badge badge-' . $roleBadge . '">' . $roleText . '</span>
                                            ' . htmlspecialchars($jabatanLabel) . ' | ' . htmlspecialchars($divisiLabel) . '
                                            ' . ($row['jumlah_bawahan'] > 0 ? ' | <i class="fas fa-users"></i> ' . $row['jumlah_bawahan'] . ' bawahan' : '') . '
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:5px">
                                        ' . ($row['jumlah_bawahan'] > 0 ? '<span class="node-level">Level ' . $level . '</span>' : '<span class="node-level" style="color:#10b981"><i class="fas fa-leaf"></i> Staff</span>') . '
                                    </div>
                                </div>';

                                $html .= renderHierarchyTree($conn, $row['id'], $level + 1);
                            }

                            $html .= '</div>';
                            return $html;
                        }

                        echo renderHierarchyTree($conn);
                        ?>
                    </div>
                </div>

                <!-- Tabel Detail Bawahan per Atasan -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-list"></i> Detail Bawahan per Atasan</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="hierarchyTable">
                            <thead>
                                <tr>
                                    <th>Atasan</th>
                                    <th>Jabatan Atasan</th>
                                    <th>Divisi Atasan</th>
                                    <th>Nama Bawahan</th>
                                    <th>Jabatan Bawahan</th>
                                    <th>Divisi Bawahan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $hierarchyQuery = $conn->query("
                                    SELECT 
                                        a.id as atasan_id, a.nama as nama_atasan, a.role as role_atasan,
                                        ja.nama_jabatan as jabatan_atasan, da.nama_divisi as divisi_atasan,
                                        b.id as bawahan_id, b.nama as nama_bawahan, b.role as role_bawahan,
                                        jb.nama_jabatan as jabatan_bawahan, db.nama_divisi as divisi_bawahan
                                    FROM users a
                                    INNER JOIN users b ON b.parent_id = a.id
                                    LEFT JOIN jabatan ja ON a.jabatan_id = ja.id
                                    LEFT JOIN divisi da ON a.divisi_id = da.id
                                    LEFT JOIN jabatan jb ON b.jabatan_id = jb.id
                                    LEFT JOIN divisi db ON b.divisi_id = db.id
                                    WHERE a.status = 'aktif' AND b.status = 'aktif'
                                    ORDER BY a.nama, b.nama
                                ");

                                if ($hierarchyQuery->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada data hierarki. Silakan assign karyawan ke atasan terlebih dahulu.
                                    </td>
                                </tr>
                                <?php else: 
                                    $currentAtasan = '';
                                    while ($h = $hierarchyQuery->fetch_assoc()):
                                        $isNewAtasan = $currentAtasan != $h['atasan_id'];
                                        $currentAtasan = $h['atasan_id'];
                                        $rowspan = $conn->query("
                                            SELECT COUNT(*) as c FROM users WHERE parent_id = " . $h['atasan_id'] . " AND status = 'aktif'
                                        ")->fetch_assoc()['c'];
                                ?>
                                <tr>
                                    <?php if ($isNewAtasan): ?>
                                    <td rowspan="<?= $rowspan ?>" style="background:#f0fdf4;font-weight:600">
                                        <i class="fas fa-user-tie" style="color:#059669"></i> <?= htmlspecialchars($h['nama_atasan']) ?>
                                    </td>
                                    <td rowspan="<?= $rowspan ?>" style="background:#f0fdf4">
                                        <?= htmlspecialchars($h['jabatan_atasan'] ?? '-') ?>
                                    </td>
                                    <td rowspan="<?= $rowspan ?>" style="background:#f0fdf4">
                                        <?= htmlspecialchars($h['divisi_atasan'] ?? '-') ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <i class="fas fa-user" style="color:#3b82f6"></i> <?= htmlspecialchars($h['nama_bawahan']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($h['jabatan_bawahan'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($h['divisi_bawahan'] ?? '-') ?></td>
                                    <td>
                                        <a href="?page=manajemen-atasan&hapus_bawahan=<?= $h['bawahan_id'] ?>&atasan_id=<?= $h['atasan_id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Yakin lepas <?= htmlspecialchars($h['nama_bawahan']) ?> dari atasan?')"
                                           title="Lepas dari atasan">
                                            <i class="fas fa-unlink"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Karyawan Tanpa Atasan -->
                <div class="card" style="margin-top:20px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-user-clock"></i> Karyawan Tanpa Atasan (<?= $totalKaryawanTanpaAtasan ?> orang)</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Divisi</th>
                                    <th>Role</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $tanpaAtasan = $conn->query("
                                    SELECT u.id, u.nama, u.role, j.nama_jabatan, d.nama_divisi
                                    FROM users u
                                    LEFT JOIN jabatan j ON u.jabatan_id = j.id
                                    LEFT JOIN divisi d ON u.divisi_id = d.id
                                    WHERE u.status = 'aktif' AND (u.parent_id IS NULL OR u.parent_id = 0)
                                    ORDER BY u.nama
                                ");

                                if ($tanpaAtasan->num_rows == 0):
                                ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:30px;color:#6b7280">
                                        <i class="fas fa-check-circle" style="color:#10b981;font-size:24px;display:block;margin-bottom:8px"></i>
                                        Semua karyawan sudah memiliki atasan!
                                    </td>
                                </tr>
                                <?php else: 
                                    while ($ta = $tanpaAtasan->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ta['nama']) ?></strong></td>
                                    <td><?= htmlspecialchars($ta['nama_jabatan'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($ta['nama_divisi'] ?? '-') ?></td>
                                    <td><span class="badge badge-<?= $ta['role']=='admin'?'danger':($ta['role']=='atasan'?'warning':'primary') ?>"><?= ucfirst($ta['role']) ?></span></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="quickAssign(<?= $ta['id'] ?>, '<?= htmlspecialchars($ta['nama']) ?>')">
                                            <i class="fas fa-user-plus"></i> Assign Atasan
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                function searchHierarchy() {
                    var input = document.getElementById('searchHierarchy');
                    var filter = input.value.toLowerCase();
                    var nodes = document.querySelectorAll('.hierarchy-node');

                    nodes.forEach(function(node) {
                        var nama = node.getAttribute('data-nama');
                        if (nama.indexOf(filter) > -1) {
                            node.style.display = 'flex';
                            var parent = node.parentElement;
                            while (parent) {
                                if (parent.classList && parent.classList.contains('hierarchy-children')) {
                                    parent.style.display = 'block';
                                }
                                parent = parent.parentElement;
                            }
                        } else {
                            node.style.display = 'none';
                        }
                    });

                    if (filter === '') {
                        nodes.forEach(function(node) {
                            node.style.display = 'flex';
                        });
                    }
                }

                function quickAssign(karyawanId, karyawanNama) {
                    document.querySelector('select[name="karyawan_id"]').value = karyawanId;
                    document.querySelector('select[name="karyawan_id"]').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    document.querySelector('select[name="karyawan_id"]').style.borderColor = '#3b82f6';
                    setTimeout(() => {
                        document.querySelector('select[name="karyawan_id"]').style.borderColor = '';
                    }, 2000);
                }
                </script>

<?php elseif ($page == 'laporan'): ?>
                <h1 class="page-title">Laporan</h1>
                <p class="page-subtitle">Cetak laporan absensi dengan filter periode</p>

                <?php
                $filter_tanggal_mulai   = $_GET['tanggal_mulai']   ?? '';
                $filter_tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
                $filter_karyawan        = $_GET['karyawan_id']      ?? '';
                $filter_status          = $_GET['status_absensi']  ?? '';

                $where = [];
                $params = [];
                $types = '';

                if ($filter_tanggal_mulai)   { $where[] = "a.tanggal >= ?"; $params[] = $filter_tanggal_mulai;   $types .= 's'; }
                if ($filter_tanggal_selesai) { $where[] = "a.tanggal <= ?"; $params[] = $filter_tanggal_selesai; $types .= 's'; }
                if ($filter_karyawan)        { $where[] = "a.user_id = ?";  $params[] = $filter_karyawan;        $types .= 'i'; }
                if ($filter_status)          { $where[] = "a.status = ?";   $params[] = $filter_status;          $types .= 's'; }

                $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $previewQuery = "SELECT a.*, u.nama as nama_karyawan, d.nama_divisi, j.nama_jabatan 
                                 FROM absensi a 
                                 JOIN users u ON a.user_id = u.id 
                                 LEFT JOIN divisi d ON u.divisi_id = d.id 
                                 LEFT JOIN jabatan j ON u.jabatan_id = j.id 
                                 $whereClause 
                                 ORDER BY a.tanggal DESC, a.jam_masuk DESC 
                                 LIMIT 100";

                $stmt = $conn->prepare($previewQuery);
                if ($params) $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $previewData = $stmt->get_result();
                $rowCount = $previewData->num_rows;

                $karyawanList = $conn->query("SELECT id, nama FROM users WHERE status='aktif' ORDER BY nama");
                ?>

                <div class="card" style="margin-bottom:20px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-filter"></i> Filter Laporan Absensi</span>
                    </div>
                    <form method="GET" id="filterForm" style="padding:20px">
                        <input type="hidden" name="page" value="laporan">

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:15px">
                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#374151">
                                    <i class="fas fa-calendar-alt"></i> Tanggal Mulai
                                </label>
                                <input type="date" name="tanggal_mulai" value="<?= $filter_tanggal_mulai ?>" 
                                       style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
                            </div>

                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#374151">
                                    <i class="fas fa-calendar-alt"></i> Tanggal Selesai
                                </label>
                                <input type="date" name="tanggal_selesai" value="<?= $filter_tanggal_selesai ?>" 
                                       style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
                            </div>

                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#374151">
                                    <i class="fas fa-user"></i> Nama Karyawan
                                </label>
                                <select name="karyawan_id" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:white">
                                    <option value="">-- Semua Karyawan --</option>
                                    <?php while ($k = $karyawanList->fetch_assoc()): ?>
                                    <option value="<?= $k['id'] ?>" <?= $filter_karyawan == $k['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($k['nama']) ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#374151">
                                    <i class="fas fa-clipboard-check"></i> Status Absensi
                                </label>
                                <select name="status_absensi" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:white">
                                    <option value="">-- Semua Status --</option>
                                    <option value="hadir" <?= $filter_status == 'hadir' ? 'selected' : '' ?>>Hadir</option>
                                    <option value="telat" <?= $filter_status == 'telat' ? 'selected' : '' ?>>Telat</option>
                                    <option value="izin"  <?= $filter_status == 'izin'  ? 'selected' : '' ?>>Izin</option>
                                    <option value="sakit" <?= $filter_status == 'sakit' ? 'selected' : '' ?>>Sakit</option>
                                    <option value="cuti"  <?= $filter_status == 'cuti'  ? 'selected' : '' ?>>Cuti</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button type="submit" class="btn btn-primary" style="padding:10px 20px">
                                <i class="fas fa-search"></i> Tampilkan Data
                            </button>
                            <button type="button" class="btn btn-danger" onclick="downloadPDF()" style="padding:10px 20px;background:#dc2626">
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportToCSV('previewTable', 'laporan_absensi')" style="padding:10px 20px;background:#059669">
                                <i class="fas fa-file-excel"></i> Download Excel
                            </button>
                            <a href="?page=laporan" class="btn btn-secondary" style="padding:10px 20px;background:#6b7280;text-decoration:none;color:white;display:inline-flex;align-items:center;gap:8px;border-radius:6px">
                                <i class="fas fa-undo"></i> Reset Filter
                            </a>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">
                            <i class="fas fa-table"></i> Data Absensi
                            <?php if ($filter_tanggal_mulai || $filter_tanggal_selesai): ?>
                                <small style="color:#6b7280;font-weight:400;margin-left:8px">
                                    | Periode: <?= $filter_tanggal_mulai ? date('d/m/Y', strtotime($filter_tanggal_mulai)) : 'Awal' ?> 
                                    s/d <?= $filter_tanggal_selesai ? date('d/m/Y', strtotime($filter_tanggal_selesai)) : 'Sekarang' ?>
                                </small>
                            <?php endif; ?>
                        </span>
                        <span style="color:#6b7280;font-size:14px">Total: <?= $rowCount ?> data</span>
                    </div>
                    <div class="table-container">
                        <?php if ($rowCount > 0): ?>
                        <table class="data-table" id="previewTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Karyawan</th>
                                    <th>Divisi</th>
                                    <th>Jabatan</th>
                                    <th>Tanggal</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Keluar</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; while ($row = $previewData->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><strong><?= htmlspecialchars($row['nama_karyawan']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['nama_divisi'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_jabatan'] ?? '-') ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td><?= $row['jam_masuk'] ?: '-' ?></td>
                                    <td><?= $row['jam_keluar'] ?: '-' ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']=='hadir'?'success':($row['status']=='telat'?'warning':($row['status']=='izin'?'info':($row['status']=='sakit'?'danger':'secondary'))) ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['keterangan'] ?? '-') ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div style="text-align:center;padding:50px 20px;color:#9ca3af">
                            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:15px;display:block"></i>
                            <h4 style="margin-bottom:8px">Tidak ada data</h4>
                            <p>Silakan pilih periode tanggal dan klik "Tampilkan Data"</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-top:20px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-chart-bar"></i> Ringkasan Data Sistem</span>
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

                <script>
                function downloadPDF() {
                    const form = document.getElementById('filterForm');
                    const tanggal_mulai = form.querySelector('[name="tanggal_mulai"]').value;
                    const tanggal_selesai = form.querySelector('[name="tanggal_selesai"]').value;
                    const karyawan_id = form.querySelector('[name="karyawan_id"]').value;
                    const status_absensi = form.querySelector('[name="status_absensi"]').value;

                    let url = '?generate_laporan_absensi=pdf';
                    if (tanggal_mulai) url += '&tanggal_mulai=' + tanggal_mulai;
                    if (tanggal_selesai) url += '&tanggal_selesai=' + tanggal_selesai;
                    if (karyawan_id) url += '&karyawan_id=' + karyawan_id;
                    if (status_absensi) url += '&status_absensi=' + status_absensi;

                    window.open(url, '_blank');
                }
                </script>

            <?php elseif ($page == 'pengumuman'): ?>
                <h1 class="page-title">Manajemen Pengumuman</h1>
                <p class="page-subtitle">Buat dan kelola pengumuman untuk seluruh karyawan</p>

                <?php
                // Handle Create Pengumuman
                $pengumumanMsg = '';
                $pengumumanError = '';
                
                if (isset($_POST['create_pengumuman'])) {
                    $judul = trim($_POST['judul']);
                    $isi = trim($_POST['isi']);
                    $tipe_target = $_POST['tipe_target'];
                    $divisi_id = !empty($_POST['divisi_id']) ? (int)$_POST['divisi_id'] : null;
                    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;
                    
                    // Validasi: admin bisa semua, atasan hanya divisi sendiri
                    if (!canCreatePengumuman($conn, $_SESSION['user_id'], $_SESSION['role'], $divisi_id)) {
                        $pengumumanError = "Anda tidak memiliki akses untuk mengirim ke divisi tersebut!";
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
                            $stmt = $conn->prepare("INSERT INTO pengumuman (judul, isi, tipe_target, divisi_id, file_lampiran, tanggal_kadaluarsa, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssisss", $judul, $isi, $tipe_target, $divisi_id, $file_lampiran, $tanggal_kadaluarsa, $_SESSION['user_id']);
                            $stmt->execute();
                            $pengumumanId = $conn->insert_id;
                            
                            // Kirim notifikasi ke target user
                            if ($tipe_target == 'semua') {
                                // Notifikasi ke semua user aktif
                                $users = $conn->query("SELECT id FROM users WHERE status = 'aktif' AND id != " . $_SESSION['user_id']);
                                while ($u = $users->fetch_assoc()) {
                                    addNotification($conn, $u['id'], 'Pengumuman Baru', 'Ada pengumuman baru: ' . $judul);
                                }
                            } else {
                                // Notifikasi ke user di divisi tertentu
                                $users = $conn->query("SELECT id FROM users WHERE status = 'aktif' AND divisi_id = $divisi_id AND id != " . $_SESSION['user_id']);
                                while ($u = $users->fetch_assoc()) {
                                    addNotification($conn, $u['id'], 'Pengumuman Baru', 'Ada pengumuman baru: ' . $judul);
                                }
                            }
                            
                            $pengumumanMsg = "Pengumuman berhasil dibuat!";
                        }
                    }
                }
                
                // Handle Delete Pengumuman
                if (isset($_GET['delete_pengumuman'])) {
                    $id = (int)$_GET['delete_pengumuman'];
                    if (deletePengumuman($conn, $id, $_SESSION['user_id'], $_SESSION['role'])) {
                        $pengumumanMsg = "Pengumuman berhasil dihapus!";
                    } else {
                        $pengumumanError = "Gagal menghapus pengumuman!";
                    }
                }
                
                // Get all pengumuman created by admin
                $pengumumanList = $conn->query("
                    SELECT p.*, d.nama_divisi, u.nama as created_by_nama,
                    (SELECT COUNT(*) FROM pengumuman_read WHERE pengumuman_id = p.id) as read_count
                    FROM pengumuman p 
                    LEFT JOIN divisi d ON p.divisi_id = d.id 
                    LEFT JOIN users u ON p.created_by = u.id 
                    ORDER BY p.created_at DESC
                ");
                ?>

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

                <!-- Form Buat Pengumuman -->
                <div class="card" style="margin-bottom:25px">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-plus-circle"></i> Buat Pengumuman Baru</span>
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
                                <select name="tipe_target" id="tipeTarget" class="form-control" required onchange="toggleDivisiSelect()">
                                    <option value="semua">Semua Karyawan (Semua Divisi & Jabatan)</option>
                                    <option value="divisi">Divisi Tertentu</option>
                                </select>
                            </div>
                            
                            <div class="form-group" id="divisiSelect" style="display:none">
                                <label><i class="fas fa-building"></i> Pilih Divisi</label>
                                <select name="divisi_id" class="form-control">
                                    <option value="">-- Pilih Divisi --</option>
                                    <?php 
                                    $divisiList->data_seek(0);
                                    while ($d = $divisiList->fetch_assoc()): 
                                    ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-calendar-times"></i> Tanggal Kadaluarsa (Opsional)</label>
                                <input type="date" name="tanggal_kadaluarsa" class="form-control" 
                                       min="<?= date('Y-m-d') ?>">
                                <small style="color:#6b7280">Pengumuman akan otomatis hilang setelah tanggal ini</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-paperclip"></i> Lampiran File (Opsional)</label>
                            <input type="file" name="file_lampiran" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <small style="color:#6b7280">Format: PDF, JPG, PNG, DOC, DOCX. Max 5MB</small>
                        </div>
                        
                        <button type="submit" name="create_pengumuman" class="btn btn-primary" style="padding:10px 25px">
                            <i class="fas fa-paper-plane"></i> Kirim Pengumuman
                        </button>
                    </form>
                </div>

                <!-- Daftar Pengumuman -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-list"></i> Daftar Pengumuman</span>
                        <span class="badge badge-primary"><?= $pengumumanList->num_rows ?> pengumuman</span>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Target</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Tanggal</th>
                                    <th>Kadaluarsa</th>
                                    <th>Dibaca</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($p = $pengumumanList->fetch_assoc()): 
                                    $isExpired = !empty($p['tanggal_kadaluarsa']) && strtotime($p['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                                    $targetLabel = $p['tipe_target'] == 'semua' ? 
                                        '<span class="badge badge-purple" style="background:#8b5cf6">Semua</span>' : 
                                        '<span class="badge badge-blue" style="background:#3b82f6">' . htmlspecialchars($p['nama_divisi'] ?? 'Divisi') . '</span>';
                                ?>
                                <tr style="<?= $isExpired ? 'opacity:0.6;background:#f3f4f6' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($p['judul']) ?></strong>
                                        <?php if (!empty($p['file_lampiran'])): ?>
                                            <br><small><i class="fas fa-paperclip"></i> Ada lampiran</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $targetLabel ?></td>
                                    <td><?= htmlspecialchars($p['created_by_nama'] ?? 'Admin') ?></td>
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
                                        <button class="btn btn-info btn-sm" onclick="viewPengumumanDetail(<?= htmlspecialchars(json_encode($p)) ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="?page=pengumuman&delete_pengumuman=<?= $p['id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Yakin hapus pengumuman ini?')"
                                           title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($pengumumanList->num_rows == 0): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada pengumuman
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Modal Detail Pengumuman -->
                <div class="modal-overlay" id="detailPengumumanModal">
                    <div class="modal" style="max-width:600px">
                        <div class="modal-header">
                            <h3><i class="fas fa-bullhorn"></i> Detail Pengumuman</h3>
                            <button class="modal-close" onclick="closeModal('detailPengumumanModal')">&times;</button>
                        </div>
                        <div class="modal-body" id="detailPengumumanContent">
                            <!-- Content loaded via JS -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('detailPengumumanModal')">Tutup</button>
                        </div>
                    </div>
                </div>

                <script>
                function toggleDivisiSelect() {
                    const tipe = document.getElementById('tipeTarget').value;
                    const divisiSelect = document.getElementById('divisiSelect');
                    divisiSelect.style.display = tipe === 'divisi' ? 'block' : 'none';
                    if (tipe === 'semua') {
                        divisiSelect.querySelector('select').value = '';
                    }
                }
                
                function viewPengumumanDetail(p) {
                    const content = document.getElementById('detailPengumumanContent');
                    const targetLabel = p.tipe_target === 'semua' ? 
                        '<span class="badge" style="background:#8b5cf6;color:#fff">Semua Karyawan</span>' : 
                        '<span class="badge" style="background:#3b82f6;color:#fff">Divisi: ' + (p.nama_divisi || 'Tertentu') + '</span>';
                    
                    const expiredBadge = p.tanggal_kadaluarsa && new Date(p.tanggal_kadaluarsa) < new Date() ? 
                        '<span class="badge badge-secondary">Kadaluarsa</span>' : '';
                    
                    let html = `
                        <div style="margin-bottom:15px">
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                                ${targetLabel}
                                ${expiredBadge}
                            </div>
                            <h4 style="color:#1e40af;margin-bottom:10px">${p.judul}</h4>
                            <div style="color:#6b7280;font-size:13px;margin-bottom:15px">
                                <i class="fas fa-user"></i> ${p.created_by_nama || 'Admin'} | 
                                <i class="fas fa-clock"></i> ${new Date(p.created_at).toLocaleString('id-ID')}
                                ${p.tanggal_kadaluarsa ? ' | <i class="fas fa-calendar-times"></i> Kadaluarsa: ' + new Date(p.tanggal_kadaluarsa).toLocaleDateString('id-ID') : ''}
                            </div>
                        </div>
                        <div style="background:#f9fafb;padding:15px;border-radius:8px;border:1px solid #e5e7eb;line-height:1.6;color:#374151;white-space:pre-wrap;margin-bottom:15px">
                            ${p.isi}
                        </div>
                    `;
                    
                    if (p.file_lampiran) {
                        const ext = p.file_lampiran.split('.').pop().toLowerCase();
                        const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
                        if (isImage) {
                            html += `
                                <div style="margin-top:15px">
                                    <strong><i class="fas fa-paperclip"></i> Lampiran:</strong><br>
                                    <img src="${p.file_lampiran}" style="max-width:100%;max-height:300px;border-radius:8px;margin-top:10px;border:1px solid #e5e7eb">
                                </div>
                            `;
                        } else {
                            html += `
                                <div style="margin-top:15px">
                                    <a href="${p.file_lampiran}" target="_blank" class="btn btn-secondary">
                                        <i class="fas fa-download"></i> Download Lampiran
                                    </a>
                                </div>
                            `;
                        }
                    }
                    
                    content.innerHTML = html;
                    openModal('detailPengumumanModal');
                }
                </script>

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
                                <i class="fas fa-eye toggle-password" onclick="togglePass('oldPass', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password Baru</label>
                            <div class="input-group" style="position:relative">
                                <input type="password" name="new_password" id="newPass" class="form-control" required>
                                <i class="fas fa-eye toggle-password" onclick="togglePass('newPass', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Konfirmasi Password Baru</label>
                            <div class="input-group" style="position:relative">
                                <input type="password" name="confirm_password" id="confirmPass" class="form-control" required>
                                <i class="fas fa-eye toggle-password" onclick="togglePass('confirmPass', this)"></i>
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
                <p class="page-subtitle">Kelola pengumuman untuk seluruh karyawan atau divisi tertentu</p>

                <?php if (isset($_GET['success'])): ?>
                    <div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #10b981">
                        <i class="fas fa-check-circle"></i> 
                        <?php 
                        switch($_GET['success']) {
                            case 'added': echo 'Pengumuman berhasil ditambahkan!'; break;
                            case 'updated': echo 'Pengumuman berhasil diperbarui!'; break;
                            case 'deleted': echo 'Pengumuman berhasil dihapus!'; break;
                            default: echo 'Operasi berhasil!';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:20px;border-left:4px solid #ef4444">
                        <i class="fas fa-exclamation-circle"></i> <?= $_GET['error'] == 'unauthorized' ? 'Anda tidak memiliki akses!' : 'Terjadi kesalahan!' ?>
                    </div>
                <?php endif; ?>

                <div class="stats-grid" style="margin-bottom:20px">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-bullhorn"></i></div>
                        <div class="stat-info">
                            <h3><?= $pengumumanList->num_rows ?></h3>
                            <p>Total Pengumuman</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-globe"></i></div>
                        <div class="stat-info">
                            <h3><?= $conn->query("SELECT COUNT(*) as c FROM pengumuman WHERE tipe_target='semua'")->fetch_assoc()['c'] ?></h3>
                            <p>Untuk Semua</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-building"></i></div>
                        <div class="stat-info">
                            <h3><?= $conn->query("SELECT COUNT(*) as c FROM pengumuman WHERE tipe_target='divisi'")->fetch_assoc()['c'] ?></h3>
                            <p>Per Divisi</p>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary" onclick="openModal('addPengumumanModal')" style="margin-bottom:20px">
                    <i class="fas fa-plus"></i> Buat Pengumuman Baru
                </button>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fas fa-list"></i> Daftar Pengumuman</span>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchPengumuman" placeholder="Cari pengumuman..." onkeyup="searchTable('searchPengumuman', 'pengumumanTable')">
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="pengumumanTable">
                            <thead>
                                <tr>
                                    <th>Judul</th>
                                    <th>Target</th>
                                    <th>Tanggal</th>
                                    <th>Kadaluarsa</th>
                                    <th>Dibaca</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $pengumumanList->data_seek(0);
                                while ($row = $pengumumanList->fetch_assoc()): 
                                    $targetLabel = $row['tipe_target'] == 'semua' 
                                        ? '<span class="badge badge-primary"><i class="fas fa-globe"></i> Semua Karyawan</span>' 
                                        : '<span class="badge badge-warning"><i class="fas fa-building"></i> ' . htmlspecialchars($row['nama_divisi']) . '</span>';
                                    
                                    $isExpired = $row['tanggal_kadaluarsa'] && strtotime($row['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['judul']) ?></strong>
                                        <div style="font-size:12px;color:#6b7280;margin-top:4px">
                                            Oleh: <?= htmlspecialchars($row['pengirim']) ?>
                                        </div>
                                    </td>
                                    <td><?= $targetLabel ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?php if ($row['tanggal_kadaluarsa']): ?>
                                            <span class="badge badge-<?= $isExpired ? 'danger' : 'info' ?>">
                                                <?= date('d/m/Y', strtotime($row['tanggal_kadaluarsa'])) ?>
                                                <?= $isExpired ? ' (Expired)' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Tidak ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-success">
                                            <i class="fas fa-eye"></i> <?= $row['read_count'] ?>x dibaca
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-info btn-sm" onclick="viewPengumumanDetail(<?= $row['id'] ?>)" title="Lihat Detail">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-warning btn-sm" onclick="editPengumuman(<?= htmlspecialchars(json_encode($row)) ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?page=pengumuman&hapus_pengumuman=<?= $row['id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Yakin hapus pengumuman ini?')"
                                           title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($pengumumanList->num_rows == 0): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:40px;color:#6b7280">
                                        <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px"></i>
                                        Belum ada pengumuman
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- MODAL: Tambah Pengumuman -->
                <div class="modal-overlay" id="addPengumumanModal">
                    <div class="modal" style="max-width:700px">
                        <div class="modal-header">
                            <h3><i class="fas fa-plus-circle"></i> Buat Pengumuman Baru</h3>
                            <button class="modal-close" onclick="closeModal('addPengumumanModal')">&times;</button>
                        </div>
                        <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm('addPengumumanForm')" id="addPengumumanForm">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Judul Pengumuman</label>
                                    <input type="text" name="judul" class="form-control" required placeholder="Masukkan judul...">
                                </div>
                                <div class="form-group">
                                    <label>Isi Pengumuman</label>
                                    <textarea name="isi" class="form-control" rows="6" required placeholder="Tulis isi pengumuman..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Target Pengumuman</label>
                                    <select name="tipe_target" id="tipeTarget" class="form-control" required onchange="toggleDivisiSelect()">
                                        <option value="semua">Seluruh Karyawan</option>
                                        <option value="divisi">Divisi Tertentu</option>
                                    </select>
                                </div>
                                <div class="form-group" id="divisiSelectGroup" style="display:none">
                                    <label>Pilih Divisi</label>
                                    <select name="divisi_id" class="form-control">
                                        <option value="">-- Pilih Divisi --</option>
                                        <?php 
                                        $divisiList->data_seek(0);
                                        while ($d = $divisiList->fetch_assoc()): 
                                        ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Tanggal Kadaluarsa (Opsional)</label>
                                    <input type="date" name="tanggal_kadaluarsa" class="form-control">
                                    <small style="color:#6b7280">Pengumuman akan otomatis hilang setelah tanggal ini</small>
                                </div>
                                <div class="form-group">
                                    <label>Lampiran File (Opsional)</label>
                                    <input type="file" name="file_lampiran" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <small style="color:#6b7280">Max 5MB. PDF, Word, atau Gambar</small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('addPengumumanModal')">Batal</button>
                                <button type="submit" name="tambah_pengumuman" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Kirim Pengumuman
                                </button>
                            </div>
                        </form>
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
                                    <label>Judul Pengumuman</label>
                                    <input type="text" name="judul" id="editPengumumanJudul" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Isi Pengumuman</label>
                                    <textarea name="isi" id="editPengumumanIsi" class="form-control" rows="6" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Target Pengumuman</label>
                                    <select name="tipe_target" id="editTipeTarget" class="form-control" required onchange="toggleEditDivisiSelect()">
                                        <option value="semua">Seluruh Karyawan</option>
                                        <option value="divisi">Divisi Tertentu</option>
                                    </select>
                                </div>
                                <div class="form-group" id="editDivisiSelectGroup" style="display:none">
                                    <label>Pilih Divisi</label>
                                    <select name="divisi_id" id="editDivisiId" class="form-control">
                                        <option value="">-- Pilih Divisi --</option>
                                        <?php 
                                        $divisiList->data_seek(0);
                                        while ($d = $divisiList->fetch_assoc()): 
                                        ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Tanggal Kadaluarsa (Opsional)</label>
                                    <input type="date" name="tanggal_kadaluarsa" id="editTanggalKadaluarsa" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Lampiran File (Opsional)</label>
                                    <input type="file" name="file_lampiran" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <div id="editFileLampiran" style="margin-top:8px;font-size:13px;color:#6b7280"></div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('editPengumumanModal')">Batal</button>
                                <button type="submit" name="edit_pengumuman" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- MODAL: Detail Pengumuman -->
                <div class="modal-overlay" id="detailPengumumanModal">
                    <div class="modal" style="max-width:800px">
                        <div class="modal-header">
                            <h3><i class="fas fa-info-circle"></i> Detail Pengumuman</h3>
                            <button class="modal-close" onclick="closeModal('detailPengumumanModal')">&times;</button>
                        </div>
                        <div class="modal-body" id="detailPengumumanContent">
                            <!-- Diisi via AJAX -->
                        </div>
                    </div>
                </div>

                <script>
                function toggleDivisiSelect() {
                    const tipe = document.getElementById('tipeTarget').value;
                    document.getElementById('divisiSelectGroup').style.display = tipe === 'divisi' ? 'block' : 'none';
                }
                
                function toggleEditDivisiSelect() {
                    const tipe = document.getElementById('editTipeTarget').value;
                    document.getElementById('editDivisiSelectGroup').style.display = tipe === 'divisi' ? 'block' : 'none';
                }
                
                function editPengumuman(data) {
                    document.getElementById('editPengumumanId').value = data.id;
                    document.getElementById('editPengumumanJudul').value = data.judul;
                    document.getElementById('editPengumumanIsi').value = data.isi;
                    document.getElementById('editTipeTarget').value = data.tipe_target;
                    document.getElementById('editTanggalKadaluarsa').value = data.tanggal_kadaluarsa || '';
                    
                    if (data.tipe_target === 'divisi') {
                        document.getElementById('editDivisiSelectGroup').style.display = 'block';
                        document.getElementById('editDivisiId').value = data.divisi_id || '';
                    } else {
                        document.getElementById('editDivisiSelectGroup').style.display = 'none';
                    }
                    
                    if (data.file_lampiran) {
                        document.getElementById('editFileLampiran').innerHTML = 
                            '<i class="fas fa-paperclip"></i> File saat ini: <a href="' + data.file_lampiran + '" target="_blank">' + data.file_lampiran.split('/').pop() + '</a>';
                    } else {
                        document.getElementById('editFileLampiran').innerHTML = 'Tidak ada file lampiran';
                    }
                    
                    openModal('editPengumumanModal');
                }
                
                function viewPengumumanDetail(id) {
                    fetch('ajax_pengumuman_detail.php?id=' + id)
                        .then(response => response.text())
                        .then(html => {
                            document.getElementById('detailPengumumanContent').innerHTML = html;
                            openModal('detailPengumumanModal');
                        });
                }
                </script>

            <?php endif; ?>
            <!-- END CONTENT PAGES -->
        </div>
    </div>
        <!-- MODALS (Shared across pages) -->

    <!-- ADD USER MODAL -->
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
                        <label>Status</label>
                        <select name="status" class="form-control" required>
                            <option value="aktif">Aktif</option>
                            <option value="tidak_aktif">Tidak Aktif (Resign)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="divisi_id" class="form-control">
                            <option value="">Pilih Divisi</option>
                            <?php 
                            $divisiList->data_seek(0);
                            while ($d = $divisiList->fetch_assoc()): 
                            ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="jabatan_id" class="form-control">
                            <option value="">Pilih Jabatan</option>
                            <?php 
                            $jabatanList->data_seek(0);
                            while ($j = $jabatanList->fetch_assoc()): 
                            ?>
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

    <!-- ============================================ -->
    <!-- EDIT USER MODAL -->
    <!-- ============================================ -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form method="POST" id="editUserForm" onsubmit="return validateEditPassword()">
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
                        <label><i class="fas fa-lock"></i> Password Baru <small style="color:#6b7280;font-weight:400">(Kosongkan jika tidak diubah)</small></label>
                        <div class="input-group">
                            <input type="password" name="password" id="editPassword" class="form-control" placeholder="Masukkan password baru..." minlength="6" oninput="checkPasswordMatch()">
                            <i class="fas fa-eye toggle-password" id="toggleEditPass" onclick="togglePassword('editPassword', 'toggleEditPass')"></i>
                        </div>
                        <small style="color:#6b7280;font-size:11px">Minimal 6 karakter</small>
                    </div>
                    <div class="form-group" id="confirmPassGroup" style="display:none">
                        <label><i class="fas fa-lock"></i> Konfirmasi Password Baru</label>
                        <div class="input-group">
                            <input type="password" id="editConfirmPassword" class="form-control" placeholder="Ulangi password baru..." oninput="checkPasswordMatch()">
                            <i class="fas fa-eye toggle-password" id="toggleEditConfirmPass" onclick="togglePassword('editConfirmPassword', 'toggleEditConfirmPass')"></i>
                        </div>
                        <div id="passMatchMsg"></div>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="editRole" class="form-control" required>
                            <option value="karyawan">Karyawan</option>
                            <option value="atasan">Atasan</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editStatus" class="form-control" required>
                            <option value="aktif">Aktif</option>
                            <option value="tidak_aktif">Tidak Aktif (Resign)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="divisi_id" id="editDivisi" class="form-control">
                            <option value="">Pilih Divisi</option>
                            <?php 
                            $divisiList->data_seek(0);
                            while ($d = $divisiList->fetch_assoc()): 
                            ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama_divisi']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="jabatan_id" id="editJabatan" class="form-control">
                            <option value="">Pilih Jabatan</option>
                            <?php 
                            $jabatanList->data_seek(0);
                            while ($j = $jabatanList->fetch_assoc()): 
                            ?>
                            <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['nama_jabatan']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Batal</button>
                    <button type="submit" name="edit_user" class="btn btn-primary" id="btnUpdateUser">
                        <i class="fas fa-save"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- NOTIF MODAL -->
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
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
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

        function checkPasswordMatch() {
            const password = document.getElementById('editPassword').value;
            const confirmGroup = document.getElementById('confirmPassGroup');
            const confirmInput = document.getElementById('editConfirmPassword');
            const msg = document.getElementById('passMatchMsg');
            const btnUpdate = document.getElementById('btnUpdateUser');
            if (password.length > 0) {
                confirmGroup.style.display = 'block';
                if (confirmInput.value.length > 0) {
                    if (password === confirmInput.value) {
                        msg.innerHTML = '<i class="fas fa-check-circle"></i> Password cocok';
                        msg.className = 'password-match';
                        confirmInput.classList.remove('input-error');
                        confirmInput.classList.add('input-success');
                        btnUpdate.disabled = false;
                        btnUpdate.classList.remove('btn-disabled');
                    } else {
                        msg.innerHTML = '<i class="fas fa-times-circle"></i> Password tidak cocok';
                        msg.className = 'password-mismatch';
                        confirmInput.classList.remove('input-success');
                        confirmInput.classList.add('input-error');
                        btnUpdate.disabled = true;
                        btnUpdate.classList.add('btn-disabled');
                    }
                } else {
                    msg.innerHTML = '';
                    confirmInput.classList.remove('input-error', 'input-success');
                    btnUpdate.disabled = true;
                    btnUpdate.classList.add('btn-disabled');
                }
            } else {
                confirmGroup.style.display = 'none';
                msg.innerHTML = '';
                confirmInput.classList.remove('input-error', 'input-success');
                btnUpdate.disabled = false;
                btnUpdate.classList.remove('btn-disabled');
            }
        }

        function validateEditPassword() {
            const password = document.getElementById('editPassword').value;
            const confirmInput = document.getElementById('editConfirmPassword');
            if (password.length > 0) {
                if (password.length < 6) {
                    alert('Password minimal 6 karakter!');
                    document.getElementById('editPassword').focus();
                    return false;
                }
                if (password !== confirmInput.value) {
                    alert('Password dan konfirmasi password tidak cocok!');
                    confirmInput.focus();
                    return false;
                }
            }
            return true;
        }

        function editUser(user) {
            document.getElementById('editUserForm').reset();
            document.getElementById('confirmPassGroup').style.display = 'none';
            document.getElementById('passMatchMsg').innerHTML = '';
            document.getElementById('btnUpdateUser').disabled = false;
            document.getElementById('btnUpdateUser').classList.remove('btn-disabled');
            document.getElementById('toggleEditPass').classList.remove('fa-eye-slash');
            document.getElementById('toggleEditPass').classList.add('fa-eye');
            document.getElementById('editPassword').type = 'password';

            document.getElementById('editUserId').value = user.id;
            document.getElementById('editNama').value = user.nama;
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editRole').value = user.role;
            document.getElementById('editStatus').value = user.status || 'aktif';
            document.getElementById('editDivisi').value = user.divisi_id || '';
            document.getElementById('editJabatan').value = user.jabatan_id || '';
            document.getElementById('editPassword').value = '';
            document.getElementById('editConfirmPassword').value = '';

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

        function filterByStatus() {
            var filter = document.getElementById('filterStatus').value;
            var rows = document.querySelectorAll('#usersTable tbody tr');
            rows.forEach(function(row) {
                if (filter === 'all' || row.getAttribute('data-status') === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
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