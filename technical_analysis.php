<?php
// technical_analysis.php - Gerçek Teknik Analiz Motoru

/**
 * RSI (Relative Strength Index) Hesaplama
 * @param array $prices - Kapanış fiyatları (en yeni en sonda)
 * @param int $period - Periyot (genelde 14)
 * @return float
 */
function calculateRSI($prices, $period = 14) {
    if (count($prices) < $period + 1) {
        return 50; // Yeterli veri yok
    }
    
    $gains = [];
    $losses = [];
    
    // Fiyat değişimlerini hesapla
    for ($i = 1; $i < count($prices); $i++) {
        $change = $prices[$i] - $prices[$i - 1];
        if ($change >= 0) {
            $gains[] = $change;
            $losses[] = 0;
        } else {
            $gains[] = 0;
            $losses[] = abs($change);
        }
    }
    
    // İlk ortalamalar
    $avg_gain = array_sum(array_slice($gains, 0, $period)) / $period;
    $avg_loss = array_sum(array_slice($losses, 0, $period)) / $period;
    
    // Smoothed averages
    for ($i = $period; $i < count($gains); $i++) {
        $avg_gain = (($avg_gain * ($period - 1)) + $gains[$i]) / $period;
        $avg_loss = (($avg_loss * ($period - 1)) + $losses[$i]) / $period;
    }
    
    if ($avg_loss == 0) {
        return 100;
    }
    
    $rs = $avg_gain / $avg_loss;
    $rsi = 100 - (100 / (1 + $rs));
    
    return round($rsi, 2);
}

/**
 * EMA (Exponential Moving Average) Hesaplama
 */
function calculateEMA($prices, $period) {
    if (count($prices) < $period) {
        return array_sum($prices) / count($prices);
    }
    
    // İlk SMA
    $sma = array_sum(array_slice($prices, 0, $period)) / $period;
    $multiplier = 2 / ($period + 1);
    $ema = $sma;
    
    // EMA hesapla
    for ($i = $period; $i < count($prices); $i++) {
        $ema = ($prices[$i] - $ema) * $multiplier + $ema;
    }
    
    return round($ema, 2);
}

/**
 * MACD Hesaplama
 */
function calculateMACD($prices) {
    $ema12 = calculateEMA($prices, 12);
    $ema26 = calculateEMA($prices, 26);
    
    $macd = $ema12 - $ema26;
    
    // Signal line (MACD'nin 9 günlük EMA'sı - basitleştirilmiş)
    $signal = $macd * 0.9; // Yaklaşık
    $histogram = $macd - $signal;
    
    return [
        'macd' => round($macd, 4),
        'signal' => round($signal, 4),
        'histogram' => round($histogram, 4),
        'ema12' => $ema12,
        'ema26' => $ema26
    ];
}

/**
 * SMA (Simple Moving Average)
 */
function calculateSMA($prices, $period) {
    if (count($prices) < $period) {
        return array_sum($prices) / count($prices);
    }
    
    $slice = array_slice($prices, -$period);
    return round(array_sum($slice) / $period, 2);
}

/**
 * Bollinger Bands
 */
function calculateBollingerBands($prices, $period = 20, $stddev = 2) {
    $sma = calculateSMA($prices, $period);
    
    if (count($prices) < $period) {
        return [
            'upper' => $sma * 1.02,
            'middle' => $sma,
            'lower' => $sma * 0.98
        ];
    }
    
    $slice = array_slice($prices, -$period);
    
    // Standart sapma hesapla
    $variance = 0;
    foreach ($slice as $price) {
        $variance += pow($price - $sma, 2);
    }
    $std = sqrt($variance / $period);
    
    return [
        'upper' => round($sma + ($stddev * $std), 2),
        'middle' => $sma,
        'lower' => round($sma - ($stddev * $std), 2)
    ];
}

/**
 * Gelişmiş AI Analiz - Gerçek Teknik Göstergelerle
 */
