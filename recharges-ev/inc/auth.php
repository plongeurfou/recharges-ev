<?php
declare(strict_types=1);

/*
 * Authentification par mot de passe unique.
 * Le cookie est signé (HMAC) : pas de session serveur à conserver,
 * donc la connexion reste valable longtemps sur le GSM.
 * Changer le mot de passe ou le « secret » déconnecte tous les appareils.
 */

const AUTH_COOKIE = 'recharges_ev_auth';

function auth_configured(): bool
{
    $c = config();
    return $c['password_hash'] !== '' && $c['secret'] !== '';
}

function auth_hmac(string $data): string
{
    $c = config();
    return hash_hmac('sha256', $data, $c['secret'] . '|' . $c['password_hash']);
}

function cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir === '' ? '/' : $dir . '/';
}

/** Expiration (timestamp) du cookie valide, ou null si non connecté. */
function auth_expiry(): ?int
{
    if (!auth_configured()) {
        return null;
    }
    $c = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($c) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $c, $m)) {
        return null;
    }
    if ((int) $m[1] < time() || !hash_equals(auth_hmac('auth|' . $m[1]), $m[2])) {
        return null;
    }
    return (int) $m[1];
}

function is_logged_in(): bool
{
    return auth_expiry() !== null;
}

function login_user(): void
{
    $exp = time() + 86400 * (int) config()['session_days'];
    setcookie(AUTH_COOKIE, $exp . '.' . auth_hmac('auth|' . $exp), [
        'expires'  => $exp,
        'path'     => cookie_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function logout_user(): void
{
    setcookie(AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => cookie_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

function csrf_token(): string
{
    return auth_hmac('csrf|' . (auth_expiry() ?? 0));
}

function check_csrf(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Requête invalide. Rechargez la page et réessayez.');
    }
}

/* Limitation des tentatives : 5 échecs en 15 minutes par adresse IP. */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 45);
}

function login_blocked(PDO $db): bool
{
    $limit = date('Y-m-d H:i:s', time() - 900);
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$limit]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $st->execute([client_ip(), $limit]);
    return (int) $st->fetchColumn() >= 5;
}

function login_failed(PDO $db): void
{
    $db->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')
       ->execute([client_ip(), date('Y-m-d H:i:s')]);
}

function login_cleared(PDO $db): void
{
    $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}
