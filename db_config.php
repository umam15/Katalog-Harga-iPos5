<?php
// db_config.php

$host = "ipos5.domainku.com";       // atau alamat ip
$port = "5444";
$dbname = "i5_1Ramadhan2026";       // nama database diawali i5_
$user = "sysi5adm";
$password = "*****";

$conn_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password}";
$dbconn = pg_connect($conn_string);

if (!$dbconn) {
    die("Koneksi Error: " . pg_last_error());
}
?>