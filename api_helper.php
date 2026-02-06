<?php
// api_helper.php - BIST API (Yahoo Finance)

/**
 * BIST30 Hisse Listesi (Yahoo format: SYMBOL.IS)
 */
function getBIST30Stocks() {
    return array(
        'THYAO.IS' => 'Turk Hava Yollari',
        'GARAN.IS' => 'Garanti Bankasi',
        'AKBNK.IS' => 'Akbank',
        'EREGL.IS' => 'Eregli Demir Celik',
        'TUPRS.IS' => 'Tupras',
        'SASA.IS' => 'Sasa Polyester',
        'KCHOL.IS' => 'Koc Holding',
        'SAHOL.IS' => 'Sabanci Holding',
        'PETKM.IS' => 'Petkim',
        'ASELS.IS' => 'Aselsan',
        'TTKOM.IS' => 'Turk Telekom',
        'BIMAS.IS' => 'BIM',
        'MGROS.IS' => 'Migros',
        'SISE.IS' => 'Sise Cam',
        'TOASO.IS' => 'Tofas',
        'TAVHL.IS' => 'TAV Havalimanlari',
        'TCELL.IS' => 'Turkcell',
        'ISCTR.IS' => 'Is Bankasi',
        'KOZAL.IS' => 'Koza Altin',
        'ENKAI.IS' => 'Enka Insaat',
        'FROTO.IS' => 'Ford Otosan',
        'ARCLK.IS' => 'Arcelik',
        'PGSUS.IS' => 'Pegasus',
        'HALKB.IS' => 'Halkbank',
        'YKBNK.IS' => 'Yapi Kredi',
        'DOHOL.IS' => 'Dogan Holding',
        'VESTL.IS' => 'Vestel',
        'AEFES.IS' => 'Anadolu Efes',
        'TTRAK.IS' => 'Turk Traktor',
        'CIMSA.IS' => 'Cimsa'
    );
}

/**
 * Cache'ten veri al veya yeni cek
 */
function getCachedData($symbol, $data_type, $api_function, $cache_minutes = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT data_json, expires_at 
            FROM market_data_cache 
            WHERE symbol = ? AND data_type = ? AND expires_at > NOW()
            ORDER BY cached_at DESC 
            LIMIT 1
        ");
        $stmt->execute(array($symbol, $data_type));
        $cached = $stmt->fetch();
        
        if ($cached) {
            return json_decode($cached['data_json'], true);
        }
        
        $data = $api_function($symbol);
        
        if ($data) {
            $stmt = $pdo->prepare("
                INSERT INTO market_data_cache (symbol, data_type, data_json, cached_at, expires_at) 
                VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))
            ");
            $stmt->execute(array(
                $symbol, 
                $data_type, 
                json_encode($data), 
                $cache_minutes
            ));
        }
        
        return $data;
        
    } catch (Exception $e) {
        logError('Cache Error', $e->getMessage());
        return null;
    }
}

/**
 * Yahoo Finance - Hisse Fiyati (YH Finance API v8)
 */
function getStockQuote($symbol) {
    // Yahoo Finance query1 API (Herkese acik)
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($symbol);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        
        if (isset($data['chart']['result'][0]['meta'])) {
            $meta = $data['chart']['result'][0]['meta'];
            
            $current = isset($meta['regularMarketPrice']) ? floatval($meta['regularMarketPrice']) : 0;
            $prev_close = isset($meta['chartPreviousClose']) ? floatval($meta['chartPreviousClose']) : 0;
            
            if ($current > 0) {
                $change = $current - $prev_close;
                $change_percent = ($prev_close > 0) ? (($change / $prev_close) * 100) : 0;
                
                return array(
                    'symbol' => $symbol,
                    'current' => $current,
                    'high' => isset($meta['regularMarketDayHigh']) ? floatval($meta['regularMarketDayHigh']) : $current,
                    'low' => isset($meta['regularMarketDayLow']) ? floatval($meta['regularMarketDayLow']) : $current,
                    'open' => isset($meta['regularMarketOpen']) ? floatval($meta['regularMarketOpen']) : $current,
                    'prev_close' => $prev_close,
                    'change' => $change,
                    'change_percent' => $change_percent,
                    'volume' => isset($meta['regularMarketVolume']) ? intval($meta['regularMarketVolume']) : 0,
                    'currency' => isset($meta['currency']) ? $meta['currency'] : 'TRY'
                );
            }
        }
    }
    
    return null;
}

/**
 * Hisse Senedi Fiyati (Cache ile)
 */
function getStockQuoteCached($symbol, $cache_minutes = 2) {
    return getCachedData($symbol, 'quote', function($sym) {
        return getStockQuote($sym);
    }, $cache_minutes);
}

/**
 * Toplu hisse fiyatlarini cek (BIST30)
 */
function getAllBISTQuotes($limit = 30) {
    $stocks = getBIST30Stocks();
    $results = array();
    $count = 0;
    
    foreach ($stocks as $symbol => $name) {
        if ($count >= $limit) break;
        
        $quote = getStockQuoteCached($symbol, 2);
        
        if ($quote && $quote['current'] > 0) {
            $quote['name'] = $name;
            $results[] = $quote;
            $count++;
        }
        
        usleep(300000); // 0.3 saniye bekle (rate limit icin)
    }
    
    return $results;
}

/**
 * Teknik analiz - RSI hesapla
 */
function calculateRSI($prices, $period = 14) {
    if (count($prices) < $period + 1) {
        return 50;
    }
    
    $gains = array();
    $losses = array();
    
    for ($i = 1; $i < count($prices); $i++) {
        $change = $prices[$i] - $prices[$i - 1];
        $gains[] = $change > 0 ? $change : 0;
        $losses[] = $change < 0 ? abs($change) : 0;
    }
    
    $avg_gain = array_sum(array_slice($gains, -$period)) / $period;
    $avg_loss = array_sum(array_slice($losses, -$period)) / $period;
    
    if ($avg_loss == 0) return 100;
    
    $rs = $avg_gain / $avg_loss;
    $rsi = 100 - (100 / (1 + $rs));
    
    return round($rsi, 2);
}

/**
 * Guven skoru hesapla (0-100)
 */
function calculateConfidence($data) {
    $score = 50;
    
    if (isset($data['change_percent'])) {
        if ($data['change_percent'] > 3) $score += 20;
        elseif ($data['change_percent'] > 1.5) $score += 15;
        elseif ($data['change_percent'] > 0.5) $score += 10;
        elseif ($data['change_percent'] < -3) $score -= 20;
    }
    
    if (isset($data['volume']) && $data['volume'] > 1000000) {
        $score += 10;
    }
    
    if (isset($data['current'], $data['low'], $data['high'])) {
        $range = $data['high'] - $data['low'];
        if ($range > 0) {
            $position = ($data['current'] - $data['low']) / $range;
            if ($position > 0.8) $score += 15;
            elseif ($position < 0.2) $score += 10;
        }
    }
    
    return max(0, min(100, $score));
}

/**
 * Sistem logu
 */
function logError($type, $message, $data = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (log_type, message, data_json, created_at) VALUES ('error', ?, ?, NOW())");
        $stmt->execute(array($type . ': ' . $message, $data ? json_encode($data) : null));
    } catch (Exception $e) {}
}

function logSuccess($message, $data = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (log_type, message, data_json, created_at) VALUES ('success', ?, ?, NOW())");
        $stmt->execute(array($message, $data ? json_encode($data) : null));
    } catch (Exception $e) {}
}
?>
