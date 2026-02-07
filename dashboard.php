<?php
require_once 'config.php';
checkAuth();
$user_id = getAuthenticatedUserId();

/**
 * "2-3 gün" / "3-5 gün" / "7 gün" gibi timeframe'den gün sayısını yakala.
 * Bulamazsa default 3.
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

function safe(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function riskLabel(string $risk): array {
    $r = strtolower(trim($risk));
    if ($r === 'low') return ['Düşük', 'low'];
    if ($r === 'high') return ['Yüksek', 'high'];
    return ['Orta', 'medium'];
}

/**
 * Basit performans metriği:
 * - expires_at dolmuş sinyalleri "kapanmış" kabul eder.
 * - BUY için target > entry ise "başarılı", değilse "başarısız" sayar (baseline).
 * Not: Gerçek performans için updater mantığına bağlayacağız.
 */
function calcPerformance(PDO $pdo, ?int $user_id): array {
    $params = [];
    $clauses = ["( (expires_at IS NOT NULL AND expires_at <= NOW()) OR is_active = 0 )"];
    if ($user_id !== null) {
        $clauses[] = 'user_id = ?';
        $params[] = $user_id;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $clauses);

    // Kapanmış sinyaller: expires_at geçmiş veya is_active=0 (ikisini de sayalım)
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS closed_total,
            SUM(
                CASE
                    WHEN action='BUY'  AND target_price > entry_price THEN 1
                    WHEN action='SELL' AND target_price < entry_price THEN 1
                    ELSE 0
                END
            ) AS closed_win,
            AVG(expected_profit_percent) AS avg_expected,
            AVG(confidence_score) AS avg_conf
        FROM opportunities
        {$whereSql}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $closed_total = (int)($row['closed_total'] ?? 0);
    $closed_win   = (int)($row['closed_win'] ?? 0);
    $closed_loss  = max(0, $closed_total - $closed_win);

    $win_rate = $closed_total > 0 ? round(($closed_win / $closed_total) * 100, 1) : 0.0;

    return [
        'closed_total' => $closed_total,
        'closed_win' => $closed_win,
        'closed_loss' => $closed_loss,
        'win_rate' => $win_rate,
        'avg_expected' => (float)($row['avg_expected'] ?? 0),
        'avg_conf' => (float)($row['avg_conf'] ?? 0),
    ];
}

/**
 * Son X gün performansı
 */
function calcRecentPerformance(PDO $pdo, ?int $user_id, int $days): array {
    $days = max(1, (int)$days);
    $whereUser = '';
    $params = [];
    if ($user_id !== null) {
        $whereUser = 'WHERE user_id = ? AND';
        $params[] = $user_id;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN realized_profit_percent > 0 THEN 1
                    ELSE 0
                END
            ) AS win
        FROM trade_results
        {$whereUser}
        closed_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($row['total'] ?? 0);
    $win = (int)($row['win'] ?? 0);
    $loss = max(0, $total - $win);
    $win_rate = $total > 0 ? round(($win / $total) * 100, 1) : 0.0;

    return [
        'total' => $total,
        'win' => $win,
        'loss' => $loss,
        'win_rate' => $win_rate,
    ];
}

// Kullanıcıya ait fırsat var mı? (yoksa global göster)
$hasUserOpps = false;
$stats = [
    'total_opportunities' => 0,
    'avg_potential' => 0,
    'max_potential' => 0,
    'today_count' => 0,
    'active_count' => 0,
    'avg_confidence' => 0,
];
$opportunities = [];
$topDaily = [];