function analyzeStockAdvanced($symbol, $current_data, $historical_prices) {
    $current_price = $current_data['current_price'];
    
    // Teknik göstergeleri hesapla
    $rsi = calculateRSI($historical_prices);
    $macd = calculateMACD($historical_prices);
    $sma20 = calculateSMA($historical_prices, 20);
    $sma50 = calculateSMA($historical_prices, 50);
    $bollinger = calculateBollingerBands($historical_prices);
    
    // AI Skor hesaplama (0-100)
    $score = 50; // Base score
    
    // RSI Analizi
    if ($rsi < 30) {
        $score += 20; // Oversold - AL sinyali
    } elseif ($rsi > 70) {
        $score -= 15; // Overbought - SAT baskısı
    } elseif ($rsi >= 40 && $rsi <= 60) {
        $score += 10; // Nötr bölge - stabil
    }
    
    // MACD Analizi
    if ($macd['histogram'] > 0) {
        $score += 15; // Bullish momentum
    } else {
        $score -= 10;
    }
    
    // SMA Trend Analizi
    if ($current_price > $sma20 && $sma20 > $sma50) {
        $score += 15; // Güçlü yükseliş trendi
    } elseif ($current_price > $sma20) {
        $score += 8;
    }
    
    // Bollinger Bands
    if ($current_price < $bollinger['lower']) {
        $score += 12; // Alt banda yakın - potansiyel toparlanma
    } elseif ($current_price > $bollinger['upper']) {
        $score -= 8; // Üst banda yakın - aşırı alım
    }
    
    // Volume analizi
    if ($current_data['volume'] > 5000000) {
        $score += 8;
    }
    
    // Günlük değişim
    if ($current_data['change_percent'] > 2) {
        $score += 10;
    } elseif ($current_data['change_percent'] < -2) {
        $score -= 10;
    }
    
    // Skor limitleri
    $score = max(30, min(95, $score));
    
    // Kar tahmini (teknik göstergelere dayalı)
    $expected_profit = 5; // Base
    
    if ($rsi < 30) $expected_profit += 5; // Oversold potansiyeli
    if ($macd['histogram'] > 0) $expected_profit += 3;
    if ($current_price < $bollinger['lower']) $expected_profit += 4;
    
    $expected_profit = min($expected_profit, 15); // Max %15
    
    $target_price = $current_price * (1 + ($expected_profit / 100));
    
    // Analiz nedeni
    $reasons = [];
    if ($rsi < 35) $reasons[] = "RSI düşük ({$rsi}) - aşırı satım";
    if ($macd['histogram'] > 0) $reasons[] = "MACD pozitif momentum";
    if ($current_price > $sma20) $reasons[] = "SMA20 üzerinde";
    if ($current_price < $bollinger['lower']) $reasons[] = "Bollinger alt bandına yakın";
    
    $reason = !empty($reasons) ? implode(". ", $reasons) . "." : "Teknik analiz tamamlandı.";
    
    $trendState = 'neutral';
    if ($current_price > $sma20 && $sma20 > $sma50) {
        $trendState = 'bullish';
    } elseif ($current_price < $sma20 && $sma20 < $sma50) {
        $trendState = 'bearish';
    }

    return [
        'symbol' => $symbol,
        'confidence_score' => round($score),
        'expected_profit_percent' => round($expected_profit, 2),
        'target_price' => round($target_price, 2),
        'entry_price' => $current_price,
        'stop_loss' => round($current_price * 0.95, 2),
        'reason' => $reason,
        'trend_state' => $trendState,
        'indicators' => [
            'rsi' => $rsi,
            'macd' => $macd['macd'],
            'macd_signal' => $macd['signal'],
            'sma20' => $sma20,
            'sma50' => $sma50,
            'bollinger_upper' => $bollinger['upper'],
            'bollinger_lower' => $bollinger['lower']
        ]
    ];
}
?>
