<?php
declare(strict_types=1);

/*
 * Toute la logique d'argent est faite en entiers (centimes / centièmes de kWh)
 * pour éviter les erreurs d'arrondi des nombres à virgule.
 */

const MOIS_FR  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                  'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JOURS_FR = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

/** « 12,35 » ou « 12.35 » => 1235 (centièmes de kWh). null si invalide. */
function parse_kwh(string $s): ?int
{
    $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($s));
    $s = str_replace(',', '.', $s);
    if ($s !== '' && $s[0] === '.') {
        $s = '0' . $s;
    }
    if (!preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $h = (int) $m[1] * 100 + (int) substr(($m[2] ?? '') . '00', 0, 2);
    return ($h > 0 && $h <= 100000) ? $h : null;
}

/** Tarif HTVA en dix-millièmes d'euro par kWh (0.47 => 4700). */
function rate_10k(string $type): int
{
    return (int) round(((float) config()['tarifs'][$type]) * 10000);
}

function vat_pct(): int
{
    return (int) config()['tva_pct'];
}

/** Tout kWh entamé est facturé : 12,10 kWh => 13. */
function billed_kwh(int $kwhHundredths): int
{
    return intdiv($kwhHundredths + 99, 100);
}

/**
 * @return array{billed:int, rate10k:int, vat:int, htva:int, ttc:int} (montants en centimes)
 */
function compute_price(int $kwhHundredths, int $rate10k, int $vat): array
{
    $billed = billed_kwh($kwhHundredths);
    $htva   = intdiv($billed * $rate10k + 50, 100);
    $tva    = intdiv($htva * $vat + 50, 100);
    return ['billed' => $billed, 'rate10k' => $rate10k, 'vat' => $vat, 'htva' => $htva, 'ttc' => $htva + $tva];
}

/** Entier de centimes => « 123.45 » pour la base de données. */
function cents_dec(int $c): string
{
    $sign = $c < 0 ? '-' : '';
    $a = abs($c);
    return sprintf('%s%d.%02d', $sign, intdiv($a, 100), $a % 100);
}

function dec_cents(string $s): int
{
    return (int) round(((float) $s) * 100);
}

