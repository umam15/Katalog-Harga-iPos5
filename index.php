<?php
// index.php
require_once 'db_config.php';

// --- 1. Konfigurasi Filter & Pagination ---
$search  = isset($_GET['q']) ? pg_escape_string($dbconn, $_GET['q']) : '';
$f_jenis = isset($_GET['jenis']) ? pg_escape_string($dbconn, $_GET['jenis']) : '';
$f_merek = isset($_GET['merek']) ? pg_escape_string($dbconn, $_GET['merek']) : '';
$f_stok  = isset($_GET['stok']) ? true : false;

$limit   = 100;
$page    = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset  = ($page - 1) * $limit;

// --- 2. Query untuk Dropdown Filter ---
$res_jenis = pg_query($dbconn, "SELECT DISTINCT jenis FROM tbl_item WHERE jenis IS NOT NULL AND jenis != '' ORDER BY jenis");
$res_merek = pg_query($dbconn, "SELECT DISTINCT merek FROM tbl_item WHERE merek IS NOT NULL AND merek != '' ORDER BY merek");

// --- 3. Membangun Logika Query Utama ---
// Klausa WHERE dasar berdasarkan aturan bisnis sistem harga
$where_clause = " WHERE 1=1 ";
$where_clause .= " AND (
    (i.sistemhargajual = 'O' AND b.satuan = i.satuan) OR
    (i.sistemhargajual = 'S' AND hj.satuan IS NOT NULL) OR
    (i.sistemhargajual = 'L' AND hj.level = 1) OR
    (i.sistemhargajual = 'J' AND hj.jmlsampai >= 1)
)";

// Filter dinamis dari User
if ($search) {
    $where_clause .= " AND (b.kodebarcode ILIKE '%$search%' OR i.namaitem ILIKE '%$search%' OR i.keterangan ILIKE '%$search%')";
}
if ($f_jenis) $where_clause .= " AND i.jenis = '$f_jenis'";
if ($f_merek) $where_clause .= " AND i.merek = '$f_merek'";
if ($f_stok)  $where_clause .= " AND st.stok > 0";

// Hitung Total Data untuk Pagination
$sql_count = "SELECT COUNT(*) FROM tbl_itemsatuanjml b 
              JOIN tbl_item i ON b.kodeitem = i.kodeitem 
              LEFT JOIN tbl_itemstok st ON i.kodeitem = st.kodeitem 
              LEFT JOIN tbl_itemhj hj ON i.kodeitem = hj.kodeitem AND b.satuan = hj.satuan 
              $where_clause";
$res_count = pg_query($dbconn, $sql_count);
$total_rows = pg_fetch_result($res_count, 0, 0);
$total_pages = ceil($total_rows / $limit);

// Ambil Data Utama
$sql = "SELECT i.namaitem, i.jenis, i.merek, i.sistemhargajual, i.keterangan,
               i.satuan AS satuan_dasar, i.hargajual1 AS harga_dasar,
               st.stok, b.kodebarcode, b.satuan AS satuan_barcode,
               hj.hargajual AS harga_hj, hj.satuan AS satuan_hj, hj.level, hj.jmlsampai
        FROM tbl_itemsatuanjml b
        JOIN tbl_item i ON b.kodeitem = i.kodeitem
        LEFT JOIN tbl_itemstok st ON i.kodeitem = st.kodeitem
        LEFT JOIN tbl_itemhj hj ON i.kodeitem = hj.kodeitem AND b.satuan = hj.satuan
        $where_clause
        ORDER BY i.namaitem ASC LIMIT $limit OFFSET $offset";
$result = pg_query($dbconn, $sql);

// --- 4. Helper Function untuk Memproses Baris ---
function processData($row) {
    $s = $row['sistemhargajual'];
    
    // Logika Penetapan Harga & Satuan sesuai Sistem
    if ($s == 'O') {
        $harga = $row['harga_dasar'];
        $satuan = $row['satuan_dasar'];
    } else {
        $harga = $row['harga_hj'];
        $satuan = $row['satuan_barcode'];
    }

    // Format Kolom System
    $sysDetail = $s;
    if ($s == 'J') $sysDetail .= " (" . (int)$row['jmlsampai'] . ")";
    if ($s == 'L') $sysDetail .= " (L1)";

    return [
        'barcode' => $row['kodebarcode'],
        'nama'    => $row['namaitem'],
        'info'    => $row['jenis'] . " | " . $row['merek'],
        'satuan'  => $satuan,
        'harga'   => "Rp " . number_format((float)$harga, 0, ',', '.'),
        'stok'    => ((float)$row['stok'] > 0) ? '<span class="stok-ok">+</span>' : '<span class="stok-no">x</span>',
        'ket'     => $row['keterangan'],
        'sys'     => $sysDetail
    ];
}

// Build query string untuk pagination
$query_params = $_GET;
unset($query_params['page']);
$base_url = "?" . http_build_query($query_params) . "&page=";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katalog Harga</title>
    <link rel="stylesheet" href="style.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
