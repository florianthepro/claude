<?php

declare(strict_types=1);

namespace Stimmwerk\Security;

/**
 * Restriktive HTTP-Sicherheitsheader. Die Content-Security-Policy startet bei
 * default-src 'none' und gibt ausschließlich eigene Ressourcen frei; es gibt
 * keine Inline-Skripte und keine externen Quellen.
 */
final class Headers
{
    public static function send(bool $isHttps): void
    {
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; "
            . "style-src 'self'; img-src 'self'; font-src 'self'; "
            . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Robots-Tag: noindex');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
