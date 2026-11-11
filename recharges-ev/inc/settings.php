<?php
declare(strict_types=1);

/*
 * Paramètres de l'application (V2).
 *
 *  - inc/local.php : connexion à la base + deux options techniques (HTTPS, diagnostic).
 *    C'est le seul fichier de configuration : il est écrit par parametres.php, car la
 *    connexion à la base ne peut évidemment pas être stockée dans la base elle-même.
 *  - table « settings » : tout le reste (budget, mois de départ, tarifs, TVA, mot de passe…).
 *
 * Compatibilité V1 : si inc/local.php n'existe pas mais qu'un ancien inc/config.php complet
 * est présent, ses valeurs sont reprises automatiquement (une seule fois).
 */

const LOCAL_FILE  = __DIR__ . '/local.php';
const LEGACY_FILE = __DIR__ . '/config.php';

function setting_defaults(): array
{
    return [
        'budget_mensuel' => '250.00',
        'mois_depart'    => date('Y-m'),
        'report_initial' => '0.00',
        'tva_pct'        => '21',
        'tarif_ac'       => '0.47',
        'tarif_dc'       => '0.65',
        'session_days'   => '180',
        'password_hash'  => '',
        'secret'         => '',
        'setup_done'     => '0',
        'schema_version' => '0',   // dernière migration de base de données appliquée
        'app_version'    => '',    // dernière version dont les nouveautés ont été vues
        'update_cache'   => '',    // dernier résultat de la vérification des mises à jour (JSON)
        'update_snooze'  => '',    // « version|horodatage » : rappel repoussé
    ];
}

/* ---------- Fichiers de configuration locale ---------- */

function read_config_file(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $v = (static function () use ($file) {
        return include $file;
    })();
    return is_array($v) ? $v : null;
}

/** Ancien config.php de la V1, s'il est complet (pas les valeurs d'exemple). */
function legacy_config(): ?array
{
    $c = read_config_file(LEGACY_FILE);
    if (!$c || empty($c['db']) || !is_array($c['db'])) {
        return null;
    }
    foreach (['host', 'name', 'user', 'pass'] as $k) {
        if (!isset($c['db'][$k]) || $c['db'][$k] === '') {
            return null;
        }
    }
    $d = $c['db'];
    if (str_contains((string) $d['host'], 'VOTRECOMPTE') || str_contains((string) $d['name'], 'VOTRECOMPTE') || $d['pass'] === 'A_REMPLACER') {
        return null;
    }
    return $c;
}

/** Connexion + options techniques : inc/local.php, sinon l'ancien config.php, sinon null. */
function local_config(bool $reload = false): ?array
{
    static $c = false;
    if ($c === false || $reload) {
        $c = read_config_file(LOCAL_FILE);
        if ($c === null && ($legacy = legacy_config()) !== null) {
            $c = ['db' => $legacy['db'], 'force_https' => $legacy['force_https'] ?? true, 'debug' => !empty($legacy['debug'])];
        }
    }
    return $c;
}

function write_local_config(array $cfg): bool
{
    $code = "<?php\n// Fichier généré par parametres.php — ne pas le partager (contient le mot de passe de la base).\nreturn "
          . var_export($cfg, true) . ";\n";
    $tmp = LOCAL_FILE . '.tmp';
    if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, LOCAL_FILE)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(LOCAL_FILE, true);
    }
    local_config(true);
    return true;
}

/* ---------- Connexion à la base ---------- */

function db_connect(array $d): PDO
{
    return new PDO(
        'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4',
        $d['user'],
        $d['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]
    );
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $l = local_config();
        if ($l === null) {
            throw new RuntimeException('Base de données non configurée.');
        }
        $pdo = db_connect($l['db']);
    }
    return $pdo;
}

/** Message d'erreur de connexion lisible, sans détail technique inutile. */
function db_error_message(Throwable $e): string
{
    return match ((int) $e->getCode()) {
        1045    => 'Identifiants refusés : vérifiez l\'utilisateur et le mot de passe.',
        1044    => 'Cet utilisateur n\'a pas accès à cette base : vérifiez le nom de la base et l\'utilisateur.',
        1049    => 'Base inconnue : vérifiez le nom de la base (chez Alwaysdata, il commence par l\'identifiant du compte).',
        2002, 2005 => 'Hôte introuvable ou injoignable : vérifiez l\'hôte (ex. mysql-moncompte.alwaysdata.net).',
        default => 'Connexion impossible (' . trim(preg_replace('/\s+/', ' ', $e->getMessage())) . ').',
    };
}

/* ---------- Paramètres en base ---------- */

function settings(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $s = setting_defaults();
        try {
            foreach (db()->query('SELECT name, value FROM settings') as $r) {
                if (array_key_exists($r['name'], $s)) {
                    $s[$r['name']] = (string) $r['value'];
                }
            }
        } catch (Throwable $e) {
            // table absente ou base injoignable : valeurs par défaut
        }
    }
    return $s;
}

