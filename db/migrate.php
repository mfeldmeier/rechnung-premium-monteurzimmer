<?php

declare(strict_types=1);

/**
 * Wendet db/migrations/*.sql der Reihe nach an und merkt sich das Angewandte.
 * Aufruf: php db/migrate.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Pmz\Support\Db;
use Pmz\Support\Env;

Env::load(__DIR__ . '/../.env');

$prefix = Env::get('DB_PREFIX', 'pmz_');
if (preg_match('/^[a-z0-9_]+$/i', $prefix) !== 1) {
    fwrite(STDERR, "DB_PREFIX enthaelt unzulaessige Zeichen: {$prefix}\n");
    exit(1);
}

$pdo = Db::pdo();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `{$prefix}migrations` (
        `filename` VARCHAR(190) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = $pdo->query("SELECT `filename` FROM `{$prefix}migrations`")->fetchAll(PDO::FETCH_COLUMN);
$files   = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = str_replace('{{prefix}}', $prefix, (string) file_get_contents($file));

    // Jede Anweisung einzeln: MySQL kann DDL ohnehin nicht zurueckrollen,
    // ein Sammel-exec wuerde bei einem Fehler nur verschleiern, wo es klemmt.
    foreach (explode(";\n", $sql) as $statement) {
        $statement = trim($statement, " \t\n\r;");
        if ($statement === '' || !preg_match('/\b(CREATE|INSERT|ALTER|UPDATE|DROP)\b/i', $statement)) {
            continue;
        }

        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            fwrite(STDERR, "Fehler in {$name}:\n{$statement}\n\n{$e->getMessage()}\n");
            exit(1);
        }
    }

    $pdo->prepare("INSERT INTO `{$prefix}migrations` (`filename`) VALUES (?)")->execute([$name]);
    echo "angewendet: {$name}\n";
    $ran++;
}

echo $ran === 0 ? "nichts zu tun\n" : "{$ran} Migration(en) angewendet\n";
