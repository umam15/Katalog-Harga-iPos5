<?php
// index.php
require_once 'db_config.php';

// --- 1. PARAMETER FILTER & PAGINATION ---
$search  = isset($_GET['q']) ? trim($_GET['q']) : '';
$f_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$f_merek = isset($_GET['merek']) ? $_GET['merek'] : '';
$f_stok  = isset($_GET['stok']) ? true : false;

$limit   = 100;
$page    = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset  = ($page - 1) * $limit;

// --- 2. DATA UNTUK DROPDOWN ---
$res_jenis = $pdo->query("SELECT DISTINCT jenis FROM tbl_item WHERE jenis IS NOT NULL AND jenis != '' ORDER BY jenis")->fetchAll();
$res_merek = $pdo->query("SELECT DISTINCT merek FROM tbl_item WHERE merek IS NOT NULL AND merek != '' ORDER BY merek")->fetchAll();

// --- 3. LOGIKA QUERY (ATURAN SISTEM HARGA) ---
$params = [];
$where_clause = " WHERE 1=1 ";
$where_clause .= " AND (
    (i.sistemhargajual = 'O' AND b.satuan = i.satuan) OR
    (i.sistemhargajual = 'S' AND hj.satuan IS NOT NULL) OR
    (i.sistemhargajual = 'L' AND hj.level = 1) OR
    (i.sistemhargajual = 'J' AND hj.jmlsampai >= 1)
)";

if ($search) {
    $where_clause .= " AND (b.kodebarcode ILIKE :search OR i.namaitem ILIKE :search OR i.keterangan ILIKE :search)";
    $params[':search'] = "%$search%";
}
if ($f_jenis) {
    $where_clause .= " AND i.jenis = :jenis";
    $params[':jenis'] = $f_jenis;
}
if ($f_merek) {
    $where_clause .= " AND i.merek = :merek";
    $params[':merek'] = $f_merek;
}
if ($f_stok) {
    $where_clause .= " AND st.stok > 0";
}

// Hitung Total Rows
$sql_count = "SELECT COUNT(*) FROM tbl_itemsatuanjml b 
              JOIN tbl_item i ON b.kodeitem = i.kodeitem 
              LEFT JOIN tbl_itemstok st ON i.kodeitem = st.kodeitem 
              LEFT JOIN tbl_itemhj hj ON i.kodeitem = hj.kodeitem AND b.satuan = hj.satuan 
              $where_clause";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();
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
        ORDER BY i.namaitem ASC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$results = $stmt->fetchAll();

// --- 4. FORMATTING FUNCTION ---
function formatRow($row) {
    $s = $row['sistemhargajual'];
    $harga = ($s == 'O') ? $row['harga_dasar'] : $row['harga_hj'];
    $satuan = ($s == 'O') ? $row['satuan_dasar'] : $row['satuan_barcode'];

    $sysTag = $s;
    if ($s == 'J') $sysTag .= " (" . (int)$row['jmlsampai'] . ")";
    if ($s == 'L') $sysTag .= " (L1)";

    return [
        'barcode' => $row['kodebarcode'],
        'nama'    => $row['namaitem'],
        'jenis'   => $row['jenis'],
        'merek'   => $row['merek'],
        'satuan'  => $satuan,
        'harga'   => number_format((float)$harga, 0, ',', '.'),
        'stok'    => ((float)$row['stok'] > 0) ? '<span class="stok-ok">+</span>' : '<span class="stok-no">x</span>',
        'ket'     => $row['keterangan'],
        'sys'     => $sysTag
    ];
}

$query_params = $_GET;
unset($query_params['page']);
$base_pagination_url = "?" . http_build_query($query_params) . "&page=";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katalog Harga</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>Katalog Harga</h1>
</header>

<form class="filters" method="GET">
    <input type="text" name="q" placeholder="Cari barcode/nama..." value="<?= htmlspecialchars($search) ?>">
    
    <select name="jenis">
        <option value="">-- Jenis --</option>
        <?php foreach($res_jenis as $rj): ?>
            <option value="<?= htmlspecialchars($rj['jenis']) ?>" <?= ($f_jenis == $rj['jenis']) ? 'selected' : '' ?>><?= htmlspecialchars($rj['jenis']) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="merek">
        <option value="">-- Merek --</option>
        <?php foreach($res_merek as $rm): ?>
            <option value="<?= htmlspecialchars($rm['merek']) ?>" <?= ($f_merek == $rm['merek']) ? 'selected' : '' ?>><?= htmlspecialchars($rm['merek']) ?></option>
        <?php endforeach; ?>
    </select>

    <label style="cursor:pointer; font-size: 0.85rem;">
        <input type="checkbox" name="stok" <?= $f_stok ? 'checked' : '' ?>> Ready
    </label>

    <button type="submit">Cari</button>
    <?php if($search || $f_jenis || $f_merek || $f_stok): ?>
        <a href="index.php" style="color:var(--danger, red); font-size:0.8rem; text-decoration:none; margin-left:5px;">Reset</a>
    <?php endif; ?>
