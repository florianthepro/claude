<?php
declare(strict_types=1);

namespace Nexus\Core;

use PDO;

/** Schlanker PDO/SQLite-Zugriff als Singleton. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . NX_DB);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec('PRAGMA busy_timeout = 5000;');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Prepared-Statement ausführen. */
    public static function run(string $sql, array $args = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    /** Eine Zeile. */
    public static function one(string $sql, array $args = []): ?array
    {
        $row = self::run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    /** Alle Zeilen. */
    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    /** Skalarer Einzelwert. */
    public static function scalar(string $sql, array $args = [])
    {
        return self::run($sql, $args)->fetchColumn();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
