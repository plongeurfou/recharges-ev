<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/*
 * Paramètres de l'application.
 *  - Première utilisation : assistant en 2 étapes (base de données, puis budget et accès).
 *  - Ensuite (connecté) : gestion de tous les paramètres.
 */

$state = app_state();
if ($state === 'dberror') {
    render_db_error();
    exit;
}
$setup = $state !== 'ready';
if (!$setup) {
    require_login();
}

/* ---------- Validations (renvoient les valeurs prêtes à stocker, ou null + message) ---------- */

function v_budget(array $p, ?string &$err): ?array
{
    $b = parse_money((string) ($p['budget_mensuel'] ?? ''), false, 100000);
    if ($b === null) {
        $err = 'Budget mensuel invalide : saisissez un montant en euros (ex. 250 ou 250,50).';
        return null;
    }
    $m = parse_month((string) ($p['mois_depart'] ?? ''));
    if ($m === null) {
        $err = 'Mois de la première recharge invalide : choisissez un mois (format AAAA-MM).';
        return null;
    }
    $rep = trim((string) ($p['report_initial'] ?? '')) === '' ? 0 : parse_money((string) $p['report_initial'], true, 100000);
    if ($rep === null) {
        $err = 'Report initial invalide : saisissez un montant en euros, éventuellement négatif (ex. 0 ou -20).';
        return null;
    }
    return ['budget_mensuel' => cents_dec($b), 'mois_depart' => $m, 'report_initial' => cents_dec($rep)];
}

function v_tarifs(array $p, ?string &$err): ?array
{
    $ac = parse_rate((string) ($p['tarif_ac'] ?? ''));
    $dc = parse_rate((string) ($p['tarif_dc'] ?? ''));
    if ($ac === null || $dc === null) {
        $err = 'Tarif invalide : saisissez un prix en €/kWh HTVA, 4 décimales au maximum, inférieur à 100 (ex. 0,47).';
        return null;
    }
    $tva = trim((string) ($p['tva_pct'] ?? ''));
    if (!preg_match('/^\d{1,3}$/', $tva) || (int) $tva > 100) {
        $err = 'TVA invalide : saisissez un nombre entier entre 0 et 100 (ex. 21).';
        return null;
    }
    return ['tarif_ac' => rate_store($ac), 'tarif_dc' => rate_store($dc), 'tva_pct' => (string) (int) $tva];
}

function v_session(array $p, ?string &$err): ?string
{
    $d = trim((string) ($p['session_days'] ?? ''));
    if (!preg_match('/^\d{1,3}$/', $d) || (int) $d < 1 || (int) $d > 365) {
        $err = 'Durée de connexion invalide : saisissez un nombre de jours entre 1 et 365.';
        return null;
    }
    return (string) (int) $d;
}

function v_db(array $p, string $currentPass, ?string &$err): ?array
{
    $d = [
        'host' => trim((string) ($p['host'] ?? '')),
        'name' => trim((string) ($p['name'] ?? '')),
        'user' => trim((string) ($p['user'] ?? '')),
        'pass' => (string) ($p['pass'] ?? ''),
    ];
    if ($d['pass'] === '') {
        $d['pass'] = $currentPass;
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', $d['host'])) {
        $err = 'Hôte invalide (ex. mysql-moncompte.alwaysdata.net).';
    } elseif (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $d['name'])) {
        $err = 'Nom de base invalide (lettres, chiffres, _ ou -).';
    } elseif (!preg_match('/^[A-Za-z0-9_.@$-]{1,80}$/', $d['user'])) {
        $err = 'Nom d\'utilisateur invalide.';
    } elseif ($d['pass'] === '') {
        $err = 'Saisissez le mot de passe de l\'utilisateur de la base.';
    }
    return $err === null ? $d : null;
}

function manual_local_code(array $cfg): string
{
    return "<?php\nreturn " . var_export($cfg, true) . ";\n";
}

