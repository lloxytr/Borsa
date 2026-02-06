<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Test 1: PHP çalışıyor<br>";

try {
    require_once 'config.php';
    echo "Test 2: Config yüklendi<br>";
} catch (Exception $e) {
    die("Config hatası: " . $e->getMessage());
}

try {
    require_once 'api_live.php';
    echo "Test 3: API live yüklendi<br>";
} catch (Exception $e) {
    die("API hatası: " . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("SELECT * FROM portfolio WHERE user_id = ? LIMIT 1");
    $stmt->execute([1]);
    echo "Test 4: Portfolio tablosu sorgusu başarılı<br>";
    
    $count = $stmt->rowCount();
    echo "Test 5: Portfolio kayıt sayısı: {$count}<br>";
    
} catch (Exception $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

echo "<br>✅ Tüm testler başarılı! portfolio.php çalışmalı.";
?>
