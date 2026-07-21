<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Security;

/** Minimaler SMTP-Client (SSL oder STARTTLS), abhängigkeitsfrei. */
final class Smtp
{
    /** @return true bei Erfolg, sonst Fehlertext. */
    public static function send(array $acc, string $to, string $subject, string $body)
    {
        $host = $acc['smtp_host'];
        $port = (int) $acc['smtp_port'];
        $enc  = $acc['smtp_enc'];
        $pass = Security::decrypt($acc['enc_pass']);
        $verify = !empty($acc['validate_cert']);

        $transport = $enc === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]]);
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return "Verbindung fehlgeschlagen ($errstr)";
        }

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';

        $read();
        $cmd('EHLO ' . $ehloName);
        if ($enc === 'tls') {
            $cmd('STARTTLS');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                return 'STARTTLS fehlgeschlagen';
            }
            $cmd('EHLO ' . $ehloName);
        }
        $r = $cmd('AUTH LOGIN');
        if (strpos($r, '334') !== 0) {
            return 'AUTH nicht unterstützt: ' . trim($r);
        }
        $cmd(base64_encode($acc['username']));
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') !== 0) {
            return 'Anmeldung abgelehnt: ' . trim($r);
        }

        $from = $acc['email'];
        $r = $cmd('MAIL FROM:<' . $from . '>');
        if ($r[0] !== '2') {
            return 'MAIL FROM abgelehnt: ' . trim($r);
        }
        $r = $cmd('RCPT TO:<' . $to . '>');
        if ($r[0] !== '2') {
            return 'Empfänger abgelehnt: ' . trim($r);
        }
        $cmd('DATA');

        $headers = 'From: ' . $from . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n";
        $data = $headers . "\r\n" . chunk_split(base64_encode($body));
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $r = $read();
        $cmd('QUIT');
        fclose($fp);
        return $r[0] === '2' ? true : 'Server-Antwort: ' . trim($r);
    }
}
