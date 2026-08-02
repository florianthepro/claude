<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

/**
 * CSRF-Schutz: sitzungsgebundener Zufallstoken, auf jedem POST-Formular
 * mitgesendet und mit hash_equals geprüft.
 */
final class Csrf
{
    public function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Verstecktes Formularfeld (fertig escaped). */
    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public function isValid(): bool
    {
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $sent);
    }
}
