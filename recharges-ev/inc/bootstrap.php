<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Brussels');

require __DIR__ . '/settings.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/version.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/install.php';
require __DIR__ . '/updater.php';

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

if (PHP_SAPI !== 'cli') {
    // HTTPS obligatoire (la géolocalisation ne fonctionne pas sans).
    if ((local_config()['force_https'] ?? true) && !is_https()) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) {
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        } else {
            http_response_code(400);
        }
        exit;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: geolocation=(self)');
    header('Cache-Control: no-store');

    set_exception_handler(function (Throwable $e): void {
        error_log((string) $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Erreur</title><body style="font-family:system-ui;padding:24px">'
            . '<h1>Erreur technique</h1><p>Une erreur est survenue. Réessayez dans un instant.</p>';
        // Diagnostic : option « Afficher le détail des erreurs » de parametres.php (à désactiver ensuite).
        if (!empty((local_config() ?? [])['debug'])) {
            echo '<pre style="white-space:pre-wrap;background:#fee;padding:12px">'
                . htmlspecialchars(get_class($e) . ': ' . $e->getMessage()) . '</pre>';
        }
    });
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require __DIR__ . '/calc.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/view.php';