function save_settings(PDO $db, array $kv): void
{
    $db->beginTransaction();
    try {
        $st = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($kv as $k => $v) {
            $st->execute([(string) $k, (string) $v]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    settings(true);
    config(true);
}

/** Vue « config » utilisée par le reste de l'application (même forme qu'en V1). */
function config(bool $reload = false): array
{
    static $c = null;
    if ($c === null || $reload) {
        $s = settings($reload);
        $l = local_config() ?? [];
        $c = [
            'force_https'    => $l['force_https'] ?? true,
            'debug'          => !empty($l['debug']),
            'db'             => $l['db'] ?? [],
            'password_hash'  => $s['password_hash'],
            'secret'         => $s['secret'],
            'session_days'   => max(1, (int) $s['session_days']),
            'mois_depart'    => $s['mois_depart'],
            'budget_mensuel' => (float) $s['budget_mensuel'],
            'report_initial' => (float) $s['report_initial'],
            'tva_pct'        => (int) $s['tva_pct'],
            'tarifs'         => ['AC' => $s['tarif_ac'], 'DC' => $s['tarif_dc']],
        ];
    }
    return $c;
}

/* ---------- État de l'application ---------- */

/** Reprend les réglages d'une V1 complète (une seule fois). */
function legacy_import_settings(PDO $db): bool
{
    $c = legacy_config();
    if (!$c || ($c['password_hash'] ?? '') === '' || ($c['secret'] ?? '') === '') {
        return false;
    }
    create_tables($db);
    save_settings($db, [
        'budget_mensuel' => sprintf('%.2f', (float) ($c['budget_mensuel'] ?? 250)),
        'mois_depart'    => (string) ($c['mois_depart'] ?? date('Y-m')),
        'report_initial' => sprintf('%.2f', (float) ($c['report_initial'] ?? 0)),
        'tva_pct'        => (string) (int) ($c['tva_pct'] ?? 21),
        'tarif_ac'       => (string) ($c['tarifs']['AC'] ?? '0.47'),
        'tarif_dc'       => (string) ($c['tarifs']['DC'] ?? '0.65'),
        'session_days'   => (string) (int) ($c['session_days'] ?? 180),
        'password_hash'  => (string) $c['password_hash'],
        'secret'         => (string) $c['secret'],
        'setup_done'     => '1',
    ]);
    if (!is_file(LOCAL_FILE)) {
        write_local_config(['db' => $c['db'], 'force_https' => $c['force_https'] ?? true, 'debug' => !empty($c['debug'])]);
    }
    return true;
}

/**
 * 'nodb'       : aucune connexion configurée -> assistant, étape 1
 * 'dberror'    : connexion configurée mais impossible -> page d'erreur
 * 'nosettings' : base joignable mais réglages absents -> assistant, étape 2
 * 'ready'      : application prête
 */
function app_state(): string
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }
    if (local_config() === null) {
        return $state = 'nodb';
    }
    try {
        $db = db();
    } catch (Throwable $e) {
        $GLOBALS['db_error'] = db_error_message($e);
        return $state = 'dberror';
    }
    try {
        $done = $db->query("SELECT value FROM settings WHERE name = 'setup_done'")->fetchColumn();
    } catch (Throwable $e) {
        $done = false;
    }
    if ($done === '1') {
        $state = 'ready';
        run_pending_migrations();
        return $state;
    }
    try {
        if (legacy_import_settings($db)) {
            $state = 'ready';
            run_pending_migrations();
            return $state;
        }
    } catch (Throwable $e) {
        error_log((string) $e);
    }
    return $state = 'nosettings';
}

/** À appeler en tête des pages de l'application : renvoie vers l'assistant si besoin. */
function require_configured(): void
{
    $st = app_state();
    if ($st === 'ready') {
        return;
    }
    if ($st === 'dberror') {
        render_db_error();
        exit;
    }
    header('Location: parametres.php');
    exit;
}

/* ---------- Validation des saisies ---------- */

/** « 250 », « 250,5 » => centimes ; null si invalide. */
function parse_money(string $s, bool $allowNegative = false, int $maxEuros = 1000000): ?int
{
    $s = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim($s));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^(-?)(\d{1,7})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    if ($m[1] === '-' && !$allowNegative) {
        return null;
    }
    $c = (int) $m[2] * 100 + (int) substr(($m[3] ?? '') . '00', 0, 2);
    if ($c > $maxEuros * 100) {
        return null;
    }
    return $m[1] === '-' ? -$c : $c;
}

/** « 0,47 » => 4700 (dix-millièmes d'euro par kWh) ; null si invalide ou hors 0 – 100 €. */
function parse_rate(string $s): ?int
{
    $s = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim($s));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^(\d{1,2})(?:\.(\d{1,4}))?$/', $s, $m)) {
        return null;
    }
    $r = (int) $m[1] * 10000 + (int) substr(($m[2] ?? '') . '0000', 0, 4);
    return ($r > 0 && $r < 1000000) ? $r : null;
}

function parse_month(string $s): ?string
{
    return preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', trim($s)) ? trim($s) : null;
}

/** Stockage d'un tarif : 4700 => « 0.4700 ». */
function rate_store(int $r10k): string
{
    return sprintf('%.4f', $r10k / 10000);
}

/** Affichage d'un tarif stocké : « 0.4700 » => « 0,47 ». */
function rate_display(string $stored): string
{
    $s = rtrim(sprintf('%.4f', (float) $stored), '0');
    $dec = strlen((string) substr((string) strrchr($s, '.'), 1));
    if ($dec < 2) {
        $s .= str_repeat('0', 2 - $dec);
    }
    return str_replace('.', ',', $s);
}

function money_display(string $stored): string
{
    return str_replace('.', ',', cents_dec(dec_cents($stored)));
}

/** Refuse un POST venant d'un autre site (utile quand on n'est pas encore connecté). */
function check_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || $origin === 'null') {
        return;
    }
    $oh   = parse_url($origin, PHP_URL_HOST);
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($oh === null || strcasecmp((string) $oh, $host) !== 0) {
        http_response_code(400);
        exit('Requête invalide.');
    }
}
