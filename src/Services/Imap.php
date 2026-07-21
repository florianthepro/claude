<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Security;

/**
 * IMAP-Zugriff, auf geringe Bandbreite optimiert:
 *  - Listen laden nur die Overview (Betreff/Absender/Datum), keine Bodies
 *  - Bodies werden erst beim Öffnen und nur der bevorzugte Textteil geladen
 *  - text/plain wird bevorzugt (kleiner als HTML); Anhänge werden nicht geladen
 */
final class Imap
{
    public static function available(): bool
    {
        return function_exists('imap_open');
    }

    private static function mailbox(array $acc, string $folder = 'INBOX'): string
    {
        $enc = $acc['imap_enc'] === 'ssl' ? '/ssl' : ($acc['imap_enc'] === 'tls' ? '/tls' : '/notls');
        $flags = $enc . (empty($acc['validate_cert']) ? '/novalidate-cert' : '/validate-cert');
        return '{' . $acc['imap_host'] . ':' . (int) $acc['imap_port'] . '/imap' . $flags . '}' . $folder;
    }

    public static function open(array $acc, bool $readonly = true)
    {
        $opts = $readonly ? OP_READONLY : 0;
        return @imap_open(self::mailbox($acc), $acc['username'], Security::decrypt($acc['enc_pass']), $opts, 1);
    }

    /** Letzte $count Nachrichten als Overview (bandbreitensparend). */
    public static function recent($mbox, int $count = 40): array
    {
        $total = imap_num_msg($mbox);
        if ($total === 0) {
            return [];
        }
        $from = max(1, $total - $count + 1);
        $ov = imap_fetch_overview($mbox, $from . ':' . $total, 0) ?: [];
        usort($ov, static fn($a, $b) => ($b->uid ?? 0) <=> ($a->uid ?? 0));
        return $ov;
    }

    public static function lastError(): string
    {
        return imap_last_error() ?: 'unbekannter Fehler';
    }

    /** Bevorzugten Textteil laden: ['text'=>..,'html'=>..]. */
    public static function bodyPref($mbox, int $uid): array
    {
        $struct = imap_fetchstructure($mbox, $uid, FT_UID);
        $res = ['text' => '', 'html' => ''];
        if (empty($struct->parts)) {
            $data = self::decode(imap_body($mbox, $uid, FT_UID | FT_PEEK), $struct->encoding ?? 0);
            if (($struct->subtype ?? '') === 'HTML') {
                $res['html'] = $data;
            } else {
                $res['text'] = $data;
            }
            return $res;
        }
        self::walk($mbox, $uid, $struct->parts, '', $res);
        return $res;
    }

    private static function walk($mbox, int $uid, array $parts, string $prefix, array &$res): void
    {
        foreach ($parts as $i => $part) {
            $section = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
            $isText  = ($part->type ?? 0) === 0;
            $type    = strtoupper($part->subtype ?? '');
            $isAttach = false;
            foreach (($part->dparameters ?? []) as $p) {
                if (strtoupper($p->attribute) === 'FILENAME') {
                    $isAttach = true;
                }
            }
            // text/plain reicht → HTML gar nicht erst nachladen (spart Bandbreite)
            if ($isText && !$isAttach && !($type === 'HTML' && $res['text'] !== '')) {
                $data = self::decode(imap_fetchbody($mbox, $uid, $section, FT_UID | FT_PEEK), $part->encoding ?? 0);
                $cs = '';
                foreach (($part->parameters ?? []) as $p) {
                    if (strtoupper($p->attribute) === 'CHARSET') {
                        $cs = $p->value;
                    }
                }
                if ($cs && strtoupper($cs) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                    $data = @mb_convert_encoding($data, 'UTF-8', $cs) ?: $data;
                }
                if ($type === 'HTML') {
                    $res['html'] .= $data;
                } else {
                    $res['text'] .= $data;
                }
            }
            if (!empty($part->parts)) {
                self::walk($mbox, $uid, $part->parts, $section, $res);
            }
        }
    }

    private static function decode(string $data, int $enc): string
    {
        switch ($enc) {
            case 3: return base64_decode($data);
            case 4: return quoted_printable_decode($data);
            default: return $data;
        }
    }

    public static function decodeHeader(string $s): string
    {
        if (!function_exists('imap_mime_header_decode')) {
            return $s;
        }
        $out = '';
        foreach (imap_mime_header_decode($s) as $part) {
            $cs = strtoupper($part->charset);
            $txt = $part->text;
            if ($cs !== 'DEFAULT' && $cs !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $txt = @mb_convert_encoding($txt, 'UTF-8', $cs) ?: $txt;
            }
            $out .= $txt;
        }
        return $out;
    }

    public static function markSeen($mbox, int $uid): void
    {
        @imap_setflag_full($mbox, (string) $uid, "\\Seen", ST_UID);
    }
}
