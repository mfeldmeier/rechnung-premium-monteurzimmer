<?php

declare(strict_types=1);

namespace Pmz\Support;

use PDO;
use Throwable;

final class Db
{
    private static ?PDO $pdo = null;
    private static string $prefix = '';

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME'),
        );

        self::$prefix = Env::get('DB_PREFIX', 'pmz_');
        self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    public static function table(string $name): string
    {
        if (self::$pdo === null) {
            self::pdo();
        }

        return '`' . self::$prefix . $name . '`';
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
