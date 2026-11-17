<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

$db = db();

/* ---------- Données ---------- */
$tot = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(kwh),0) AS kwh, COALESCE(SUM(kwh_billed),0) AS billed,
                          CAST(ROUND(COALESCE(SUM(amount_ttc),0) * 100) AS SIGNED) AS cents,
                          COALESCE(SUM(CASE WHEN type = 'AC' THEN kwh END),0) AS ac_kwh,
                          COALESCE(SUM(CASE WHEN type = 'DC' THEN kwh END),0) AS dc_kwh,
                          SUM(type = 'AC') AS ac_n, SUM(type = 'DC') AS dc_n, MIN(charged_at) AS first_at
                   FROM recharges")->fetch();
$n = (int) $tot['n'];

$totals = monthly_totals($db);              // AAAA-MM => n, kwh, cents
krsort($totals);
$monthly = (int) round(config()['budget_mensuel'] * 100);
$maxBar  = max($monthly, $totals ? max(array_column($totals, 'cents')) : 0, 1);

$segments = consumption_segments($db);
$cons     = consumption_summary($segments);
$odoOn    = odometer_enabled();
$odoCount = (int) $db->query('SELECT COUNT(*) FROM recharges WHERE odometer_km IS NOT NULL')->fetchColumn();
$b        = budget_status($db);
$rangeKm  = ($cons && $b['ac_kwh'] > 0) ? (int) round($b['ac_kwh'] * 100 / $cons['kwh100']) : null;

$nf  = fn (float $v, int $d = 0): string => number_format($v, $d, ',', "\u{00A0}");
$cent = fn (float $c): string => eur((int) round($c));

page_head('Statistiques — Recharges EV');
?>
<main class="wrap">
  <?php render_appbar('Statistiques'); ?>
  <?php render_banners(); ?>

  <?php if ($n === 0): ?>
    <p class="card muted">Pas encore de données : les statistiques apparaîtront dès les premières recharges et s'enrichiront avec le temps.</p>
  <?php else: ?>

  <section class="card" aria-labelledby="st-syn">
    <h2 id="st-syn">Synthèse</h2>
    <dl class="kv">
      <dt>Recharges enregistrées</dt><dd><?= $n ?></dd>
      <dt>Énergie récupérée</dt><dd><?= e($nf((float) $tot['kwh'], 2)) ?> kWh</dd>
      <dt>Total dépensé (TVAC)</dt><dd><?= e(eur((int) $tot['cents'])) ?></dd>
      <dt>Coût moyen</dt><dd><?= (float) $tot['kwh'] > 0 ? e($nf($tot['cents'] / 100 / (float) $tot['kwh'], 3)) . ' €/kWh' : '—' ?></dd>
      <dt>Part AC / DC (kWh)</dt>
      <dd><?= (float) $tot['kwh'] > 0 ? e($nf((float) $tot['ac_kwh'] * 100 / (float) $tot['kwh'])) . ' % / ' . e($nf((float) $tot['dc_kwh'] * 100 / (float) $tot['kwh'])) . ' %' : '—' ?></dd>
      <dt>Depuis le</dt><dd><?= e((new DateTimeImmutable((string) $tot['first_at']))->format('d/m/Y')) ?></dd>
    </dl>
  </section>

  <section class="card" aria-labelledby="st-mois">
    <h2 id="st-mois">Mois par mois</h2>
    <p class="help">La barre compare la dépense du mois au budget mensuel (<?= e(eur($monthly)) ?>).</p>
    <ul class="bars">
      <?php foreach ($totals as $ym => $t): $over = $t['cents'] > $monthly; ?>
        <li>
          <div class="bar-head">
            <span><?= e(ucfirst(mois_label($ym))) ?></span>
            <strong class="<?= $over ? 'neg' : '' ?>"><?= e(eur((int) $t['cents'])) ?></strong>
          </div>
          <div class="hbar<?= $over ? ' over' : '' ?>" role="img" aria-label="<?= e(eur((int) $t['cents'])) ?> sur un budget de <?= e(eur($monthly)) ?>">
            <i style="width:<?= (int) round($t['cents'] * 100 / $maxBar) ?>%"></i>
            <?php if ($maxBar > $monthly): ?><b style="left:<?= (int) round($monthly * 100 / $maxBar) ?>%"></b><?php endif; ?>
          </div>
          <p class="help"><?= $t['n'] ?> recharge<?= $t['n'] > 1 ? 's' : '' ?> · <?= e(kwh_fmt($t['kwh'])) ?> kWh<?= $t['kwh'] > 0 ? ' · ' . e($nf($t['cents'] / 100 / $t['kwh'], 3)) . ' €/kWh' : '' ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card" aria-labelledby="st-conso">
    <h2 id="st-conso">Consommation</h2>
    <?php if (!$odoOn && $odoCount === 0): ?>
      <p class="help">Activez l'option « Odomètre » dans le menu ☰ et saisissez le compteur à chaque recharge : la consommation, le coût au kilomètre et l'autonomie budgétaire apparaîtront ici.</p>
    <?php elseif (!$cons): ?>
      <p class="help">Il faut au moins deux recharges avec un compteur différent pour calculer une consommation (<?= $odoCount ?> relevé<?= $odoCount > 1 ? 's' : '' ?> pour l'instant). Les chiffres apparaîtront ici dès le prochain relevé.</p>
    <?php else: ?>
      <dl class="kv">
        <dt>Consommation moyenne</dt><dd><?= e($nf($cons['kwh100'], 1)) ?> kWh/100 km</dd>
        <dt>Dernier trajet</dt><dd><?= e($nf($cons['last_kwh100'], 1)) ?> kWh/100 km</dd>
        <dt>Coût aux 100 km</dt><dd><?= e($cent($cons['cents100'])) ?></dd>
        <dt>Coût au kilomètre</dt><dd><?= e($nf($cons['cents_km'] / 100, 3)) ?> €</dd>
        <dt>Distance suivie</dt><dd><?= e($nf((float) $cons['km'])) ?> km</dd>
        <?php if ($rangeKm !== null): ?>
          <dt>Autonomie du budget restant</dt><dd>≈ <?= e($nf((float) $rangeKm)) ?> km</dd>
        <?php endif; ?>
      </dl>
      <p class="help">Calcul « plein à plein » : kWh rechargés entre deux relevés ÷ kilomètres parcourus. Plus il y a de relevés, plus c'est fiable
        (une recharge partielle en DC peut fausser un trajet isolé).</p>

      <h3 class="ver">Derniers trajets</h3>
      <ul class="history">
        <?php foreach (array_slice(array_reverse($segments), 0, 10) as $s): ?>
          <li>
            <div class="line1">
              <span class="kwh"><?= e($nf((float) $s['km'])) ?> km</span>
              <span class="billed"><?= e($nf($s['kwh'] * 100 / $s['km'], 1)) ?> kWh/100 km</span>
              <span class="amt"><?= e(eur((int) $s['cents'])) ?></span>
            </div>
            <div class="line2"><span><?= e((new DateTimeImmutable($s['from']))->format('d/m/Y')) ?> → <?= e((new DateTimeImmutable($s['to']))->format('d/m/Y')) ?></span></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php endif; ?>
</main>
<?php page_foot(['assets/menu.js']); ?>
