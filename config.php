<?php
// config.php - Ana Konfigürasyon (PHP 8.1 uyumlu)
declare(strict_types=1);

/**
 * Ortam
 * production / development
 */
if (!defined('APP_ENV')) {
    define('APP_ENV', 'production');
}

/**
 * Timezone
 */
date_default_timezone_set('Europe/Istanbul');

/**
 * Hata Ayarları
 */
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL); // log için açık, ekrana basma kapalı
}

/**
 * Site Sabitleri
 */
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'FezliTrade AI');
}

/**
 * Dashboard / Admin Secret (tek yerden yönet)
 * NOT: Buradaki secret'ı değiştir!
 */
if (!defined('DASHBOARD_URL')) {
    define('DASHBOARD_URL', 'https://baralmotor.online/dashboard.php');
}
if (!defined('NOTIFY_ADMIN_SECRET')) {
    define('NOTIFY_ADMIN_SECRET', 'CHANGE_ME_SUPER_SECRET_123');
}

/**
 * Session (cron/cli scriptlerde NO_SESSION true gelir)
 */
if (!defined('NO_SESSION') || NO_SESSION !== true) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Veritabanı Bilgileri (KENDİ BİLGİLERİNİ GİR)
 */
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'baraltyz_tabani');
if (!defined('DB_USER')) define('DB_USER', 'baraltyz_user');
if (!defined('DB_PASS')) define('DB_PASS', 'Sifre1234.'); // <<< KENDİ ŞİFREN

/**
 * PDO bağlantısı
 */
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    error_log("DB connection error: " . $e->getMessage());
    http_response_code(500);
    exit('Sunucu hatası: Veritabanına bağlanılamadı.');
}
