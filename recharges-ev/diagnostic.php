<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/*
 * Page de diagnostic : vérifie que l'hébergement convient et que l'installation est saine.
 * Accessible une fois connecté, ou pendant l'assistant de configuration après saisie du code d'installation.
 */
$state = app_state();
$ready = $state === 'ready';
if ($ready) {
    require_login();
} elseif (!setup_authorized()) {
    http_response_code(403);
    page_head('Diagnostic — Recharges EV', 'narrow');
    echo '<main class="wrap"><h1>Diagnostic</h1><section class="card"><p class="msg bad">Accès refusé. Saisissez d\'abord le code d\'installation dans <a href="parametres.php">parametres.php</a>.</p></section></main>';
    page_foot();
    exit;
}

$rows = [];
$add = function (string $label, string $status, string $detail = '') use (&$rows): void {
    $rows[] = [$label, $status, $detail];
};

/* PHP et extensions */
$add('Version de PHP', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'ko', PHP_VERSION . ' (8.0 minimum)');
foreach ([
    'pdo_mysql' => ['ko', 'indispensable'],
    'mbstring'  => ['ko', 'indispensable'],
    'json'      => ['ko', 'indispensable'],
    'sodium'    => ['warn', 'nécessaire pour vérifier les mises à jour'],
] as $ext => [$lvl, $why]) {
    $add('Extension ' . $ext, extension_loaded($ext) ? 'ok' : $lvl, extension_loaded($ext) ? 'présente' : 'absente — ' . $why);
}
$net = function_exists('curl_init') || ini_get('allow_url_fopen');
$add('Accès réseau sortant (cURL / allow_url_fopen)', $net ? 'ok' : 'warn', $net ? 'disponible' : 'absent — la détection des mises à jour ne fonctionnera pas');
$add('Connexion sécurisée (HTTPS)', is_https() ? 'ok' : 'warn', is_https() ? 'active' : 'non utilisée — activez HTTPS sur l\'hébergement');

/* Droits d'écriture */
$add('Écriture dans inc/', is_writable(__DIR__ . '/inc') ? 'ok' : 'warn',
    is_writable(__DIR__ . '/inc') ? 'possible (enregistrement des paramètres)' : 'impossible — les paramètres de connexion ne pourront pas être enregistrés');

/* Protection du dossier inc/ (auto-requête, seulement connecté) */
if ($ready) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $dir  = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $url  = (is_https() ? 'https://' : 'http://') . $host . $dir . '/inc/local.php';
    $code = null;
    $body = '';
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:]+$/', $host) && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
        $r = curl_exec($ch);
        $code = $r === false ? null : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = is_string($r) ? $r : '';
        curl_close($ch);
    }
    if ($code === null) {
        $add('Dossier inc/ inaccessible depuis Internet', 'info', 'vérification impossible automatiquement — essayez d\'ouvrir inc/local.php dans le navigateur : il doit être refusé');
    } elseif (in_array($code, [401, 403, 404], true)) {
        $add('Dossier inc/ inaccessible depuis Internet', 'ok', 'protégé (HTTP ' . $code . ')');
    } elseif ($code === 200 && str_contains($body, "'pass'")) {
        $add('Dossier inc/ inaccessible depuis Internet', 'ko', 'CRITIQUE : le fichier inc/local.php est lisible depuis Internet. Vérifiez le fichier .htaccess de inc/.');
    } else {
        $add('Dossier inc/ inaccessible depuis Internet', 'warn', 'réponse HTTP ' . $code . ' — contrôlez manuellement');
    }
}

