<?php
// notification_sender.php - CRON + WEB ADMIN PANEL (MANUEL GÖNDER + ID YÖNET)
// PHP 8.1 uyumlu
declare(strict_types=1);

define('NO_SESSION', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram_bot.php';

/* ================== AYARLAR ================== */
$SPAM_SECONDS   = 300; // 5 dk
$LOOKBACK_HOURS = 24;
$LIMIT_OPPS     = 3;

// Secret tek yerden (config.php)
$ADMIN_SECRET = defined('NOTIFY_ADMIN_SECRET') ? (string)NOTIFY_ADMIN_SECRET : 'CHANGE_ME';

// Dashboard URL tek yerden (config.php)
$DASHBOARD_URL = defined('DASHBOARD_URL') ? (string)DASHBOARD_URL : 'https://baralmotor.online/dashboard.php';
/* ============================================= */

// Log
function nlog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/notification_sender.log', "[{$ts}] {$msg}\n", FILE_APPEND);
}

// Telegram Markdown escape (klasik Markdown)
function tg(string $t): string {
    return str_replace(
        ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
        ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'],
        $t
    );
}

function isCli(): bool {
    return PHP_SAPI === 'cli';
}

function safe(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Bazı sunucularda "secret" paramı WAF tarafından drop edilebiliyor.
 * O yüzden birden fazla isim kabul ediyoruz + QUERY_STRING manuel parse.
 */
function getAdminKey(): string {
    $keys = ['k','token','key','secret','admin','auth'];

    foreach ($keys as $k) {
        if (isset($_GET[$k])) {
            $v = trim((string)$_GET[$k]);
            if ($v !== '') return $v;
        }
    }

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $v = trim((string)$_POST[$k]);
            if ($v !== '') return $v;
        }
    }

    // Son çare: QUERY_STRING manuel parse
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') {
        $arr = [];
        parse_str($qs, $arr);
        foreach ($keys as $k) {
            if (isset($arr[$k])) {
                $v = trim((string)$arr[$k]);
                if ($v !== '') return $v;
            }
        }
    }

    return '';
}

/**
 * Asıl gönderim
 * @param bool $forceSpamBypass  true ise spam kilidini aş
 * @param bool $includeNotified  true ise notified=1 olanları da gönder (manuel test için)
 * @return array{sent:int, users:int, opps:int, marked:int, reason:string, detail:string}
 */
function runSend(PDO $pdo, int $LOOKBACK_HOURS, int $LIMIT_OPPS, int $SPAM_SECONDS, string $DASHBOARD_URL, bool $forceSpamBypass = false, bool $includeNotified = false): array {
    $lockFile = __DIR__ . '/last_notification.txt';
    $lastTime = file_exists($lockFile) ? (int)trim((string)file_get_contents($lockFile)) : 0;

    if (!$forceSpamBypass && (time() - $lastTime < $SPAM_SECONDS)) {
        return ['sent'=>0,'users'=>0,'opps'=>0,'marked'=>0,'reason'=>'SPAM_LOCK','detail'=>'5 dk dolmadı'];
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Kullanıcılar
    $users = $pdo->query("
        SELECT id, telegram_id
        FROM users
        WHERE telegram_id IS NOT NULL
          AND telegram_id != ''
          AND notifications_enabled = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$users) {
        return ['sent'=>0,'users'=>0,'opps'=>0,'marked'=>0,'reason'=>'NO_USERS','detail'=>'notifications_enabled=1 ve telegram_id dolu user yok'];
    }

    // Fırsatlar (notified filtre opsiyonlu)
    $whereNotified = $includeNotified ? "" : "AND notified = 0";

    $sql = "
        SELECT *
        FROM opportunities
        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
          {$whereNotified}
          AND is_active = 1
        ORDER BY confidence_score DESC
        LIMIT {$LIMIT_OPPS}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$LOOKBACK_HOURS]);
    $opps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$opps) {
        $d = $includeNotified
            ? 'is_active=1 fırsat yok (notified filtresi kapalı)'
            : 'notified=0 & is_active=1 fırsat yok';
        return ['sent'=>0,'users'=>count($users),'opps'=>0,'marked'=>0,'reason'=>'NO_OPPS','detail'=>$d];
    }

    $sentCount = 0;
    $tgErrors = 0;

    foreach ($users as $user) {
        $chatId = (string)($user['telegram_id'] ?? '');
        if ($chatId === '') continue;

        foreach ($opps as $opp) {
            $symbol = tg((string)($opp['symbol'] ?? '---'));
            $name   = tg((string)($opp['name'] ?? ''));

            $entry  = number_format((float)($opp['entry_price'] ?? 0), 2, '.', '');
            $target = number_format((float)($opp['target_price'] ?? 0), 2, '.', '');
            $stop   = number_format((float)($opp['stop_loss'] ?? 0), 2, '.', '');

            $profit = number_format((float)($opp['expected_profit_percent'] ?? 0), 1, '.', '');
            $conf   = (int)($opp['confidence_score'] ?? 0);

            $riskRaw = (string)($opp['risk_level'] ?? 'medium');
            $risk = tg(strtoupper($riskRaw));

            $time   = tg((string)($opp['timeframe'] ?? '2-3 gün'));
            $reason = tg((string)($opp['analysis_reason'] ?? 'Teknik sinyal'));

            $emoji = $conf >= 80 ? "🔥" : ($conf >= 70 ? "🟢" : "⚡");

            $message =
                "{$emoji} *ALIM ZAMANI – {$symbol}*\n\n" .
                "🏷️ *{$name}*\n\n" .
                "⏰ *Şimdi / Bugün*\n" .
                "💵 *Alış:* {$entry} ₺\n" .
                "🎯 *Hedef:* {$target} ₺\n" .
                "🛑 *Stop:* {$stop} ₺\n\n" .
                "📈 Beklenen Getiri: *+{$profit}%*\n" .
                "⏳ *Süre:* {$time}\n" .
                "🔒 *Güven:* {$conf} / 100\n" .
                "⚠️ Risk: *{$risk}*\n\n" .
                "📊 *Sebep:*\n{$reason}\n\n" .
                "👉 *Plan:* Al → Bekle → Hedefte Sat\n\n" .
                "📊 Dashboard:\n{$DASHBOARD_URL}";

            $res = sendTelegramMessage($chatId, $message);

            if (!$res || !isset($res['ok']) || $res['ok'] !== true) {
                $tgErrors++;
                $desc = is_array($res) && isset($res['description']) ? (string)$res['description'] : 'Bilinmeyen hata';
                nlog("Telegram gönderim hatası user={$user['id']} chat={$chatId} => {$desc}");
            } else {
                $sentCount++;
            }

            sleep(1);
        }
    }

    // Notified işaretle (sadece notified=0 olanları)
    $upd = $pdo->prepare("
        UPDATE opportunities
        SET notified = 1, notified_at = NOW()
        WHERE notified = 0
          AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $upd->execute([$LOOKBACK_HOURS]);
    $marked = $upd->rowCount();

    file_put_contents(__DIR__ . '/last_notification.txt', (string)time());

    if ($sentCount === 0 && $tgErrors > 0) {
        return ['sent'=>0,'users'=>count($users),'opps'=>count($opps),'marked'=>$marked,'reason'=>'TG_ERROR','detail'=>'Telegram API hatası var (logu kontrol et)'];
    }

    return [
        'sent'   => $sentCount,
        'users'  => count($users),
        'opps'   => count($opps),
        'marked' => $marked,
        'reason' => 'OK',
        'detail' => 'Gönderim tamam'
    ];
}

