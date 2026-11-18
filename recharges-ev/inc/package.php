<?php
declare(strict_types=1);

/*
 * Outils communs aux mises à jour en un clic et à l'installeur : téléchargement HTTPS, vérification de la
 * signature (Ed25519), contrôle et extraction SÛRE d'un paquet zip.
 *
 * Ce fichier ne dépend d'aucune autre partie de l'application : il est aussi copié dans installer.php.
 * Principes : rien n'est installé sans signature valide (le manifeste signé contient l'empreinte SHA-256 du zip),
 * le zip est inspecté avant toute écriture (pas de chemin « .. », pas de lien symbolique, extensions autorisées,
 * tailles limitées, syntaxe PHP contrôlée), et les fichiers locaux (configuration, code d'installation) ne sont
 * jamais écrasés.
 */

const PKG_ROOT         = 'recharges-ev/';
const PKG_MAX_ZIP      = 8 * 1024 * 1024;      // taille maximale du zip téléchargé
const PKG_MAX_UNPACKED = 30 * 1024 * 1024;     // taille maximale décompressée
const PKG_MAX_FILE     = 5 * 1024 * 1024;
const PKG_MAX_ENTRIES  = 600;
const PKG_EXTENSIONS   = ['php', 'js', 'css', 'png', 'jpg', 'svg', 'ico', 'webmanifest', 'htaccess', 'txt', 'md', 'json', 'html'];

/** Fichiers et dossiers propres à une installation : jamais écrasés ni sauvegardés dans un paquet. */
function pkg_protected(string $rel): bool
{
    static $files = ['inc/local.php', 'inc/config.php', 'inc/code_installation.txt', 'inc/reinitialisation.txt', 'inc/maintenance.lock'];
    return in_array($rel, $files, true)
        || str_starts_with($rel, 'inc/sauvegardes/')
        || str_starts_with($rel, 'inc/maj_tmp/');
}

/** Téléchargement HTTPS (suivi des redirections HTTPS seulement), taille et durée limitées. Écrit dans $toFile si fourni. */
function pkg_http_get(string $url, int $maxBytes, string $ua, ?string $toFile = null, int $timeout = 8): string
{
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Adresse non sécurisée (HTTPS obligatoire).');
    }
    $fh = null;
    if ($toFile !== null && ($fh = @fopen($toFile, 'wb')) === false) {
        throw new RuntimeException('Écriture impossible dans le dossier temporaire.');
    }
    $body = '';
    $size = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_USERAGENT       => $ua,
            CURLOPT_WRITEFUNCTION   => function ($c, string $chunk) use (&$body, &$size, $fh, $maxBytes): int {
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    return 0;
                }
                if ($fh) {
                    fwrite($fh, $chunk);
                } else {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($ok === false) {
            throw new RuntimeException($size > $maxBytes ? 'Réponse trop volumineuse.' : 'Dépôt injoignable (' . ($err !== '' ? $err : 'erreur réseau') . ').');
        }
        if ($code !== 200) {
            throw new RuntimeException('Le dépôt a répondu HTTP ' . $code . '.');
        }
        return $body;
    }
    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('Ni cURL ni allow_url_fopen ne sont disponibles sur cet hébergement.');
    }
    $ctx  = stream_context_create([
        'http' => ['timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 5, 'header' => 'User-Agent: ' . $ua . "\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($data === false || strlen($data) > $maxBytes) {
        if ($fh) {
            fclose($fh);
        }
        throw new RuntimeException('Dépôt injoignable ou réponse trop volumineuse.');
    }
    if ($fh) {
        fwrite($fh, $data);
        fclose($fh);
        return '';
    }
    return $data;
}

/** Vérifie la signature du manifeste {"payload": "<JSON>", "signature": "<base64>"} et valide son contenu. */
function pkg_verify_manifest(string $body, string $publicKeyB64): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('L\'extension PHP « sodium » est absente : impossible de vérifier la signature.');
    }
    $outer = json_decode($body, true);
    if (!is_array($outer) || !is_string($outer['payload'] ?? null) || !is_string($outer['signature'] ?? null)) {
        throw new RuntimeException('Fichier de version illisible.');
    }
    $pub = base64_decode($publicKeyB64, true);
    $sig = base64_decode($outer['signature'], true);
    if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new RuntimeException('Clé publique invalide.');
    }
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($sig, $outer['payload'], $pub)) {
        throw new RuntimeException('Signature invalide : ce fichier de version n\'est pas authentique.');
    }
    $p = json_decode($outer['payload'], true);
    if (!is_array($p) || ($p['app'] ?? '') !== 'recharges-ev') {
        throw new RuntimeException('Fichier de version inattendu.');
    }
    $v = (string) ($p['version'] ?? '');
    $d = (string) ($p['date'] ?? '');
    $u = (string) ($p['download_url'] ?? '');
    $h = (string) ($p['sha256'] ?? '');
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        || !preg_match('/^[a-f0-9]{64}$/', $h) || !str_starts_with($u, 'https://') || filter_var($u, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Fichier de version incomplet ou invalide.');
    }
    $notes = [];
    foreach (array_slice((array) ($p['notes'] ?? []), 0, 30) as $n) {
        if (is_string($n) && $n !== '') {
            $notes[] = mb_substr($n, 0, 300);
        }
    }
    return [
        'version'      => $v,
        'date'         => $d,
        'titre'        => mb_substr((string) ($p['titre'] ?? ''), 0, 120),
        'notes'        => $notes,
        'min_php'      => preg_match('/^\d+\.\d+$/', (string) ($p['min_php'] ?? '')) ? (string) $p['min_php'] : '',
        'download_url' => $u,
        'sha256'       => $h,
        'size'         => max(0, (int) ($p['size'] ?? 0)),
    ];
}

