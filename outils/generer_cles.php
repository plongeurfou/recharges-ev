<?php
declare(strict_types=1);

/*
 * Génère la paire de clés de signature des versions (à faire UNE SEULE FOIS).
 *   php outils/generer_cles.php [fichier_cle_privee]
 * - La clé PRIVÉE est écrite dans un fichier : gardez-la hors du dépôt GitHub (sauvegarde sûre !).
 * - La clé PUBLIQUE s'affiche : collez-la dans UPDATE_PUBLIC_KEY (recharges-ev/inc/version.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "Extension PHP sodium requise.\n"); exit(1); }

$file = $argv[1] ?? 'cle_privee_recharges_ev.key';
if (file_exists($file)) { fwrite(STDERR, "Refus : $file existe déjà (rien n'a été écrasé).\n"); exit(1); }

$kp  = sodium_crypto_sign_keypair();
$sk  = sodium_crypto_sign_secretkey($kp);
$pk  = sodium_crypto_sign_publickey($kp);
if (file_put_contents($file, base64_encode($sk) . "\n") === false) { fwrite(STDERR, "Écriture impossible.\n"); exit(1); }
@chmod($file, 0600);

echo "Clé privée écrite dans : $file  (NE JAMAIS la publier ni la mettre sur GitHub)\n\n";
echo "Clé publique à coller dans recharges-ev/inc/version.php (UPDATE_PUBLIC_KEY) :\n\n";
echo base64_encode($pk) . "\n";
