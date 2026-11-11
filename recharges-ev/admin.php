<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

$db   = db();
$err  = null;
$edit = null;   // formulaire ouvert (tableau) ou null
$page = max(1, (int) ($_GET['p'] ?? 1));

/* ---------- Actions POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $db->prepare('DELETE FROM recharges WHERE id = ?')->execute([$id]);
        header('Location: admin.php?msg=deleted&p=' . $page);
        exit;
    }

    if ($action === 'save') {
        $type  = ($_POST['type'] ?? '') === 'DC' ? 'DC' : 'AC';
        $h     = parse_kwh((string) ($_POST['kwh'] ?? ''));
        $at    = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($_POST['charged_at'] ?? ''));
        $latIn = trim((string) ($_POST['lat'] ?? ''));
        $lngIn = trim((string) ($_POST['lng'] ?? ''));
        $edit  = ['id' => $id, 'charged_at' => (string) ($_POST['charged_at'] ?? ''), 'kwh' => (string) ($_POST['kwh'] ?? ''),
                  'type' => $type, 'lat' => $latIn, 'lng' => $lngIn];

        $lat = $lng = null;
        if ($latIn !== '' || $lngIn !== '') {
            [$lat, $lng] = read_position(['lat' => str_replace(',', '.', $latIn), 'lng' => str_replace(',', '.', $lngIn)]);
            if ($lat === null) {
                $err = 'Position invalide : renseignez latitude et longitude (ex. 50,6025 et 4,1370) ou laissez les deux vides.';
            }
        }
        if ($err === null && $h === null) {
            $err = 'Quantité invalide : nombre de kWh supérieur à 0, 2 décimales maximum.';
        }
        if ($err === null && (!$at || $at->format('Y-m-d\TH:i') !== $edit['charged_at'])) {
            $err = 'Date et heure invalides.';
        }

        if ($err === null) {
            $old = null;
            if ($id > 0) {
                $st = $db->prepare('SELECT * FROM recharges WHERE id = ?');
                $st->execute([$id]);
                $old = $st->fetch() ?: null;
            }
            // Tarif figé à la saisie, sauf changement de type ou demande de recalcul avec les tarifs actuels.
            $useCurrent = $old === null || $old['type'] !== $type || !empty($_POST['recalc']);
            $rate10k = $useCurrent ? rate_10k($type) : (int) round(((float) $old['rate_htva']) * 10000);
            $vat     = $useCurrent ? vat_pct() : (int) round((float) $old['vat_pct']);
            $p       = compute_price($h, $rate10k, $vat);
            $vals = [$at->format('Y-m-d H:i:s'), cents_dec($h), $p['billed'], $type, sprintf('%.4f', $rate10k / 10000), $vat,
                     cents_dec($p['htva']), cents_dec($p['ttc']), $lat, $lng];

            if ($old === null) {
                $db->prepare('INSERT INTO recharges (charged_at, kwh, kwh_billed, type, rate_htva, vat_pct, amount_htva, amount_ttc, latitude, longitude)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($vals);
                header('Location: admin.php?msg=added');
            } else {
                $samePos = $old['latitude'] === $lat && $old['longitude'] === $lng;
                $vals[]  = $samePos ? $old['accuracy_m'] : null;
                $vals[]  = $id;
                $db->prepare('UPDATE recharges SET charged_at = ?, kwh = ?, kwh_billed = ?, type = ?, rate_htva = ?, vat_pct = ?,
                              amount_htva = ?, amount_ttc = ?, latitude = ?, longitude = ?, accuracy_m = ? WHERE id = ?')->execute($vals);
                header('Location: admin.php?msg=saved&p=' . $page);
            }
            exit;
        }
    }
}

/* ---------- Ouverture du formulaire ---------- */
if ($edit === null && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    if ($eid === 0) {
        $edit = ['id' => 0, 'charged_at' => date('Y-m-d\TH:i'), 'kwh' => '', 'type' => 'AC', 'lat' => '', 'lng' => ''];
    } else {
        $st = $db->prepare('SELECT * FROM recharges WHERE id = ?');
        $st->execute([$eid]);
        if ($r = $st->fetch()) {
            $edit = ['id' => $eid, 'charged_at' => (new DateTimeImmutable($r['charged_at']))->format('Y-m-d\TH:i'),
                     'kwh' => str_replace('.', ',', $r['kwh']), 'type' => $r['type'],
                     'lat' => (string) $r['latitude'], 'lng' => (string) $r['longitude']];
        }
    }
}

