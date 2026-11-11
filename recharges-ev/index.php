<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

$db    = db();
$error = null;
$old   = ['kwh' => '', 'type' => 'AC'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $old['kwh']  = trim((string) ($_POST['kwh'] ?? ''));
    $old['type'] = ($_POST['type'] ?? '') === 'DC' ? 'DC' : 'AC';
    $h = parse_kwh($old['kwh']);
    if ($h === null) {
        $error = 'Quantité invalide : saisissez un nombre de kWh supérieur à 0, avec 2 décimales maximum (ex. 12,35).';
    } else {
        [$lat, $lng, $acc] = read_position($_POST);
        $id = record_recharge($db, $h, $old['type'], $lat, $lng, $acc);
        header('Location: index.php?ok=' . $id);
        exit;
    }
}

/* Confirmation après enregistrement */
$flash = isset($_GET['bienvenue'])
    ? 'Configuration terminée. Vous pouvez enregistrer votre première recharge.'
        . (is_file(INSTALL_CODE_FILE) ? ' Pensez à supprimer le fichier inc/code_installation.txt.' : '')
    : null;
if (isset($_GET['ok']) && ctype_digit((string) $_GET['ok'])) {
    $st = $db->prepare('SELECT * FROM recharges WHERE id = ?');
    $st->execute([(int) $_GET['ok']]);
    if ($r = $st->fetch()) {
        $flash = 'Recharge enregistrée : ' . kwh_fmt($r['kwh']) . ' kWh ' . $r['type']
            . ' (' . $r['kwh_billed'] . ' kWh facturés) = ' . eur(dec_cents($r['amount_ttc'])) . ' TVAC'
            . ($r['latitude'] === null ? ' — sans position.' : '.');
    }
}

$b      = budget_status($db);
$totals = monthly_totals($db);

/* Historique, du plus récent au plus ancien */
$limit = min(1000, max(50, (int) ($_GET['n'] ?? 50)));
$rows  = $db->query('SELECT * FROM recharges ORDER BY charged_at DESC, id DESC LIMIT ' . ($limit + 1))->fetchAll();
$more  = count($rows) > $limit;
$rows  = array_slice($rows, 0, $limit);
$byMonth = [];
foreach ($rows as $r) {
    $byMonth[substr($r['charged_at'], 0, 7)][] = $r;
}

$over     = $b['remaining'] < 0;
$pct      = $b['available'] > 0 ? min(100, (int) round($b['spent'] * 100 / $b['available'])) : 100;
$nextDate = $b['next_month']->format('d/m/Y');

