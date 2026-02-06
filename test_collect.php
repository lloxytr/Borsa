<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>TEST: Geçmiş Veri Toplama</h2>";

try {
    require_once 'config.php';
    echo "✓ Config yüklendi<br>";
    
    require_once 'api_live.php';
    echo "✓ API live yüklendi<br>";
    
    // Test: Tek hisse için veri çek
    $symbol = 'THYAO';
    echo "<br><h3>Test: {$symbol}</h3>";
    
    $data = getStockData($symbol, false);
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    
    if ($data['success']) {
        // Veritabanına kaydet
        $date = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            INSERT INTO price_history (symbol, date, open, high, low, close, volume, change_percent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            close = VALUES(close)
        ");
        
        $result = $stmt->execute([
            $symbol,
            $date,
            $data['open'],
            $data['high'],
            $data['low'],
            $data['current_price'],
            $data['volume'],
            $data['change_percent']
        ]);
        
        if ($result) {
            echo "<br>✅ Veritabanına kaydedildi!<br>";
        } else {
            echo "<br>❌ Kayıt hatası!<br>";
        }
        
        // Kontrol et
        $check = $pdo->query("SELECT * FROM price_history WHERE symbol = '{$symbol}' ORDER BY date DESC LIMIT 1")->fetch();
        echo "<br><b>Veritabanındaki kayıt:</b><br>";
        echo "<pre>";
        print_r($check);
        echo "</pre>";
    } else {
        echo "<br>❌ API hatası: " . $data['error'];
    }
    
} catch (Exception $e) {
    echo "<br><b style='color:red;'>HATA:</b> " . $e->getMessage();
    echo "<br><b>Dosya:</b> " . $e->getFile();
    echo "<br><b>Satır:</b> " . $e->getLine();
}
?>
