<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();

$next = in_array($_GET['next'] ?? '', ['admin.php', 'parametres.php'], true) ? $_GET['next'] : 'index.php';

if (is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

$error = null;
$configured = auth_configured();

if ($configured && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = db();
    if (login_blocked($db)) {
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (password_verify((string) ($_POST['password'] ?? ''), config()['password_hash'])) {
        login_cleared($db);
        login_user();
        header('Location: ' . $next);
        exit;
    } else {
        login_failed($db);
        usleep(600000);
        $error = 'Mot de passe incorrect.';
    }
}

page_head('Connexion — Recharges EV', 'narrow');
?>
<main class="wrap">
  <h1>Recharges EV</h1>
  <section class="card">
    <?php if (!$configured): ?>
      <p class="msg bad">Application non configurée : ouvrez <a href="parametres.php">parametres.php</a> pour lancer l'assistant.</p>
    <?php else: ?>
      <form method="post" action="login.php?next=<?= e($next) ?>" autocomplete="on">
        <label class="field">
          <span>Mot de passe</span>
          <input type="password" name="password" autocomplete="current-password" required autofocus>
        </label>
        <?php if ($error): ?><p class="msg bad" role="alert"><?= e($error) ?></p><?php endif; ?>
        <button type="submit" class="btn">Se connecter</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php page_foot(); ?>
