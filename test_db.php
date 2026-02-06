<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "<h2>TEST: Veritabanı Son Güncelleme</h2>";

// Opportunities tablosundan son kayıtlar
try {
    $stmt = $pdo->query("SELECT symbol, confidence, created_at FROM opportunities ORDER BY created_at DESC LIMIT 5");
    $data = $stmt->fetchAll();
    
    echo "<h3>Son 5 Fırsat (Opportunities):</h3>";
    if(empty($data)) {
        echo "<b style='color:red;'>BOŞ! Hiç kayıt yok!</b><br>";
    } else {
        foreach($data as $row) {
            echo "- {$row['symbol']} (Güven: {$row['confidence']}%) - Tarih: {$row['created_at']}<br>";
        }
    }
} catch(Exception $e) {
    echo "<b style='color:red;'>HATA: " . $e->getMessage() . "</b><br>";
}

echo "<br>";

// Portfolio tablosundan güncel fiyatlar
try {
    echo "<h3>Portfolio Güncel Fiyatlar:</h3>";
    $stmt2 = $pdo->query("SELECT symbol, current_price, updated_at FROM portfolio ORDER BY updated_at DESC LIMIT 5");
    $portfolio = $stmt2->fetchAll();
    
    if(empty($portfolio)) {
        echo "<b style='color:red;'>BOŞ! Hiç portföy kaydı yok!</b><br>";
    } else {
        foreach($portfolio as $row) {
            echo "- {$row['symbol']}: ₺{$row['current_price']} - Güncelleme: {$row['updated_at']}<br>";
        }
    }
} catch(Exception $e) {
    echo "<b style='color:red;'>HATA: " . $e->getMessage() . "</b><br>";
}

echo "<br>";

// Stocks tablosunu kontrol et
try {
    echo "<h3>Stocks Tablosu (Tüm hisseler):</h3>";
    $stmt3 = $pdo->query("SELECT symbol, name, last_price, updated_at FROM stocks ORDER BY updated_at DESC LIMIT 10");
    $stocks = $stmt3->fetchAll();
    
    if(empty($stocks)) {
        echo "<b style='color:red;'>BOŞ! Hiç hisse kaydı yok!</b><br>";
    } else {
        foreach($stocks as $row) {
            echo "- {$row['symbol']} ({$row['name']}): ₺{$row['last_price']} - Güncelleme: {$row['updated_at']}<br>";
        }
    }
} catch(Exception $e) {
    echo "<b style='color:red;'>HATA: " . $e->getMessage() . "</b><br>";
}
?>
