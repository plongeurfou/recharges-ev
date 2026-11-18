<?php
declare(strict_types=1);

/*
 * Installeur de Recharges EV — un seul fichier.
 *
 * 1. Déposez ce fichier (installer.php) sur votre hébergement, dans le dossier du site (ex. www/), avec un fichier
 *    texte « installer_code.txt » contenant un code de votre choix (8 caractères minimum).
 * 2. Ouvrez https://votre-site/installer.php, saisissez le code : l'application est téléchargée depuis le dépôt
 *    officiel, sa signature est vérifiée, puis elle est installée dans le dossier recharges-ev/.
 * 3. L'installeur se supprime ensuite et vous mène à l'assistant de configuration.
 */

const INSTALLER_MANIFEST_URL = '{{MANIFEST_URL}}';
const INSTALLER_PUBLIC_KEY   = '{{PUBLIC_KEY}}';
const INSTALLER_VERSION      = '{{VERSION}}';
const INSTALLER_TARGET       = 'recharges-ev';

{{PACKAGE_LIB}}

/* ------------------------------------------------------------------ */

function inst_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inst_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function inst_page(string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Installation — Recharges EV</title><style>'
       . 'body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:0 auto;padding:20px 16px;color:#16221f;background:#f4f7f6}'
       . 'h1{font-size:1.4rem}.c{background:#fff;border:1px solid #d6e0dd;border-radius:14px;padding:16px;margin:0 0 14px}'
       . 'input[type=password],input[type=text]{width:100%;box-sizing:border-box;font-size:1rem;padding:10px;border:1.5px solid #b9c8c4;border-radius:10px}'
       . 'button,a.b{display:inline-block;background:#0f766e;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-size:1rem;text-decoration:none;cursor:pointer}'
       . '.ko{color:#b91c1c}.ok{color:#166534}code{background:#eef3f1;padding:1px 5px;border-radius:5px;overflow-wrap:anywhere}li{margin:4px 0}'
       . '</style></head><body><h1>Installation de Recharges EV</h1>' . $body . '</body></html>';
    exit;
}

$dir      = __DIR__;
$codeFile = $dir . '/installer_code.txt';
$failFile = $dir . '/installer_tentatives.txt';
$target   = $dir . '/' . INSTALLER_TARGET;

if (!inst_https()) {
    inst_page('<div class="c"><p class="ko">Cette page doit être ouverte en HTTPS (adresse commençant par https://) : le code ne doit pas circuler en clair.</p></div>', 403);
}

/* Contrôles de l'environnement */
$problems = [];
if (version_compare(PHP_VERSION, '8.0.0', '<')) { $problems[] = 'PHP 8.0 ou plus récent est requis (version actuelle : ' . PHP_VERSION . ').'; }
foreach (['zip', 'sodium', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) { $problems[] = 'Extension PHP « ' . $ext . ' » absente.'; }
}
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) { $problems[] = 'Ni cURL ni allow_url_fopen ne sont disponibles : téléchargement impossible.'; }
if (!is_writable($dir)) { $problems[] = 'Le dossier du site n\'est pas accessible en écriture pour PHP.'; }
if (is_file($target . '/inc/local.php')) { $problems[] = 'Une installation existe déjà dans ' . INSTALLER_TARGET . '/. Supprimez installer.php, ou utilisez la mise à jour intégrée à l\'application.'; }
elseif (is_dir($target) && count(array_diff(scandir($target) ?: [], ['.', '..'])) > 0) { $problems[] = 'Le dossier ' . INSTALLER_TARGET . '/ existe déjà et n\'est pas vide.'; }
if ($problems) {
    inst_page('<div class="c"><p class="ko">Installation impossible :</p><ul><li>' . implode('</li><li>', array_map('inst_h', $problems)) . '</li></ul></div>', 500);
}

/* Code de l'installeur */
$code = is_file($codeFile) ? trim((string) @file_get_contents($codeFile)) : '';
if (mb_strlen($code) < 8) {
    inst_page('<div class="c"><p>Avant de commencer, prouvez que vous avez accès au site :</p><ol>'
        . '<li>Créez par FTP (ou dans le gestionnaire de fichiers de l\'hébergeur), à côté de ce fichier, un fichier <code>installer_code.txt</code> '
        . 'contenant un code de votre choix (8 caractères minimum).</li><li>Rechargez cette page.</li></ol></div>', 403);
}
$fails = (int) @file_get_contents($failFile);
if ($fails >= 10) {
    inst_page('<div class="c"><p class="ko">Trop de codes erronés. Supprimez par FTP le fichier <code>installer_tentatives.txt</code> pour réessayer.</p></div>', 429);
}

