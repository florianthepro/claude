<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

/**
 * Sicherheits- und Fehlerprotokoll außerhalb des Webroots.
 * Es werden keine personenbezogenen Klardaten protokolliert.
 */
final class Logger
{
    private string $file;

    public function __construct(string $dataDir)
    {
        $this->file = rtrim($dataDir, '/') . '/app.log';
    }

    public function security(string $event, array $context = []): void
    {
        $this->write('SECURITY', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    private function write(string $level, string $event, array $context): void
    {
        $line = sprintf(
            "%s %s %s %s\n",
            Clock::nowStr(),
            $level,
            $event,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
