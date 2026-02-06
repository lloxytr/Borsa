<?php
// telegram_bot.php - Production (PHP 8.1+)

declare(strict_types=1);

// Telegram Bot Ayarları
define('TELEGRAM_BOT_TOKEN', '8502935337:AAEtxDO0j_fMyYx2O56Hnwj4238BxWTsbkA');
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);

/**
 * Telegram'a mesaj gönder
 */
function sendTelegramMessage(string $chat_id, string $message, string $parse_mode = 'HTML'): array|false
{
    $url = TELEGRAM_API_URL . '/sendMessage';

    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Telegram CURL error: " . $curl_err);
        return false;
    }

    $json = json_decode($response, true);

    // Telegram çoğu zaman 200 döndürür; hata varsa ok:false gelir
    if ($http_code === 200 && is_array($json)) {
        return $json;
    }

    error_log("Telegram HTTP {$http_code}: " . $response);
    return is_array($json) ? $json : false;
}

/**
 * Bot bilgisini kontrol et
 */
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $url = TELEGRAM_API_URL . '/getMe';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo json_encode([
        'http' => $http_code,
        'response' => json_decode((string)$response, true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Manuel test: telegram_bot.php?test=1&chat_id=123456789
 */
if (isset($_GET['test'], $_GET['chat_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $chat_id = (string)$_GET['chat_id'];

    $msg = "🚀 <b>FezliTrade AI Bot Aktif!</b>\n\n";
    $msg .= "✅ Bot bağlantısı OK\n";
    $msg .= "⏰ " . date('d.m.Y H:i');

    $res = sendTelegramMessage($chat_id, $msg, 'HTML');
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