$form = '<div class="c"><p>Version à installer : la dernière version publiée sur le dépôt officiel, dont la signature sera vérifiée.</p>'
      . '<form method="post"><p><label>Code du fichier installer_code.txt<br><input type="password" name="code" required autocomplete="off" autofocus></label></p>'
      . '<p><button type="submit">Installer</button></p></form>'
      . '<p><small>Ce même code servira ensuite pour l\'assistant de configuration (fichier inc/code_installation.txt).</small></p></div>';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inst_page($form);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $origin !== 'null' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) !== 0) {
    inst_page('<div class="c"><p class="ko">Requête invalide.</p></div>', 400);
}
if (!hash_equals($code, trim((string) ($_POST['code'] ?? '')))) {
    @file_put_contents($failFile, (string) ($fails + 1));
    sleep(1);
    inst_page('<div class="c"><p class="ko">Code incorrect.</p></div>' . $form, 403);
}

@set_time_limit(180);
$work = $dir . '/installer_tmp';
try {
    pkg_rmdir($work);
    if (!@mkdir($work, 0755) && !is_dir($work)) {
        throw new RuntimeException('Création du dossier temporaire impossible.');
    }
    $ua = 'RechargesEV-installer/' . INSTALLER_VERSION;
    $m  = pkg_verify_manifest(pkg_http_get(INSTALLER_MANIFEST_URL, 65536, $ua), INSTALLER_PUBLIC_KEY);
    if (!pkg_download_url_ok($m['download_url'], INSTALLER_MANIFEST_URL)) {
        throw new RuntimeException('Adresse de téléchargement étrangère au dépôt : refusée.');
    }
    if ($m['min_php'] !== '' && version_compare(PHP_VERSION, $m['min_php'], '<')) {
        throw new RuntimeException('Cette version demande PHP ' . $m['min_php'] . ' ou plus récent.');
    }
    $zipPath = $work . '/paquet.zip';
    pkg_http_get($m['download_url'], PKG_MAX_ZIP, $ua, $zipPath, 90);
    $files = pkg_inspect_zip($zipPath, $m['sha256']);
    pkg_stage($zipPath, $files, $work . '/stage');
    pkg_check_syntax($work . '/stage', $files);
    if (pkg_staged_version($work . '/stage') !== $m['version']) {
        throw new RuntimeException('La version contenue dans le paquet ne correspond pas à celle annoncée.');
    }
    if (!is_dir($target) && !@mkdir($target, 0755)) {
        throw new RuntimeException('Création du dossier ' . INSTALLER_TARGET . '/ impossible.');
    }
    pkg_apply($work . '/stage', $target, $files);
    if (@file_put_contents($target . '/inc/code_installation.txt', $code . "\n") === false) {
        throw new RuntimeException('Écriture du code d\'installation impossible.');
    }
    @chmod($target . '/inc/code_installation.txt', 0640);
} catch (Throwable $e) {
    pkg_rmdir($work);
    inst_page('<div class="c"><p class="ko">L\'installation a échoué : ' . inst_h($e->getMessage()) . '</p><p>Rien n\'a été configuré. Vous pouvez réessayer, '
        . 'ou installer manuellement depuis la page des versions du dépôt.</p></div>', 500);
}

/* Nettoyage */
pkg_rmdir($work);
@unlink($codeFile);
@unlink($failFile);
$selfGone = @unlink(__FILE__);
$next = INSTALLER_TARGET . '/';
inst_page('<div class="c"><p class="ok"><strong>Version ' . inst_h($m['version']) . ' installée.</strong></p>'
    . '<p>' . ($selfGone ? 'L\'installeur a été supprimé.' : '<span class="ko">Supprimez maintenant installer.php par FTP.</span>') . '</p>'
    . '<p><a class="b" href="' . inst_h($next) . '">Continuer vers l\'assistant de configuration</a></p>'
    . '<p><small>Le code que vous venez de saisir sert pour la première étape de l\'assistant.</small></p></div>');