</head>
<body>

<header>
    <h1>Katalog Harga</h1>
</header>

<form class="filters" method="GET">
    <input type="text" name="q" placeholder="Cari barcode atau nama..." value="<?= htmlspecialchars($search) ?>">
    
    <select name="jenis">
        <option value="">-- Semua Jenis --</option>
        <?php while($rj = pg_fetch_assoc($res_jenis)): ?>
            <option value="<?= $rj['jenis'] ?>" <?= ($f_jenis == $rj['jenis']) ? 'selected' : '' ?>><?= $rj['jenis'] ?></option>
        <?php endwhile; ?>
    </select>

    <select name="merek">
        <option value="">-- Semua Merek --</option>
        <?php while($rm = pg_fetch_assoc($res_merek)): ?>
            <option value="<?= $rm['merek'] ?>" <?= ($f_merek == $rm['merek']) ? 'selected' : '' ?>><?= $rm['merek'] ?></option>
        <?php endwhile; ?>
    </select>

    <label style="font-size: 0.85rem; cursor:pointer;">
        <input type="checkbox" name="stok" <?= $f_stok ? 'checked' : '' ?>> Stok Ready
    </label>

    <button type="submit">Filter</button>
    <?php if($search || $f_jenis || $f_merek || $f_stok): ?>
        <a href="index.php" style="font-size:0.8rem; color:var(--danger); text-decoration:none;">Reset</a>
    <?php endif; ?>
</form>

<div class="desktop-view">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Nama Item</th>
                    <th>Jenis/Merek</th>
                    <th>Satuan</th>
                    <th>Harga</th>
                    <th style="text-align:center">Stok</th>
                    <th>Keterangan</th>
                    <th>System</th>
                </tr>
            </thead>
            <tbody>
                <?php if (pg_num_rows($result) > 0): ?>
                    <?php while ($row = pg_fetch_assoc($result)): $d = processData($row); ?>
                    <tr>
                        <td style="font-family:monospace; color:var(--text-muted);"><?= $d['barcode'] ?></td>
                        <td style="font-weight:600;"><?= $d['nama'] ?></td>
                        <td><?= $d['info'] ?></td>
                        <td><?= $d['satuan'] ?></td>
                        <td style="font-weight:700; color:var(--accent);"><?= $d['harga'] ?></td>
                        <td style="text-align:center;"><?= $d['stok'] ?></td>
                        <td style="font-size:0.8rem; color:var(--text-muted);"><?= $d['ket'] ?></td>
                        <td><span style="background:#f1f5f9; padding:2px 5px; border-radius:3px; font-size:0.7rem;"><?= $d['sys'] ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; padding:3rem;">Data tidak ditemukan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mobile-view">
    <?php if (pg_num_rows($result) > 0): pg_result_seek($result, 0); ?>
        <?php while ($row = pg_fetch_assoc($result)): $d = processData($row); ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-subtitle"><?= $d['barcode'] ?></div>
                    <div class="card-title"><?= $d['nama'] ?></div>
                </div>
                <?= $d['stok'] ?>
            </div>
            <div class="card-row">
                <span class="card-label">Harga</span>
                <span class="card-price"><?= $d['harga'] ?> <small style="font-weight:normal; color:var(--text-muted);">/ <?= $d['satuan'] ?></small></span>
            </div>
            <div class="card-row">
                <span class="card-label">Info</span>
                <span><?= $d['info'] ?></span>
            </div>
            <?php if($d['ket']): ?>
                <div class="card-row"><span class="card-label">Ket</span><span style="font-size:0.8rem;"><?= $d['ket'] ?></span></div>
            <?php endif; ?>
            <div class="card-row" style="border-top:1px dashed var(--border); margin-top:8px; padding-top:8px;">
                <span class="card-label">System</span>
                <span style="font-size:0.7rem; background:#f1f5f9; padding:1px 4px; border-radius:3px;"><?= $d['sys'] ?></span>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p style="text-align:center; padding:2rem;">Data tidak ditemukan.</p>
    <?php endif; ?>
</div>

<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="<?= $base_url ?>1" title="Pertama">&laquo;</a>
        <a href="<?= $base_url . ($page - 1) ?>">Prev</a>
    <?php endif; ?>

    <?php
    $start_loop = max(1, $page - 2);
    $end_loop = min($total_pages, $page + 2);
    for ($i = $start_loop; $i <= $end_loop; $i++): ?>
        <a href="<?= $base_url . $i ?>" class="<?= ($page == $i) ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?= $base_url . ($page + 1) ?>">Next</a>
        <a href="<?= $base_url . $total_pages ?>" title="Terakhir">&raquo;</a>
    <?php endif; ?>
</div>

<div style="text-align:center; font-size:0.75rem; color:var(--text-muted); margin-bottom:30px;">
    Menampilkan <?= pg_num_rows($result) ?> dari <?= number_format($total_rows, 0, ',', '.') ?> total data
</div>

</body>
</html>
