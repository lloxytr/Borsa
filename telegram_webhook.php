<?php
// telegram_webhook.php - chat_id kaydetme + komutlar
define('NO_SESSION', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram_bot.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) { http_response_code(200); exit; }

$message = $update['message'] ?? null;
if (!$message) { http_response_code(200); exit; }

$chat_id = $message['chat']['id'] ?? null;
$text = trim($message['text'] ?? '');
if (!$chat_id) { http_response_code(200); exit; }

// Basit kullanıcı: user_id = 1 (ileride login yapınca bunu büyütürüz)
$user_id = 1;

// chat_id kaydet
try {
    $stmt = $pdo->prepare("UPDATE users SET telegram_id = ?, notifications_enabled = 1 WHERE id = ?");
    $stmt->execute([$chat_id, $user_id]);
} catch (Exception $e) {
    // sessiz geç
}

// Komutlar
if ($text === "/start") {
    $msg = "✅ *FezliTrade AI aktif!*\n\n"
         . "Bu hesap artık Telegram'a bağlandı.\n"
         . "Yeni fırsat olunca otomatik bildirim alacaksın.\n\n"
         . "Komutlar:\n"
         . "• /status -> bağlantı durumu\n"
         . "• /off -> bildirimleri kapat\n"
         . "• /on -> bildirimleri aç\n";
    sendTelegramMessage($chat_id, $msg);
}
elseif ($text === "/status") {
    $row = $pdo->query("SELECT telegram_id, notifications_enabled FROM users WHERE id = 1")->fetch();
    $enabled = (!empty($row['notifications_enabled'])) ? "AÇIK ✅" : "KAPALI ❌";
    $msg = "📌 *Durum*\n\nTelegram ID: `{$row['telegram_id']}`\nBildirim: *{$enabled}*";
    sendTelegramMessage($chat_id, $msg);
}
elseif ($text === "/off") {
    $pdo->query("UPDATE users SET notifications_enabled = 0 WHERE id = 1");
    sendTelegramMessage($chat_id, "🔕 Bildirimler kapatıldı.");
}
elseif ($text === "/on") {
    $pdo->query("UPDATE users SET notifications_enabled = 1 WHERE id = 1");
    sendTelegramMessage($chat_id, "🔔 Bildirimler açıldı.");
}

http_response_code(200);
exit;