</form>

<div class="desktop-view">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Nama Item</th>
                    <th>Jenis</th>
                    <th>Harga</th>
                    <th style="text-align:center;">Stok</th>
                    <th>Sistem</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($results) > 0): ?>
                    <?php foreach ($results as $raw): $d = formatRow($raw); ?>
                    <tr>
                        <td style="font-family:monospace;"><?= htmlspecialchars($d['barcode']) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($d['nama']) ?></strong>
                            <?php if (!empty($d['ket'])): ?>
                                <br><small style="color:var(--text-muted, gray);"><?= htmlspecialchars($d['ket']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                        	<?= htmlspecialchars($d['jenis']) ?>
                        	<br><small style="color:var(--text-muted, gray);"><?= htmlspecialchars($d['merek']) ?></small>
                        </td>
                        <td>
                            <span style="color:var(--primary, blue); font-weight:700;">Rp <?= $d['harga'] ?></span>
                            <br><small style="color:var(--text-muted, gray);"><?= htmlspecialchars($d['satuan']) ?></small>
                        </td>
                        <td style="text-align:center;"><?= $d['stok'] ?></td>
                        <td><span style="background:#f1f5f9; padding:2px 5px; border-radius:4px; font-size:0.7rem;"><?= $d['sys'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:3rem;">Data tidak ditemukan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mobile-view">
    <?php if (count($results) > 0): ?>
        <?php foreach ($results as $raw): $d = formatRow($raw); ?>
        <div class="card" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 8px;">
            <div class="card-header" style="display:flex; justify-content: space-between;">
                <div>
                    <div style="font-size: 0.7rem; color: gray;"><?= htmlspecialchars($d['barcode']) ?></div>
                    <div style="font-weight: bold; line-height:1.2;"><?= htmlspecialchars($d['nama']) ?></div>
                </div>
                <?= $d['stok'] ?>
            </div>
            
            <?php if (!empty($d['ket'])): ?>
                <div style="font-size: 0.8rem; color: gray; margin-bottom: 8px;">
                    Ket: <?= htmlspecialchars($d['ket']) ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items:center;">
                <span style="font-size: 0.85rem; color:gray;">
                    <?= htmlspecialchars($d['jenis']) ?> &bull; <?= htmlspecialchars($d['merek']) ?>
                </span>
                <span style="font-weight: bold; color: var(--primary, blue);">
                    Rp <?= $d['harga'] ?> <small style="color:gray; font-weight:normal;">/ <?= htmlspecialchars($d['satuan']) ?></small>
                </span>
            </div>
            <div style="font-size: 0.8rem; border-top: 1px solid #eee; margin-top: 8px; padding-top: 8px;">
                System: <span style="background:#f1f5f9; padding:1px 5px; border-radius:3px;"><?= $d['sys'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="text-align:center; padding:2rem;">Data tidak ditemukan.</p>
    <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination" style="display: flex; gap: 5px; justify-content: center; margin: 20px 0;">
    <?php if ($page > 1): ?>
        <a href="<?= $base_pagination_url ?>1" style="padding: 5px 10px; border: 1px solid #ddd; text-decoration: none; border-radius:4px;">&laquo;</a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?= $base_pagination_url . $i ?>" style="padding: 5px 10px; border: 1px solid #ddd; text-decoration: none; border-radius:4px; <?= ($page == $i) ? 'background: #2563eb; color: white; border-color: #2563eb;' : 'color:#333;' ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?= $base_pagination_url . ($page + 1) ?>" style="padding: 5px 10px; border: 1px solid #ddd; text-decoration: none; border-radius:4px; color:#333;">Next</a>
        <a href="<?= $base_pagination_url . $total_pages ?>" style="padding: 5px 10px; border: 1px solid #ddd; text-decoration: none; border-radius:4px; color:#333;">&raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="text-align:center; font-size:0.75rem; color:gray; margin-bottom:40px;">
    Menampilkan <?= count($results) ?> dari <?= number_format($total_rows, 0, ',', '.') ?> total data
</div>

</body>
</html>
