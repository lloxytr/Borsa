<?php
// performance_updater.php - Sinyal performans takipçisi (WIN/LOSS/EXPIRED)
// PHP 8.1 uyumlu
declare(strict_types=1);

define('NO_SESSION', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api_live.php';

function plog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    file_put_contents(__DIR__ . '/performance_updater.log', "[{$ts}] {$msg}\n", FILE_APPEND);
}

/**
 * "2-3 gün" / "3-5 gün" / "7 gün" -> gün sayısı (max)
 */
function extractDaysFromTimeframe(?string $timeframe): int {
    $t = trim((string)$timeframe);
    if ($t === '') return 3;

    if (preg_match('/(\d+)\s*-\s*(\d+)/u', $t, $m)) {
        return max((int)$m[1], (int)$m[2]);
    }
    if (preg_match('/(\d+)/u', $t, $m)) {
        return max(1, (int)$m[1]);
    }
    return 3;
}

if (!isset($pdo)) {
    plog("HATA: PDO yok (config.php).");
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

plog("=== Performance Updater başladı ===");

$user_id = 1; // şimdilik sabit

// OPEN + aktif sinyaller
$stmt = $pdo->prepare("
    SELECT *
    FROM opportunities
    WHERE user_id = ?
      AND is_active = 1
      AND status = 'OPEN'
    ORDER BY created_at ASC
    LIMIT 200
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    plog("Takip edilecek OPEN sinyal yok.");
    exit;
}

$upd = $pdo->prepare("
    UPDATE opportunities
    SET
        status = ?,
        is_active = 0,
        closed_at = NOW(),
        exit_price = ?,
        exit_reason = ?,
        realized_profit_percent = ?
    WHERE id = ?
    LIMIT 1
");

$expiredUpd = $pdo->prepare("
    UPDATE opportunities
    SET
        status = 'EXPIRED',
        is_active = 0,
        closed_at = NOW(),
        exit_reason = 'EXPIRE',
        realized_profit_percent = 0
    WHERE id = ?
    LIMIT 1
");

$closedCount = 0;

foreach ($rows as $opp) {
    $id     = (int)$opp['id'];
    $symbol = (string)$opp['symbol'];

    $entry  = (float)$opp['entry_price'];
    $target = (float)$opp['target_price'];
    $stop   = (float)($opp['stop_loss'] ?? 0);

    $timeframe = (string)($opp['timeframe'] ?? '');
    $days = extractDaysFromTimeframe($timeframe);
    $deadline = strtotime((string)$opp['created_at'] . " +{$days} days");

    // Süresi doldu mu?
    if ($deadline > 0 && time() > $deadline) {
        $expiredUpd->execute([$id]);
        $closedCount++;
        plog("EXPIRED: {$symbol} (#{$id}) timeframe doldu");
        usleep(200000);
        continue;
    }

    // Fiyat çek
    $data = getStockData($symbol, true);
    if (!isset($data['success']) || !$data['success']) {
        $err = $data['error'] ?? 'API error';
        plog("API hata: {$symbol} (#{$id}) | {$err}");
        usleep(200000);
        continue;
    }

    $price = (float)($data['current_price'] ?? 0);
    if ($price <= 0) {
        plog("Geçersiz fiyat: {$symbol} (#{$id})");
        usleep(200000);
        continue;
    }

    // Hedef / Stop kontrol
    // (BUY sinyali varsayıyoruz. SELL de üretirsen ayrıca mantık ekleriz.)
    $hitTarget = ($price >= $target);
    $hitStop   = ($stop > 0 && $price <= $stop);

    if ($hitTarget) {
        $realized = $entry > 0 ? (($price - $entry) / $entry) * 100 : 0;
        $upd->execute(['WIN', $price, 'TARGET', round($realized, 2), $id]);
        $closedCount++;
        plog("WIN: {$symbol} (#{$id}) price={$price} target={$target} realized={$realized}%");
    } elseif ($hitStop) {
        $realized = $entry > 0 ? (($price - $entry) / $entry) * 100 : 0;
        $upd->execute(['LOSS', $price, 'STOP', round($realized, 2), $id]);
        $closedCount++;
        plog("LOSS: {$symbol} (#{$id}) price={$price} stop={$stop} realized={$realized}%");
    }

    usleep(250000);
}

plog("Kapatılan sinyal sayısı: {$closedCount}");
plog("=== Performance Updater bitti ===");
