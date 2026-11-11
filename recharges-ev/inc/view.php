<?php
declare(strict_types=1);

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : '0');
}

function page_head(string $title, string $class = ''): void
{
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0f766e">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
</head>
<body class="<?= e($class) ?>">
<?php
}

/** Bandeau « version disponible » (HTML déjà échappé), vide s'il n'y a rien à annoncer. */
function update_banner_html(array $info): string
{
    if (!$info['available'] || $info['snoozed']) {
        return '';
    }
    return '<div class="banner update" role="status"><strong>Version ' . e($info['latest']['version']) . ' disponible</strong>'
         . ' (vous utilisez la ' . e(APP_VERSION) . '). <a href="maj.php">Voir les nouveautés</a></div>';
}

/** Bandeaux de mise à jour et de nouveautés ; actualise la vérification en arrière-plan si elle est ancienne. */
function render_banners(): void
{
    echo '<div id="update-slot">' . update_banner_html(update_info()) . "</div>\n";

    $news = news_pending();
    if ($news) {
        $back = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        echo '<div class="banner news" role="status"><span><strong>Nouveautés de la version ' . e(APP_VERSION) . '</strong> · '
           . '<a href="maj.php#nouveautes">Voir</a></span>'
           . '<form method="post" action="maj.php" class="inline">'
           . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
           . '<input type="hidden" name="action" value="ack">'
           . '<input type="hidden" name="back" value="' . e($back) . '">'
           . '<button type="submit" class="linkbtn">Compris</button></form></div>' . "\n";
    }

    if (update_configured() && update_is_stale()) {
        echo '<script src="' . e(asset('assets/update.js')) . '" defer data-update-refresh data-csrf="' . e(csrf_token()) . '"></script>' . "\n";
    }
}

/** Page affichée quand la base configurée est injoignable. */
function render_db_error(): void
{
    if (!headers_sent()) {
        http_response_code(503);
    }
    page_head('Base de données injoignable — Recharges EV', 'narrow');
    ?>
<main class="wrap">
  <h1>Recharges EV</h1>
  <section class="card">
    <p class="msg bad" role="alert">Connexion à la base de données impossible.</p>
    <?php if (!empty(local_config()['debug']) && !empty($GLOBALS['db_error'])): ?>
      <p><code><?= e((string) $GLOBALS['db_error']) ?></code></p>
    <?php endif; ?>
    <p>Réessayez dans un instant. Si le problème persiste, vérifiez la base dans le panneau Alwaysdata, puis les
       paramètres de connexion dans <code>inc/local.php</code>. Pour relancer l'assistant de configuration, supprimez
       ce fichier (vos données restent dans la base).</p>
  </section>
</main>
<?php
    page_foot();
}

function page_foot(string $script = ''): void
{
    if ($script !== '') {
        echo '<script src="' . e(asset($script)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}
