<?php
declare(strict_types=1);

/*
 * Mise à jour en un clic (V2.4).
 * Déroulement : manifeste signé → téléchargement du zip → empreinte SHA-256 → inspection du paquet → préparation dans
 * un dossier temporaire → contrôle de la syntaxe PHP → sauvegarde du code actuel → remplacement des fichiers (inc/version.php
 * en dernier) → en cas d'échec, restauration automatique. Pendant l'opération, un verrou affiche « mise à jour en cours ».
 * Les fichiers locaux (inc/local.php, codes d'installation…) ne sont jamais touchés ; la base se met à jour toute seule
 * à la première page chargée ensuite (migrations).
 */

define('APP_DIR', dirname(__DIR__));
const UPDATE_LOCK_FILE  = __DIR__ . '/maintenance.lock';
const UPDATE_WORK_DIR   = __DIR__ . '/maj_tmp';
const UPDATE_BACKUP_DIR = __DIR__ . '/sauvegardes';
const UPDATE_LOCK_TTL   = 300;

/** Raisons pour lesquelles la mise à jour en un clic est impossible sur cet hébergement (vide = possible). */
function update_prerequisites(): array
{
    $p = [];
    if (!class_exists('ZipArchive')) { $p[] = 'extension PHP « zip » absente'; }
    if (!extension_loaded('sodium')) { $p[] = 'extension PHP « sodium » absente'; }
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) { $p[] = 'aucun accès réseau sortant (cURL ou allow_url_fopen)'; }
    foreach ([APP_DIR, APP_DIR . '/inc', APP_DIR . '/assets'] as $d) {
        if (!is_writable($d)) { $p[] = 'dossier non modifiable par PHP : ' . basename(realpath($d) ?: $d); }
    }
    return $p;
}

function maintenance_active(): bool
{
    return is_file(UPDATE_LOCK_FILE) && time() - (int) @filemtime(UPDATE_LOCK_FILE) < UPDATE_LOCK_TTL;
}

function update_lock(): void
{
    if (is_file(UPDATE_LOCK_FILE) && !maintenance_active()) {
        @unlink(UPDATE_LOCK_FILE);               // verrou périmé (opération interrompue)
    }
    $fh = @fopen(UPDATE_LOCK_FILE, 'x');
    if ($fh === false) {
        throw new RuntimeException('Une mise à jour est déjà en cours. Réessayez dans quelques minutes.');
    }
    fwrite($fh, (string) time());
    fclose($fh);
}

function update_rollback_info(): ?array
{
    $r = json_decode(settings()['rollback'] ?? '', true);
    return is_array($r) && is_file((string) ($r['file'] ?? '')) ? $r : null;
}

/** Installe la dernière version publiée. Retourne ['from' => …, 'to' => …]. */
function update_install(): array
{
    if ($problems = update_prerequisites()) {
        throw new RuntimeException('Mise à jour en un clic impossible ici : ' . implode(', ', $problems) . '.');
    }
    $c = update_refresh();
    if (!$c['ok']) {
        throw new RuntimeException((string) $c['error']);
    }
    $m = $c['latest'];
    if (!version_compare($m['version'], APP_VERSION, '>')) {
        throw new RuntimeException('Vous utilisez déjà la dernière version.');
    }
    if ($m['min_php'] !== '' && version_compare(PHP_VERSION, $m['min_php'], '<')) {
        throw new RuntimeException('Cette version demande PHP ' . $m['min_php'] . ' ou plus récent (ce site : ' . PHP_VERSION . ').');
    }

    @set_time_limit(180);
    update_lock();
    $work = UPDATE_WORK_DIR . '/' . date('YmdHis');
    $backedUp = false;
    try {
        pkg_rmdir(UPDATE_WORK_DIR);
        if (!@mkdir($work, 0755, true)) {
            throw new RuntimeException('Création du dossier temporaire impossible (inc/maj_tmp).');
        }
        $ua  = 'RechargesEV/' . APP_VERSION;
        $zip = $work . '/paquet.zip';
        pkg_http_get($m['download_url'], PKG_MAX_ZIP, $ua, $zip, 90);
        $files = pkg_inspect_zip($zip, $m['sha256']);
        pkg_stage($zip, $files, $work . '/stage');
        pkg_check_syntax($work . '/stage', $files);
        if (pkg_staged_version($work . '/stage') !== $m['version']) {
            throw new RuntimeException('La version contenue dans le paquet ne correspond pas à celle annoncée.');
        }

        // Sauvegarde du code actuel (une seule conservée)
        if (!is_dir(UPDATE_BACKUP_DIR) && !@mkdir(UPDATE_BACKUP_DIR, 0755)) {
            throw new RuntimeException('Création du dossier de sauvegarde impossible (inc/sauvegardes).');
        }
        foreach (glob(UPDATE_BACKUP_DIR . '/app-*.zip') ?: [] as $old) {
            @unlink($old);
        }
        $backup = UPDATE_BACKUP_DIR . '/app-' . APP_VERSION . '.zip';
        $sha    = pkg_backup(APP_DIR, $backup);
        $backedUp = true;
        save_settings(db(), ['rollback' => json_encode(['version' => APP_VERSION, 'file' => $backup, 'sha256' => $sha, 'at' => time()])]);

        try {
            pkg_apply($work . '/stage', APP_DIR, $files);
        } catch (Throwable $e) {
            try {
                update_restore_from($backup, $sha);
            } catch (Throwable $e2) {
                throw new RuntimeException($e->getMessage() . ' — ATTENTION : la restauration automatique a aussi échoué (' . $e2->getMessage()
                    . '). Recopiez les fichiers de la version ' . $m['version'] . ' par FTP.');
            }
            throw new RuntimeException($e->getMessage() . ' L\'ancienne version a été restaurée.');
        }
    } finally {
        pkg_rmdir(UPDATE_WORK_DIR);
        @unlink(UPDATE_LOCK_FILE);
    }
    return ['from' => APP_VERSION, 'to' => $m['version']];
}

function update_restore_from(string $backup, string $sha): void
{
    $files = pkg_inspect_zip($backup, $sha);
    $stage = UPDATE_WORK_DIR . '/restore';
    pkg_rmdir($stage);
    pkg_stage($backup, $files, $stage);
    pkg_apply($stage, APP_DIR, $files);
    pkg_rmdir($stage);
}

/** Revient à la version sauvegardée avant la dernière mise à jour. */
function update_rollback(): string
{
    $r = update_rollback_info();
    if (!$r) {
        throw new RuntimeException('Aucune sauvegarde disponible.');
    }
    @set_time_limit(120);
    update_lock();
    try {
        update_restore_from((string) $r['file'], (string) $r['sha256']);
        @unlink((string) $r['file']);
        save_settings(db(), ['rollback' => '']);
    } finally {
        pkg_rmdir(UPDATE_WORK_DIR);
        @unlink(UPDATE_LOCK_FILE);
    }
    return (string) $r['version'];
}
