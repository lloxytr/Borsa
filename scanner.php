<?php
// scanner.php - Otomatik Hisse Tarama Sistemi (Gerçek API)
// BIST200 tarar, AI analiz yapar, fırsat kaydeder
// PHP 8.1 uyumlu
declare(strict_types=1);

define('NO_SESSION', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_engine.php';
require_once __DIR__ . '/api_live.php';
require_once __DIR__ . '/bist_universe.php';

/**
 * Log
 */
function logMessage(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
    file_put_contents(__DIR__ . '/scanner.log', "[{$timestamp}] {$message}\n", FILE_APPEND);
}

/**
 * Risk level normalize (DB enum: low/medium/high)
 */
function normalizeRiskLevel(string $risk): string {
    $clean = trim($risk);
    $r = function_exists('mb_strtolower')
        ? mb_strtolower($clean)
        : strtolower($clean);

    if ($r === 'low' || $r === 'düşük' || $r === 'dusuk') return 'low';
    if ($r === 'high' || $r === 'yüksek' || $r === 'yuksek') return 'high';
    if ($r === 'medium' || $r === 'orta' || $r === 'normal') return 'medium';

    return 'medium';
}

/**
 * Sinyal hash (duplicate engeli) - günlük bucket
 * Aynı gün aynı sinyal tekrar yazılmasın diye
 */
function buildSignalHash(string $symbol, string $action, float $entry, float $target, float $stop, string $timeframe): string {
    $dayBucket = date('Y-m-d');

    $raw =
        strtoupper(trim($symbol)) . '|' .
        strtoupper(trim($action)) . '|' .
        number_format($entry, 2, '.', '') . '|' .
        number_format($target, 2, '.', '') . '|' .
        number_format($stop, 2, '.', '') . '|' .
        trim($timeframe) . '|' .
        $dayBucket;

    return hash('sha256', $raw);
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son X gün trend eğimi (yüzde değişim)
 * Veri yoksa null döner.
 */
function getTrendSlope(PDO $pdo, string $symbol, int $days = 7): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT close
            FROM price_history
            WHERE symbol = ?
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$symbol, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (!$rows || count($rows) < 2) {
        return null;
    }

    $latest = (float)$rows[0];
    $oldest = (float)$rows[count($rows) - 1];
    if ($oldest <= 0) {
        return null;
    }

    return (($latest - $oldest) / $oldest) * 100;
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

/**
 * Son performansa göre dinamik min confidence eşiği
 */
function getDynamicMinConfidence(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN realized_profit_percent > 0 THEN 1 ELSE 0 END) AS wins
            FROM trade_results
            WHERE user_id = ?
              AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return 50;
    }

    $total = (int)($row['total'] ?? 0);
    $wins = (int)($row['wins'] ?? 0);
    if ($total < 8) {
        return 50;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 60;
    if ($winRate < 55) return 55;
    if ($winRate < 65) return 50;
    return 45;
}

/**
 * Volatiliteye göre hedef/stop ayarla
 */
function adjustTargetsByVolatility(float $entry, float $target, float $stop, float $volatilityPercent): array {
    $volatilityPercent = max(0.5, min(12.0, $volatilityPercent));
    $expectedProfit = max(2.0, min(12.0, $volatilityPercent * 0.9));
    $stopPercent = max(1.2, min(6.0, $volatilityPercent * 0.6));

    $adjustedTarget = $entry * (1 + ($expectedProfit / 100));
    $adjustedStop = $entry * (1 - ($stopPercent / 100));

    if ($target > 0) {
        $adjustedTarget = min($target, $adjustedTarget);
    }
    if ($stop > 0) {
        $adjustedStop = max($stop, $adjustedStop);
    }

    return [
        'target' => round($adjustedTarget, 2),
        'stop' => round($adjustedStop, 2),
        'expected_profit_percent' => round((($adjustedTarget - $entry) / $entry) * 100, 2)
    ];
}

logMessage("=== Scanner başlatıldı ===");

$bistUniverse = getBistUniverse();

$opportunities_found = 0;
$user_id = 1;
$minConfidence = getDynamicMinConfidence($pdo, $user_id);
logMessage("Dinamik min güven eşiği: {$minConfidence}");

if (!isset($pdo)) {
    logMessage("HATA: PDO bağlantısı yok (config.php).");
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * opportunities tablosundaki kolonları al
 */
function getTableColumns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }
        return $columns;
    } catch (PDOException $e) {
        logMessage("HATA: Tablo kolonları alınamadı ({$table}) - " . $e->getMessage());
        return [];
    }
}

/**
 * Dinamik INSERT oluştur (mevcut kolonlara göre)
 */
