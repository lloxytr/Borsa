<?php
// api_live.php - Gerçek Zamanlı API Veri Çekici (BIST)

// ========== 1) Yahoo Finance (Unofficial) ==========
function getYahooFinanceData(string $symbol): array {
    $yahoo_symbol = $symbol . '.IS';

    // Intraday: 5m / 1d
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$yahoo_symbol}?interval=5m&range=1d";

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'Sunucuda cURL aktif değil'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return ['success' => false, 'error' => 'Yahoo Finance API erişim hatası'];
    }

    $data = json_decode($response, true);
    if (!isset($data['chart']['result'][0])) {
        return ['success' => false, 'error' => 'Yahoo Finance veri formatı hatası'];
    }

    $result = $data['chart']['result'][0];
    $meta   = $result['meta'] ?? [];
    $quote  = $result['indicators']['quote'][0] ?? [];

    $closes = $quote['close'] ?? [];
    $opens  = $quote['open'] ?? [];
    $highs  = $quote['high'] ?? [];
    $lows   = $quote['low'] ?? [];
    $vols   = $quote['volume'] ?? [];
    $tss    = $result['timestamp'] ?? [];

    // Son dolu close bul (null olabiliyor)
    $lastClose = null; $lastOpen = null; $lastHigh = null; $lastLow = null; $lastVol = 0; $lastTs = time();
    for ($i = count($closes) - 1; $i >= 0; $i--) {
        if (isset($closes[$i]) && $closes[$i] !== null) {
            $lastClose = (float)$closes[$i];
            $lastOpen  = (isset($opens[$i]) && $opens[$i] !== null) ? (float)$opens[$i] : $lastClose;
            $lastHigh  = (isset($highs[$i]) && $highs[$i] !== null) ? (float)$highs[$i] : $lastClose;
            $lastLow   = (isset($lows[$i])  && $lows[$i]  !== null) ? (float)$lows[$i]  : $lastClose;
            $lastVol   = (isset($vols[$i])  && $vols[$i]  !== null) ? (int)$vols[$i]   : 0;
            $lastTs    = isset($tss[$i]) ? (int)$tss[$i] : time();
            break;
        }
    }

    // Fallback
    if ($lastClose === null && isset($meta['regularMarketPrice'])) {
        $p = (float)$meta['regularMarketPrice'];
        $lastClose = $p; $lastOpen = $p; $lastHigh = $p; $lastLow = $p; $lastVol = 0; $lastTs = time();
    }

    if ($lastClose === null) {
        return ['success' => false, 'error' => 'Yahoo Finance fiyat verisi bulunamadı'];
    }

    $prevClose = (float)($meta['previousClose'] ?? $meta['chartPreviousClose'] ?? $lastClose);
    $change = $lastClose - $prevClose;
    $changePercent = ($prevClose != 0.0) ? ($change / $prevClose) * 100 : 0.0;

    return [
        'success' => true,
        'symbol' => $symbol,
        'current_price' => $lastClose,
        'open' => $lastOpen,
        'high' => $lastHigh,
        'low' => $lastLow,
        'volume' => $lastVol,
        'previous_close' => $prevClose,
        'change' => $change,
        'change_percent' => $changePercent,
        'currency' => $meta['currency'] ?? 'TRY',
        'timestamp' => time(),
        'market_timestamp' => $lastTs,
        'source' => 'Yahoo Finance (intraday 5m)'
    ];
}

// ========== 2) AlphaVantage (Yedek) ==========
function getAlphaVantageData(string $symbol): array {
    $api_key = 'demo'; // demo günlük çok düşük limit; istersen ücretsiz key alıp buraya yazacağız
    $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol={$symbol}.IST&apikey={$api_key}";

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'Sunucuda cURL aktif değil'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return ['success' => false, 'error' => 'Alpha Vantage API hatası'];
    }

    $data = json_decode($response, true);
    if (!isset($data['Global Quote'])) {
        return ['success' => false, 'error' => 'Alpha Vantage veri formatı hatası'];
    }

    $q = $data['Global Quote'];

    return [
        'success' => true,
        'symbol' => $symbol,
        'current_price' => (float)($q['05. price'] ?? 0),
        'open' => (float)($q['02. open'] ?? 0),
        'high' => (float)($q['03. high'] ?? 0),
        'low' => (float)($q['04. low'] ?? 0),
        'volume' => (int)($q['06. volume'] ?? 0),
        'previous_close' => (float)($q['08. previous close'] ?? 0),
        'change' => (float)($q['09. change'] ?? 0),
        'change_percent' => (float)str_replace('%', '', ($q['10. change percent'] ?? '0')),
        'currency' => 'TRY',
        'timestamp' => time(),
        'source' => 'Alpha Vantage'
    ];
}

// ========== 3) Basit file cache ==========
function getStockDataWithCache(string $symbol, int $cache_time = 120): array {
    $cacheDir = __DIR__ . "/cache";
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . "/stock_{$symbol}.json";

    if (file_exists($cacheFile)) {
        $cache_data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cache_data) && isset($cache_data['timestamp'])) {
            $age = time() - (int)$cache_data['timestamp'];
            if ($age < $cache_time) {
                $cache_data['from_cache'] = true;
                return $cache_data;
            }
        }
    }

    $data = getYahooFinanceData($symbol);
    if (!$data['success']) {
        $data = getAlphaVantageData($symbol);
    }

    if ($data['success']) {
        file_put_contents($cacheFile, json_encode($data));
        $data['from_cache'] = false;
    }

    return $data;
}

// ========== 4) Son çare: simulated ==========
function getSimulatedData(string $symbol): array {
    $base_price = rand(10, 500) + (rand(0, 99) / 100);
    $change_percent = (rand(-500, 500) / 100);

    return [
        'success' => true,
        'symbol' => $symbol,
        'current_price' => $base_price,
        'open' => $base_price * (1 - ($change_percent / 100)),
        'high' => $base_price * 1.03,
        'low' => $base_price * 0.97,
        'volume' => rand(1000000, 50000000),
        'previous_close' => $base_price * (1 - ($change_percent / 100)),
        'change' => $base_price * ($change_percent / 100),
        'change_percent' => $change_percent,
        'currency' => 'TRY',
        'timestamp' => time(),
        'source' => 'Simulated Data',
        'from_cache' => false,
        'simulated' => true
    ];
}

function getStockData(string $symbol, bool $use_cache = true): array {
    $symbol = strtoupper(trim($symbol));
    $data = $use_cache ? getStockDataWithCache($symbol, 120) : getYahooFinanceData($symbol);

    if (!$data['success']) {
        return getSimulatedData($symbol);
    }
    return $data;
}

// ===== Test Endpoint =====
if (isset($_GET['test'], $_GET['symbol'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getStockData($_GET['symbol'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Eğer doğrudan açıldıysa mini bilgi:
header('Content-Type: text/plain; charset=utf-8');
echo "OK. Test: api_live.php?test=1&symbol=THYAO\n";