page_head('Recharges EV');
?>
<main class="wrap">
  <?php render_banners(); ?>

  <section class="card hero<?= $over ? ' over' : '' ?>" aria-labelledby="hero-label">
    <p class="label" id="hero-label"><?= $over ? 'Dépassement' : 'Budget restant' ?></p>
    <p class="big"><?= e(eur($b['remaining'])) ?></p>
    <p class="sub">
      <?php if ($over): ?>
        Budget épuisé : 0 kWh restant. Ce dépassement sera déduit du mois suivant.
      <?php else: ?>
        ≈ <strong><?= number_format($b['ac_kwh'], 0, ',', "\u{00A0}") ?> kWh</strong> encore rechargeables en AC
      <?php endif; ?>
    </p>
    <div class="bar" role="img" aria-label="<?= $pct ?> % du budget disponible utilisé"><i style="width:<?= $pct ?>%"></i></div>
    <dl class="kv">
      <dt>Report des mois précédents</dt>
      <dd class="<?= $b['carry'] < 0 ? 'neg' : '' ?>"><?= e(eur($b['carry'])) ?></dd>
      <dt>Budget de <?= e(mois_label($b['month'])) ?></dt>
      <dd><?= e(eur($b['monthly'])) ?></dd>
      <dt>Dépensé ce mois-ci</dt>
      <dd><?= e(eur($b['spent'])) ?></dd>
    </dl>
    <p class="hint">Prochaine remise à jour le <?= e($nextDate) ?> (+<?= e(eur($b['monthly'])) ?>).</p>
  </section>

  <section class="card" aria-labelledby="form-title">
    <h2 id="form-title">Nouvelle recharge</h2>
    <?php if ($flash): ?><p class="msg ok" role="status"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="msg bad" role="alert"><?= e($error) ?></p><?php endif; ?>

    <form method="post" action="index.php" id="recharge-form"
          data-rate-ac="<?= rate_10k('AC') ?>" data-rate-dc="<?= rate_10k('DC') ?>" data-vat="<?= vat_pct() ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="lat" value="">
      <input type="hidden" name="lng" value="">
      <input type="hidden" name="acc" value="">

      <label class="field" for="kwh">
        <span>kWh récupérés</span>
        <input type="text" id="kwh" name="kwh" inputmode="decimal" autocomplete="off" required
               maxlength="8" placeholder="ex. 12,35" title="Nombre de kWh, 2 décimales maximum (ex. 12,35)" pattern="\s*\d{0,4}([.,]\d{1,2})?\s*"
               value="<?= e($old['kwh']) ?>">
      </label>

      <div class="field">
        <span id="type-label">Type de recharge</span>
        <div class="switch" role="radiogroup" aria-labelledby="type-label">
          <input type="radio" id="type-ac" name="type" value="AC" <?= $old['type'] === 'AC' ? 'checked' : '' ?>>
          <label for="type-ac">AC<small>jusqu'à 22 kW</small></label>
          <input type="radio" id="type-dc" name="type" value="DC" <?= $old['type'] === 'DC' ? 'checked' : '' ?>>
          <label for="type-dc">DC<small>borne rapide</small></label>
        </div>
      </div>

      <p class="preview" id="preview" aria-live="polite">&nbsp;</p>
      <p class="geo" id="geo-status">Position : recherche en cours…</p>

      <button type="submit" class="btn" id="submit-btn">Enregistrer la recharge</button>
    </form>
  </section>

  <section aria-labelledby="hist-title">
    <h2 class="section-title" id="hist-title">Historique</h2>
    <?php if (!$byMonth): ?>
      <p class="card muted">Aucune recharge enregistrée pour l'instant.</p>
    <?php endif; ?>

    <?php foreach ($byMonth as $ym => $list): $t = $totals[$ym] ?? null; ?>
      <div class="card month">
        <header>
          <h3><?= e(ucfirst(mois_label($ym))) ?></h3>
          <?php if ($t): ?>
            <p class="muted"><?= $t['n'] ?> recharge<?= $t['n'] > 1 ? 's' : '' ?> · <?= e(kwh_fmt($t['kwh'])) ?> kWh · <strong><?= e(eur($t['cents'])) ?></strong></p>
          <?php endif; ?>
        </header>
        <ul class="history">
          <?php foreach ($list as $r): $map = map_url($r['latitude'], $r['longitude']); ?>
            <li>
              <div class="line1">
                <span class="badge <?= $r['type'] === 'DC' ? 'dc' : 'ac' ?>"><?= e($r['type']) ?></span>
                <span class="kwh"><?= e(kwh_fmt($r['kwh'])) ?> kWh</span>
                <span class="billed">(<?= (int) $r['kwh_billed'] ?> facturés)</span>
                <span class="amt"><?= e(eur(dec_cents($r['amount_ttc']))) ?></span>
              </div>
              <div class="line2">
                <span><?= e(fmt_datetime($r['charged_at'])) ?></span>
                <?php if ($map): ?>
                  <a href="<?= e($map) ?>" target="_blank" rel="noopener noreferrer">Voir sur la carte</a>
                <?php else: ?>
                  <span class="muted">position inconnue</span>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>

    <?php if ($more): ?>
      <p class="center"><a class="btn secondary" href="index.php?n=<?= $limit + 50 ?>">Voir plus</a></p>
    <?php endif; ?>
  </section>

  <footer class="foot">
    <a href="admin.php">Administration (PC)</a> · <a href="logout.php">Se déconnecter</a>
  </footer>
</main>
<?php page_foot('assets/app.js'); ?>