function buildOpportunityInsert(PDO $pdo): array {
    $available = array_flip(getTableColumns($pdo, 'opportunities'));
    if (empty($available)) {
        throw new RuntimeException('opportunities tablo kolonları bulunamadı.');
    }

    $columnSqlMap = [
        'user_id' => ':user_id',
        'symbol' => ':symbol',
        'name' => ':name',
        'asset_type' => "'stock'",
        'action' => ':action',
        'entry_price' => ':entry_price',
        'target_price' => ':target_price',
        'potential_profit' => ':potential_profit',
        'ai_score' => ':ai_score',
        'stop_loss' => ':stop_loss',
        'expected_profit_percent' => ':expected_profit_percent',
        'confidence_score' => ':confidence_score',
        'risk_level' => ':risk_level',
        'timeframe' => ':timeframe',
        'analysis_reason' => ':analysis_reason',
        'is_active' => '1',
        'created_at' => 'NOW()',
        'notified' => '0',
        'confidence' => ':confidence',
        'prediction' => ':prediction',
        'signal_hash' => ':signal_hash'
    ];

    $columns = [];
    $values = [];
    foreach ($columnSqlMap as $column => $sqlValue) {
        if (isset($available[$column])) {
            $columns[] = $column;
            $values[] = $sqlValue;
        }
    }

    $sql = "INSERT IGNORE INTO opportunities (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
    return [$pdo->prepare($sql), $columns];
}

/**
 * opportunities tablo şemanla uyumlu INSERT
 * signal_hash UNIQUE olduğu için INSERT IGNORE kullanıyoruz:
 * - duplicate olursa hata fırlatmaz, rowCount=0 döner
 */
[$insertStmt, $insertColumns] = buildOpportunityInsert($pdo);