/** Le zip doit provenir du même dépôt que le manifeste (…/releases/download/…). */
function pkg_download_url_ok(string $downloadUrl, string $manifestUrl): bool
{
    $pos = strpos($manifestUrl, '/releases/');
    return $pos !== false && str_starts_with($downloadUrl, substr($manifestUrl, 0, $pos) . '/releases/download/');
}

/**
 * Contrôle un zip téléchargé AVANT toute extraction : empreinte, structure, chemins, types, tailles.
 * Retourne la liste des fichiers à installer (chemin relatif => taille déclarée), sans les fichiers protégés.
 */
function pkg_inspect_zip(string $zipPath, string $expectedSha256): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('L\'extension PHP « zip » est absente.');
    }
    $size = (int) @filesize($zipPath);
    if ($size <= 0 || $size > PKG_MAX_ZIP) {
        throw new RuntimeException('Taille du paquet inattendue.');
    }
    if (!hash_equals($expectedSha256, (string) hash_file('sha256', $zipPath))) {
        throw new RuntimeException('Empreinte SHA-256 du paquet différente de celle annoncée : téléchargement refusé.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Paquet illisible.');
    }
    $n = $zip->numFiles;
    if ($n < 1 || $n > PKG_MAX_ENTRIES) {
        $zip->close();
        throw new RuntimeException('Nombre de fichiers du paquet inattendu.');
    }
    $files = [];
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $st = $zip->statIndex($i);
        $name = (string) ($st['name'] ?? '');
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('/^[A-Za-z]:/', $name) || !str_starts_with($name, PKG_ROOT)) {
            $zip->close();
            throw new RuntimeException('Chemin interdit dans le paquet : ' . mb_substr($name, 0, 80));
        }
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
            $zip->close();
            throw new RuntimeException('Lien symbolique interdit dans le paquet.');
        }
        if (str_ends_with($name, '/')) {
            continue;
        }
        $rel = substr($name, strlen(PKG_ROOT));
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $base = basename($rel);
        if (!in_array($ext, PKG_EXTENSIONS, true) && !($base === '.htaccess')) {
            $zip->close();
            throw new RuntimeException('Type de fichier interdit dans le paquet : ' . mb_substr($rel, 0, 80));
        }
        $fsize = (int) $st['size'];
        $total += $fsize;
        if ($fsize > PKG_MAX_FILE || $total > PKG_MAX_UNPACKED) {
            $zip->close();
            throw new RuntimeException('Paquet trop volumineux une fois décompressé.');
        }
        if (!pkg_protected($rel)) {
            $files[$rel] = $fsize;
        }
    }
    $zip->close();
    if (!isset($files['inc/version.php']) || !isset($files['index.php'])) {
        throw new RuntimeException('Paquet incomplet (fichiers essentiels absents).');
    }
    return $files;
}