function old(string $key, string $default = ''): string
{
    return e((string) ($_POST[$key] ?? $default));
}

/* ---------- Traitement des formulaires ---------- */

$err = null;
$manual = null;
$d1 = ['host' => '', 'name' => '', 'user' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $setup ? check_origin() : check_csrf();

    if ($setup && $action === 'setup_code') {
        $icode = install_code();
        if (!install_code_valid($icode)) {
            $err = 'Le fichier inc/code_installation.txt est absent ou son code est trop court (8 caractères minimum).';
        } elseif (hash_equals((string) $icode, trim((string) ($_POST['code'] ?? '')))) {
            setup_authorize();
            header('Location: parametres.php');
            exit;
        } else {
            usleep(800000);
            $err = 'Code incorrect.';
        }
    } elseif ($setup && !setup_authorized()) {
        // code d'installation pas encore saisi : toute autre action est ignorée
    } elseif ($state === 'nodb' && $action === 'setup_db') {
        $d = v_db($_POST, '', $err);
        $d1 = ['host' => trim((string) ($_POST['host'] ?? '')), 'name' => trim((string) ($_POST['name'] ?? '')), 'user' => trim((string) ($_POST['user'] ?? ''))];
        if ($d !== null) {
            $pdo = null;
            try {
                $pdo = db_connect($d);
            } catch (Throwable $e) {
                $err = db_error_message($e);
            }
            if ($pdo !== null) {
                try {
                    create_tables($pdo);
                } catch (Throwable $e) {
                    $err = 'Connexion réussie, mais les tables n\'ont pas pu être créées : ' . trim(preg_replace('/\s+/', ' ', $e->getMessage()));
                }
            }
            if ($err === null) {
                $cfg = ['db' => $d, 'force_https' => true, 'debug' => false];
                if (write_local_config($cfg)) {
                    header('Location: parametres.php');
                    exit;
                }
                $err = 'Connexion réussie et tables créées, mais PHP ne peut pas écrire dans le dossier inc/. '
                     . 'Donnez-lui le droit d\'écriture (ou créez vous-même le fichier inc/local.php avec le contenu ci-dessous), puis rechargez la page.';
                $manual = manual_local_code($cfg);
            }
        }
    } elseif ($state === 'nosettings' && $action === 'setup_settings') {
        $b = v_budget($_POST, $err);
        $t = $err === null ? v_tarifs($_POST, $err) : null;
        $sd = $err === null ? v_session($_POST, $err) : null;
        $pw = (string) ($_POST['password'] ?? '');
        if ($err === null) {
            if (mb_strlen($pw) < 10) {
                $err = 'Choisissez un mot de passe d\'au moins 10 caractères.';
            } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
                $err = 'Les deux mots de passe ne sont pas identiques.';
            }
        }
        if ($err === null) {
            save_settings(db(), $b + $t + [
                'session_days'  => $sd,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'secret'        => bin2hex(random_bytes(32)),
                'setup_done'    => '1',
                'schema_version' => (string) SCHEMA_VERSION,
                'app_version'    => APP_VERSION,
            ]);
            setup_finish();
            login_user();
            header('Location: index.php?bienvenue=1');
            exit;
        }
    } elseif ($state === 'ready') {
        $db = db();
        $loc = local_config() ?? [];

        if ($action === 'save_budget') {
            if (($b = v_budget($_POST, $err)) !== null) {
                save_settings($db, $b);
                header('Location: parametres.php?ok=budget');
                exit;
            }
        } elseif ($action === 'save_tarifs') {
            if (($t = v_tarifs($_POST, $err)) !== null) {
                save_settings($db, $t);
                header('Location: parametres.php?ok=tarifs');
                exit;
            }
        } elseif ($action === 'save_security') {
            $kv = [];
            if (($sd = v_session($_POST, $err)) !== null) {
                $kv['session_days'] = $sd;
            }
            $new = (string) ($_POST['new_password'] ?? '');
            if ($err === null && $new !== '') {
                if (login_blocked($db)) {
                    $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
                } elseif (!password_verify((string) ($_POST['current_password'] ?? ''), config()['password_hash'])) {
                    login_failed($db);
                    $err = 'Le mot de passe actuel est incorrect.';
                } elseif (mb_strlen($new) < 10) {
                    $err = 'Le nouveau mot de passe doit comporter au moins 10 caractères.';
                } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
                    $err = 'Les deux nouveaux mots de passe ne sont pas identiques.';
                } else {
                    $kv['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                }
            }
            if ($err === null) {
                if (isset($kv['password_hash']) || !empty($_POST['regenerate'])) {
                    $kv['secret'] = bin2hex(random_bytes(32));
                }
                save_settings($db, $kv);
                if (isset($kv['secret'])) {
                    login_user();   // les autres appareils sont déconnectés, celui-ci reste connecté
                }
                header('Location: parametres.php?ok=security');
                exit;
            }
        } elseif ($action === 'save_db') {
            $cur = $loc['db'] ?? ['pass' => ''];
            $d = v_db($_POST, (string) $cur['pass'], $err);
            if ($d !== null) {
                $new = null;
                try {
                    $new = db_connect($d);
                } catch (Throwable $e) {
                    $err = db_error_message($e);
                }
                if ($new !== null) {
                    try {
                        create_tables($new);
                        $done = $new->query("SELECT value FROM settings WHERE name = 'setup_done'")->fetchColumn();
                        if ($done !== '1') {
                            // Base vide : on y recopie les réglages actuels pour que l'application reste utilisable.
                            $copy = settings();
                            $copy['setup_done'] = '1';
                            $st = $new->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
                            foreach ($copy as $k => $v) {
                                $st->execute([$k, $v]);
                            }
                        }
                    } catch (Throwable $e) {
                        $err = 'Connexion réussie, mais la base n\'a pas pu être préparée : ' . trim(preg_replace('/\s+/', ' ', $e->getMessage()));
                    }
                }
                if ($err === null) {
                    $cfg = ['db' => $d, 'force_https' => $loc['force_https'] ?? true, 'debug' => !empty($loc['debug'])];
                    if (write_local_config($cfg)) {
                        header('Location: parametres.php?ok=db');
                        exit;
                    }
                    $err = 'La base répond, mais PHP ne peut pas écrire inc/local.php. Créez ce fichier vous-même avec le contenu ci-dessous.';
                    $manual = manual_local_code($cfg);
                }
            }
        } elseif ($action === 'save_tech') {
            $cfg = ['db' => $loc['db'], 'force_https' => !empty($_POST['force_https']), 'debug' => !empty($_POST['debug'])];
            if (write_local_config($cfg)) {
                header('Location: parametres.php?ok=tech');
                exit;
            }
            $err = 'PHP ne peut pas écrire inc/local.php. Donnez-lui le droit d\'écriture sur le dossier inc/.';
        }
    }
}

