<?php
declare(strict_types=1);

/*
 * Code d'installation.
 * Tant que l'assistant de configuration n'est pas terminé, la page parametres.php serait ouverte à
 * quiconque connaît l'adresse. Pour l'éviter, l'installateur crée par FTP un fichier
 * inc/code_installation.txt contenant un code de son choix (8 caractères minimum), puis saisit ce
 * code dans l'assistant. Le fichier est supprimé automatiquement à la fin de la configuration.
 */

const INSTALL_CODE_FILE = __DIR__ . '/code_installation.txt';
const SETUP_COOKIE      = 'recharges_ev_setup';

function install_code(): ?string
{
    if (!is_file(INSTALL_CODE_FILE)) {
        return null;
    }
    $c = trim((string) @file_get_contents(INSTALL_CODE_FILE));
    return $c === '' ? null : $c;
}

function install_code_valid(?string $code): bool
{
    return $code !== null && mb_strlen($code) >= 8;
}

function setup_key(string $code): string
{
    return hash('sha256', 'recharges-ev-setup|' . $code);
}

function setup_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => cookie_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

/** Vrai si le code d'installation a été saisi correctement dans les 2 dernières heures. */
function setup_authorized(): bool
{
    $code = install_code();
    if (!install_code_valid($code)) {
        return false;
    }
    $c = $_COOKIE[SETUP_COOKIE] ?? '';
    if (!is_string($c) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $c, $m)) {
        return false;
    }
    return (int) $m[1] >= time() && hash_equals(hash_hmac('sha256', 'setup|' . $m[1], setup_key($code)), $m[2]);
}

function setup_authorize(): void
{
    $code = (string) install_code();
    $exp  = time() + 7200;
    setcookie(SETUP_COOKIE, $exp . '.' . hash_hmac('sha256', 'setup|' . $exp, setup_key($code)), setup_cookie_options($exp));
}

/** Fin de l'assistant : supprime le fichier-code (si PHP le peut) et le cookie. Renvoie vrai si le fichier a disparu. */
function setup_finish(): bool
{
    @unlink(INSTALL_CODE_FILE);
    setcookie(SETUP_COOKIE, '', setup_cookie_options(time() - 3600));
    return !is_file(INSTALL_CODE_FILE);
}
