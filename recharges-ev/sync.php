<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/*
 * Réception des recharges saisies hors ligne (une par appel, JSON en réponse).
 * L'envoi est idempotent grâce à l'identifiant (uid) généré par l'appareil.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$reply = function (int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (app_state() !== 'ready') {
    $reply(503, ['ok' => false, 'error' => 'Application non configurée.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}
if (!is_logged_in()) {
    $reply(401, ['ok' => false, 'auth' => true, 'error' => 'Session expirée : reconnectez-vous.']);
}
$t = $_POST['csrf'] ?? '';
if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
    $reply(403, ['ok' => false, 'auth' => true, 'error' => 'Jeton périmé : rechargez la page.']);
}

$db   = db();
$uid  = parse_uid($_POST['uid'] ?? null);
$type = ($_POST['type'] ?? '') === 'DC' ? 'DC' : (($_POST['type'] ?? '') === 'AC' ? 'AC' : null);
$h    = parse_kwh(trim((string) ($_POST['kwh'] ?? '')));
$ts   = filter_var($_POST['ts'] ?? '', FILTER_VALIDATE_INT);
$now  = time();

if ($uid === null || $type === null) {
    $reply(422, ['ok' => false, 'error' => 'Données incomplètes.']);
}
if ($h === null) {
    $reply(422, ['ok' => false, 'error' => 'Quantité invalide.']);
}
if ($ts === false || $ts < $now - 30 * 86400) {
    $reply(422, ['ok' => false, 'error' => 'Date de saisie absente ou de plus de 30 jours : saisissez cette recharge dans l\'administration.']);
}
$at = (new DateTimeImmutable('@' . min($ts, $now)))->setTimezone(new DateTimeZone(date_default_timezone_get()));

[$lat, $lng, $acc] = read_position($_POST);

/* Compteur : accepté s'il est cohérent avec les relevés voisins, sinon la recharge est enregistrée sans compteur. */
$warning = null;
$odo = null;
if (odometer_enabled()) {
    $odo = parse_odometer(trim((string) ($_POST['odo'] ?? '')));
    if ($odo === false) {
        $odo = null;
        $warning = 'compteur illisible, ignoré';
    } elseif ($odo !== null) {
        $when = $at->format('Y-m-d H:i:s');
        $p = $db->prepare('SELECT odometer_km FROM recharges WHERE odometer_km IS NOT NULL AND charged_at <= ? ORDER BY charged_at DESC, id DESC LIMIT 1');
        $p->execute([$when]);
        $n = $db->prepare('SELECT odometer_km FROM recharges WHERE odometer_km IS NOT NULL AND charged_at > ? ORDER BY charged_at ASC, id ASC LIMIT 1');
        $n->execute([$when]);
        $pv = $p->fetchColumn();
        $nx = $n->fetchColumn();
        if (($pv !== false && $odo < (int) $pv) || ($nx !== false && $odo > (int) $nx)) {
            $odo = null;
            $warning = 'compteur incohérent avec les relevés voisins, ignoré (à corriger dans la recharge)';
        }
    }
}

try {
    $id = record_recharge($db, $h, $type, $lat, $lng, $acc, $odo, $uid, $at);
} catch (Throwable $e) {
    $reply(500, ['ok' => false, 'retry' => true, 'error' => 'Erreur serveur : réessayez plus tard.']);
}
$reply(200, ['ok' => true, 'id' => $id, 'warning' => $warning]);