foreach ($bistUniverse as $symbol => $name) {
    logMessage("Taranıyor: {$symbol} ({$name})");

    try {
        // Gerçek API verisi çek (cache'li)
        $stock_data = getStockData($symbol, true);

        if (!isset($stock_data['success']) || !$stock_data['success']) {
            $err = (string)($stock_data['error'] ?? 'Bilinmeyen API hatası');
            logMessage("→ API hatası: {$symbol} | {$err}");
            usleep(400000);
            continue;
        }

        $current_price  = (float)($stock_data['current_price'] ?? 0.0);
        $open_price     = (float)($stock_data['open'] ?? $current_price);
        $high_price     = (float)($stock_data['high'] ?? $current_price);
        $low_price      = (float)($stock_data['low'] ?? $current_price);
        $volume         = (int)($stock_data['volume'] ?? 0);
        $change_percent = (float)($stock_data['change_percent'] ?? 0.0);

        $source = (string)($stock_data['source'] ?? 'Unknown');
        $from_cache = (!empty($stock_data['from_cache'])) ? ' (Cache)' : '';
        logMessage("  Fiyat: ₺{$current_price} | Değişim: %{$change_percent} | Kaynak: {$source}{$from_cache}");

        // AI analiz
        $analysis_data = [
            'symbol' => $symbol,
            'name' => $name,
            'current_price' => $current_price,
            'open' => $open_price,
            'high' => $high_price,
            'low' => $low_price,
            'volume' => $volume,
            'change_percent' => $change_percent
        ];

        $ai_result = analyzeStock($analysis_data);

        // DB’de confidence_score NOT NULL
        $confidence_score = (int)($ai_result['confidence_score'] ?? $ai_result['confidence'] ?? 0);

        // min filtre (dinamik)
        if ($confidence_score < $minConfidence) {
            logMessage("→ Düşük güven skoru: {$symbol} ({$confidence_score}%)");
            usleep(400000);
            continue;
        }

        // Kısa trend filtresi (düşüş trendinde zayıf sinyali ele)
        $trendSlope = getTrendSlope($pdo, $symbol, 7);
        if ($trendSlope !== null && $trendSlope < -2.5 && $confidence_score < 75) {
            logMessage("→ Negatif trend filtresi: {$symbol} (7g eğim {$trendSlope}%)");
            usleep(400000);
            continue;
        }

        // Trend state filtresi (bearish ise BUY engeli)
        $trendState = (string)($ai_result['trend_state'] ?? '');
        if ($trendState === 'bearish') {
            logMessage("→ Bearish trend_state filtresi: {$symbol}");
            usleep(400000);
            continue;
        }

        // RSI + MACD histogram filtresi
        $indicators = $ai_result['indicators'] ?? [];
        $rsi = isset($indicators['rsi']) ? (float)$indicators['rsi'] : null;
        $macdHist = isset($indicators['macd_histogram'])
            ? (float)$indicators['macd_histogram']
            : (isset($indicators['macd'], $indicators['macd_signal']) ? (float)$indicators['macd'] - (float)$indicators['macd_signal'] : null);

        if ($rsi !== null) {
            if ($rsi > 70) {
                logMessage("→ RSI aşırı alım filtresi: {$symbol} (RSI {$rsi})");
                usleep(400000);
                continue;
            }
            if ($rsi < 30 && $macdHist !== null && $macdHist <= 0) {
                logMessage("→ RSI/MACD filtresi: {$symbol} (RSI {$rsi}, hist {$macdHist})");
                usleep(400000);
                continue;
            }
        }

        // Momentum + hacim filtresi
        $momentumScore = ($change_percent * 0.7) + (($volume / 10000000) * 0.3);
        if ($momentumScore < 0.4 && $volume < 500000) {
            logMessage("→ Düşük momentum/hacim filtresi: {$symbol} (mom {$momentumScore})");
            usleep(400000);
            continue;
        }

        $action = strtoupper((string)($ai_result['action'] ?? 'BUY'));
        if (!in_array($action, ['BUY', 'SELL'], true)) $action = 'BUY';

        $entry_price  = (float)($ai_result['entry_price'] ?? $current_price);
        $target_price = (float)($ai_result['target_price'] ?? ($current_price * 1.02));
        $stop_loss    = (float)($ai_result['stop_loss'] ?? ($current_price * 0.98));
        $timeframe    = (string)($ai_result['timeframe'] ?? '2-3 gün');

        $expected_profit_percent = (float)($ai_result['expected_profit_percent'] ?? 0.0);

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // Risk/Ödül oranı filtresi
        $riskAmount = $entry_price - $stop_loss;
        $rewardAmount = $target_price - $entry_price;
        if ($riskAmount > 0 && ($rewardAmount / $riskAmount) < 1.4) {
            logMessage("→ Risk/Ödül filtresi: {$symbol} (R/R " . number_format($rewardAmount / $riskAmount, 2) . ")");
            usleep(400000);
            continue;
        }

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        if ($volatilityPercent > 9.5 && $confidence_score < 75) {
            logMessage("→ Aşırı volatilite filtresi: {$symbol} (vol={$volatilityPercent}%)");
            usleep(400000);
            continue;
        }
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        $volatilityPercent = $current_price > 0 ? (($high_price - $low_price) / $current_price) * 100 : 0.0;
        $adjusted = adjustTargetsByVolatility($entry_price, $target_price, $stop_loss, $volatilityPercent);
        $target_price = $adjusted['target'];
        $stop_loss = $adjusted['stop'];
        $expected_profit_percent = $adjusted['expected_profit_percent'];

        // tablo kolonları
        $risk_level = normalizeRiskLevel((string)($ai_result['risk_level'] ?? 'medium'));
        $analysis_reason = (string)($ai_result['reason'] ?? $ai_result['analysis_reason'] ?? 'Teknik göstergeler pozitif');

        // ai_score & potential_profit tabloda var
        $ai_score = (int)($ai_result['ai_score'] ?? $confidence_score);
        $potential_profit = (float)($ai_result['potential_profit'] ?? $expected_profit_percent);

        // Hash
        $signal_hash = buildSignalHash($symbol, $action, $entry_price, $target_price, $stop_loss, $timeframe);

        $paramPool = [
            ':user_id' => $user_id,
            ':symbol' => $symbol,
            ':name' => $name,
            ':action' => $action,
            ':entry_price' => $entry_price,
            ':target_price' => $target_price,
            ':potential_profit' => $potential_profit,
            ':ai_score' => $ai_score,
            ':stop_loss' => $stop_loss,
            ':expected_profit_percent' => $expected_profit_percent,
            ':confidence_score' => $confidence_score,
            ':risk_level' => $risk_level,
            ':timeframe' => $timeframe,
            ':analysis_reason' => $analysis_reason,
            ':confidence' => $confidence_score,
            ':prediction' => 'HOLD',
            ':signal_hash' => $signal_hash
        ];

        $params = [];
        foreach ($insertColumns as $column) {
            $key = ':' . $column;
            if (array_key_exists($key, $paramPool)) {
                $params[$key] = $paramPool[$key];
            }
        }

        // INSERT (duplicate hash olursa rowCount=0)
        $insertStmt->execute($params);

        if ($insertStmt->rowCount() === 1) {
            $opportunities_found++;
            logMessage("✓ YENİ FIRSAT: {$symbol} - Güven: {$confidence_score}/100 - Beklenen: +{$expected_profit_percent}%");
        } else {
            logMessage("→ Duplicate sinyal (UNIQUE hash): {$symbol}");
        }

        // Rate limit
        usleep(500000);

    } catch (Throwable $e) {
        logMessage("HATA: {$symbol} - " . $e->getMessage());
        usleep(400000);
    }
}

logMessage("=== Tarama tamamlandı ===");
logMessage("Toplam yeni fırsat: {$opportunities_found}");

// Eski fırsatları pasifleştir (24 saatten eski)
try {
    $updated = $pdo->query("
        UPDATE opportunities
        SET is_active = 0
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND is_active = 1
    ");
    logMessage("Eski fırsatlar pasifleştirildi: " . $updated->rowCount() . " adet");
} catch (Throwable $e) {
    logMessage("Pasifleştirme hatası: " . $e->getMessage());
}

logMessage("Scanner sonlandırıldı.");
