<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// keygenerator.php - Admin Key Üretici
require_once 'config.php';

$error = '';
$success = '';
$generated_key = '';

// Admin şifre kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_authenticated'] = true;
        } else {
            $error = 'Yanlış admin şifresi!';
        }
    }
    
    // Key üretme
    if (isset($_POST['generate_key']) && isset($_SESSION['admin_authenticated'])) {
        $new_key = strtoupper(bin2hex(random_bytes(8)));
        
        try {
            $stmt = $pdo->prepare("INSERT INTO license_keys (key_code, created_at, is_active) VALUES (?, NOW(), 1)");
            $stmt->execute([$new_key]);
            $generated_key = $new_key;
            $success = 'Yeni lisans anahtarı oluşturuldu!';
        } catch(PDOException $e) {
            $error = 'Hata: ' . $e->getMessage();
        }
    }
}

// Key listesi
$keys = [];
if (isset($_SESSION['admin_authenticated'])) {
    try {
        $stmt = $pdo->query("SELECT * FROM license_keys ORDER BY created_at DESC LIMIT 50");
        $keys = $stmt->fetchAll();
    } catch(PDOException $e) {
        $error = 'Hata: ' . $e->getMessage();
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: keygenerator.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Key Generator - <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0e17 0%, #1a1f2e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #e5e7eb;
            padding: 40px 20px;
        }
        
        .container { max-width: 900px; width: 100%; }
        
        .card {
            background: rgba(19, 24, 33, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #00ff88, #0066ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .subtitle { color: #9ca3af; margin-bottom: 30px; }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-error {
            background: rgba(255, 51, 102, 0.1);
            border: 1px solid rgba(255, 51, 102, 0.3);
            color: #ff3366;
        }
        
        .alert-success {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.3);
            color: #00ff88;
        }
        
        .form-group { margin-bottom: 24px; }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #9ca3af;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        input[type="password"], input[type="text"] {
            width: 100%;
            padding: 14px 18px;
            background: rgba(10, 14, 23, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #e5e7eb;
            font-size: 16px;
            font-family: 'IBM Plex Mono', monospace;
            transition: all 0.3s;
        }
        
        input:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #00ff88, #00cc6a);
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
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 255, 136, 0.4);
        }
        
        .key-display {
            background: rgba(10, 14, 23, 0.8);
            border: 2px solid #00ff88;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .key-code {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 28px;
            font-weight: 700;
            color: #00ff88;
            letter-spacing: 3px;
            margin: 10px 0;
            user-select: all;
        }
        
        .key-list { margin-top: 40px; }
        
        .key-list h3 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #e5e7eb;
        }
        
        .key-item {
            background: rgba(10, 14, 23, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .key-item-code {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 16px;
            font-weight: 600;
            color: #00ff88;
        }
        
        .key-item-date {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 4px;
        }
        
        .badge {
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .badge-active {
            background: rgba(0, 255, 136, 0.2);
            color: #00ff88;
        }
        
        .logout-link {
            display: inline-block;
            margin-top: 30px;
            color: #ff3366;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .logout-link:hover { text-decoration: underline; }
        
        .copy-btn {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid #00ff88;
            color: #00ff88;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .copy-btn:hover {
            background: rgba(0, 255, 136, 0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>🔑 Key Generator</h1>
            <p class="subtitle">Admin Panel - Lisans Anahtarı Yönetimi</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if (!isset($_SESSION['admin_authenticated'])): ?>
                <!-- Admin Şifre Girişi -->
                <form method="POST">
                    <div class="form-group">
                        <label>Admin Şifresi</label>
                        <input type="password" name="admin_password" required placeholder="Admin şifrenizi girin" autocomplete="off">
                    </div>
                    <button type="submit" class="btn">🔓 Giriş Yap</button>
                </form>
            <?php else: ?>
                <!-- Key Üretme -->
                <?php if ($generated_key): ?>
                    <div class="key-display">
                        <div style="color: #9ca3af; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">✨ Yeni Lisans Anahtarı</div>
                        <div class="key-code" id="generatedKey"><?php echo $generated_key; ?></div>
                        <button class="copy-btn" onclick="copyKey()">📋 Kopyala</button>
                        <div style="color: #9ca3af; font-size: 12px; margin-top: 10px;">⚠️ Bu anahtarı güvenli bir yerde saklayın!</div>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="generate_key" value="1">
                    <button type="submit" class="btn">⚡ Yeni Key Üret</button>
                </form>
                
                <!-- Key Listesi -->
                <?php if (!empty($keys)): ?>
                    <div class="key-list">
                        <h3>📋 Oluşturulan Lisans Anahtarları (<?php echo count($keys); ?>)</h3>
                        <?php foreach ($keys as $key): ?>
                            <div class="key-item">
                                <div>
                                    <div class="key-item-code"><?php echo htmlspecialchars($key['key_code']); ?></div>
                                    <div class="key-item-date">
                                        📅 Oluşturulma: <?php echo date('d.m.Y H:i', strtotime($key['created_at'])); ?>
                                        <?php if ($key['last_used']): ?>
                                            | 🕐 Son Kullanım: <?php echo date('d.m.Y H:i', strtotime($key['last_used'])); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge badge-active">AKTİF</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="text-align: center;">
                    <a href="?logout=1" class="logout-link">🚪 Çıkış Yap</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function copyKey() {
            const keyText = document.getElementById('generatedKey').textContent;
            navigator.clipboard.writeText(keyText).then(() => {
                alert('✅ Key kopyalandı: ' + keyText);
            });
        }
    </script>
</body>
</html>
