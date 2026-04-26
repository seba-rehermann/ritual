<?php
/**
 * Auth compartido para el panel del búnker.
 * Cargado por admin_server.php y mindustry_admin.php.
 *
 * 🔐 Cambiar contraseña:
 *   docker exec portal_publico php -r 'echo password_hash("NUEVO_PASS", PASSWORD_BCRYPT);'
 *   Pegar el resultado debajo en ADMIN_PASSWORD_HASH.
 *   Password inicial: bunker2026
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ADMIN_PASSWORD_HASH = '$2y$10$jHhlBiSTZTW1QKJLrE72Deh.SUTrCcdFbBibTOLCTkiakwZNRe1Yq';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/** Escapa HTML. */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Devuelve true si la sesión actual está autenticada. */
function is_authed(): bool { return !empty($_SESSION['admin_ok']); }

/** Token CSRF de la sesión. */
function csrf_token(): string { return $_SESSION['csrf']; }

/**
 * Procesa login/logout si el POST corresponde. Redirige y termina si procesa.
 * $self: URL relativa de esta misma página (ej: "admin_server.php").
 */
function auth_handle_login_logout(string $self): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $op = $_POST['op'] ?? '';

    if ($op === 'login') {
        if (password_verify($_POST['password'] ?? '', ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            header("Location: $self"); exit;
        }
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Contraseña incorrecta.'];
        header("Location: $self"); exit;
    }

    if ($op === 'logout') {
        $_SESSION = []; session_destroy();
        header("Location: $self"); exit;
    }
}

/** Aborta con 400 si el token CSRF no coincide. */
function auth_require_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('CSRF inválido.');
    }
}

/** Toma y limpia el flash message. */
function flash_pop(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function flash_set(string $type, string $msg, array $extra = []): void {
    $_SESSION['flash'] = array_merge(['type' => $type, 'msg' => $msg], $extra);
}
