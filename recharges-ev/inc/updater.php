<?php
declare(strict_types=1);

/*
 * Détection des mises à jour (V2.1).
 *
 * L'application lit, au plus une fois par jour, le fichier latest.json publié avec chaque version.
 * Ce fichier est SIGNÉ (Ed25519) : sans la clé privée de l'éditeur, personne ne peut faire afficher
 * une fausse annonce ni un faux lien de téléchargement. Rien n'est installé automatiquement :
 * l'utilisateur télécharge le zip et le copie par FTP ; la base se met à jour toute seule (migrations).
 *
 * Aucune donnée personnelle n'est envoyée : seulement une requête HTTPS avec le numéro de version.
 */

const UPDATE_REFRESH_OK   = 86400;   // nouvelle vérification après 24 h
const UPDATE_REFRESH_FAIL = 3600;    // ou après 1 h si la dernière a échoué

function update_configured(): bool
{
    return UPDATE_PUBLIC_KEY !== ''
        && str_starts_with(UPDATE_MANIFEST_URL, 'https://')
        && !str_contains(UPDATE_MANIFEST_URL, '/OWNER/');
}

/** Téléchargement HTTPS limité en taille et en durée (cURL, sinon flux PHP). */
function update_http_get(string $url, int $maxBytes = 65536): string
{
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Adresse de mise à jour non sécurisée (HTTPS obligatoire).');
    }
    if (function_exists('curl_init')) {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'RechargesEV/' . APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException('Dépôt injoignable (' . ($err !== '' ? $err : 'erreur réseau') . ').');
        }
        if ($code !== 200) {
            throw new RuntimeException('Le dépôt a répondu HTTP ' . $code . '.');
        }
        return $body;
    }
    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('Ni cURL ni allow_url_fopen ne sont disponibles sur cet hébergement.');
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 5, 'header' => 'User-Agent: RechargesEV/' . APP_VERSION . "\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($body === false || strlen($body) > $maxBytes) {
        throw new RuntimeException('Dépôt injoignable ou réponse trop volumineuse.');
    }
    return $body;
}

/**
 * Vérifie la signature du fichier latest.json et valide son contenu.
 * Format : {"payload": "<texte JSON signé>", "signature": "<base64>"}.
 */
function update_verify_manifest(string $body): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('L\'extension PHP « sodium » est absente : impossible de vérifier la signature.');
    }
    $outer = json_decode($body, true);
    if (!is_array($outer) || !is_string($outer['payload'] ?? null) || !is_string($outer['signature'] ?? null)) {
        throw new RuntimeException('Fichier de version illisible.');
    }
    $pub = base64_decode(UPDATE_PUBLIC_KEY, true);
    $sig = base64_decode($outer['signature'], true);
    if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new RuntimeException('Clé publique de l\'application invalide (inc/version.php).');
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

/* ---------- Cache de la dernière vérification (réglage « update_cache ») ---------- */

function update_cache(): array
{
    $c = json_decode(settings()['update_cache'], true);
    return is_array($c) ? $c : ['checked_at' => 0, 'ok' => false, 'error' => null, 'latest' => null];
}

function update_is_stale(): bool
{
    $c = update_cache();
    return time() - (int) ($c['checked_at'] ?? 0) > (!empty($c['ok']) ? UPDATE_REFRESH_OK : UPDATE_REFRESH_FAIL);
}

/** Interroge le dépôt (réseau) et mémorise le résultat. */
function update_refresh(): array
{
    $c = ['checked_at' => time(), 'ok' => false, 'error' => null, 'latest' => null];
    try {
        $c['latest'] = update_verify_manifest(update_http_get(UPDATE_MANIFEST_URL));
        $c['ok'] = true;
    } catch (Throwable $e) {
        $c['error'] = $e->getMessage();
    }
    save_settings(db(), ['update_cache' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return $c;
}

/** État affichable, lu dans le cache (aucun accès réseau). */
function update_info(): array
{
    $c      = update_cache();
    $latest = is_array($c['latest'] ?? null) ? $c['latest'] : null;
    $avail  = $latest !== null && version_compare($latest['version'], APP_VERSION, '>');
    $sn     = explode('|', settings()['update_snooze']);
    return [
        'configured' => update_configured(),
        'checked_at' => (int) ($c['checked_at'] ?? 0),
        'ok'         => !empty($c['ok']),
        'error'      => $c['error'] ?? null,
        'latest'     => $latest,
        'available'  => $avail,
        'snoozed'    => $avail && ($sn[0] ?? '') === $latest['version'] && (int) ($sn[1] ?? 0) > time(),
    ];
}

function update_snooze(string $version, int $days = 7): void
{
    save_settings(db(), ['update_snooze' => $version . '|' . (time() + $days * 86400)]);
}

/* ---------- Notes de version ---------- */

function changelog(): array
{
    static $c = null;
    return $c ??= (require __DIR__ . '/changelog.php');
}

/** Nouveautés pas encore vues : versions comprises entre la dernière vue (exclue) et la version installée. */
function news_pending(): array
{
    $seen = settings()['app_version'] !== '' ? settings()['app_version'] : '2.0.0';
    $out  = [];
    foreach (changelog() as $v => $entry) {
        if (version_compare((string) $v, $seen, '>') && version_compare((string) $v, APP_VERSION, '<=')) {
            $out[(string) $v] = $entry;
        }
    }
    uksort($out, fn ($a, $b) => version_compare($b, $a));
    return $out;
}

function news_acknowledge(): void
{
    save_settings(db(), ['app_version' => APP_VERSION]);
}
