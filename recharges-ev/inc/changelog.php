<?php
declare(strict_types=1);

/*
 * Notes de version, de la plus récente à la plus ancienne.
 * Source unique : affichées dans l'application après une mise à jour et reprises
 * dans le fichier latest.json par outils/publier.php.
 */
return [
    '2.1.0' => [
        'date'  => '2026-11-10',
        'titre' => 'Mises à jour, diagnostic et installation sécurisée',
        'notes' => [
            'Nouvelle page « Mises à jour » : l\'application vérifie chaque jour si une nouvelle version existe et affiche ses nouveautés.',
            'La base de données se met à jour toute seule quand de nouveaux fichiers sont copiés.',
            'Nouvelle page « Diagnostic » : vérifie PHP, les droits d\'écriture, le HTTPS, la base de données et la protection du dossier inc/.',
            'Code d\'installation : l\'assistant de première configuration n\'est accessible qu\'avec un code que vous choisissez.',
        ],
    ],
    '2.0.0' => [
        'date'  => '2026-11-05',
        'titre' => 'Paramètres en base de données',
        'notes' => [
            'Tous les réglages (budget, mois de départ, tarifs, TVA, mot de passe) se gèrent dans parametres.php.',
            'Assistant de configuration au premier lancement ; les tables sont créées automatiquement.',
        ],
    ],
];
