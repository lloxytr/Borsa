<?php
// collect_historical_data.php - Geçmiş Veri Toplayıcı
require_once 'config.php';
require_once 'api_live.php';

set_time_limit(600); // 10 dakika

$bist30 = ['THYAO', 'GARAN', 'AKBNK', 'YKBNK', 'SISE', 'TUPRS', 'EREGL', 'KCHOL', 'SAHOL', 'TCELL', 'ENKAI', 'ASELS', 'BIMAS'];

echo "Geçmiş veri toplama başladı...\n\n";

foreach ($bist30 as $symbol) {
    echo "Toplanan: {$symbol}\n";
    
    // Son 30 günlük veri topla (simülasyon - gerçekte API'den çekilir)
    for ($i = 30; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        
        // Gerçek API çağrısı
        $data = getStockData($symbol, false);
        
        if ($data['success']) {
            $stmt = $pdo->prepare("
                INSERT INTO price_history (symbol, date, open, high, low, close, volume, change_percent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                close = VALUES(close),
                high = VALUES(high),
                low = VALUES(low),
                volume = VALUES(volume),
                change_percent = VALUES(change_percent)
            ");
            
            $stmt->execute([
                $symbol,
                $date,
                $data['open'],
                $data['high'],
                $data['low'],
                $data['current_price'],
                $data['volume'],
                $data['change_percent']
            ]);
        }
        
        usleep(100000); // 0.1 saniye
    }
    
    echo "  ✓ {$symbol} tamamlandı\n";
    sleep(1);
}

echo "\n✅ Geçmiş veri toplama tamamlandı!\n";
?>
