<?php

declare(strict_types=1);

namespace Stimmwerk\Auth;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;
use Stimmwerk\Core\Secrets;
use Stimmwerk\Core\Session;

/**
 * Kontoverwaltung auf Pseudonym-Basis: gespeichert wird ausschließlich der
 * HMAC des dienstespezifischen Ausweis-Pseudonyms. Eine Karte = ein Konto.
 */
final class Auth
{
    private ?array $cachedUser = null;
    private bool $cacheLoaded = false;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Secrets $secrets,
        private readonly string $defaultLang,
    ) {
    }

    /** Meldet ein per eID bestätigtes Pseudonym an (legt das Konto ggf. an). */
    public function loginWithPseudonym(string $pseudonym): array
    {
        $hash = $this->secrets->hmac('pseudonym|' . $pseudonym);
        $now = Clock::nowStr();
        $user = $this->db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$hash]);
        if ($user === null) {
            $this->db->run(
                'INSERT INTO users (pseudonym_hash, lang, created_at, last_login_at) VALUES (?, ?, ?, ?)',
                [$hash, $this->defaultLang, $now, $now]
            );
            $user = $this->db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$hash]);
        } else {
            $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, (int) $user['id']]);
        }
        $this->session->authenticate((int) $user['id']);
        $this->cacheLoaded = false;
        return $user;
    }

    public function logout(): void
    {
        $this->session->clearAuthenticated();
        $this->cacheLoaded = false;
    }

    public function user(): ?array
    {
        if (!$this->cacheLoaded) {
            $id = $this->session->userId();
            $this->cachedUser = $id === null
                ? null
                : $this->db->one('SELECT * FROM users WHERE id = ? AND is_system = 0', [$id]);
            $this->cacheLoaded = true;
        }
        return $this->cachedUser;
    }

    public function userId(): ?int
    {
        $user = $this->user();
        return $user === null ? null : (int) $user['id'];
    }

    public function setLang(int $userId, string $lang): void
    {
        $this->db->run('UPDATE users SET lang = ? WHERE id = ?', [$lang, $userId]);
        $this->cacheLoaded = false;
    }

    /** Kurzes, nicht rückrechenbares Anzeige-Kürzel des Pseudonyms. */
    public static function shortId(array $user): string
    {
        return strtoupper(substr((string) $user['pseudonym_hash'], 0, 8));
    }

    /** Gehashter Tages-IP-Schlüssel für Rate-Limits (keine Klar-IP-Speicherung). */
    public function ipKey(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return substr($this->secrets->hmac('ip|' . Clock::localDate() . '|' . $ip), 0, 24);
    }
}
