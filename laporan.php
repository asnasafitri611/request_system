<?php
require_once 'config.php';
checkRole(['admin']);

// Simple PDF-like HTML report
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Laporan Request System</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; }
        h1 { color: #10b981; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #10b981; color: white; }
        tr:nth-child(even) { background: #f9fafb; }
        .header-info { text-align: center; margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="header-info">
        <h1>LAPORAN REQUEST SYSTEM</h1>
        <p>PT. KREATOR SOLUSI INFORMASI</p>
        <p>Tanggal: <?= date('d F Y H:i:s') ?></p>
    </div>

    <h3>Data User</h3>
    <table>
        <tr><th>ID</th><th>Nama</th><th>Role</th><th>Email</th></tr>
        <?php
        $users = $conn->query("SELECT * FROM users");
        while ($u = $users->fetch_assoc()):
        ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['nama']) ?></td>
            <td><?= ucfirst($u['role']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <h3 style="margin-top:30px">Data Absensi</h3>
    <table>
        <tr><th>Tanggal</th><th>User ID</th><th>Masuk</th><th>Keluar</th><th>Status</th></tr>
        <?php
        $absen = $conn->query("SELECT * FROM absensi ORDER BY tanggal DESC LIMIT 50");
        while ($a = $absen->fetch_assoc()):
        ?>
        <tr>
            <td><?= $a['tanggal'] ?></td>
            <td><?= $a['user_id'] ?></td>
            <td><?= $a['jam_masuk'] ?></td>
            <td><?= $a['jam_keluar'] ?></td>
            <td><?= ucfirst($a['status']) ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <h3 style="margin-top:30px">Data Request</h3>
    <table>
        <tr><th>ID</th><th>User ID</th><th>Jenis</th><th>Status</th><th>Tanggal</th></tr>
        <?php
        $req = $conn->query("SELECT * FROM request_system ORDER BY created_at DESC LIMIT 50");
        while ($r = $req->fetch_assoc()):
        ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><?= $r['user_id'] ?></td>
            <td><?= ucfirst($r['jenis_request']) ?></td>
            <td><?= ucfirst($r['status']) ?></td>
            <td><?= $r['tanggal_mulai'] ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <script>window.print();</script>
</body>
</html>