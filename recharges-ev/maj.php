<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

$err = null;

/* Actualisation en arrière-plan (appelée par assets/update.js) : renvoie le bandeau en JSON. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'refresh') {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    if (update_configured() && update_is_stale()) {
        update_refresh();
    }
    echo json_encode(['html' => update_banner_html(update_info())], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'check') {
        if (!update_configured()) {
            $err = 'La vérification n\'est pas configurée dans cette installation.';
        } else {
            update_refresh();
            header('Location: maj.php?ok=checked');
            exit;
        }
    } elseif ($action === 'snooze') {
        $info = update_info();
        if ($info['available']) {
            update_snooze($info['latest']['version'], 7);
        }
        header('Location: maj.php?ok=snoozed');
        exit;
    } elseif ($action === 'ack') {
        news_acknowledge();
        $back = (string) ($_POST['back'] ?? '');
        header('Location: ' . (in_array($back, ['index.php', 'admin.php', 'parametres.php', 'maj.php', 'diagnostic.php'], true) ? $back : 'maj.php'));
        exit;
    }
}

$info    = update_info();
$news    = news_pending();
$history = changelog();
$ok      = $_GET['ok'] ?? '';
$flash   = ['checked' => 'Vérification terminée.', 'snoozed' => 'Rappel repoussé de 7 jours.'][$ok] ?? null;
$latest  = $info['latest'];

page_head('Mises à jour — Recharges EV', 'mid');
?>
<main class="wrap">
  <header class="topbar">
    <h1>Mises à jour</h1>
    <nav><a href="admin.php">← Administration</a> · <a href="parametres.php">Paramètres</a> · <a href="diagnostic.php">Diagnostic</a> · <a href="index.php">Vue mobile</a></nav>
  </header>
  <?php if ($flash): ?><p class="msg ok" role="status"><?= e($flash) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>

  <section class="card">
    <h2>Version installée : <?= e(APP_VERSION) ?></h2>
    <?php if (!$info['configured']): ?>
      <p class="msg warn">La vérification des mises à jour n'est pas configurée dans cette installation (adresse du dépôt ou clé publique
        absente de <code>inc/version.php</code>).</p>
    <?php else: ?>
      <?php if ($info['error']): ?>
        <p class="msg bad" role="alert">Dernière vérification impossible : <?= e((string) $info['error']) ?></p>
      <?php elseif ($info['available']): ?>
        <p class="msg ok">La version <strong><?= e($latest['version']) ?></strong> est disponible.</p>
      <?php elseif ($info['checked_at'] > 0): ?>
        <p class="msg ok">Vous utilisez la dernière version.</p>
      <?php else: ?>
        <p class="help">Aucune vérification effectuée pour l'instant.</p>
      <?php endif; ?>
      <p class="help">
        <?= $info['checked_at'] > 0 ? 'Dernière vérification : ' . e(fmt_datetime(date('Y-m-d H:i:s', $info['checked_at']))) . '.' : '' ?>
        L'application vérifie automatiquement une fois par jour ; seule la requête HTTPS vers le dépôt est envoyée, sans donnée personnelle.
      </p>
      <form method="post" action="maj.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn small secondary">Vérifier maintenant</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($info['available']): ?>
  <section class="card" id="disponible">
    <h2>Version <?= e($latest['version']) ?> — <?= e($latest['titre'] !== '' ? $latest['titre'] : 'nouveautés') ?></h2>
    <p class="help">Publiée le <?= e((new DateTimeImmutable($latest['date']))->format('d/m/Y')) ?></p>
    <?php if ($latest['notes']): ?>
      <ul class="notes"><?php foreach ($latest['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($latest['min_php'] !== '' && version_compare(PHP_VERSION, $latest['min_php'], '<')): ?>
      <p class="msg bad">Cette version demande PHP <?= e($latest['min_php']) ?> ou plus récent ; ce site utilise PHP <?= e(PHP_VERSION) ?>.</p>
    <?php endif; ?>

    <p><a class="btn" href="<?= e($latest['download_url']) ?>" target="_blank" rel="noopener noreferrer">
      Télécharger la version <?= e($latest['version']) ?><?= $latest['size'] > 0 ? ' (' . number_format($latest['size'] / 1024, 0, ',', "\u{00A0}") . "\u{00A0}Ko)" : '' ?></a></p>
    <ol class="help">
      <li>Décompressez le fichier téléchargé.</li>
      <li>Copiez le contenu du dossier <code>recharges-ev/</code> par FTP sur votre site, en écrasant les fichiers existants.
        Ne supprimez pas <code>inc/local.php</code> (il contient votre connexion à la base).</li>
      <li>Rechargez l'application : la base de données se met à jour toute seule et les nouveautés s'affichent.</li>
    </ol>
    <p class="help">Empreinte SHA-256 du fichier : <code><?= e($latest['sha256']) ?></code></p>
    <form method="post" action="maj.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="snooze">
      <button type="submit" class="linkbtn">Me le rappeler dans 7 jours</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="card" id="nouveautes">
    <h2>Nouveautés des versions installées</h2>
    <?php if ($news): ?>
      <form method="post" action="maj.php" class="inline-block">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="ack">
        <input type="hidden" name="back" value="maj.php">
        <button type="submit" class="btn small">Marquer comme lues</button>
      </form>
    <?php endif; ?>
    <?php foreach ($history as $v => $entry): if (version_compare((string) $v, APP_VERSION, '>')) { continue; } ?>
      <h3 class="ver"><?= e((string) $v) ?> — <?= e($entry['titre']) ?>
        <?php if (isset($news[(string) $v])): ?><span class="badge ac">nouveau</span><?php endif; ?></h3>
      <p class="help"><?= e((new DateTimeImmutable($entry['date']))->format('d/m/Y')) ?></p>
      <ul class="notes"><?php foreach ($entry['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
    <?php endforeach; ?>
  </section>
</main>
<?php page_foot(); ?>
