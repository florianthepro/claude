<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Favoriten für Kategorien und Gebiete – am Pseudonym gespeichert und damit
 * auf jedem Gerät verfügbar.
 */
final class FavoriteService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Schaltet einen Favoriten um. kind: 'category' (ref = Slug) oder
     * 'scope' (ref = "level:Name" bzw. "bund").
     *
     * @throws \DomainException mit Übersetzungsschlüssel
     */
    public function toggle(int $userId, string $kind, string $ref): bool
    {
        if (!$this->isValidRef($kind, $ref)) {
            throw new \DomainException('flash.invalid_input');
        }
        $exists = $this->db->one(
            'SELECT 1 FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?',
            [$userId, $kind, $ref]
        );
        if ($exists !== null) {
            $this->db->run(
                'DELETE FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?',
                [$userId, $kind, $ref]
            );
            return false;
        }
        $this->db->run(
            'INSERT INTO favorites (user_id, kind, ref, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $kind, $ref, Clock::nowStr()]
        );
        return true;
    }

    public function isFavorite(int $userId, string $kind, string $ref): bool
    {
        return null !== $this->db->one(
            'SELECT 1 FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?',
            [$userId, $kind, $ref]
        );
    }

    /** @return array<int,array> */
    public function listFor(int $userId): array
    {
        return $this->db->all(
            'SELECT f.kind, f.ref, c.name_de, c.name_en
             FROM favorites f
             LEFT JOIN categories c ON f.kind = \'category\' AND c.slug = f.ref
             WHERE f.user_id = ?
             ORDER BY f.kind, f.ref',
            [$userId]
        );
    }

    private function isValidRef(string $kind, string $ref): bool
    {
        if ($kind === 'category') {
            return null !== $this->db->one('SELECT 1 FROM categories WHERE slug = ?', [$ref]);
        }
        if ($kind === 'scope') {
            if ($ref === 'bund') {
                return true;
            }
            $parts = explode(':', $ref, 2);
            return count($parts) === 2
                && in_array($parts[0], ['kommune', 'landkreis', 'bundesland'], true)
                && $parts[1] !== ''
                && mb_strlen($parts[1]) <= TopicService::SCOPE_NAME_MAX
                && preg_match('/^[\p{L}0-9 .\-()]+$/u', $parts[1]) === 1;
        }
        return false;
    }
}
