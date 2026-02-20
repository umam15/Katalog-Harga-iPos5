<?php
// db_config.php
$host = 'ipos5.domainku.com';
$port = '5444';
$db   = 'i5_1Ramadhan2026';
$user = 'sysi5adm';
$pass = '*****';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
