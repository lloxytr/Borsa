<?php
// scanner.php - Otomatik Hisse Tarama Sistemi (Gerçek API)
// BIST30 tarar, AI analiz yapar, fırsat kaydeder
// PHP 8.1 uyumlu
declare(strict_types=1);

define('NO_SESSION', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_engine.php';
require_once __DIR__ . '/api_live.php';

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
    $r = mb_strtolower(trim($risk));

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
        return 55;
    }

    $winRate = $total > 0 ? ($wins / $total) * 100 : 0;
    if ($winRate < 45) return 70;
    if ($winRate < 55) return 65;
    if ($winRate < 65) return 60;
    return 55;
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

// BIST30 Hisseleri
$bist30_stocks = [
    'THYAO' => 'Türk Hava Yolları',
    'GARAN' => 'Garanti BBVA',
    'AKBNK' => 'Akbank',
    'YKBNK' => 'Yapı Kredi Bankası',
    'SISE'  => 'Şişe Cam',
    'TUPRS' => 'Tüpraş',
    'EREGL' => 'Ereğli Demir Çelik',
    'KCHOL' => 'Koç Holding',
    'SAHOL' => 'Sabancı Holding',
    'TCELL' => 'Turkcell',
    'ENKAI' => 'Enka İnşaat',
    'ASELS' => 'Aselsan',
    'BIMAS' => 'BİM',
    'KOZAL' => 'Koza Altın',
    'PETKM' => 'Petkim',
    'TTKOM' => 'Türk Telekom',
    'TOASO' => 'Tofaş Oto',
    'ARCLK' => 'Arçelik',
    'FROTO' => 'Ford Otosan',
    'HALKB' => 'Halkbank',
    'ISCTR' => 'İş Bankası C',
    'VAKBN' => 'Vakıfbank',
    'TAVHL' => 'TAV Havalimanları',
    'SODA'  => 'Soda Sanayii',
    'EKGYO' => 'Emlak Konut GYO',
    'KOZAA' => 'Koza Madencilik',
    'PGSUS' => 'Pegasus',
    'DOHOL' => 'Doğan Holding',
    'MGROS' => 'Migros',
    'VESBE' => 'Vestel Beyaz Eşya'
];

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
 * opportunities tablo şemanla uyumlu INSERT
 * signal_hash UNIQUE olduğu için INSERT IGNORE kullanıyoruz:
 * - duplicate olursa hata fırlatmaz, rowCount=0 döner
 */
$insertSql = "
    INSERT IGNORE INTO opportunities (
        user_id, symbol, name, asset_type, action,
        entry_price, target_price, potential_profit, ai_score,
        stop_loss, expected_profit_percent, confidence_score,
        risk_level, timeframe, analysis_reason,
        is_active, created_at, notified,
        confidence, prediction, signal_hash
    ) VALUES (
        :user_id, :symbol, :name, 'stock', :action,
        :entry_price, :target_price, :potential_profit, :ai_score,
        :stop_loss, :expected_profit_percent, :confidence_score,
        :risk_level, :timeframe, :analysis_reason,
        1, NOW(), 0,
        :confidence, 'HOLD', :signal_hash
    )
";

$insertStmt = $pdo->prepare($insertSql);

foreach ($bist30_stocks as $symbol => $name) {
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

        $action = strtoupper((string)($ai_result['action'] ?? 'BUY'));
        if (!in_array($action, ['BUY', 'SELL'], true)) $action = 'BUY';

        $entry_price  = (float)($ai_result['entry_price'] ?? $current_price);
        $target_price = (float)($ai_result['target_price'] ?? ($current_price * 1.02));
        $stop_loss    = (float)($ai_result['stop_loss'] ?? ($current_price * 0.98));
        $timeframe    = (string)($ai_result['timeframe'] ?? '2-3 gün');

        $expected_profit_percent = (float)($ai_result['expected_profit_percent'] ?? 0.0);

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

        // INSERT (duplicate hash olursa rowCount=0)
        $insertStmt->execute([
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
            ':confidence' => $confidence_score, // eski kolon, dashboard bazı yerlerde kullanıyor
            ':signal_hash' => $signal_hash
        ]);

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
