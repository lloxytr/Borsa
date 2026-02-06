<?php
// close_positions.php - Performans kapanış/başarı oranı motoru
declare(strict_types=1);

define('NO_SESSION', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api_live.php';

function clog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    file_put_contents(__DIR__ . '/close_positions.log', "[{$ts}] {$msg}\n", FILE_APPEND);
}

if (!isset($pdo)) {
    clog("HATA: PDO yok (config.php).");
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

clog("=== Close Positions başlatıldı ===");

// Aktif fırsatlar (kapanmamış)
$stmt = $pdo->query("
    SELECT *
    FROM opportunities
    WHERE is_active = 1
    ORDER BY created_at DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    clog("Aktif fırsat yok.");
    exit;
}

$insertResult = $pdo->prepare("
    INSERT INTO trade_results (
        opportunity_id, user_id, symbol, action,
        entry_price, exit_price, exit_reason,
        expected_profit_percent, realized_profit_percent,
        confidence_score, risk_level,
        opened_at, closed_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, ?,
        ?, ?
    )
");

$updateOpp = $pdo->prepare("
    UPDATE opportunities
    SET is_active = 0, notified_at = NOW()
    WHERE id = ?
");

$closedCount = 0;

foreach ($rows as $opp) {
    $oppId = (int)$opp['id'];
    $userId = (int)$opp['user_id'];
    $symbol = (string)$opp['symbol'];

    $entry = (float)$opp['entry_price'];
    $target = (float)$opp['target_price'];
    $stop = isset($opp['stop_loss']) ? (float)$opp['stop_loss'] : 0.0;

    $expected = (float)$opp['expected_profit_percent'];
    $confidenceScore = (int)$opp['confidence_score'];
    $risk = (string)($opp['risk_level'] ?? 'medium');
    $action = (string)($opp['action'] ?? 'BUY');

    $openedAt = (string)$opp['created_at'];
    $expiresAt = $opp['expires_at'] ?? null;

    // expires kontrolü
    if ($expiresAt !== null && strtotime((string)$expiresAt) <= time()) {
        // expire olmuş, exit fiyatı: current price çekelim (yoksa entry)
        $stock_data = getStockData($symbol, true);
        $current = (float)($stock_data['current_price'] ?? $entry);

        $realized = ($entry > 0) ? (($current - $entry) / $entry) * 100.0 : 0.0;

        try {
            $insertResult->execute([
                $oppId, $userId, $symbol, $action,
                $entry, $current, 'EXPIRE',
                $expected, $realized,
                $confidenceScore, $risk,
                $openedAt, date('Y-m-d H:i:s')
            ]);
            $updateOpp->execute([$oppId]);
            $closedCount++;
            clog("EXPIRE kapandı: {$symbol} | entry={$entry} exit={$current} pnl=" . number_format($realized, 2) . "%");
        } catch (PDOException $e) {
            // uniq_opp çakışırsa geç
            if ($e->getCode() !== '23000') {
                clog("DB HATA (EXPIRE) {$symbol}: " . $e->getMessage());
            }
        }

        usleep(200000);
        continue;
    }

    // fiyat çek
    $stock_data = getStockData($symbol, true);
    if (!isset($stock_data['success']) || !$stock_data['success']) {
        clog("API hatası (skip): {$symbol}");
        usleep(200000);
        continue;
    }

    $current = (float)($stock_data['current_price'] ?? 0);
    if ($current <= 0) {
        clog("Geçersiz fiyat (skip): {$symbol}");
        usleep(200000);
        continue;
    }

    $exitReason = null;

    if ($current >= $target) {
        $exitReason = 'TARGET';
    } elseif ($stop > 0 && $current <= $stop) {
        $exitReason = 'STOP';
    } else {
        // açık kalmaya devam
        usleep(200000);
        continue;
    }

    $realized = ($entry > 0) ? (($current - $entry) / $entry) * 100.0 : 0.0;

    try {
        $insertResult->execute([
            $oppId, $userId, $symbol, $action,
            $entry, $current, $exitReason,
            $expected, $realized,
            $confidenceScore, $risk,
            $openedAt, date('Y-m-d H:i:s')
        ]);
        $updateOpp->execute([$oppId]);
        $closedCount++;
        clog("{$exitReason} kapandı: {$symbol} | entry={$entry} exit={$current} pnl=" . number_format($realized, 2) . "%");
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            clog("DB HATA ({$exitReason}) {$symbol}: " . $e->getMessage());
        }
    }

    usleep(250000);
}

clog("=== Close Positions bitti | Kapanan: {$closedCount} ===");
