<?php
// logout.php - Guvenli Cikis Sistemi
session_start();

// Tum session verilerini temizle
$_SESSION = array();

// Session cookie'sini sil
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Session'i yok et
session_destroy();

// Giris sayfasina yonlendir
header('Location: index.php');
exit;
?>