/** Extrait le paquet (déjà inspecté) dans un dossier de préparation, en plafonnant réellement les octets écrits. */
function pkg_stage(string $zipPath, array $files, string $stageDir): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Paquet illisible.');
    }
    $total = 0;
    foreach (array_keys($files) as $rel) {
        $target = $stageDir . '/' . $rel;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $zip->close();
            throw new RuntimeException('Création de dossier impossible : ' . dirname($rel));
        }
        $in  = $zip->getStream(PKG_ROOT . $rel);
        $out = @fopen($target, 'wb');
        if (!$in || !$out) {
            $zip->close();
            throw new RuntimeException('Extraction impossible : ' . $rel);
        }
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            $total   += strlen($chunk);
            if ($written > PKG_MAX_FILE || $total > PKG_MAX_UNPACKED) {
                fclose($in);
                fclose($out);
                $zip->close();
                throw new RuntimeException('Paquet trop volumineux une fois décompressé.');
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
    }
    $zip->close();
}

/** Vérifie que chaque fichier PHP préparé est syntaxiquement valide (sans l'exécuter). */
function pkg_check_syntax(string $stageDir, array $files): void
{
    foreach (array_keys($files) as $rel) {
        if (!str_ends_with($rel, '.php')) {
            continue;
        }
        try {
            token_get_all((string) file_get_contents($stageDir . '/' . $rel), TOKEN_PARSE);
        } catch (Throwable $e) {
            throw new RuntimeException('Fichier PHP invalide dans le paquet (' . $rel . ').');
        }
    }
}

/** Version déclarée par le fichier inc/version.php préparé. */
function pkg_staged_version(string $stageDir): string
{
    $src = (string) @file_get_contents($stageDir . '/inc/version.php');
    return preg_match("/const\s+APP_VERSION\s*=\s*'(\d{1,3}\.\d{1,3}\.\d{1,3})'/", $src, $m) ? $m[1] : '';
}

/** Ordre de remplacement : ressources, puis PHP, et inc/version.php en tout dernier (il marque la fin de l'installation). */
function pkg_order(array $files): array
{
    $rels = array_keys($files);
    usort($rels, function (string $a, string $b): int {
        $rank = fn (string $r): int => $r === 'inc/version.php' ? 2 : (str_ends_with($r, '.php') ? 1 : 0);
        return [$rank($a), $a] <=> [$rank($b), $b];
    });
    return $rels;
}

/** Copie les fichiers préparés dans le dossier de l'application (écriture temporaire puis renommage). */
function pkg_apply(string $stageDir, string $appDir, array $files): void
{
    foreach (pkg_order($files) as $rel) {
        $dest = $appDir . '/' . $rel;
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Création de dossier impossible : ' . dirname($rel));
        }
        $tmp = $dest . '.tmp' . bin2hex(random_bytes(3));
        if (!@copy($stageDir . '/' . $rel, $tmp) || !@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('Écriture impossible : ' . $rel . ' (droits du dossier ?).');
        }
        if (function_exists('opcache_invalidate') && str_ends_with($rel, '.php')) {
            @opcache_invalidate($dest, true);
        }
    }
}

/** Liste des fichiers de l'application (chemins relatifs), hors fichiers protégés. */
function pkg_current_files(string $appDir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($appDir) + 1));
        if (!pkg_protected($rel) && !preg_match('/\.tmp[0-9a-f]{6}$/', $rel)) {
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

/** Sauvegarde du code actuel (sans configuration ni données) dans un zip ; renvoie son empreinte. */
function pkg_backup(string $appDir, string $zipPath): string
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Création de la sauvegarde impossible.');
    }
    foreach (pkg_current_files($appDir) as $rel) {
        $zip->addFile($appDir . '/' . $rel, PKG_ROOT . $rel);
    }
    if (!$zip->close()) {
        throw new RuntimeException('Écriture de la sauvegarde impossible.');
    }
    return (string) hash_file('sha256', $zipPath);
}

/** Supprime récursivement un dossier de travail (uniquement sous le dossier fourni). */
function pkg_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
