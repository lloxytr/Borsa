<?php
// index.php - Giris Ekrani
require_once 'config.php';

$error = '';

// Zaten giris yapmissa dashboard'a yonlendir
if (isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Key kontrolu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $key = strtoupper(trim(str_replace('-', '', $_POST['license_key'])));
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM license_keys WHERE key_code = ? AND is_active = 1");
        $stmt->execute(array($key));
        $key_data = $stmt->fetch();
        
        if ($key_data) {
            // Key gecerli - Session baslat
            $_SESSION['user_authenticated'] = true;
            $_SESSION['license_key'] = $key;
            $_SESSION['user_id'] = $key_data['id'];
            
            // Son kullanim zamanini guncelle
            $update = $pdo->prepare("UPDATE license_keys SET last_used = NOW() WHERE id = ?");
            $update->execute(array($key_data['id']));
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Gecersiz veya pasif lisans anahtari!';
        }
    } catch(PDOException $e) {
        $error = 'Bir hata olustu. Lutfen tekrar deneyin.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giris - <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
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
            position: relative;
            overflow: hidden;
        }
        
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            opacity: 0.3;
        }
        
        .bg-circle {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            animation: float 20s infinite ease-in-out;
        }
        
        .circle-1 {
            width: 400px;
            height: 400px;
            background: #00ff88;
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }
        
        .circle-2 {
            width: 300px;
            height: 300px;
            background: #0066ff;
            bottom: -150px;
            right: -150px;
            animation-delay: 7s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(50px, -50px) scale(1.1); }
            66% { transform: translate(-30px, 30px) scale(0.9); }
        }
        
        .login-container {
            position: relative;
            z-index: 10;
            max-width: 480px;
            width: 100%;
            padding: 0 20px;
        }
        
        .login-card {
            background: rgba(19, 24, 33, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.6);
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #00ff88, #0066ff);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            color: #000;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        h1 {
            font-size: 36px;
            font-weight: 900;
            text-align: center;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #00ff88, #0066ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            text-align: center;
            color: #9ca3af;
            font-size: 15px;
            margin-bottom: 40px;
        }
        
        .error-message {
            background: rgba(255, 51, 102, 0.1);
            border: 1px solid rgba(255, 51, 102, 0.3);
            border-radius: 12px;
            padding: 16px 20px;
            color: #ff3366;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 24px;
            text-align: center;
            animation: shake 0.4s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #9ca3af;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 16px 20px;
            background: rgba(10, 14, 23, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #e5e7eb;
            font-size: 18px;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-align: center;
            transition: all 0.3s;
        }
        
        input:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 4px rgba(0, 255, 136, 0.1);
        }
        
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #00ff88, #00cc6a);
            border: none;
            border-radius: 12px;
            color: #000;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(0, 255, 136, 0.4);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .footer-text {
            text-align: center;
            margin-top: 30px;
            font-size: 13px;
            color: #9ca3af;
        }
        
        .footer-text a {
            color: #00ff88;
            text-decoration: none;
            font-weight: 600;
        }
        
        .footer-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    
    <div class="bg-animation">
        <div class="bg-circle circle-1"></div>
        <div class="bg-circle circle-2"></div>
    </div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <div class="logo-icon">Q</div>
                <h1><?php echo SITE_NAME; ?></h1>
                <p class="subtitle">Yapay Zeka Destekli Trading Platformu</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="license_key">Lisans Anahtari</label>
                    <input 
                        type="text" 
                        id="license_key" 
                        name="license_key" 
                        placeholder="XXXXXXXXXXXXXXXX"
                        required
                        maxlength="19"
                        autocomplete="off"
                    >
                </div>
                
                <button type="submit" class="btn-submit">Sisteme Giris Yap</button>
            </form>
            
            <div class="footer-text">
                Lisans anahtariniz yok mu? <a href="mailto:support@quantumtrade.ai">Destek ekibi</a>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('license_key').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            let formatted = value.match(/.{1,4}/g);
            if (formatted) {
                e.target.value = formatted.join('-');
            } else {
                e.target.value = value;
            }
        });
    </script>
</body>
</html>
