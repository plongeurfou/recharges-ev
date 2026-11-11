<?php
declare(strict_types=1);

/*
 * Migrations de la base de données.
 *
 * Chaque migration porte un numéro, ne sert qu'une fois et doit pouvoir être rejouée sans dégât
 * (CREATE ... IF NOT EXISTS, ajout de colonne seulement si elle manque). Elles ne font qu'AJOUTER :
 * l'ancienne version du code reste ainsi compatible avec une base déjà migrée (retour arrière possible).
 * Le numéro atteint est mémorisé dans le réglage « schema_version ».
 */

const SCHEMA_VERSION = 1;

function migrations(): array
{
    return [
        // 1 : base de la V2 (tables recharges, login_attempts, settings)
        1 => function (PDO $db): void {
            create_tables($db);
        },
        // Exemple pour une version future :
        // 2 => function (PDO $db): void {
        //     if (!db_column_exists($db, 'recharges', 'lieu')) {
        //         $db->exec('ALTER TABLE recharges ADD COLUMN lieu VARCHAR(120) NULL');
        //     }
        // },
    ];
}

function db_column_exists(PDO $db, string $table, string $column): bool
{
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

/** Joue les migrations manquantes (un verrou évite deux exécutions simultanées). */
function run_pending_migrations(): void
{
    $target = max(SCHEMA_VERSION, (int) max(array_keys(migrations())));
    if ((int) settings()['schema_version'] >= $target) {
        return;
    }
    $db = db();
    if ((int) $db->query("SELECT GET_LOCK('recharges_ev_migrate', 20)")->fetchColumn() !== 1) {
        throw new RuntimeException('Mise à jour de la base de données déjà en cours.');
    }
    try {
        $current = (int) settings(true)['schema_version'];   // relu une fois le verrou obtenu
        foreach (migrations() as $number => $migrate) {
            if ($number > $current) {
                $migrate($db);
                save_settings($db, ['schema_version' => (string) $number]);
            }
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('recharges_ev_migrate')");
    }
}
