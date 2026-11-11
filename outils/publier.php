<?php
declare(strict_types=1);

/*
 * Prépare une publication : zip de l'application + latest.json signé.
 *   php outils/publier.php --cle=cle_privee_recharges_ev.key [--sortie=dist]
 * Lit la version dans recharges-ev/inc/version.php et ses notes dans recharges-ev/inc/changelog.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('sodium_crypto_sign_detached') || !class_exists('ZipArchive')) { fwrite(STDERR, "Extensions PHP sodium et zip requises.\n"); exit(1); }

function fail(string $m): never { fwrite(STDERR, "ERREUR : $m\n"); exit(1); }

$opt  = getopt('', ['cle:', 'sortie::']);
$keyF = $opt['cle'] ?? fail('indiquez --cle=<fichier clé privée>');
$out  = rtrim((string) ($opt['sortie'] ?? 'dist'), '/');

$root = dirname(__DIR__) . '/recharges-ev';
if (!is_dir($root)) fail('dossier recharges-ev introuvable');

/* version.php définit des constantes : on lit le texte plutôt que de l'exécuter. */
$vsrc = (string) file_get_contents($root . '/inc/version.php');
$grab = function (string $name) use ($vsrc): string {
    return preg_match("/const\\s+$name\\s*=\\s*'([^']*)'/", $vsrc, $m) ? $m[1] : fail("constante $name introuvable");
};
$version = $grab('APP_VERSION');
$url     = $grab('UPDATE_MANIFEST_URL');
$pubConf = $grab('UPDATE_PUBLIC_KEY');

if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $version)) fail("APP_VERSION invalide : $version");
if (!preg_match('#^https://github\.com/([^/]+)/([^/]+)/releases/latest/download/latest\.json$#', $url, $m) || $m[1] === 'OWNER' || $m[2] === 'REPO') {
    fail('UPDATE_MANIFEST_URL doit être https://github.com/<compte>/<dépôt>/releases/latest/download/latest.json');
}
[$owner, $repo] = [$m[1], $m[2]];

$chlog = require $root . '/inc/changelog.php';
$entry = $chlog[$version] ?? fail("aucune entrée $version dans inc/changelog.php");

$sk = base64_decode(trim((string) @file_get_contents($keyF)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) fail('clé privée illisible ou invalide');
$pk = base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk));
if ($pubConf === '' || !hash_equals($pubConf, $pk)) {
    fail("la clé privée ne correspond pas à UPDATE_PUBLIC_KEY de inc/version.php.\nClé publique de cette clé privée : $pk");
}

if (!is_dir($out) && !mkdir($out, 0755, true)) fail("impossible de créer $out");
$zipName = "recharges-ev-$version.zip";
$zipPath = "$out/$zipName";
@unlink($zipPath);

$exclude = ['inc/local.php', 'inc/config.php', 'inc/code_installation.txt'];
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) fail('création du zip impossible');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    $base = basename($rel);
    if (in_array($rel, $exclude, true) || in_array($base, ['.DS_Store', 'Thumbs.db'], true) || str_ends_with($base, '.key')) continue;
    $files[$rel] = $f->getPathname();
}
ksort($files);
foreach ($files as $rel => $path) {
    $zip->addFile($path, "recharges-ev/$rel");
    $zip->setMtimeName("recharges-ev/$rel", 1700000000);
}
$zip->close();

$sha  = hash_file('sha256', $zipPath);
$size = filesize($zipPath);

$payload = json_encode([
    'app'          => 'recharges-ev',
    'version'      => $version,
    'date'         => (string) $entry['date'],
    'titre'        => (string) $entry['titre'],
    'notes'        => array_values($entry['notes']),
    'min_php'      => '8.0',
    'download_url' => "https://github.com/$owner/$repo/releases/download/v$version/$zipName",
    'sha256'       => $sha,
    'size'         => $size,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$sig   = base64_encode(sodium_crypto_sign_detached($payload, $sk));
$latest = json_encode(['payload' => $payload, 'signature' => $sig], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents("$out/latest.json", $latest . "\n");

/* Contrôle final */
$chk = json_decode((string) file_get_contents("$out/latest.json"), true);
if (!sodium_crypto_sign_verify_detached(base64_decode($chk['signature']), $chk['payload'], base64_decode($pk))) fail('la signature ne se vérifie pas');

echo "OK — version $version\n";
echo "  $zipPath  (" . count($files) . " fichiers, $size octets)\n  $out/latest.json  (signé)\n  SHA-256 : $sha\n\n";
echo "À faire sur GitHub (https://github.com/$owner/$repo/releases/new) :\n";
echo "  1. Tag : v$version (créer à la publication)\n";
echo "  2. Titre : {$entry['titre']}\n";
echo "  3. Glisser les DEUX fichiers : $zipName et latest.json\n";
echo "  4. Publier (« Set as the latest release » coché)\n";
