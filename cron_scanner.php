<?php
// cron_scanner.php - Otomatik Tarama (Cron Job)
// Her 30 dakikada bir calisir

// Session'i devre disi birak
define('NO_SESSION', true);

// === GUVENLI HATA AYARI (Ekrana basma, log'a yaz) ===
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cron_errors.log');

// Zaman asimi onleme
set_time_limit(300); // 5 dakika

// === CRON LOCK (Ayni anda 2 kez calismasin) ===
$lockFile = __DIR__ . '/cron_scanner.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp) {
    // Lock acilamiyorsa bile calismayi durdurmak daha guvenli
    error_log("[" . date('Y-m-d H:i:s') . "] Lock dosyasi acilamadi: $lockFile");
    exit;
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Zaten calisiyor -> cik
    echo date('Y-m-d H:i:s') . " - Cron zaten calisiyor (lock aktif). Cikiliyor.\n";
    fclose($lockFp);
    exit;
}
// Lock icine bilgi yaz (opsiyonel)
ftruncate($lockFp, 0);
fwrite($lockFp, "PID=" . getmypid() . " | " . date('Y-m-d H:i:s') . "\n");

// Basla
echo date('Y-m-d H:i:s') . " - Cron tarama basladi\n";

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api_helper.php';
require_once __DIR__ . '/ai_engine.php';

try {
    // Eski firsatlari temizle
    echo "Eski firsatlar temizleniyor...\n";
    $cleaned = cleanOldOpportunities();
    echo "Temizlenen: " . $cleaned . " adet\n";

    // BIST30 taramasini yap
    echo "BIST30 taramasi baslatiliyor...\n";
    $found = scanBIST30ForOpportunities();
    echo "Bulunan firsat: " . $found . " adet\n";

    // === TELEGRAM BILDIRIMI GONDER ===
    if ($found > 0) {
        echo "Telegram bildirimleri gonderiliyor...\n";
        try {
            require_once __DIR__ . '/notification_sender.php';
            echo "Bildirimler basariyla gonderildi!\n";
        } catch (Exception $e) {
            echo "Bildirim hatasi: " . $e->getMessage() . "\n";
            logError('Telegram Error', $e->getMessage());
        }
    } else {
        echo "Yeni firsat yok, bildirim gonderilmedi.\n";
    }

    // Basarili log
    logSuccess('Cron tarama tamamlandi: ' . $found . ' firsat bulundu');
    echo date('Y-m-d H:i:s') . " - Cron tarama basariyla tamamlandi\n";

} catch (Exception $e) {
    echo "HATA: " . $e->getMessage() . "\n";
    logError('Cron Error', $e->getMessage());
} finally {
    // Lock'u serbest birak
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

echo "---\n";
?>