/* ---------- Affichage ---------- */

$flashes = [
    'budget'   => 'Budget enregistré.',
    'tarifs'   => 'Tarifs et TVA enregistrés. Ils s\'appliquent aux prochaines recharges.',
    'security' => 'Paramètres de sécurité enregistrés.',
    'db'       => 'Connexion à la base enregistrée.',
    'tech'     => 'Options techniques enregistrées.',
];
$flash = $flashes[$_GET['ok'] ?? ''] ?? null;
$S = settings();
$L = local_config() ?? [];
$active = $_POST['action'] ?? '';

page_head($setup ? 'Configuration — Recharges EV' : 'Paramètres — Recharges EV', 'mid');
?>
<main class="wrap">

<?php if ($setup && !setup_authorized()): $icode = install_code(); ?>
  <h1>Bienvenue dans Recharges EV</h1>
  <section class="card">
    <h2>Code d'installation</h2>
    <?php if ($icode === null): ?>
      <p>Pour protéger la configuration, créez d'abord, par FTP, un fichier texte nommé <code>inc/code_installation.txt</code>
        contenant un code de votre choix (8 caractères minimum), puis rechargez cette page.</p>
      <p class="help">Ce code évite que quelqu'un d'autre ne termine la configuration à votre place. Le fichier est supprimé
        automatiquement à la fin de l'assistant.</p>
    <?php elseif (!install_code_valid($icode)): ?>
      <p class="msg bad" role="alert">Le code contenu dans <code>inc/code_installation.txt</code> est trop court (8 caractères minimum).
        Modifiez le fichier puis rechargez cette page.</p>
    <?php else: ?>
      <p class="help">Saisissez le code que vous avez écrit dans le fichier <code>inc/code_installation.txt</code>.</p>
      <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
      <form method="post" action="parametres.php" autocomplete="off">
        <input type="hidden" name="action" value="setup_code">
        <label class="field"><span>Code d'installation</span>
          <input type="password" name="code" required autocomplete="off"></label>
        <button type="submit" class="btn">Continuer</button>
      </form>
    <?php endif; ?>
  </section>

