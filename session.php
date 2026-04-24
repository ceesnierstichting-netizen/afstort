<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('AFSTORTSESSID');
    session_start();
}

const AFSTORT_IDLE_TIMEOUT_SECONDS = 3600;

function afstort_destroy_current_session() {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

$isAuthenticatedSession = !empty($_SESSION['username']) && !empty($_SESSION['twofa_verified']);

if ($isAuthenticatedSession) {
    $now = time();
    $lastActivity = (int)($_SESSION['LAST_ACTIVITY'] ?? $now);

    if (($now - $lastActivity) > AFSTORT_IDLE_TIMEOUT_SECONDS) {
        $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $isJsonRequest = isset($_GET['action'])
            || in_array($currentScript, ['saveRitten.php', 'saveChauffeurs.php'], true);

        afstort_destroy_current_session();

        if ($isJsonRequest) {
            header("HTTP/1.1 401 Unauthorized");
            header("Content-Type: application/json");
            echo json_encode(["status" => "error", "message" => "Je sessie is verlopen. Log opnieuw in."]);
            exit();
        }

        if ($currentScript !== 'login.php' && $currentScript !== 'logout.php') {
            header("Location: login.php?timeout=1");
            exit();
        }
    } else {
        $_SESSION['LAST_ACTIVITY'] = $now;
    }
}

?>
