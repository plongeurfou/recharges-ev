# Recharges EV

Application web personnelle (PHP 8 + MySQL/MariaDB) de suivi du budget de recharge d'un véhicule électrique : saisie des kWh (AC/DC), tarifs, TVA, budget mensuel avec report, estimation des kWh restants. Interface en français, montants en EUR, conçue pour Android (Chrome) avec une page d'administration sur PC.

## Installation (hébergement PHP, ex. Alwaysdata)
1. Téléchargez le zip de la [dernière version](../../releases/latest) et décompressez-le.
2. Créez une base MySQL/MariaDB et un utilisateur sur votre hébergement.
3. Copiez le dossier `recharges-ev/` par FTP.
4. Créez par FTP le fichier `inc/code_installation.txt` contenant un code de votre choix (8 caractères minimum).
5. Ouvrez `https://votre-site/recharges-ev/` : l'assistant demande le code, la base, le budget, les tarifs et le mot de passe.
6. Contrôlez la page **Diagnostic**.

## Mise à jour
L'application vérifie chaque jour la présence d'une nouvelle version (fichier `latest.json` signé), affiche les nouveautés et propose le téléchargement. Copiez les nouveaux fichiers par FTP (sans toucher à `inc/local.php`) : la base se met à jour automatiquement.

## Pour le mainteneur
Voir `GUIDE_GITHUB.md` (clés de signature, publication d'une version).