<?php elseif ($setup): ?>
  <h1>Bienvenue dans Recharges EV</h1>
  <ol class="steps" aria-label="Étapes de la configuration">
    <li class="<?= $state === 'nodb' ? 'on' : 'done' ?>">1. Base de données</li>
    <li class="<?= $state === 'nosettings' ? 'on' : '' ?>">2. Budget et accès</li>
  </ol>
  <p class="help"><a href="diagnostic.php">Vérifier mon hébergement (diagnostic)</a></p>

  <?php if ($state === 'nodb'): ?>
    <section class="card">
      <h2>Connexion à la base de données</h2>
      <p class="help">Dans le panneau Alwaysdata (Bases de données &gt; MySQL), créez une base et un utilisateur, puis recopiez-les ici.
        Les tables sont créées automatiquement (celles qui existent déjà et leurs données ne sont pas touchées).</p>
      <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
      <?php if ($manual): ?><pre class="code"><?= e($manual) ?></pre><?php endif; ?>
      <form method="post" action="parametres.php" autocomplete="off">
        <input type="hidden" name="action" value="setup_db">
        <label class="field"><span>Hôte de la base</span>
          <input type="text" name="host" required placeholder="mysql-moncompte.alwaysdata.net" value="<?= e($d1['host']) ?>" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Nom de la base</span>
          <input type="text" name="name" required placeholder="moncompte_recharges" value="<?= e($d1['name']) ?>" autocapitalize="none" spellcheck="false">
          <small>Chez Alwaysdata, il commence par l'identifiant du compte.</small></label>
        <label class="field"><span>Utilisateur de la base</span>
          <input type="text" name="user" required placeholder="moncompte" value="<?= e($d1['user']) ?>" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Mot de passe de l'utilisateur de la base</span>
          <input type="password" name="pass" required autocomplete="new-password"></label>
        <button type="submit" class="btn">Tester la connexion et créer les tables</button>
      </form>
    </section>

  <?php else: ?>
    <section class="card">
      <h2>Budget et accès</h2>
      <p class="msg ok">Base de données connectée, tables créées.</p>
      <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
      <form method="post" action="parametres.php" autocomplete="off">
        <input type="hidden" name="action" value="setup_settings">
        <div class="cols">
          <label class="field"><span>Budget mensuel (€ TVAC)</span>
            <input type="text" name="budget_mensuel" inputmode="decimal" required value="<?= old('budget_mensuel', money_display($S['budget_mensuel'])) ?>">
            <small>Remis à jour le 1er de chaque mois ; le solde est reporté.</small></label>
          <label class="field"><span>Mois de la première recharge</span>
            <input type="month" name="mois_depart" required value="<?= old('mois_depart', $S['mois_depart']) ?>">
            <small>Par défaut : le mois en cours. Le compteur démarre ce mois-là.</small></label>
        </div>

        <label class="field"><span>Mot de passe de l'application (10 caractères minimum)</span>
          <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label class="field"><span>Confirmer le mot de passe</span>
          <input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>

        <details<?= in_array($active, ['setup_settings'], true) && $err && !isset($_POST['password']) ? ' open' : '' ?>>
          <summary>Options avancées (tarifs, TVA, report, connexion)</summary>
          <div class="cols">
            <label class="field"><span>Tarif AC (€/kWh HTVA)</span>
              <input type="text" name="tarif_ac" inputmode="decimal" required value="<?= old('tarif_ac', rate_display($S['tarif_ac'])) ?>"></label>
            <label class="field"><span>Tarif DC (€/kWh HTVA)</span>
              <input type="text" name="tarif_dc" inputmode="decimal" required value="<?= old('tarif_dc', rate_display($S['tarif_dc'])) ?>"></label>
            <label class="field"><span>TVA (%)</span>
              <input type="text" name="tva_pct" inputmode="numeric" required value="<?= old('tva_pct', $S['tva_pct']) ?>"></label>
            <label class="field"><span>Report à l'ouverture (€)</span>
              <input type="text" name="report_initial" inputmode="decimal" value="<?= old('report_initial', money_display($S['report_initial'])) ?>"></label>
            <label class="field"><span>Connexion mémorisée (jours)</span>
              <input type="text" name="session_days" inputmode="numeric" required value="<?= old('session_days', $S['session_days']) ?>"></label>
          </div>
        </details>
        <button type="submit" class="btn">Terminer la configuration</button>
      </form>
    </section>
  <?php endif; ?>

