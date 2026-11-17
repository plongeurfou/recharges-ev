<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();

/*
 * Mot de passe oublié.
 * Preuve d'accès au serveur : on dépose par FTP le fichier inc/reinitialisation.txt contenant un code de son choix
 * (8 caractères minimum, valable 1 heure). Ce code, saisi ici, permet de choisir un nouveau mot de passe.
 * Le fichier est supprimé ensuite ; les autres appareils sont déconnectés.
 */
const RESET_CODE_FILE = __DIR__ . '/inc/reinitialisation.txt';
const RESET_VALID_SECONDS = 3600;

if (is_logged_in()) {
    header('Location: parametres.php');
    exit;
}

function reset_code(): ?string
{
    if (!is_file(RESET_CODE_FILE) || time() - (int) @filemtime(RESET_CODE_FILE) > RESET_VALID_SECONDS) {
        return null;
    }
    $c = trim((string) @file_get_contents(RESET_CODE_FILE));
    return mb_strlen($c) >= 8 ? $c : null;
}

$db    = db();
$code  = reset_code();
$error = null;
$exists = is_file(RESET_CODE_FILE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== null) {
    check_origin();
    $new = (string) ($_POST['new_password'] ?? '');
    if (login_blocked($db)) {
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (!hash_equals($code, trim((string) ($_POST['code'] ?? '')))) {
        login_failed($db);
        usleep(600000);
        $error = 'Code incorrect.';
    } elseif (mb_strlen($new) < 10) {
        $error = 'Le nouveau mot de passe doit comporter au moins 10 caractères.';
    } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
        $error = 'Les deux mots de passe ne sont pas identiques.';
    } else {
        save_settings($db, ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'secret' => bin2hex(random_bytes(32))]);
        login_cleared($db);
        @unlink(RESET_CODE_FILE);
        login_user();
        header('Location: index.php?reinit=1');
        exit;
    }
}

page_head('Mot de passe oublié — Recharges EV', 'narrow');
?>
<main class="wrap">
  <h1>Mot de passe oublié</h1>
  <section class="card">
    <?php if ($code === null): ?>
      <p>Pour réinitialiser le mot de passe, prouvez que vous avez accès au serveur :</p>
      <ol class="help">
        <li>Par FTP, créez le fichier <code>inc/reinitialisation.txt</code> contenant un code de votre choix (8 caractères minimum).</li>
        <li>Rechargez cette page (le code est valable 1 heure) et saisissez-le avec votre nouveau mot de passe.</li>
      </ol>
      <?php if ($exists): ?><p class="msg warn">Le fichier existe, mais son code est trop court ou a plus d'une heure : recréez-le.</p><?php endif; ?>
    <?php else: ?>
      <form method="post" action="reset.php" autocomplete="off">
        <label class="field"><span>Code du fichier reinitialisation.txt</span>
          <input type="password" name="code" required autocomplete="off" autofocus></label>
        <label class="field"><span>Nouveau mot de passe <small>(10 caractères minimum)</small></span>
          <input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
        <label class="field"><span>Confirmer le mot de passe</span>
          <input type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>
        <?php if ($error): ?><p class="msg bad" role="alert"><?= e($error) ?></p><?php endif; ?>
        <button type="submit" class="btn">Changer le mot de passe</button>
      </form>
    <?php endif; ?>
    <p class="center"><a href="login.php">← Retour à la connexion</a></p>
  </section>
</main>
<?php page_foot(); ?>
