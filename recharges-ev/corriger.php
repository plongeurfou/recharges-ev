<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

/*
 * Correction d'une saisie depuis le mobile : quantité, type AC/DC, compteur — ou suppression.
 * Limité aux recharges des 7 derniers jours ; l'administration PC reste disponible pour tout le reste.
 * Le tarif figé à la saisie est conservé, sauf si le type change (tarif actuel du nouveau type).
 */
const CORRECTION_DAYS = 7;

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $db->prepare('SELECT * FROM recharges WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
$limit = (new DateTimeImmutable('now'))->modify('-' . CORRECTION_DAYS . ' days')->format('Y-m-d H:i:s');

if (!$r || $r['charged_at'] < $limit) {
    http_response_code(404);
    page_head('Correction — Recharges EV');
    echo '<main class="wrap"><section class="card"><p class="msg bad">Cette recharge n\'existe plus ou date de plus de ' . CORRECTION_DAYS
       . ' jours (utilisez l\'administration sur PC).</p><p><a href="index.php">← Retour</a></p></section></main>';
    page_foot();
    exit;
}

$odoOn   = odometer_enabled() || $r['odometer_km'] !== null;
$error   = null;
$form    = ['kwh' => kwh_fmt((float) $r['kwh']), 'type' => $r['type'], 'odo' => $r['odometer_km'] === null ? '' : (string) $r['odometer_km']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        if (empty($_POST['confirm'])) {
            $error = 'Cochez « Je confirme la suppression » pour supprimer cette recharge.';
        } else {
            $db->prepare('DELETE FROM recharges WHERE id = ?')->execute([$id]);
            header('Location: index.php?ok=deleted');
            exit;
        }
    } else {
        $form['kwh']  = trim((string) ($_POST['kwh'] ?? ''));
        $form['type'] = ($_POST['type'] ?? '') === 'DC' ? 'DC' : 'AC';
        $h   = parse_kwh($form['kwh']);
        $odo = null;
        if ($odoOn) {
            $form['odo'] = trim((string) ($_POST['odo'] ?? ''));
            $odo = parse_odometer($form['odo']);
        }
        if ($h === null) {
            $error = 'Quantité invalide : nombre de kWh supérieur à 0, 2 décimales maximum (ex. 12,35).';
        } elseif ($odo === false) {
            $error = 'Compteur invalide : nombre entier de kilomètres ou champ vide.';
        } else {
            if ($odo !== null) {
                $prev = $db->prepare('SELECT odometer_km FROM recharges WHERE odometer_km IS NOT NULL AND id <> ? AND (charged_at < ? OR (charged_at = ? AND id < ?)) ORDER BY charged_at DESC, id DESC LIMIT 1');
                $prev->execute([$id, $r['charged_at'], $r['charged_at'], $id]);
                $next = $db->prepare('SELECT odometer_km FROM recharges WHERE odometer_km IS NOT NULL AND id <> ? AND (charged_at > ? OR (charged_at = ? AND id > ?)) ORDER BY charged_at ASC, id ASC LIMIT 1');
                $next->execute([$id, $r['charged_at'], $r['charged_at'], $id]);
                $p = $prev->fetchColumn();
                $n = $next->fetchColumn();
                $nf = fn (int $v): string => number_format($v, 0, ',', "\u{00A0}") . ' km';
                if ($p !== false && $odo < (int) $p) {
                    $error = 'Le compteur doit être au moins égal au relevé précédent (' . $nf((int) $p) . ').';
                } elseif ($n !== false && $odo > (int) $n) {
                    $error = 'Le compteur doit être inférieur ou égal au relevé suivant (' . $nf((int) $n) . ').';
                }
            }
            if ($error === null) {
                $useCurrent = $r['type'] !== $form['type'];
                $rate10k = $useCurrent ? rate_10k($form['type']) : (int) round(((float) $r['rate_htva']) * 10000);
                $vat     = $useCurrent ? vat_pct() : (int) round((float) $r['vat_pct']);
                $pr      = compute_price($h, $rate10k, $vat);
                $db->prepare('UPDATE recharges SET kwh = ?, kwh_billed = ?, type = ?, rate_htva = ?, vat_pct = ?, amount_htva = ?, amount_ttc = ?, odometer_km = ? WHERE id = ?')
                   ->execute([cents_dec($h), $pr['billed'], $form['type'], sprintf('%.4f', $pr['rate10k'] / 10000), $pr['vat'],
                              cents_dec($pr['htva']), cents_dec($pr['ttc']), $odoOn ? $odo : $r['odometer_km'], $id]);
                header('Location: index.php?ok=corrected');
                exit;
            }
        }
    }
}

page_head('Corriger une recharge — Recharges EV');
?>
<main class="wrap">
  <?php render_appbar('Corriger'); ?>
  <section class="card">
    <p class="help">Recharge du <?= e(fmt_datetime($r['charged_at'])) ?> — enregistrée à <?= e(eur(dec_cents($r['amount_ttc']))) ?>.</p>
    <form method="post" action="corriger.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="save">
      <label class="field" for="kwh"><span>kWh récupérés</span>
        <input type="text" id="kwh" name="kwh" inputmode="decimal" required maxlength="8" value="<?= e($form['kwh']) ?>"
               pattern="\s*\d{0,4}([.,]\d{1,2})?\s*" title="Nombre de kWh, 2 décimales maximum (ex. 12,35)"></label>
      <div class="field">
        <span id="type-label">Type de recharge</span>
        <div class="switch" role="radiogroup" aria-labelledby="type-label">
          <input type="radio" id="type-ac" name="type" value="AC" <?= $form['type'] === 'AC' ? 'checked' : '' ?>>
          <label for="type-ac">AC<small>jusqu'à 22 kW</small></label>
          <input type="radio" id="type-dc" name="type" value="DC" <?= $form['type'] === 'DC' ? 'checked' : '' ?>>
          <label for="type-dc">DC<small>borne rapide</small></label>
        </div>
      </div>
      <?php if ($odoOn): ?>
      <label class="field" for="odo"><span>Compteur (km) <small class="muted">— facultatif</small></span>
        <input type="text" id="odo" name="odo" inputmode="numeric" maxlength="9" value="<?= e($form['odo']) ?>"
               pattern="[\s0-9  ]{0,9}" title="Nombre entier de kilomètres"></label>
      <?php endif; ?>
      <?php if ($error): ?><p class="msg bad" role="alert"><?= e($error) ?></p><?php endif; ?>
      <p class="help">Le tarif appliqué à la saisie est conservé (le montant est recalculé avec la nouvelle quantité). Si vous changez AC/DC, le tarif actuel du nouveau type s'applique.</p>
      <button type="submit" class="btn">Enregistrer la correction</button>
    </form>
    <p class="center"><a href="index.php">Annuler</a></p>
  </section>

  <section class="card">
    <h2>Supprimer cette recharge</h2>
    <form method="post" action="corriger.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1"> Je confirme la suppression</label>
      <button type="submit" class="btn danger">Supprimer</button>
    </form>
  </section>
  <?php render_version(); ?>
</main>
<?php page_foot(['assets/menu.js']); ?>
