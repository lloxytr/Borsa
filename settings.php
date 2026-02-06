<?php
// settings.php - Ayarlar Sayfasi
require_once 'config.php';
checkAuth();

$theme = $_SESSION['theme'];
$success = '';
$error = '';

// Tema degistirme
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'dark') ? 'light' : 'dark';
    header('Location: settings.php');
    exit;
}

// Ayarlar kaydetme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        // Burada normalde MySQL'e kaydedilecek
        // Simdilik session'da tutuyoruz
        
        $_SESSION['telegram_id'] = $_POST['telegram_id'];
        $_SESSION['risk_level'] = $_POST['risk_level'];
        $_SESSION['min_profit'] = $_POST['min_profit'];
        $_SESSION['notifications'] = isset($_POST['notifications']) ? 1 : 0;
        
        $success = 'Ayarlar basariyla kaydedildi!';
    }
}

// Varsayilan degerler
$telegram_id = isset($_SESSION['telegram_id']) ? $_SESSION['telegram_id'] : '';
$risk_level = isset($_SESSION['risk_level']) ? $_SESSION['risk_level'] : 'medium';
$min_profit = isset($_SESSION['min_profit']) ? $_SESSION['min_profit'] : '3';
$notifications = isset($_SESSION['notifications']) ? $_SESSION['notifications'] : 1;
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ayarlar - <?php echo SITE_NAME; ?></title>
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
        
        [data-theme="light"] {
            --bg-primary: #f3f4f6;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f9fafb;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --border-color: rgba(0, 0, 0, 0.08);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            transition: background 0.3s, color 0.3s;
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
        
        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .theme-toggle:hover {
            border-color: var(--accent-green);
            transform: scale(1.05);
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: var(--bg-tertiary);
            border-radius: 24px;
            border: 1px solid var(--border-color);
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--accent-green), var(--accent-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: #000;
        }
        
        .logout-btn {
            color: var(--accent-red);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .main-container {
            margin-top: 64px;
            padding: 32px 40px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .page-header {
            margin-bottom: 32px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 900;
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            font-size: 16px;
            color: var(--text-secondary);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-success {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.3);
            color: var(--accent-green);
        }
        
        .alert-error {
            background: rgba(255, 51, 102, 0.1);
            border: 1px solid rgba(255, 51, 102, 0.3);
            color: var(--accent-red);
        }
        
        .settings-section {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            background: var(--bg-tertiary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .label-desc {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 400;
            margin-top: 4px;
        }
        
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 14px 18px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.3s;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: var(--accent-green);
            box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.1);
        }
        
        .radio-group {
            display: flex;
            gap: 16px;
            margin-top: 12px;
        }
        
        .radio-option {
            flex: 1;
            padding: 16px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        
        .radio-option:hover {
            border-color: var(--accent-green);
        }
        
        .radio-option input[type="radio"] {
            display: none;
        }
        
        .radio-option input[type="radio"]:checked + label {
            color: var(--accent-green);
        }
        
        .radio-option.selected {
            border-color: var(--accent-green);
            background: rgba(0, 255, 136, 0.05);
        }
        
        .radio-label {
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: block;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            cursor: pointer;
        }
        
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .btn-save {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent-green), #00cc6a);
            border: none;
            border-radius: 12px;
            color: #000;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 255, 136, 0.4);
        }
        
        .info-box {
            background: rgba(0, 102, 255, 0.1);
            border: 1px solid rgba(0, 102, 255, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--accent-blue);
        }
        
        .info-box i {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    
    <nav class="navbar">
        <div class="logo-section">
            <div class="logo-icon">Q</div>
            <div class="logo-text">QuantumTrade AI</div>
        </div>
        <div class="nav-items">
            <a href="dashboard.php" class="nav-item">Ana Sayfa</a>
            <a href="portfolio.php" class="nav-item">Portfoy</a>
            <a href="settings.php" class="nav-item active">Ayarlar</a>
            <a href="?toggle_theme=1" class="theme-toggle">
                <?php echo ($theme === 'dark') ? '☀️' : '🌙'; ?>
            </a>
        </div>
        <div class="user-profile">
            <div class="user-avatar">U</div>
            <a href="logout.php" class="logout-btn">Cikis</a>
        </div>
    </nav>
    
    <div class="main-container">
        
        <div class="page-header">
            <div class="page-title">Ayarlar</div>
            <div class="page-subtitle">Hesap ve bildirim tercihlerinizi yonetin</div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            
            <!-- TELEGRAM AYARLARI -->
            <div class="settings-section">
                <div class="section-title">
                    <div class="section-icon">📱</div>
                    <span>Telegram Bildirimleri</span>
                </div>
                
                <div class="form-group">
                    <label>
                        Telegram Chat ID
                        <div class="label-desc">Telegram botundan @userinfobot yazarak ID'nizi ogreniniz</div>
                    </label>
                    <input 
                        type="text" 
                        name="telegram_id" 
                        value="<?php echo htmlspecialchars($telegram_id); ?>"
                        placeholder="Ornek: 123456789"
                    >
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        Telegram'da <strong>@userinfobot</strong> botuna mesaj gonderin ve size verilen ID'yi buraya yapistiriniz.
                    </div>
                </div>
            </div>
            
            <!-- RISK AYARLARI -->
            <div class="settings-section">
                <div class="section-title">
                    <div class="section-icon">⚖️</div>
                    <span>Risk Tercihleri</span>
                </div>
                
                <div class="form-group">
                    <label>Risk Seviyesi</label>
                    <select name="risk_level">
                        <option value="low" <?php echo ($risk_level === 'low') ? 'selected' : ''; ?>>Dusuk Risk (Muhafazakar)</option>
                        <option value="medium" <?php echo ($risk_level === 'medium') ? 'selected' : ''; ?>>Orta Risk (Dengeli)</option>
                        <option value="high" <?php echo ($risk_level === 'high') ? 'selected' : ''; ?>>Yuksek Risk (Agresif)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        Minimum Beklenen Getiri (%)
                        <div class="label-desc">Bu oranin altindaki firsatlar size bildirilmeyecektir</div>
                    </label>
                    <input 
                        type="number" 
                        name="min_profit" 
                        value="<?php echo htmlspecialchars($min_profit); ?>"
                        min="1"
                        max="100"
                        step="0.5"
                    >
                </div>
            </div>
            
            <!-- BILDIRIM AYARLARI -->
            <div class="settings-section">
                <div class="section-title">
                    <div class="section-icon">🔔</div>
                    <span>Bildirim Tercihleri</span>
                </div>
                
                <div class="checkbox-wrapper">
                    <input 
                        type="checkbox" 
                        id="notifications" 
                        name="notifications"
                        <?php echo ($notifications) ? 'checked' : ''; ?>
                    >
                    <label for="notifications" style="cursor: pointer; margin: 0;">
                        <strong>Bildirimleri Aktif Et</strong>
                        <div class="label-desc">Yeni firsatlar tespit edildiginde Telegram uzerinden bildirim alin</div>
                    </label>
                </div>
            </div>
            
            <!-- HESAP BILGILERI -->
            <div class="settings-section">
                <div class="section-title">
                    <div class="section-icon">🔑</div>
                    <span>Hesap Bilgileri</span>
                </div>
                
                <div class="form-group">
                    <label>Lisans Anahtari</label>
                    <input 
                        type="text" 
                        value="<?php echo htmlspecialchars($_SESSION['license_key']); ?>"
                        readonly
                        style="background: var(--bg-primary); cursor: not-allowed;"
                    >
                </div>
            </div>
            
            <button type="submit" name="save_settings" class="btn-save">
                <i class="fas fa-save"></i> Ayarlari Kaydet
            </button>
            
        </form>
        
    </div>
    
    <script>
        // Radio button secimlerini gorsel guncelle
        document.querySelectorAll('.radio-option').forEach(option => {
            option.addEventListener('click', function() {
                this.parentElement.querySelectorAll('.radio-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
    </script>
    
</body>
</html>
