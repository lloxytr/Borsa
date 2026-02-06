<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<!-- DEBUG START -->";

// portfolio.php - Portfoy Sayfasi (Veritabani Entegreli)
require_once 'config.php';
require_once 'api_live.php';

$user_id = 1;
$theme = 'dark';

// Portföy verilerini veritabanından çek
$stmt = $pdo->prepare("SELECT * FROM portfolio WHERE user_id = ? ORDER BY created_at DESC");

$stmt->execute([$user_id]);
$portfolio_items = $stmt->fetchAll();

// Gerçek zamanlı fiyatları güncelle
$portfolio = [];
foreach ($portfolio_items as $item) {
    $stock_data = getStockData($item['symbol'], true);
    
    if ($stock_data['success']) {
        $current_price = $stock_data['current_price'];
    } else {
        $current_price = $item['buy_price'];
    }
    
    $current_value = $item['quantity'] * $current_price;
    $profit_loss = $current_value - ($item['quantity'] * $item['buy_price']);
    $profit_loss_percent = (($current_price - $item['buy_price']) / $item['buy_price']) * 100;
    
    // Veritabanını güncelle
    $update = $pdo->prepare("
        UPDATE portfolio 
        SET current_price = ?, 
            current_value = ?, 
            profit_loss = ?, 
            profit_loss_percent = ? 
        WHERE id = ?
    ");
    $update->execute([$current_price, $current_value, $profit_loss, $profit_loss_percent, $item['id']]);
    
    // Array'e ekle
    $portfolio[] = [
        'symbol' => $item['symbol'],
        'name' => $item['name'],
        'amount' => $item['quantity'],
        'avg_price' => $item['buy_price'],
        'current_price' => $current_price,
        'icon' => mb_substr($item['symbol'], 0, 1)
    ];
}

// Eğer veritabanında veri yoksa örnek veriler göster
if (empty($portfolio)) {
    $portfolio = [
        ['symbol' => 'THYAO', 'name' => 'Türk Hava Yolları', 'amount' => 150, 'avg_price' => 250, 'current_price' => 286.5, 'icon' => '✈️'],
        ['symbol' => 'GARAN', 'name' => 'Garanti BBVA', 'amount' => 200, 'avg_price' => 140, 'current_price' => 145.8, 'icon' => '🏦'],
        ['symbol' => 'ASELS', 'name' => 'Aselsan', 'amount' => 100, 'avg_price' => 275, 'current_price' => 290, 'icon' => '🎖️']
    ];
}

$total_invested = 0;
$total_current = 0;

foreach ($portfolio as $asset) {
    $total_invested += $asset['amount'] * $asset['avg_price'];
    $total_current += $asset['amount'] * $asset['current_price'];
}

$total_profit = $total_current - $total_invested;
$total_profit_percent = $total_invested > 0 ? ($total_profit / $total_invested) * 100 : 0;

// Yeni hisse ekleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_stock'])) {
    $symbol = strtoupper(trim($_POST['symbol']));
    $name = trim($_POST['name']);
    $quantity = floatval($_POST['quantity']);
    $buy_price = floatval($_POST['buy_price']);
    $buy_date = $_POST['buy_date'];
    
    $insert = $pdo->prepare("
        INSERT INTO portfolio (
            user_id, symbol, name, quantity, buy_price, 
            buy_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $insert->execute([$user_id, $symbol, $name, $quantity, $buy_price, $buy_date]);
    
    header('Location: portfolio.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portföy - FezliTrade AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg-primary: #0a0e17;
            --bg-secondary: #131821;
            --bg-tertiary: #1a1f2e;
            --text-primary: #e5e7eb;
            --text-secondary: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.08);
            --accent-green: #00ff88;
            --accent-red: #ff3366;
            --accent-blue: #0066ff;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
        }
        
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: var(--bg-secondary);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 40px;
            z-index: 1000;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent-green), var(--accent-blue));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 20px;
            color: #000;
        }
        
        .logo-text {
            font-size: 20px;
            font-weight: 700;
        }
        
        .nav-items {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .nav-item {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 8px;
            text-decoration: none;
        }
        
        .nav-item:hover {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }
        
        .nav-item.active {
            color: var(--accent-green);
            background: rgba(0, 255, 136, 0.1);
        }
        
        .btn-add {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--accent-green), var(--accent-blue));
            color: #000;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.2s;
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
        }
        
        .main-container {
            margin-top: 64px;
            padding: 32px 40px;
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .page-header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 900;
        }
        
        .portfolio-summary {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 32px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
        }
        
        .summary-item {
            text-align: center;
        }
        
        .summary-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        
        .summary-value {
            font-size: 48px;
            font-weight: 900;
            font-family: 'IBM Plex Mono', monospace;
            color: var(--accent-green);
            letter-spacing: -2px;
        }
        
        .summary-change {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent-green);
            margin-top: 8px;
        }
        
        .summary-change.negative {
            color: var(--accent-red);
        }
        
        .assets-grid {
            display: grid;
            gap: 20px;
        }
        
        .asset-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 28px;
            transition: all 0.3s;
        }
        
        .asset-card:hover {
            border-color: var(--accent-green);
            box-shadow: 0 8px 32px rgba(0, 255, 136, 0.1);
            transform: translateY(-2px);
        }
        
        .asset-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .asset-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .asset-icon {
            width: 56px;
            height: 56px;
            background: var(--bg-tertiary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .asset-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .asset-symbol {
            font-size: 14px;
            color: var(--text-secondary);
            font-family: 'IBM Plex Mono', monospace;
        }
        
        .asset-profit {
            text-align: right;
        }
        
        .asset-profit-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--accent-green);
            font-family: 'IBM Plex Mono', monospace;
        }
        
        .asset-profit-value.negative {
            color: var(--accent-red);
        }
        
        .asset-profit-percent {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent-green);
            margin-top: 4px;
        }
        
        .asset-profit-percent.negative {
            color: var(--accent-red);
        }
        
        .asset-details {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }
        
        .detail-item {
            text-align: center;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .detail-value {
            font-size: 18px;
            font-weight: 700;
            font-family: 'IBM Plex Mono', monospace;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--accent-green);
        }
        
        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: 1fr; gap: 24px; }
            .asset-details { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    
    <nav class="navbar">
        <div class="logo-section">
            <div class="logo-icon">🚀</div>
            <div class="logo-text">FezliTrade AI</div>
        </div>
        <div class="nav-items">
            <a href="dashboard.php" class="nav-item">Dashboard</a>
            <a href="portfolio.php" class="nav-item active">Portföy</a>
            <a href="settings.php" class="nav-item">Ayarlar</a>
        </div>
    </nav>
    
    <div class="main-container">
        
        <div class="page-header">
            <div class="page-title">💼 Portföy</div>
            <button class="btn-add" onclick="document.getElementById('addModal').classList.add('active')">
                + Hisse Ekle
            </button>
        </div>
        
        <div class="portfolio-summary">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Toplam Değer</div>
                    <div class="summary-value">₺<?php echo number_format($total_current, 0, ',', '.'); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Toplam Kar/Zarar</div>
                    <div class="summary-value <?php echo $total_profit < 0 ? 'negative' : ''; ?>">₺<?php echo number_format($total_profit, 0, ',', '.'); ?></div>
                    <div class="summary-change <?php echo $total_profit_percent < 0 ? 'negative' : ''; ?>">
                        <i class="fas fa-arrow-<?php echo $total_profit >= 0 ? 'up' : 'down'; ?>"></i> <?php echo $total_profit >= 0 ? '+' : ''; ?><?php echo number_format($total_profit_percent, 2); ?>%
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Toplam Yatırım</div>
                    <div class="summary-value" style="color: var(--text-primary);">₺<?php echo number_format($total_invested, 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="assets-grid">
            <?php foreach ($portfolio as $asset): 
                $invested = $asset['amount'] * $asset['avg_price'];
                $current = $asset['amount'] * $asset['current_price'];
                $profit = $current - $invested;
                $profit_percent = $invested > 0 ? ($profit / $invested) * 100 : 0;
            ?>
            <div class="asset-card">
                <div class="asset-header">
                    <div class="asset-info">
                        <div class="asset-icon"><?php echo $asset['icon']; ?></div>
                        <div>
                            <div class="asset-name"><?php echo htmlspecialchars($asset['name']); ?></div>
                            <div class="asset-symbol"><?php echo htmlspecialchars($asset['symbol']); ?></div>
                        </div>
                    </div>
                    <div class="asset-profit">
                        <div class="asset-profit-value <?php echo $profit < 0 ? 'negative' : ''; ?>">₺<?php echo number_format($profit, 0, ',', '.'); ?></div>
                        <div class="asset-profit-percent <?php echo $profit_percent < 0 ? 'negative' : ''; ?>"><?php echo $profit >= 0 ? '+' : ''; ?><?php echo number_format($profit_percent, 2); ?>%</div>
                    </div>
                </div>
                
                <div class="asset-details">
                    <div class="detail-item">
                        <div class="detail-label">Miktar</div>
                        <div class="detail-value"><?php echo number_format($asset['amount'], 2); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Ort. Fiyat</div>
                        <div class="detail-value">₺<?php echo number_format($asset['avg_price'], 2); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Güncel Fiyat</div>
                        <div class="detail-value">₺<?php echo number_format($asset['current_price'], 2); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Toplam Değer</div>
                        <div class="detail-value">₺<?php echo number_format($current, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
    </div>
    
    <!-- Add Stock Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 25px;">+ Yeni Hisse Ekle</h2>
            
            <form method="POST">
                <div class="form-group">
                    <label>Hisse Sembolü *</label>
                    <input type="text" name="symbol" placeholder="THYAO" required>
                </div>
                
                <div class="form-group">
                    <label>Hisse Adı *</label>
                    <input type="text" name="name" placeholder="Türk Hava Yolları" required>
                </div>
                
                <div class="form-group">
                    <label>Adet *</label>
                    <input type="number" step="0.01" name="quantity" placeholder="100" required>
                </div>
                
                <div class="form-group">
                    <label>Alış Fiyatı (₺) *</label>
                    <input type="number" step="0.01" name="buy_price" placeholder="250.50" required>
                </div>
                
                <div class="form-group">
                    <label>Alış Tarihi *</label>
                    <input type="date" name="buy_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="add_stock" class="btn-add">Ekle</button>
                    <button type="button" class="btn-add" style="background: var(--bg-tertiary);" onclick="document.getElementById('addModal').classList.remove('active')">İptal</button>
                </div>
            </form>
        </div>
    </div>
    
</body>
</html>