/** Affichage : « 1 234,50 € » (signe moins typographique). */
function eur(int $cents): string
{
    $s = number_format(abs($cents) / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
    return ($cents < 0 ? "\u{2212}" : '') . $s;
}

function kwh_fmt(string|int|float $kwh): string
{
    return number_format((float) $kwh, 2, ',', "\u{00A0}");
}

function hundredths_str(int $h): string
{
    return intdiv($h, 100) . ',' . sprintf('%02d', $h % 100);
}

function mois_label(string $ym): string
{
    [$y, $m] = explode('-', $ym);
    return MOIS_FR[(int) $m] . ' ' . $y;
}

function fmt_datetime(string $dt): string
{
    $d = new DateTimeImmutable($dt);
    return JOURS_FR[(int) $d->format('w')] . ' ' . $d->format('d/m/Y') . ' à ' . $d->format('H:i');
}

function map_url(?string $lat, ?string $lng): ?string
{
    if ($lat === null || $lng === null) {
        return null;
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
}

/** Lit lat/lng/précision envoyés par le navigateur ; null si absents ou invalides. */
function read_position(array $p): array
{
    $lat = filter_var($p['lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($p['lng'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return [null, null, null];
    }
    $acc = filter_var($p['acc'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
    return [sprintf('%.6f', $lat), sprintf('%.6f', $lng), $acc === false ? null : $acc];
}

/** Enregistre une recharge (date = maintenant). Ignore un doublon identique envoyé dans la minute. */
function record_recharge(PDO $db, int $kwhHundredths, string $type, ?string $lat, ?string $lng, ?int $acc): int
{
    $now = new DateTimeImmutable('now');
    $kwhDec = cents_dec($kwhHundredths);

    $dup = $db->prepare('SELECT id FROM recharges WHERE kwh = ? AND type = ? AND charged_at >= ? ORDER BY id DESC LIMIT 1');
    $dup->execute([$kwhDec, $type, $now->modify('-60 seconds')->format('Y-m-d H:i:s')]);
    if (($id = $dup->fetchColumn()) !== false) {
        return (int) $id;
    }

    $p = compute_price($kwhHundredths, rate_10k($type), vat_pct());
    $st = $db->prepare(
        'INSERT INTO recharges (charged_at, kwh, kwh_billed, type, rate_htva, vat_pct, amount_htva, amount_ttc, latitude, longitude, accuracy_m)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $now->format('Y-m-d H:i:s'), $kwhDec, $p['billed'], $type,
        sprintf('%.4f', $p['rate10k'] / 10000), $p['vat'],
        cents_dec($p['htva']), cents_dec($p['ttc']),
        $lat, $lng, $acc,
    ]);
    return (int) $db->lastInsertId();
}

/** Totaux par mois (clé « AAAA-MM ») : nombre, kWh, centimes TTC. */
function monthly_totals(PDO $db): array
{
    $rows = $db->query(
        "SELECT DATE_FORMAT(charged_at, '%Y-%m') AS ym, COUNT(*) AS n, SUM(kwh) AS kwh,
                CAST(ROUND(SUM(amount_ttc) * 100) AS SIGNED) AS cents
         FROM recharges GROUP BY ym ORDER BY ym"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['ym']] = ['n' => (int) $r['n'], 'kwh' => (float) $r['kwh'], 'cents' => (int) $r['cents']];
    }
    return $out;
}

/** Nombre de mois entre le mois de départ et $month inclus (>= 1). */
function months_between(DateTimeImmutable $start, DateTimeImmutable $month): int
{
    return ((int) $month->format('Y') - (int) $start->format('Y')) * 12
         + ((int) $month->format('n') - (int) $start->format('n')) + 1;
}

/**
 * État du budget pour le mois courant (montants en centimes).
 * Le solde est cumulatif : report + budget du mois − dépenses du mois.
 * Un dépassement est donc automatiquement déduit des mois suivants.
 */
function budget_status(PDO $db, ?DateTimeImmutable $now = null): array
{
    $cfg   = config();
    $now ??= new DateTimeImmutable('now');
    $start = new DateTimeImmutable($cfg['mois_depart'] . '-01 00:00:00');
    $monthStart = new DateTimeImmutable($now->format('Y-m-01 00:00:00'));
    if ($monthStart < $start) {
        $monthStart = $start;
    }
    $nextMonth = $monthStart->modify('+1 month');
    $months    = months_between($start, $monthStart);
    $monthly   = (int) round($cfg['budget_mensuel'] * 100);
    $initial   = (int) round($cfg['report_initial'] * 100);

    $sum = $db->prepare('SELECT COALESCE(CAST(ROUND(SUM(amount_ttc) * 100) AS SIGNED), 0) FROM recharges WHERE charged_at >= ? AND charged_at < ?');
    $sum->execute([$start->format('Y-m-d H:i:s'), $monthStart->format('Y-m-d H:i:s')]);
    $spentBefore = (int) $sum->fetchColumn();
    $sum->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
    $spentMonth = (int) $sum->fetchColumn();

    $carry     = $initial + $monthly * ($months - 1) - $spentBefore;
    $available = $carry + $monthly;
    $remaining = $available - $spentMonth;

    // Estimation en AC : nombre de kWh dont le prix TVAC tient dans le solde restant.
    $acKwh = $remaining > 0 ? intdiv($remaining * 10000, rate_10k('AC') * (100 + vat_pct())) : 0;

    return [
        'month'      => $monthStart->format('Y-m'),
        'next_month' => $nextMonth,
        'monthly'    => $monthly,
        'carry'      => $carry,
        'available'  => $available,
        'spent'      => $spentMonth,
        'remaining'  => $remaining,
        'ac_kwh'     => $acKwh,
    ];
}