try {
    $hasUserOppsStmt = $pdo->prepare("SELECT COUNT(*) FROM opportunities WHERE user_id = ?");
    $hasUserOppsStmt->execute([$user_id]);
    $hasUserOpps = ((int)$hasUserOppsStmt->fetchColumn()) > 0;

    $statsWhere = $hasUserOpps ? "WHERE user_id = ?" : "";
    $statsParams = $hasUserOpps ? [$user_id] : [];

    // İstatistikler
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_opportunities,
            AVG(expected_profit_percent) as avg_potential,
            MAX(expected_profit_percent) as max_potential,
            COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as today_count,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count,
            AVG(confidence_score) as avg_confidence
        FROM opportunities 
        {$statsWhere}
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: $stats;

    // Fırsatlar
    $oppWhere = $hasUserOpps ? "WHERE user_id = ? AND is_active = 1" : "WHERE is_active = 1";
    $oppStmt = $pdo->prepare("SELECT * FROM opportunities {$oppWhere} ORDER BY confidence_score DESC LIMIT 20");
    $oppStmt->execute($hasUserOpps ? [$user_id] : []);
    $opportunities = $oppStmt->fetchAll(PDO::FETCH_ASSOC);

    $dailyWhere = $hasUserOpps ? "WHERE user_id = ? AND" : "WHERE";
    $dailyParams = $hasUserOpps ? [$user_id] : [];
    $dailyStmt = $pdo->prepare("
        SELECT *
        FROM opportunities
        {$dailyWhere} created_at >= CURDATE()
        ORDER BY confidence_score DESC, expected_profit_percent DESC
        LIMIT 3
    ");
    $dailyStmt->execute($dailyParams);
    $topDaily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Dashboard data load error: " . $e->getMessage());
}

// Top fırsat
$top = $opportunities[0] ?? null;

// Performans
try {
    $perf = calcPerformance($pdo, $hasUserOpps ? $user_id : null);
    $recent7 = calcRecentPerformance($pdo, $hasUserOpps ? $user_id : null, 7);
$recent30 = calcRecentPerformance($pdo, $hasUserOpps ? $user_id : null, 30);
} catch (Throwable $e) {
    error_log("Dashboard performance error: " . $e->getMessage());
    $perf = ['closed_total' => 0, 'closed_win' => 0, 'closed_loss' => 0, 'win_rate' => 0, 'avg_expected' => 0, 'avg_conf' => 0];
    $recent7 = ['total' => 0, 'win' => 0, 'loss' => 0, 'win_rate' => 0];
    $recent30 = ['total' => 0, 'win' => 0, 'loss' => 0, 'win_rate' => 0];
}

// Stat bar oranları
$totalOpp = (int)($stats['total_opportunities'] ?? 0);
$totalOppBar = min(100, $totalOpp * 5);
$avgConf = (float)($stats['avg_confidence'] ?? 0);
$avgConfBar = min(100, $avgConf);
$winRateBar = min(100, (float)$perf['win_rate']);
$recent7Bar = min(100, (float)$recent7['win_rate']);
$recent30Bar = min(100, (float)$recent30['win_rate']);
$avgPotential = (float)($stats['avg_potential'] ?? 0);
$avgPotentialBar = min(100, ($avgPotential / 15) * 100);

// Grafik datası (son 10)
$chartOpps = array_slice($opportunities, 0, 10);
$chartLabels = [];
$chartProfit = [];
$chartConf = [];
foreach (array_reverse($chartOpps) as $o) {
    $chartLabels[] = (string)($o['symbol'] ?? '');
    $chartProfit[] = (float)($o['expected_profit_percent'] ?? 0);
    $chartConf[] = (int)($o['confidence_score'] ?? 0);
}

$newsSources = [
    [
        'title' => 'Bloomberg HT',
        'url' => 'https://www.bloomberght.com/',
        'desc' => 'Piyasa haberleri ve ekonomi gündemi.'
    ],
    [
        'title' => 'Foreks',
        'url' => 'https://www.foreks.com/',
        'desc' => 'BIST ve ekonomi haber akışı.'
    ],
    [
        'title' => 'Investing Türkiye',
        'url' => 'https://tr.investing.com/news/economy',
        'desc' => 'Küresel ekonomi ve piyasa gelişmeleri.'
    ],
    [
        'title' => 'TCMB Duyurular',
        'url' => 'https://www.tcmb.gov.tr/wps/wcm/connect/TR/TCMB+TR/Main+Menu/Duyurular',
        'desc' => 'Merkez Bankası resmi duyuruları.'
    ],
    [
        'title' => 'TÜİK',
        'url' => 'https://data.tuik.gov.tr/',
        'desc' => 'Resmi makroekonomik istatistikler.'
    ],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FezliTrade AI - Premium Trading Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
            background: radial-gradient(1200px 800px at 20% 10%, rgba(59,130,246,0.20), transparent 60%),
                        radial-gradient(900px 600px at 80% 30%, rgba(139,92,246,0.18), transparent 55%),
                        #0a0e27;
            color:#fff;overflow-x:hidden
        }
        .grid-background{
            position:fixed;top:0;left:0;width:100%;height:100%;
            background-image:
                linear-gradient(rgba(59,130,246,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59,130,246,.05) 1px, transparent 1px);
            background-size:50px 50px;z-index:0;pointer-events:none;
            mask-image: radial-gradient(circle at 50% 20%, black 0%, transparent 65%);
        }
        .ambient-glow{
            position:fixed;inset:0;pointer-events:none;z-index:0;
        }
        .glow-orb{
            position:absolute;border-radius:50%;filter: blur(80px);
            opacity:.35;animation: floatOrb 18s ease-in-out infinite;
        }
        .glow-orb.blue{background: rgba(59,130,246,.65);width:420px;height:420px;top:-120px;left:-120px;}
        .glow-orb.purple{background: rgba(139,92,246,.6);width:360px;height:360px;bottom:-160px;right:-100px;animation-delay:6s;}
        .glow-orb.cyan{background: rgba(14,165,233,.55);width:280px;height:280px;top:45%;left:70%;animation-delay:10s;}
        @keyframes floatOrb{
            0%,100%{transform: translate(0,0) scale(1)}
            50%{transform: translate(20px,-30px) scale(1.08)}
        }
        .container{max-width:1600px;margin:0 auto;padding:40px 30px;position:relative;z-index:1}
        .header{
            display:flex;justify-content:space-between;align-items:center;
            margin-bottom:26px;padding-bottom:18px;
            border-bottom:1px solid rgba(59,130,246,.20)
        }
        .logo{display:flex;align-items:center;gap:15px}
        .logo-icon{
            width:50px;height:50px;border-radius:14px;
            background: linear-gradient(135deg,#3b82f6,#8b5cf6);
            display:flex;align-items:center;justify-content:center;font-size:24px;
            box-shadow:0 0 35px rgba(59,130,246,.55)
        }
        .logo-text h1{
            font-size:24px;font-weight:900;
            background:linear-gradient(135deg,#3b82f6,#8b5cf6);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent
        }
        .logo-text p{font-size:12px;color:#94a3b8;margin-top:2px}
        .header-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end}
        .nav-links{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .nav-link{
            text-decoration:none;color:#e2e8f0;font-size:13px;font-weight:700;
            padding:8px 12px;border-radius:999px;
            border:1px solid rgba(148,163,184,.2);
            background:rgba(15,23,42,.35);
            transition:.25s ease;
        }
        .nav-link:hover{
            transform:translateY(-2px);
            border-color:rgba(59,130,246,.6);
            box-shadow:0 10px 25px rgba(59,130,246,.2)
        }
        .primary-actions{display:flex;align-items:center;gap:10px}
        .live-badge{
            display:flex;align-items:center;gap:8px;padding:8px 14px;
            background:rgba(16,185,129,.10);
            border:1px solid rgba(16,185,129,.35);
            border-radius:999px;font-size:13px;font-weight:700;color:#10b981
        }
        .live-dot{width:8px;height:8px;background:#10b981;border-radius:50%;animation:pulse 1.6s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}

        .chip{
            display:inline-flex;align-items:center;gap:8px;
            padding:8px 12px;border-radius:999px;
            border:1px solid rgba(59,130,246,.25);
            background:rgba(15,23,42,.55);
            color:#cbd5e1;font-size:12px;font-weight:700
        }

        .action-button{
            display:inline-flex;align-items:center;gap:8px;
            padding:10px 16px;border-radius:14px;
            border:1px solid rgba(99,102,241,.45);
            background: linear-gradient(135deg, rgba(79,70,229,.95), rgba(14,165,233,.9));
            color:#fff;font-size:13px;font-weight:800;
            text-decoration:none;
            box-shadow: 0 16px 32px rgba(59,130,246,.35);
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }
        .action-button:hover{
            transform: translateY(-1px);
            box-shadow: 0 22px 50px rgba(59,130,246,.45);
            filter: brightness(1.05);
        }

        .top-row{
            display:grid;
            grid-template-columns: 1.35fr 0.65fr;
            gap:18px;
            margin-bottom:22px
        }

        .card{
            background: linear-gradient(135deg, rgba(30,41,59,.78), rgba(15,23,42,.92));
            border:1px solid rgba(59,130,246,.28);
            border-radius:22px;
            padding:22px;
            backdrop-filter: blur(10px);
            position:relative;
            overflow:hidden;
            box-shadow: 0 18px 45px rgba(8, 12, 24, 0.45);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .card:hover{
            transform: translateY(-2px);
            border-color: rgba(99,102,241,.55);
            box-shadow: 0 24px 70px rgba(8, 12, 24, 0.55);
        }
        .card::before{
            content:'';
            position:absolute;top:0;left:0;right:0;height:3px;
            background: linear-gradient(90deg,#3b82f6,#8b5cf6);
            opacity:.85
        }
        .card::after{
            content:'';
            position:absolute;
            inset:0;
            background: radial-gradient(circle at top right, rgba(99,102,241,0.18), transparent 60%);
            pointer-events:none;
        }

        .top-alarm{
            background: linear-gradient(135deg, rgba(16,185,129,.92), rgba(5,150,105,.90));
            border:2px solid rgba(16,185,129,.90);
            border-radius:22px;
            padding:26px;
            position:relative;
            overflow:hidden;
            box-shadow: 0 25px 70px rgba(16,185,129,.28);
        }
        .top-alarm::after{
            content:'';
            position:absolute;inset:-120px;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.20), transparent 45%);
            transform: rotate(10deg);
            pointer-events:none;
        }
        .alarm-content{display:flex;justify-content:space-between;gap:18px;position:relative;z-index:1}
        .alarm-main{flex:1}
        .alarm-header-section{display:flex;align-items:center;gap:14px;margin-bottom:12px}
        .alarm-icon{
            width:60px;height:60px;border-radius:16px;
            background:rgba(0,0,0,.28);
            display:flex;align-items:center;justify-content:center;
            font-size:28px;font-weight:900
        }
        .alarm-title{font-size:28px;font-weight:900;margin-bottom:4px}
        .alarm-subtitle{font-size:16px;opacity:.92}
        .alarm-details{display:grid;grid-template-columns:repeat(4,auto);gap:22px;margin-top:16px}
        .alarm-detail-label{font-size:12px;opacity:.88;margin-bottom:6px;font-weight:800}
        .alarm-detail-value{font-size:22px;font-weight:900}
        .alarm-detail-sub{font-size:12px;opacity:.85;margin-top:4px}
        .alarm-side{
            min-width:220px;
            text-align:center;
            padding:18px;
            background:rgba(0,0,0,.25);
            border-radius:16px;
            border:1px solid rgba(255,255,255,.12)
        }
        .alarm-side-label{font-size:13px;opacity:.85;margin-bottom:8px;font-weight:800}
        .alarm-side-value{font-size:22px;font-weight:900}
        .alarm-side-badge{
            font-size:12px;opacity:.9;margin-top:10px;
            padding:8px 12px;background:rgba(0,0,0,.18);
            border-radius:12px;font-weight:800
        }

        .stats-grid{
            display:grid;grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap:18px;margin-bottom:22px
        }
        .stat-label{font-size:12px;color:#94a3b8;font-weight:800;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px}
        .stat-value{font-size:34px;font-weight:900;margin-bottom:8px;letter-spacing:.2px}
        .stat-change{font-size:13px;color:#10b981;font-weight:800}
        .stat-change.neg{color:#ef4444}
        .stat-bar{
            height:6px;border-radius:999px;background:rgba(148,163,184,.12);
            overflow:hidden;margin-top:10px
        }
        .stat-bar span{
            display:block;height:100%;
            background: linear-gradient(90deg,#22d3ee,#6366f1);
            box-shadow:0 0 12px rgba(99,102,241,.6);
            border-radius:999px;
            transition: width .6s ease;
        }

        .main-grid{display:grid;grid-template-columns: 1fr 420px;gap:18px;margin-bottom:20px}
        .section-title{font-size:18px;font-weight:900}
        .opportunity-card{
            background:rgba(15,23,42,.58);
            border:1px solid rgba(59,130,246,.20);
            border-radius:18px;
            padding:18px;
            margin-bottom:14px;
            transition: all .25s ease;
            cursor:pointer;
            position:relative;
            overflow:hidden;
        }
        .opportunity-card:hover{
            transform: translateY(-2px);
            border-color: rgba(59,130,246,.45);
            box-shadow: 0 18px 60px rgba(0,0,0,.25);
        }
        .opp-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
        .opp-symbol{display:flex;align-items:center;gap:12px}
        .opp-icon{
            width:44px;height:44px;border-radius:14px;
            background:linear-gradient(135deg,#3b82f6,#8b5cf6);
            display:flex;align-items:center;justify-content:center;
            font-weight:900;font-size:15px
        }
        .opp-info h3{font-size:15px;font-weight:900;margin-bottom:2px}
        .opp-info p{font-size:12px;color:#94a3b8}
        .confidence-badge{
            padding:8px 14px;border-radius:999px;
            font-size:13px;font-weight:900;
            border:1px solid rgba(16,185,129,.35);
            background:rgba(16,185,129,.12);
            color:#10b981
        }
        .confidence-badge.medium{
            border-color: rgba(245,158,11,.35);
            background: rgba(245,158,11,.12);
            color:#f59e0b
        }
        .confidence-badge.low{
            border-color: rgba(239,68,68,.35);
            background: rgba(239,68,68,.12);
            color:#ef4444
        }
        .profit-prediction{
            font-size:22px;font-weight:900;
            color:#10b981;margin-bottom:10px
        }

        .plan-row{
            display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 2px 0
        }
        .plan-pill{
            display:inline-flex;align-items:center;gap:8px;
            padding:8px 12px;border-radius:999px;
            background: rgba(2,6,23,.45);
            border:1px solid rgba(148,163,184,.18);
            color:#e2e8f0;
            font-size:12px;font-weight:900
        }
        .plan-pill strong{font-weight:900}
        .plan-pill .muted{color:#94a3b8;font-weight:800}

        .opp-details{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:12px}
        .detail-item{background:rgba(30,41,59,.45);padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,.10)}
        .detail-label{font-size:10.5px;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px;font-weight:900}
        .detail-value{font-size:14px;font-weight:900}
        .risk-badge{
            display:inline-flex;align-items:center;
            padding:6px 10px;border-radius:10px;
            font-size:11px;font-weight:900;text-transform:uppercase;
            border:1px solid rgba(148,163,184,.18)
        }
        .risk-low{background:rgba(16,185,129,.18);color:#10b981;border-color:rgba(16,185,129,.30)}
        .risk-medium{background:rgba(245,158,11,.18);color:#f59e0b;border-color:rgba(245,158,11,.30)}
        .risk-high{background:rgba(239,68,68,.18);color:#ef4444;border-color:rgba(239,68,68,.30)}

        .terminal-content{
            background: rgba(0,0,0,.46);
            border-radius:14px;
            padding:16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size:12.5px;line-height:1.8;
            color:#10b981;
            max-height:520px;overflow:auto;
            border:1px solid rgba(16,185,129,.12)
        }
        .terminal-line{margin-bottom:8px}
        .terminal-line.system{color:#60a5fa}
        .terminal-line.success{color:#10b981}
        .terminal-line.warning{color:#f59e0b}
        .news-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
        .news-card{padding:18px;border-radius:16px;border:1px solid rgba(148,163,184,.12);background:rgba(12,18,33,.6);box-shadow:0 16px 40px rgba(2,6,23,.4);transition:.25s ease}
        .news-card:hover{transform:translateY(-3px);border-color:rgba(59,130,246,.5)}
        .news-title{font-size:15px;font-weight:900;margin-bottom:8px}
        .news-desc{font-size:12.5px;color:#94a3b8;margin-bottom:12px;line-height:1.5}
        .news-link{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#38bdf8;text-decoration:none}
        .news-link:hover{text-decoration:underline}

        .no-data{text-align:center;padding:54px 20px;color:#94a3b8}
        .no-data-icon{font-size:62px;margin-bottom:14px;opacity:.25}

        .chart-wrap{height:260px;margin-top:10px}
        canvas{max-width:100%}

        @media (max-width: 1200px){
            .main-grid{grid-template-columns:1fr}
            .top-row{grid-template-columns:1fr}
            .alarm-content{flex-direction:column}
            .alarm-details{grid-template-columns:repeat(2,1fr)}
        }
    </style>
</head>
<body>
<div class="grid-background"></div>
<div class="ambient-glow">
    <div class="glow-orb blue"></div>
    <div class="glow-orb purple"></div>
    <div class="glow-orb cyan"></div>
</div>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <div class="logo-icon">🚀</div>
            <div class="logo-text">
                <h1>FezliTrade AI</h1>
                <p>Premium Trading Intelligence</p>
            </div>
        </div>
        <div class="header-right">
            <div class="nav-links">
                <a class="nav-link" href="dashboard.php">🏠 Dashboard</a>
                <a class="nav-link" href="portfolio.php">💼 Portföy</a>
                <a class="nav-link" href="scanner.php" target="_blank" rel="noopener noreferrer">⚡ Scanner</a>
                <a class="nav-link" href="#news">🗞️ Ekonomi Haberleri</a>
            </div>
            <div class="primary-actions">
                <a class="action-button" href="scanner.php" target="_blank" rel="noopener noreferrer">
                    ⚡ Scanner Çalıştır
                </a>
                <span class="chip">📌 Alan adı: <strong>baralmotor.online</strong></span>
            </div>
            <div class="live-badge">
                <div class="live-dot"></div>
                <span>CANLI</span>
            </div>
        </div>
    </div>

    <!-- Top row: Alarm + Grafik -->
    <div class="top-row">
        <!-- TOP ALARM -->
        <?php if (!empty($top)):
            $entry_time = "Bugün";
            $days = extractDaysFromTimeframe($top['timeframe'] ?? '');
            $sellDate = date('d.m.Y', strtotime("+{$days} days"));
            [$riskText, $riskCss] = riskLabel((string)($top['risk_level'] ?? 'medium'));
            $topConfidence = (int)($top['confidence_score'] ?? 0);
            $alarmLabel = $topConfidence >= 70 ? 'ULTRA' : ($topConfidence >= 60 ? 'YÜKSEK' : 'ORTA');
        ?>
        <div class="top-alarm">
            <div class="alarm-content">
                <div class="alarm-main">
                    <div class="alarm-header-section">
                        <div class="alarm-icon"><?php echo safe(substr((string)$top['symbol'], 0, 2)); ?></div>
                        <div>
                            <h2 class="alarm-title">🚨 YÜKSEK POTANSİYEL</h2>
                            <p class="alarm-subtitle"><?php echo safe((string)$top['name']); ?> (<?php echo safe((string)$top['symbol']); ?>)</p>
                            <div class="plan-row" style="margin-top:10px">
                                <span class="plan-pill">🟢 <strong>BUGÜN AL</strong> <span class="muted">/</span> 🔥 <strong><?php echo $sellDate; ?> SAT</strong></span>
                                <span class="plan-pill">⏱ <strong><?php echo safe((string)($top['timeframe'] ?? '')); ?></strong></span>
                                <span class="plan-pill">⚠️ <strong><?php echo $riskText; ?></strong> Risk</span>
                            </div>
                        </div>
                    </div>

                    <div class="alarm-details">
                        <div class="alarm-detail-item">
                            <div class="alarm-detail-label">⏰ ALIŞ</div>
                            <div class="alarm-detail-value">₺<?php echo number_format((float)$top['entry_price'], 2); ?></div>
                            <div class="alarm-detail-sub"><?php echo $entry_time; ?></div>
                        </div>
                        <div class="alarm-detail-item">
                            <div class="alarm-detail-label">🎯 SATIŞ</div>
                            <div class="alarm-detail-value">₺<?php echo number_format((float)$top['target_price'], 2); ?></div>
                            <div class="alarm-detail-sub"><?php echo "{$days} gün sonra (~{$sellDate})"; ?></div>
                        </div>
                        <div class="alarm-detail-item">
                            <div class="alarm-detail-label">💰 BEKLENEN</div>
                            <div class="alarm-detail-value">+<?php echo number_format((float)$top['expected_profit_percent'], 2); ?>%</div>
                            <div class="alarm-detail-sub">100 lot: ₺<?php echo number_format(((float)$top['target_price'] - (float)$top['entry_price']) * 100, 2); ?></div>
                        </div>
                        <div class="alarm-detail-item">
                            <div class="alarm-detail-label">🔒 GÜVEN</div>
                            <div class="alarm-detail-value"><?php echo $topConfidence; ?>/100</div>
                            <div class="alarm-detail-sub">Ortalama: <?php echo number_format((float)($stats['avg_confidence'] ?? 0), 1); ?>/100</div>
                        </div>
                    </div>
                </div>

                <div class="alarm-side">
                    <div class="alarm-side-label">🏁 Başarı Oranı</div>
                    <div class="alarm-side-value"><?php echo number_format((float)$perf['win_rate'], 1); ?>%</div>
                    <div class="alarm-side-badge">
                        Kapanan: <?php echo (int)$perf['closed_total']; ?> • ✅ <?php echo (int)$perf['closed_win']; ?> / ❌ <?php echo (int)$perf['closed_loss']; ?>
                    </div>
                    <div class="alarm-side-badge"><?php echo $alarmLabel; ?> Seviye</div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div style="font-weight:900;font-size:18px;margin-bottom:10px">🚨 Top Alarm</div>
            <div style="color:#94a3b8;font-weight:700">Henüz aktif fırsat yok. Sistem yeni sinyal arıyor.</div>
            <div class="chart-wrap" style="height:200px;margin-top:18px;color:#94a3b8">
                <div class="terminal-content" style="max-height:none;color:#60a5fa">
                    <div class="terminal-line system">> Scanner çalıştığında fırsatlar burada görünür.</div>
                    <div class="terminal-line">Scanner çalıştığında burası otomatik dolacak.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Grafik -->
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div>
                    <div class="section-title">📈 Sinyal Grafiği</div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:6px;font-weight:700">Son fırsatlar: Beklenen Getiri & Güven</div>
                </div>
                <span class="chip">⏱ Güncelleme: <?php echo date('d.m.Y H:i'); ?></span>
            </div>
            <div class="chart-wrap">
                <canvas id="oppChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="card">
            <div class="stat-label">Toplam Fırsat</div>
            <div class="stat-value"><?php echo number_format((int)($stats['total_opportunities'] ?? 0)); ?></div>
            <div class="stat-change">+<?php echo (int)($stats['today_count'] ?? 0); ?> Bugün Eklendi</div>
            <div class="stat-bar"><span style="width: <?php echo number_format($totalOppBar, 0); ?>%"></span></div>
        </div>
        <div class="card">
            <div class="stat-label">AI Ortalama Güven</div>
            <div class="stat-value"><?php echo number_format((float)($stats['avg_confidence'] ?? 0), 1); ?>/100</div>
            <div class="stat-change">Kalite: <?php echo ((float)($stats['avg_confidence'] ?? 0) >= 70) ? 'Yüksek' : 'Orta'; ?></div>
            <div class="stat-bar"><span style="width: <?php echo number_format($avgConfBar, 0); ?>%"></span></div>
        </div>
        <div class="card">
            <div class="stat-label">Başarı Oranı</div>
            <div class="stat-value"><?php echo number_format((float)$perf['win_rate'], 1); ?>%</div>
            <div class="stat-change">✅ <?php echo (int)$perf['closed_win']; ?> / ❌ <?php echo (int)$perf['closed_loss']; ?> (Kapanan: <?php echo (int)$perf['closed_total']; ?>)</div>
            <div class="stat-bar"><span style="width: <?php echo number_format($winRateBar, 0); ?>%"></span></div>
        </div>
        <div class="card">
            <div class="stat-label">Son 7 Gün</div>
            <div class="stat-value"><?php echo number_format((float)$recent7['win_rate'], 1); ?>%</div>
            <div class="stat-change">✅ <?php echo (int)$recent7['win']; ?> / ❌ <?php echo (int)$recent7['loss']; ?> (<?php echo (int)$recent7['total']; ?>)</div>
            <div class="stat-bar"><span style="width: <?php echo number_format($recent7Bar, 0); ?>%"></span></div>
        </div>
        <div class="card">
            <div class="stat-label">Son 30 Gün</div>
            <div class="stat-value"><?php echo number_format((float)$recent30['win_rate'], 1); ?>%</div>
            <div class="stat-change">✅ <?php echo (int)$recent30['win']; ?> / ❌ <?php echo (int)$recent30['loss']; ?> (<?php echo (int)$recent30['total']; ?>)</div>
            <div class="stat-bar"><span style="width: <?php echo number_format($recent30Bar, 0); ?>%"></span></div>
        </div>
        <div class="card">
            <div class="stat-label">Ortalama Getiri</div>
            <div class="stat-value">+<?php echo number_format((float)($stats['avg_potential'] ?? 0), 1); ?>%</div>
            <div class="stat-change">Maks: +<?php echo number_format((float)($stats['max_potential'] ?? 0), 1); ?>%</div>
            <div class="stat-bar"><span style="width: <?php echo number_format($avgPotentialBar, 0); ?>%"></span></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:22px">
        <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <div class="section-title">🏆 Günün En İyi 3 Hissesi</div>
            <span class="chip">Bugün seçilenler</span>
        </div>
        <?php if (empty($topDaily)): ?>
            <div class="no-data">
                <div class="no-data-icon">📌</div>
                <h3 style="margin-bottom: 10px; font-weight:900;">Bugün için seçim yok</h3>
                <p>Yeni sinyal üretildiğinde burada ilk 3 hisse gösterilecek.</p>
            </div>
        <?php else: ?>
            <div class="news-grid">
                <?php foreach ($topDaily as $idx => $daily): ?>
                    <div class="news-card">
                        <div class="news-title">#<?php echo ($idx + 1); ?> <?php echo safe((string)$daily['symbol']); ?></div>
                        <div class="news-desc"><?php echo safe((string)($daily['name'] ?? '')); ?></div>
                        <div class="stat-change" style="margin-bottom:8px">
                            +<?php echo number_format((float)($daily['expected_profit_percent'] ?? 0), 2); ?>% beklenen
                        </div>
                        <div class="news-link" style="color:#cbd5e1">
                            Güven: <?php echo (int)($daily['confidence_score'] ?? 0); ?>/100
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" id="news" style="margin-top:22px">
        <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <div class="section-title">🗞️ Ekonomi Haberleri</div>
            <span class="chip">Güncel kaynaklar</span>
        </div>
        <div class="news-grid">
            <?php foreach ($newsSources as $source): ?>
                <div class="news-card">
                    <div class="news-title"><?php echo safe($source['title']); ?></div>
                    <div class="news-desc"><?php echo safe($source['desc']); ?></div>
                    <a class="news-link" href="<?php echo safe($source['url']); ?>" target="_blank" rel="noopener noreferrer">
                        Haberlere git →
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="main-grid">
        <!-- Opportunities -->
        <div class="card">
            <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
                <div class="section-title">🎯 Yüksek Öncelikli Fırsatlar</div>
                <span class="chip">🧾 Gösterilen: <?php echo min(10, count($opportunities)); ?>/<?php echo count($opportunities); ?></span>
            </div>

            <?php if (empty($opportunities)): ?>
                <div class="no-data">
                    <div class="no-data-icon">📭</div>
                    <h3 style="margin-bottom: 10px; font-weight:900;">Henüz fırsat bulunamadı</h3>
                    <p>Scanner otomatik olarak BIST200 hisselerini tarayacak.</p>
                </div>
            <?php else: ?>
                <?php foreach (array_slice($opportunities, 0, 10) as $opp):
                    $conf = (int)($opp['confidence_score'] ?? 0);
                    $badgeClass = $conf >= 70 ? '' : ($conf >= 50 ? 'medium' : 'low');

                    $days = extractDaysFromTimeframe($opp['timeframe'] ?? '');
                    $sellDate = date('d.m.Y', strtotime("+{$days} days"));

                    [$riskText, $riskCss] = riskLabel((string)($opp['risk_level'] ?? 'medium'));
                ?>
                    <div class="opportunity-card">
                        <div class="opp-header">
                            <div class="opp-symbol">
                                <div class="opp-icon"><?php echo safe(substr((string)$opp['symbol'], 0, 2)); ?></div>
                                <div class="opp-info">
                                    <h3><?php echo safe((string)$opp['name']); ?></h3>
                                    <p>BIST: <?php echo safe((string)$opp['symbol']); ?> • Aksiyon: <?php echo safe((string)($opp['action'] ?? 'BUY')); ?></p>
                                </div>
                            </div>
                            <div class="confidence-badge <?php echo $badgeClass; ?>">
                                <?php echo $conf; ?>/100
                            </div>
                        </div>

                        <div class="profit-prediction">
                            +<?php echo number_format((float)($opp['expected_profit_percent'] ?? 0), 2); ?>% BEKLENEN
                        </div>

                        <!-- BUGÜN AL / X GÜN SAT -->
                        <div class="plan-row">
                            <span class="plan-pill">🟢 <strong>BUGÜN AL</strong> <span class="muted">/</span> 🔥 <strong><?php echo $sellDate; ?> SAT</strong></span>
                            <span class="plan-pill">⏱ <strong><?php echo safe((string)($opp['timeframe'] ?? '')); ?></strong></span>
                            <span class="plan-pill">⚠️ <strong><?php echo $riskText; ?></strong> Risk</span>
                        </div>

                        <div class="opp-details">
                            <div class="detail-item">
                                <div class="detail-label">Giriş Fiyatı</div>
                                <div class="detail-value">₺<?php echo number_format((float)($opp['entry_price'] ?? 0), 2); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Hedef Fiyat</div>
                                <div class="detail-value">₺<?php echo number_format((float)($opp['target_price'] ?? 0), 2); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Stop Loss</div>
                                <div class="detail-value">₺<?php echo number_format((float)($opp['stop_loss'] ?? 0), 2); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Risk Seviyesi</div>
                                <div class="detail-value">
                                    <span class="risk-badge risk-<?php echo $riskCss; ?>"><?php echo strtoupper($riskCss); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($opp['analysis_reason'])): ?>
                            <div style="margin-top:12px;color:#cbd5e1;font-size:12.5px;line-height:1.5">
                                <span style="color:#94a3b8;font-weight:900">🧠 Sebep:</span>
                                <?php echo safe((string)$opp['analysis_reason']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Terminal -->
        <div class="card">
            <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                <div class="section-title">🖥️ AI Terminal - Canlı Akış</div>
                <span class="chip">⚙️ Scanner + Telegram</span>
            </div>

            <div class="terminal-content">
                <div class="terminal-line system">> Sistem aktif. Sinyal motoru çalışıyor.</div>
                <div class="terminal-line">BIST30 taraması + AI analiz pipeline hazır.</div>
                <div class="terminal-line success">✓ DB şeması ile uyum: opportunities.confidence_score</div>
                <div class="terminal-line">━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</div>

                <?php foreach (array_slice($opportunities, 0, 6) as $opp): ?>
                    <div class="terminal-line warning">
                        YENİ FIRSAT: <?php echo safe((string)$opp['symbol']); ?>
                        +<?php echo number_format((float)($opp['expected_profit_percent'] ?? 0), 2); ?>%
                        (Güven: <?php echo (int)($opp['confidence_score'] ?? 0); ?>/100)
                    </div>
                <?php endforeach; ?>

                <div class="terminal-line success">✓ Telegram bildirimleri aktif</div>
                <div class="terminal-line">━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</div>
                <div class="terminal-line system">Portföy sağlık skoru: <?php echo number_format((float)($stats['avg_confidence'] ?? 0), 1); ?>/100</div>
                <div class="terminal-line">Başarı oranı: <?php echo number_format((float)$perf['win_rate'], 1); ?>%</div>
                <div class="terminal-line">Son güncelleme: <?php echo date('d.m.Y H:i:s'); ?></div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
    const profits = <?php echo json_encode($chartProfit, JSON_UNESCAPED_UNICODE); ?>;
    const confs = <?php echo json_encode($chartConf, JSON_UNESCAPED_UNICODE); ?>;

    const ctx = document.getElementById('oppChart');
    if (!ctx) return;

    const gradientProfit = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
    gradientProfit.addColorStop(0, 'rgba(56,189,248,0.45)');
    gradientProfit.addColorStop(1, 'rgba(56,189,248,0.02)');
    const gradientConf = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
    gradientConf.addColorStop(0, 'rgba(139,92,246,0.45)');
    gradientConf.addColorStop(1, 'rgba(139,92,246,0.02)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Beklenen Getiri (%)',
                    data: profits,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderColor: '#38bdf8',
                    backgroundColor: gradientProfit,
                    fill: true
                },
                {
                    label: 'Güven Skoru (/100)',
                    data: confs,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderColor: '#8b5cf6',
                    backgroundColor: gradientConf,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#cbd5e1', font: { weight: '700' } } },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(15,23,42,0.92)',
                    borderColor: 'rgba(99,102,241,0.6)',
                    borderWidth: 1,
                    titleColor: '#e2e8f0',
                    bodyColor: '#cbd5e1'
                }
            },
            scales: {
                x: {
                    ticks: { color: '#94a3b8' },
                    grid: { color: 'rgba(148,163,184,0.10)' }
                },
                y: {
                    ticks: { color: '#94a3b8' },
                    grid: { color: 'rgba(148,163,184,0.10)' }
                }
            }
        }
    });

    // Auto refresh (30s)
    let countdown = 30;
    setInterval(() => {
        countdown--;
        if (countdown <= 0) location.reload();
    }, 1000);
})();
</script>
</body>
</html>