<?php else: ?>
  <header class="topbar">
    <h1>Paramètres</h1>
    <nav><a href="admin.php">← Administration</a> · <a href="maj.php">Mises à jour</a> · <a href="diagnostic.php">Diagnostic</a> · <a href="index.php">Vue mobile</a> · <a href="logout.php">Se déconnecter</a></nav>
  </header>
  <?php render_banners(); ?>
  <?php if ($flash): ?><p class="msg ok" role="status"><?= e($flash) ?></p><?php endif; ?>

  <section class="card" id="budget">
    <h2>Budget</h2>
    <?php if ($err && $active === 'save_budget'): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="parametres.php#budget">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_budget">
      <div class="cols">
        <label class="field"><span>Budget mensuel (€ TVAC)</span>
          <input type="text" name="budget_mensuel" inputmode="decimal" required value="<?= old('budget_mensuel', money_display($S['budget_mensuel'])) ?>"></label>
        <label class="field"><span>Mois de la première recharge</span>
          <input type="month" name="mois_depart" required value="<?= old('mois_depart', $S['mois_depart']) ?>"></label>
        <label class="field"><span>Report à l'ouverture (€)</span>
          <input type="text" name="report_initial" inputmode="decimal" value="<?= old('report_initial', money_display($S['report_initial'])) ?>"></label>
      </div>
      <p class="help">Le solde est calculé sur l'ensemble des mois depuis le mois de départ : modifier le budget ou le mois de départ
        change donc aussi le solde des mois passés.</p>
      <button type="submit" class="btn small">Enregistrer</button>
    </form>
  </section>

  <section class="card" id="tarifs">
    <h2>Tarifs et TVA</h2>
    <?php if ($err && $active === 'save_tarifs'): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="parametres.php#tarifs">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_tarifs">
      <div class="cols">
        <label class="field"><span>Tarif AC (€/kWh HTVA)</span>
          <input type="text" name="tarif_ac" inputmode="decimal" required value="<?= old('tarif_ac', rate_display($S['tarif_ac'])) ?>"></label>
        <label class="field"><span>Tarif DC (€/kWh HTVA)</span>
          <input type="text" name="tarif_dc" inputmode="decimal" required value="<?= old('tarif_dc', rate_display($S['tarif_dc'])) ?>"></label>
        <label class="field"><span>TVA (%)</span>
          <input type="text" name="tva_pct" inputmode="numeric" required value="<?= old('tva_pct', $S['tva_pct']) ?>"></label>
      </div>
      <p class="help">Ces valeurs s'appliquent aux prochaines recharges. Les recharges déjà enregistrées gardent leur tarif
        (la page d'administration permet de recalculer une recharge au besoin).</p>
      <button type="submit" class="btn small">Enregistrer</button>
    </form>
  </section>

  <section class="card" id="security">
    <h2>Sécurité</h2>
    <?php if ($err && $active === 'save_security'): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="parametres.php#security" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_security">
      <label class="field"><span>Connexion mémorisée (jours)</span>
        <input type="text" name="session_days" inputmode="numeric" required value="<?= old('session_days', $S['session_days']) ?>"></label>
      <details<?= $err && $active === 'save_security' && ($_POST['new_password'] ?? '') !== '' ? ' open' : '' ?>>
        <summary>Changer le mot de passe de l'application</summary>
        <label class="field"><span>Mot de passe actuel</span>
          <input type="password" name="current_password" autocomplete="current-password"></label>
        <div class="cols">
          <label class="field"><span>Nouveau mot de passe (10 caractères minimum)</span>
            <input type="password" name="new_password" autocomplete="new-password"></label>
          <label class="field"><span>Confirmer</span>
            <input type="password" name="new_password2" autocomplete="new-password"></label>
        </div>
      </details>
      <label class="check"><input type="checkbox" name="regenerate" value="1"> <span>Déconnecter tous les autres appareils
        <small>(change la clé de signature des connexions ; cet appareil reste connecté)</small></span></label>
      <button type="submit" class="btn small">Enregistrer</button>
    </form>
  </section>

  <section class="card" id="db">
    <h2>Base de données</h2>
    <?php if ($err && $active === 'save_db'): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
    <?php if ($manual && $active === 'save_db'): ?><pre class="code"><?= e($manual) ?></pre><?php endif; ?>
    <form method="post" action="parametres.php#db" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_db">
      <div class="cols">
        <label class="field"><span>Hôte</span>
          <input type="text" name="host" required value="<?= old('host', (string) ($L['db']['host'] ?? '')) ?>" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Nom de la base</span>
          <input type="text" name="name" required value="<?= old('name', (string) ($L['db']['name'] ?? '')) ?>" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Utilisateur</span>
          <input type="text" name="user" required value="<?= old('user', (string) ($L['db']['user'] ?? '')) ?>" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Mot de passe</span>
          <input type="password" name="pass" autocomplete="new-password" placeholder="inchangé si vide"></label>
      </div>
      <p class="help">La connexion est testée avant d'être enregistrée, et les tables manquantes sont créées. Si vous passez à une autre base,
        les recharges ne sont pas copiées : seuls les paramètres le sont, si cette base est vide.</p>
      <button type="submit" class="btn small">Tester et enregistrer</button>
    </form>
  </section>

  <section class="card" id="tech">
    <h2>Options techniques</h2>
    <?php if ($err && $active === 'save_tech'): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="parametres.php#tech">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_tech">
      <label class="check"><input type="checkbox" name="force_https" value="1" <?= ($L['force_https'] ?? true) ? 'checked' : '' ?>>
        <span>Forcer le HTTPS <small>(recommandé : la géolocalisation du GSM ne fonctionne pas sans)</small></span></label>
      <label class="check"><input type="checkbox" name="debug" value="1" <?= !empty($L['debug']) ? 'checked' : '' ?>>
        <span>Afficher le détail des erreurs <small>(diagnostic seulement : à désactiver ensuite)</small></span></label>
      <button type="submit" class="btn small">Enregistrer</button>
    </form>
  </section>
<?php endif; ?>

</main>
<?php page_foot(); ?>
