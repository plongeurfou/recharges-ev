# Guide GitHub — mise en place (à faire une fois)

## 1. Compte et dépôt
1. Créez un compte sur github.com et **activez l'authentification à deux facteurs** (Settings → Password and authentication). Votre compte est ce qui protège vos collègues.
2. *New repository* → nom par ex. `recharges-ev` → **Public** → créez-le (sans README).
3. Envoyez le contenu de ce dossier (`recharges-ev/`, `outils/`, `.gitignore`, `README.md`, `GUIDE_GITHUB.md`) : page du dépôt → *uploading an existing file* → glissez les fichiers → *Commit changes*.
   Vérifiez que `inc/local.php`, `inc/config.php` et tout fichier `.key` n'apparaissent pas.

## 2. Clés de signature (une seule fois, sur votre PC, PHP en ligne de commande avec sodium)
```
php outils/generer_cles.php cle_privee_recharges_ev.key
```
- Le fichier `.key` est votre **clé privée** : sauvegardez-le (coffre de mots de passe, clé USB), ne le mettez **jamais** sur GitHub. Si vous le perdez, vos collègues devront réinstaller une version avec une nouvelle clé publique.
- La commande affiche la **clé publique**.

## 3. Deux constantes à renseigner dans `recharges-ev/inc/version.php`
```php
const UPDATE_MANIFEST_URL = 'https://github.com/VOTRE_COMPTE/VOTRE_DEPOT/releases/latest/download/latest.json';
const UPDATE_PUBLIC_KEY   = 'la clé publique affichée à l\'étape 2';
```
Modifiez le fichier sur GitHub (icône crayon) ou en local, puis recommencez l'envoi.

## 4. Publier la version 2.1.0
```
php outils/publier.php --cle=cle_privee_recharges_ev.key
```
Le dossier `dist/` contient `recharges-ev-2.1.0.zip` et `latest.json`. Sur GitHub : *Releases → Draft a new release* → tag `v2.1.0` → titre indiqué par l'outil → glissez **les deux fichiers** → *Publish release* (cochée « latest »).

## 5. Votre propre installation
Installez d'abord la 2.1.0 chez vous à la main (copie FTP par-dessus la V2). Elle contient les constantes de l'étape 3.

## 6. Tester la détection
1. Dans `changelog.php`, ajoutez une entrée `2.1.1`, passez `APP_VERSION` à `2.1.1`, puis `php outils/publier.php --cle=...` et publiez la release `v2.1.1`.
2. Sur votre installation 2.1.0 : page *Mises à jour* → *Vérifier maintenant* → la 2.1.1 apparaît avec ses nouveautés.
3. Copiez les fichiers 2.1.1 : le bandeau de nouveautés s'affiche.

## Pour chaque future version
Mettre à jour `APP_VERSION` et `changelog.php`, lancer `publier.php`, créer la release avec les deux fichiers. Tant que la release n'est pas publiée comme « latest », personne n'est notifié.

## Bon à savoir
- Les collègues n'ont besoin d'aucun compte GitHub.
- Aucune donnée n'est envoyée à GitHub à part la requête HTTPS (version de l'app dans le User-Agent).
- Pas d'automatisation GitHub Actions pour signer : la clé privée resterait chez GitHub et affaiblirait la protection.
