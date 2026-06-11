<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    echo '<p>Akses ditolak</p>';
    exit;
}

$id = (int) $_GET['id'];
$p = getPengumumanDetail($conn, $id, $_SESSION['user_id']);

if (!$p) {
    echo '<p>Pengumuman tidak ditemukan atau Anda tidak memiliki akses</p>';
    exit;
}

// Mark as read
markPengumumanRead($conn, $id, $_SESSION['user_id']);

$readers = getPengumumanReaders($conn, $id);
$readCount = getPengumumanReadCount($conn, $id);
$isExpired = $p['tanggal_kadaluarsa'] && strtotime($p['tanggal_kadaluarsa']) < strtotime(date('Y-m-d'));
?>

<div style="margin-bottom:20px">
    <h2 style="font-size:20px;margin-bottom:10px"><?= htmlspecialchars($p['judul']) ?></h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px">
        <span class="badge badge-<?= $p['tipe_target']=='semua'?'primary':'warning' ?>">
            <i class="fas fa-<?= $p['tipe_target']=='semua'?'globe':'building' ?>"></i>
            <?= $p['tipe_target']=='semua'?'Semua Karyawan':'Divisi: '.htmlspecialchars($p['nama_divisi']) ?>
        </span>
        <span class="badge badge-info">
            <i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
        </span>
        <?php if ($p['tanggal_kadaluarsa']): ?>
        <span class="badge badge-<?= $isExpired?'danger':'success' ?>">
            <i class="fas fa-hourglass-<?= $isExpired?'end':'half' ?>"></i> 
            Kadaluarsa: <?= date('d/m/Y', strtotime($p['tanggal_kadaluarsa'])) ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<div style="background:#f8fafc;padding:20px;border-radius:12px;margin-bottom:20px;line-height:1.7">
    <?= nl2br(htmlspecialchars($p['isi'])) ?>
</div>

<?php if ($p['file_lampiran']): ?>
<div style="margin-bottom:20px">
    <h4 style="font-size:14px;color:#6b7280;margin-bottom:8px"><i class="fas fa-paperclip"></i> Lampiran</h4>
    <a href="<?= $p['file_lampiran'] ?>" target="_blank" class="btn btn-secondary btn-sm">
        <i class="fas fa-download"></i> Download File
    </a>
    <span style="font-size:12px;color:#6b7280;margin-left:8px"><?= basename($p['file_lampiran']) ?></span>
</div>
<?php endif; ?>

<div style="border-top:2px solid #e2e8f0;padding-top:20px">
    <h4 style="font-size:16px;margin-bottom:15px">
        <i class="fas fa-eye"></i> Read Receipt 
        <span class="badge badge-success"><?= $readCount ?> orang sudah membaca</span>
    </h4>
    
    <?php if ($readers->num_rows > 0): ?>
    <div class="table-container">
        <table class="data-table" style="font-size:13px">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Role</th>
                    <th>Divisi</th>
                    <th>Dibaca Pada</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($r = $readers->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['nama']) ?></strong></td>
                    <td><span class="badge badge-<?= $r['role']=='admin'?'danger':($r['role']=='atasan'?'warning':'primary') ?>"><?= ucfirst($r['role']) ?></span></td>
                    <td><?= htmlspecialchars($r['nama_divisi'] ?? '-') ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($r['read_at'])) ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:#9ca3af;text-align:center;padding:20px">
        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:10px"></i>
        Belum ada yang membaca pengumuman ini
    </p>
    <?php endif; ?>
</div>