/* ===================== ÇALIŞTIRMA ===================== */

try {
    if (!isset($pdo)) {
        nlog("HATA: PDO yok (config.php).");
        throw new RuntimeException("PDO yok");
    }

    // CRON / CLI ise direkt çalıştır
    if (isCli()) {
        nlog("CLI çalıştı. Bildirim gönderimi başlıyor...");
        $r = runSend($pdo, $LOOKBACK_HOURS, $LIMIT_OPPS, $SPAM_SECONDS, $DASHBOARD_URL, false, false);
        nlog("Bitti. reason={$r['reason']} users={$r['users']} opps={$r['opps']} sent={$r['sent']} marked={$r['marked']} detail={$r['detail']}");
        exit;
    }

    // WEB panel: admin key kontrolü
    $key = getAdminKey();
    if (!hash_equals($ADMIN_SECRET, $key)) {
        http_response_code(403);

        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        $got = $key !== '' ? 'EVET' : 'HAYIR';
        $len = strlen($key);

        echo "403 Forbidden - admin key gerekli.\n";
        echo "Örnek: ?k=YOUR_SECRET (veya ?token=YOUR_SECRET)\n";
        echo "DEBUG: key_geldi_mi={$got} | key_uzunluk={$len}\n";
        echo "DEBUG: QUERY_STRING=" . $qs . "\n";
        exit;
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $infoMsg = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_telegram') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $tgid = trim((string)($_POST['telegram_id'] ?? ''));

            if ($uid > 0 && $tgid !== '') {
                $st = $pdo->prepare("UPDATE users SET telegram_id = ?, notifications_enabled = 1 WHERE id = ? LIMIT 1");
                $st->execute([$tgid, $uid]);
                $infoMsg = "✅ User #{$uid} telegram_id güncellendi.";
            } else {
                $infoMsg = "❌ user_id ve telegram_id gerekli.";
            }
        }

        if ($action === 'clear_telegram') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid > 0) {
                $st = $pdo->prepare("UPDATE users SET telegram_id = NULL WHERE id = ? LIMIT 1");
                $st->execute([$uid]);
                $infoMsg = "✅ User #{$uid} telegram_id silindi.";
            } else {
                $infoMsg = "❌ user_id gerekli.";
            }
        }

        if ($action === 'send_manual') {
            $force = ((string)($_POST['force'] ?? '') === '1');
            $includeNotified = ((string)($_POST['include_notified'] ?? '') === '1');

            $r = runSend($pdo, $LOOKBACK_HOURS, $LIMIT_OPPS, $SPAM_SECONDS, $DASHBOARD_URL, $force, $includeNotified);
            $infoMsg = "✅ Manuel gönderim: reason={$r['reason']} ({$r['detail']}) | users={$r['users']} opps={$r['opps']} sent={$r['sent']} marked={$r['marked']}";
        }
    }

    // Kullanıcı listesi
    $users = $pdo->query("SELECT id, telegram_id, notifications_enabled FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Son fırsatlar
    $stmt = $pdo->prepare("
        SELECT id, symbol, expected_profit_percent, confidence_score, timeframe, notified, created_at
        FROM opportunities
        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$LOOKBACK_HOURS]);
    $lastOpps = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    nlog("HATA: " . $e->getMessage());
    http_response_code(500);
    echo "Hata: " . $e->getMessage();
    exit;
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notification Admin</title>
  <style>
    body{font-family:Arial,sans-serif;background:#0b1220;color:#e5e7eb;margin:0;padding:24px}
    .box{background:#111a2c;border:1px solid rgba(99,102,241,.25);border-radius:14px;padding:18px;margin-bottom:16px}
    h1{margin:0 0 10px 0;font-size:18px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid rgba(148,163,184,.15);font-size:14px}
    input,button{padding:10px;border-radius:10px;border:1px solid rgba(148,163,184,.25);background:#0b1220;color:#e5e7eb}
    button{cursor:pointer;border-color:rgba(16,185,129,.35)}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .muted{color:#94a3b8}
    .ok{color:#10b981;font-weight:700}
  </style>
</head>
<body>

<div class="box">
  <h1>🔔 Notification Sender Admin Panel</h1>
  <div class="muted">
    Panel URL örnek: <b>?k=YOUR_SECRET</b> veya <b>?token=YOUR_SECRET</b>
  </div>
  <?php if (!empty($infoMsg)): ?>
    <div style="margin-top:12px" class="ok"><?php echo safe($infoMsg); ?></div>
  <?php endif; ?>
</div>

<div class="box">
  <h1>🚀 Manuel Bildirim Gönder</h1>
  <form method="post" class="row">
    <input type="hidden" name="k" value="<?php echo safe(getAdminKey()); ?>">
    <input type="hidden" name="action" value="send_manual">
    <button type="submit">Şimdi Gönder</button>

    <label class="muted">
      <input type="checkbox" name="force" value="1">
      Spam kilidini aş (5 dk bekleme)
    </label>

    <label class="muted">
      <input type="checkbox" name="include_notified" value="1">
      Notified=1 olsa bile gönder (test)
    </label>
  </form>

  <div class="muted" style="margin-top:10px">
    Gönderim olmuyorsa sebep olarak: <b>NO_USERS</b>, <b>NO_OPPS</b>, <b>SPAM_LOCK</b>, <b>TG_ERROR</b göreceksin.
  </div>
</div>

<div class="box">
  <h1>👤 Telegram ID Yönetimi</h1>

  <form method="post" class="row" style="margin-bottom:12px">
    <input type="hidden" name="k" value="<?php echo safe(getAdminKey()); ?>">
    <input type="hidden" name="action" value="add_telegram">
    <input name="user_id" placeholder="User ID" style="width:110px">
    <input name="telegram_id" placeholder="Telegram Chat ID (örn: 123456789)" style="width:280px">
    <button type="submit">Kaydet / Güncelle</button>
  </form>

  <form method="post" class="row" style="margin-bottom:16px">
    <input type="hidden" name="k" value="<?php echo safe(getAdminKey()); ?>">
    <input type="hidden" name="action" value="clear_telegram">
    <input name="user_id" placeholder="User ID" style="width:110px">
    <button type="submit" style="border-color:rgba(239,68,68,.45)">Telegram ID Sil</button>
  </form>

  <table>
    <thead>
      <tr>
        <th>User ID</th>
        <th>telegram_id</th>
        <th>notifications_enabled</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?php echo (int)$u['id']; ?></td>
        <td><?php echo safe((string)($u['telegram_id'] ?? '')); ?></td>
        <td><?php echo (int)($u['notifications_enabled'] ?? 0); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="box">
  <h1>📌 Son 10 Fırsat (<?php echo (int)$LOOKBACK_HOURS; ?> saat)</h1>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Symbol</th>
        <th>Getiri%</th>
        <th>Güven</th>
        <th>Timeframe</th>
        <th>Notified</th>
        <th>Tarih</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lastOpps as $o): ?>
      <tr>
        <td><?php echo (int)$o['id']; ?></td>
        <td><?php echo safe((string)$o['symbol']); ?></td>
        <td><?php echo number_format((float)$o['expected_profit_percent'], 2); ?></td>
        <td><?php echo (int)$o['confidence_score']; ?></td>
        <td><?php echo safe((string)$o['timeframe']); ?></td>
        <td><?php echo (int)$o['notified']; ?></td>
        <td><?php echo safe((string)$o['created_at']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</body>
</html>