/* Base de données */
$pdo = null;
if ($state === 'ready' || $state === 'nosettings') {
    try {
        $pdo = db();
        $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $add('Connexion à la base de données', 'ok', 'MySQL/MariaDB ' . $ver);
    } catch (Throwable $e) {
        $add('Connexion à la base de données', 'ko', db_error_message($e));
    }
} elseif ($state === 'dberror') {
    $add('Connexion à la base de données', 'ko', 'échec de connexion avec les paramètres de inc/local.php');
} else {
    $add('Connexion à la base de données', 'info', 'pas encore configurée');
}
if ($pdo) {
    try {
        $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $need = ['recharges', 'login_attempts', 'settings'];
        $miss = array_values(array_diff($need, $have));
        $add('Tables de l\'application', $miss ? 'ko' : 'ok', $miss ? 'manquantes : ' . implode(', ', $miss) : implode(', ', $need));
    } catch (Throwable $e) {
        $add('Tables de l\'application', 'ko', 'lecture impossible');
    }
    try {
        $pdo->exec('CREATE TABLE _diag_tmp (id INT)');
        $pdo->exec('ALTER TABLE _diag_tmp ADD COLUMN x INT NULL');
        $add('Droits de modification du schéma', 'ok', 'création/modification de tables possibles (migrations automatiques)');
    } catch (Throwable $e) {
        $add('Droits de modification du schéma', 'warn', 'l\'utilisateur de la base ne peut pas créer/modifier de tables : les mises à jour de la base échoueront');
    } finally {
        try { $pdo->exec('DROP TABLE IF EXISTS _diag_tmp'); } catch (Throwable $e) { /* ignoré */ }
    }
    if ($ready) {
        $sv = (int) settings()['schema_version'];
        $add('Version du schéma de la base', $sv === SCHEMA_VERSION ? 'ok' : 'warn', $sv . ' / ' . SCHEMA_VERSION . ' attendue');
    }
}

/* Application */
$add('Version de l\'application', 'info', APP_VERSION);
if (!update_configured()) {
    $add('Vérification des mises à jour', 'info', 'non configurée (adresse du dépôt ou clé publique absente de inc/version.php)');
} elseif (!extension_loaded('sodium')) {
    $add('Vérification des mises à jour', 'warn', 'configurée mais l\'extension sodium manque');
} else {
    $add('Vérification des mises à jour', 'ok', 'configurée');
}
if ($ready) {
    $add('Fichier inc/code_installation.txt', is_file(INSTALL_CODE_FILE) ? 'warn' : 'ok',
        is_file(INSTALL_CODE_FILE) ? 'toujours présent — supprimez-le par FTP' : 'absent (bien)');
}
$lc = local_config() ?? [];
$add('Mode debug', !empty($lc['debug']) ? 'warn' : 'ok', !empty($lc['debug']) ? 'activé — à désactiver en usage normal' : 'désactivé');
$add('Redirection HTTPS forcée', !empty($lc['force_https']) ? 'ok' : 'info', !empty($lc['force_https']) ? 'activée' : 'désactivée');

$labels = ['ok' => 'OK', 'warn' => 'À voir', 'ko' => 'Problème', 'info' => 'Info'];
$bad    = count(array_filter($rows, fn ($r) => $r[1] === 'ko'));
$warn   = count(array_filter($rows, fn ($r) => $r[1] === 'warn'));

page_head('Diagnostic — Recharges EV', 'mid');
?>
<main class="wrap">
  <header class="topbar">
    <h1>Diagnostic</h1>
    <nav>
      <?php if ($ready): ?><a href="admin.php">← Administration</a> · <a href="maj.php">Mises à jour</a> · <a href="parametres.php">Paramètres</a>
      <?php else: ?><a href="parametres.php">← Assistant</a><?php endif; ?>
    </nav>
  </header>
  <?php if ($bad): ?><p class="msg bad" role="alert"><?= $bad ?> problème(s) à corriger.</p>
  <?php elseif ($warn): ?><p class="msg warn"><?= $warn ?> point(s) à vérifier.</p>
  <?php else: ?><p class="msg ok">Tout est en ordre.</p><?php endif; ?>
  <section class="card">
    <table class="diag">
      <tbody>
      <?php foreach ($rows as [$label, $status, $detail]): ?>
        <tr>
          <td><?= e($label) ?></td>
          <td><span class="s s-<?= e($status) ?>"><?= e($labels[$status]) ?></span></td>
          <td class="help"><?= e($detail) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</main>
<?php page_foot(); ?>
