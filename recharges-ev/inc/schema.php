<?php
declare(strict_types=1);

/** Crée les tables manquantes (sans toucher aux données existantes). */
function create_tables(PDO $db): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->exec("CREATE TABLE IF NOT EXISTS recharges (
        id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        charged_at  DATETIME          NOT NULL,
        kwh         DECIMAL(7,2)      NOT NULL,
        kwh_billed  SMALLINT UNSIGNED NOT NULL,
        type        ENUM('AC','DC')   NOT NULL,
        rate_htva   DECIMAL(6,4)      NOT NULL,
        vat_pct     DECIMAL(4,2)      NOT NULL,
        amount_htva DECIMAL(10,2)     NOT NULL,
        amount_ttc  DECIMAL(10,2)     NOT NULL,
        latitude    DECIMAL(9,6)      NULL,
        longitude   DECIMAL(9,6)      NULL,
        accuracy_m  SMALLINT UNSIGNED NULL,
        created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_charged_at (charged_at)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip           VARCHAR(45)  NOT NULL,
        attempted_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip_time (ip, attempted_at)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        name       VARCHAR(64) NOT NULL,
        value      TEXT        NOT NULL,
        updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (name)
    ) $opts");
}
