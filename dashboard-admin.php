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
$totalUsersAktif = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='aktif'")->fetch_assoc()['total'];
$totalUsersTidakAktif = $conn->query("SELECT COUNT(*) as total FROM users WHERE status='tidak_aktif'")->fetch_assoc()['total'];

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
        $stmt->bind_param("sssiiiss", $nama, $email, $role, $divisi_id, $jabatan_id, $password, $status, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, role=?, divisi_id=?, jabatan_id=?, status=? WHERE id=?");
        $stmt->bind_param("sssiiis", $nama, $email, $role, $divisi_id, $jabatan_id, $status, $id);
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
    $nama = trim($_POST['nama']);
    $email = trim($_POST['email']) ?: null;
    $no_hp = trim($_POST['no_hp']) ?: null;
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    $divisi_id = !empty($_POST['divisi_id']) ? (int)$_POST['divisi_id'] : null;
    $jabatan_id = !empty($_POST['jabatan_id']) ? (int)$_POST['jabatan_id'] : null;
    
    // Validasi username tidak boleh kosong
    if (empty($username)) {
        $profileError = "Username tidak boleh kosong!";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        if ($stmt === false) {
            $profileError = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("si", $username, $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $profileError = "Username sudah digunakan oleh user lain!";
            } else {
                $foto = $user['foto'] ?? null;
                
                // Handle upload foto
                if (!empty($_FILES['foto']['name'])) {
                    $uploadDir = 'uploads/profile/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    // Validasi file
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = $_FILES['foto']['type'];
                    $fileSize = $_FILES['foto']['size'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        $profileError = "Format file tidak didukung! (JPG, PNG, GIF, WEBP)";
                    } elseif ($fileSize > 2 * 1024 * 1024) { // Max 2MB
                        $profileError = "Ukuran file maksimal 2MB!";
                    } else {
                        $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                        $fotoName = time() . '_' . uniqid() . '.' . $ext;
                        $fotoPath = $uploadDir . $fotoName;
                        
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], $fotoPath)) {
                            // Hapus foto lama jika ada
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
                
                // Update database hanya jika tidak ada error
                if (empty($profileError)) {
                    $stmt = $conn->prepare("UPDATE users SET nama=?, email=?, no_hp=?, username=?, role=?, divisi_id=?, jabatan_id=?, foto=? WHERE id=?");
                    if ($stmt === false) {
                        $profileError = "Database error: " . $conn->error;
                    } else {
                        $stmt->bind_param("sssssiiii", $nama, $email, $no_hp, $username, $role, $divisi_id, $jabatan_id, $foto, $_SESSION['user_id']);
                        
                        if ($stmt->execute()) {
                            $_SESSION['nama'] = $nama;
                            $_SESSION['username'] = $username;
                            $_SESSION['role'] = $role;
                            $profileMsg = "Profile berhasil diperbarui!";
                            
                            // Refresh data user
                            $user = getUserById($conn, $_SESSION['user_id']);
                        } else {
                            $profileError = "Gagal memperbarui profile: " . $stmt->error;
                        }
                    }
                }
            }
        }
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
    $pending = 0;
    $disetujui = 0;
    $ditolak = 0;
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
            <?php 
            $no = 1;
            foreach ($dataRows as $row): 
            ?>
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
// HANDLE LAPORAN ABSENSI PDF (DARI FILE 1)
// ============================================

if (isset($_GET['generate_laporan_absensi']) && $_GET['generate_laporan_absensi'] == 'pdf') {
    $tanggal_mulai   = $_GET['tanggal_mulai']   ?? '';
    $tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
    $karyawan_id     = $_GET['karyawan_id']     ?? '';
    $status_absensi  = $_GET['status_absensi']  ?? '';
    $jenis_data      = $_GET['jenis_data']      ?? 'absensi';

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
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            font-size: 12px; 
            color: #1f2937; 
            line-height: 1.5; 
            background: #fff; 
            padding: 20px; 
        }
        .header { 
            text-align: center; 
            border-bottom: 3px solid #059669; 
            padding-bottom: 15px; 
            margin-bottom: 20px; 
        }
        .header h1 { 
            font-size: 24px; 
            color: #059669; 
            margin-bottom: 5px; 
            font-weight: 800; 
            letter-spacing: 1px; 
        }
        .header .company { 
            font-size: 13px; 
            color: #4b5563; 
            font-weight: 600; 
        }
        .header .date { 
            font-size: 11px; 
            color: #6b7280; 
            margin-top: 5px; 
        }
        .info-box { 
            background: #f0fdf4; 
            border: 1px solid #bbf7d0; 
            border-radius: 8px; 
            padding: 12px 15px; 
            margin-bottom: 20px; 
        }
        .info-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .info-table td { 
            padding: 4px 8px; 
            font-size: 11px; 
            vertical-align: top; 
        }
        .info-label { 
            color: #374151; 
            font-weight: 600; 
            width: 120px; 
            white-space: nowrap; 
        }
        .info-value { 
            color: #1f2937; 
            font-weight: 500; 
        }
        table.data { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
            font-size: 11px; 
        }
        table.data thead th { 
            background: #059669; 
            color: white; 
            padding: 10px 8px; 
            text-align: center; 
            font-weight: 700; 
            font-size: 10px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            border: 1px solid #047857; 
        }
        table.data tbody td { 
            border: 1px solid #d1d5db; 
        }
        table.data tbody tr:nth-child(even) { 
            background: #f9fafb; 
        }
        table.data tbody tr:hover { 
            background: #f0fdf4; 
        }
        .summary { 
            margin-top: 20px; 
            border: 1px solid #d1d5db; 
            border-radius: 8px; 
            padding: 15px; 
            background: #fafafa; 
        }
        .summary-title { 
            font-size: 13px; 
            font-weight: 800; 
            color: #1f2937; 
            margin-bottom: 12px; 
            border-bottom: 2px solid #059669; 
            padding-bottom: 6px; 
        }
        .summary-grid { 
            display: table; 
            width: 100%; 
        }
        .summary-row { 
            display: table-row; 
        }
        .summary-cell { 
            display: table-cell; 
            padding: 6px 12px; 
            font-size: 12px; 
        }
        .summary-label { 
            font-weight: 600; 
            color: #4b5563; 
        }
        .summary-value { 
            font-weight: 700; 
            color: #059669; 
        }
        .footer { 
            margin-top: 25px; 
            text-align: center; 
            font-size: 10px; 
            color: #9ca3af; 
            border-top: 1px solid #e5e7eb; 
            padding-top: 10px; 
        }
        .print-btn { 
            position: fixed; 
            top: 15px; 
            right: 15px; 
            padding: 10px 20px; 
            background: #059669; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 13px; 
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            box-shadow: 0 2px 8px rgba(5,150,105,0.3); 
        }
        .print-btn:hover { 
            background: #047857; 
        }
        @media print { 
            .print-btn { display: none !important; } 
            body { padding: 0; } 
            .summary { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <button class='print-btn' onclick='window.print()'>
        <i class='fas fa-print'></i> Cetak / Simpan PDF
    </button>
    
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
        <tbody>
            {$rows}
        </tbody>
    </table>
    
    <div class='summary'>
        <div class='summary-title'>
            <i class='fas fa-chart-pie'></i> RINGKASAN STATUS KEHADIRAN
        </div>
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
        /* STYLE UNTUK PASSWORD TOGGLE */
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
        
        /* STYLE UNTUK KONFIRMASI PASSWORD */
        .password-match { color: #059669; font-size: 12px; margin-top: 4px; }
        .password-mismatch { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .input-error { border-color: #dc2626 !important; }
        .input-success { border-color: #059669 !important; }
        
        /* DISABLED BUTTON */
        .btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
    </style>
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
                
                $karyawanList = $conn->query("SELECT id, nama FROM users WHERE role='karyawan' ORDER BY nama");
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
                            <p style="font-size:12px;margin-top:10px">Contoh: Cetak tahun 2015 → Tanggal Mulai: 2015-01-01, Tanggal Selesai: 2015-12-31</p>
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
                                <option value="karyawan" <?= $user['role']=='karyawan'?'selected':'' ?>>Kary  Saya melihat pesan Anda terpotong lagi. Saya akan melanjutkan dari bagian yang terputus dan menyelesaikan seluruh kode. Berikut adalah **sambungan langsung** dari bagian Profile Admin yang terpotong, dilanjutkan hingga akhir file:

```php
awan</option>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- ADD USER MODAL -->
    <!-- ============================================ -->
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
    <!-- EDIT USER MODAL - DENGAN PASSWORD TOGGLE -->
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
                    
                    <!-- PASSWORD BARU DENGAN TOGGLE SHOW/HIDE -->
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password Baru <small style="color:#6b7280;font-weight:400">(Kosongkan jika tidak diubah)</small></label>
                        <div class="input-group">
                            <input type="password" name="password" id="editPassword" class="form-control" placeholder="Masukkan password baru..." minlength="6" oninput="checkPasswordMatch()">
                            <i class="fas fa-eye toggle-password" id="toggleEditPass" onclick="togglePassword('editPassword', 'toggleEditPass')"></i>
                        </div>
                        <small style="color:#6b7280;font-size:11px">Minimal 6 karakter</small>
                    </div>
                    
                    <!-- KONFIRMASI PASSWORD - MUNCUL OTOMATIS -->
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

    <!-- ============================================ -->
    <!-- NOTIF MODAL -->
    <!-- ============================================ -->
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

    <!-- ============================================ -->
    <!-- JAVASCRIPT -->
    <!-- ============================================ -->
    <script src="js/script.js"></script>
    <script>
        // ============================================
        // FUNGSI TOGGLE PASSWORD SHOW/HIDE
        // ============================================
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

        // ============================================
        // CEK KECOCOKAN PASSWORD EDIT USER
        // ============================================
        function checkPasswordMatch() {
            const password = document.getElementById('editPassword').value;
            const confirmGroup = document.getElementById('confirmPassGroup');
            const confirmInput = document.getElementById('editConfirmPassword');
            const msg = document.getElementById('passMatchMsg');
            const btnUpdate = document.getElementById('btnUpdateUser');
            
            // Tampilkan konfirmasi password jika password diisi
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

        // ============================================
        // VALIDASI SEBELUM SUBMIT EDIT USER
        // ============================================
        function validateEditPassword() {
            const password = document.getElementById('editPassword').value;
            const confirmInput = document.getElementById('editConfirmPassword');
            
            // Jika password diisi, pastikan konfirmasi juga diisi dan cocok
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

        // ============================================
        // EDIT USER - ISI DATA KE MODAL
        // ============================================
        function editUser(user) {
            // Reset form terlebih dahulu
            document.getElementById('editUserForm').reset();
            document.getElementById('confirmPassGroup').style.display = 'none';
            document.getElementById('passMatchMsg').innerHTML = '';
            document.getElementById('btnUpdateUser').disabled = false;
            document.getElementById('btnUpdateUser').classList.remove('btn-disabled');
            
            // Reset toggle icon mata
            document.getElementById('toggleEditPass').classList.remove('fa-eye-slash');
            document.getElementById('toggleEditPass').classList.add('fa-eye');
            document.getElementById('editPassword').type = 'password';
            
            // Isi data user ke form
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editNama').value = user.nama;
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editRole').value = user.role;
            document.getElementById('editStatus').value = user.status || 'aktif';
            document.getElementById('editDivisi').value = user.divisi_id || '';
            document.getElementById('editJabatan').value = user.jabatan_id || '';
            
            // Reset password fields
            document.getElementById('editPassword').value = '';
            document.getElementById('editConfirmPassword').value = '';
            
            openModal('editUserModal');
        }

        // ============================================
        // PREVIEW IMAGE PROFILE
        // ============================================
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewFoto').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ============================================
        // TOGGLE PASSWORD PROFILE ADMIN
        // ============================================
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

        // ============================================
        // FILTER USER BY STATUS
        // ============================================
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

        // ============================================
        // SEARCH TABLE
        // ============================================
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