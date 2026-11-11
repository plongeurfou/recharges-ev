<?php
declare(strict_types=1);

/** Version de l'application (voir aussi inc/changelog.php). */
const APP_VERSION = '2.1.0';

/**
 * Adresse du fichier « latest.json » publié avec chaque version sur le dépôt GitHub.
 * À renseigner une seule fois, avant la première publication (remplacer OWNER et REPO).
 */
const UPDATE_MANIFEST_URL = 'https://github.com/plongeurfou/recharges-ev/releases/latest/download/latest.json';

/**
 * Clé publique de signature des versions (base64), affichée par outils/generer_cles.php.
 * Sans elle, aucune mise à jour n'est jamais proposée.
 */
const UPDATE_PUBLIC_KEY = 'hzTeNbK3TQt3E25EL64PQScc/JMrCfc1/Brq7PC2dgQ=';