/* ---------- Récapitulatif mensuel (budget cumulatif) ---------- */
$cfg     = config();
$totals  = monthly_totals($db);
$start   = new DateTimeImmutable($cfg['mois_depart'] . '-01');
$current = new DateTimeImmutable(date('Y-m-01'));
if ($current < $start) {
    $current = $start;
}
$monthly = (int) round($cfg['budget_mensuel'] * 100);
$carry   = (int) round($cfg['report_initial'] * 100);
$summary = [];
for ($m = $start; $m <= $current; $m = $m->modify('+1 month')) {
    $ym    = $m->format('Y-m');
    $spent = $totals[$ym]['cents'] ?? 0;
    $avail = $carry + $monthly;
    $summary[] = ['ym' => $ym, 'carry' => $carry, 'monthly' => $monthly, 'spent' => $spent, 'end' => $avail - $spent,
                  'n' => $totals[$ym]['n'] ?? 0, 'kwh' => $totals[$ym]['kwh'] ?? 0.0];
    $carry = $avail - $spent;
}
$summary = array_reverse($summary);

/* ---------- Liste paginée ---------- */
$perPage = 100;
$count   = (int) $db->query('SELECT COUNT(*) FROM recharges')->fetchColumn();
$pages   = max(1, (int) ceil($count / $perPage));
$page    = min($page, $pages);
$rows    = $db->query('SELECT * FROM recharges ORDER BY charged_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage))->fetchAll();

$msgs = ['saved' => 'Recharge modifiée.', 'added' => 'Recharge ajoutée.', 'deleted' => 'Recharge supprimée.'];
$msg  = $msgs[$_GET['msg'] ?? ''] ?? null;

page_head('Administration — Recharges EV', 'wide');
?>
<main class="wrap">
  <header class="topbar">
    <h1>Administration</h1>
    <nav><a class="btn small secondary" href="parametres.php">Paramètres</a> <a href="maj.php">Mises à jour</a> · <a href="diagnostic.php">Diagnostic</a> · <a href="index.php">← Vue mobile</a> · <a href="logout.php">Se déconnecter</a></nav>
  </header>
  <?php render_banners(); ?>

  <?php if ($msg): ?><p class="msg ok" role="status"><?= e($msg) ?></p><?php endif; ?>

  <?php if ($edit): ?>
    <section class="card" id="edit">
      <h2><?= $edit['id'] ? 'Modifier la recharge n° ' . (int) $edit['id'] : 'Ajouter une recharge' ?></h2>
      <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
      <form method="post" action="admin.php?p=<?= $page ?>" class="grid-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <label class="field"><span>Date et heure</span>
          <input type="datetime-local" name="charged_at" value="<?= e($edit['charged_at']) ?>" required></label>
        <label class="field"><span>kWh récupérés</span>
          <input type="text" name="kwh" inputmode="decimal" value="<?= e($edit['kwh']) ?>" required placeholder="12,35"></label>
        <label class="field"><span>Type</span>
          <select name="type">
            <option value="AC" <?= $edit['type'] === 'AC' ? 'selected' : '' ?>>AC (jusqu'à 22 kW)</option>
            <option value="DC" <?= $edit['type'] === 'DC' ? 'selected' : '' ?>>DC (rapide)</option>
          </select></label>
        <label class="field"><span>Latitude</span>
          <input type="text" name="lat" inputmode="decimal" value="<?= e($edit['lat']) ?>" placeholder="50,602500"></label>
        <label class="field"><span>Longitude</span>
          <input type="text" name="lng" inputmode="decimal" value="<?= e($edit['lng']) ?>" placeholder="4,137000"></label>
        <?php if ($edit['id']): ?>
          <label class="check"><input type="checkbox" name="recalc" value="1"> Recalculer le prix avec les tarifs actuels
            <small>(sinon le tarif enregistré à l'époque est conservé, sauf si le type change)</small></label>
        <?php endif; ?>
        <div class="actions">
          <button type="submit" class="btn">Enregistrer</button>
          <a class="btn secondary" href="admin.php?p=<?= $page ?>">Annuler</a>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2>Budget mois par mois</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Mois</th><th class="num">Report</th><th class="num">Budget</th><th class="num">Recharges</th><th class="num">kWh</th><th class="num">Dépensé</th><th class="num">Solde fin de mois</th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
          <tr>
            <td><?= e(ucfirst(mois_label($s['ym']))) ?></td>
            <td class="num <?= $s['carry'] < 0 ? 'neg' : '' ?>"><?= e(eur($s['carry'])) ?></td>
            <td class="num"><?= e(eur($s['monthly'])) ?></td>
            <td class="num"><?= $s['n'] ?></td>
            <td class="num"><?= e(kwh_fmt($s['kwh'])) ?></td>
            <td class="num"><?= e(eur($s['spent'])) ?></td>
            <td class="num <?= $s['end'] < 0 ? 'neg' : 'pos' ?>"><strong><?= e(eur($s['end'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <div class="toolbar">
      <h2>Recharges (<?= $count ?>)</h2>
      <a class="btn small" href="admin.php?edit=0&amp;p=<?= $page ?>#edit">+ Ajouter</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>N°</th><th>Date et heure</th><th>Type</th><th class="num">kWh</th><th class="num">Facturés</th>
          <th class="num">Tarif</th><th class="num">HTVA</th><th class="num">TVAC</th><th>Position</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $map = map_url($r['latitude'], $r['longitude']); ?>
          <tr>
            <td><?= (int) $r['id'] ?></td>
            <td><?= e(fmt_datetime($r['charged_at'])) ?></td>
            <td><span class="badge <?= $r['type'] === 'DC' ? 'dc' : 'ac' ?>"><?= e($r['type']) ?></span></td>
            <td class="num"><?= e(kwh_fmt($r['kwh'])) ?></td>
            <td class="num"><?= (int) $r['kwh_billed'] ?></td>
            <td class="num"><?= e(number_format((float) $r['rate_htva'], 2, ',', '')) ?></td>
            <td class="num"><?= e(eur(dec_cents($r['amount_htva']))) ?></td>
            <td class="num"><strong><?= e(eur(dec_cents($r['amount_ttc']))) ?></strong></td>
            <td><?php if ($map): ?><a href="<?= e($map) ?>" target="_blank" rel="noopener noreferrer">Carte</a>
                <?php if ($r['accuracy_m'] !== null): ?><small class="muted">±<?= (int) $r['accuracy_m'] ?> m</small><?php endif; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="actions-cell">
              <a class="btn small secondary" href="admin.php?edit=<?= (int) $r['id'] ?>&amp;p=<?= $page ?>#edit">Modifier</a>
              <form method="post" action="admin.php?p=<?= $page ?>" class="inline"
                    onsubmit="return confirm('Supprimer définitivement la recharge n° <?= (int) $r['id'] ?> ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn small danger">Supprimer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="muted">Aucune recharge.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <p class="pager">
        <?php if ($page > 1): ?><a href="admin.php?p=<?= $page - 1 ?>">← Plus récentes</a><?php endif; ?>
        <span>Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="admin.php?p=<?= $page + 1 ?>">Plus anciennes →</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </section>
</main>
<?php page_foot(); ?>
