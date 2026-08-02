<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

use PDO;
use PDOStatement;

/**
 * Schmale PDO-Hülle. Sämtliche Zugriffe laufen über Prepared Statements;
 * es gibt bewusst keine API, die unparametrisierte Nutzerdaten in SQL erlaubt.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Datenverzeichnis nicht anlegbar.');
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function val(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T @param callable():T $fn @return T */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Führt das Schema aus, falls die Datenbank noch leer ist. */
    public function migrate(string $schemaFile): bool
    {
        $exists = $this->val("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_info'");
        if ($exists !== null) {
            return false;
        }
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new \RuntimeException('Schema-Datei nicht lesbar.');
        }
        $this->pdo->exec($sql);
        $this->run(
            "INSERT INTO schema_info (k, v) VALUES ('version', '1'), ('created_at', ?)",
            [Clock::nowStr()]
        );
        return true;
    }
}
