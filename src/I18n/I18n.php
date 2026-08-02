<?php

declare(strict_types=1);

namespace Stimmwerk\I18n;

/**
 * Zweisprachigkeit (Deutsch Standard, Englisch). Übersetzungen liegen als
 * einfache Schlüssel-Arrays vor; fehlende englische Schlüssel fallen auf
 * Deutsch zurück, damit nie Rohschlüssel im UI erscheinen.
 */
final class I18n
{
    /** @var array<string,string> */
    private array $strings;
    /** @var array<string,string> */
    private array $fallback;

    public function __construct(private readonly string $lang)
    {
        $dir = __DIR__;
        $this->fallback = require $dir . '/de.php';
        $this->strings = $lang === 'de' ? $this->fallback : require $dir . '/' . $lang . '.php';
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /** @param array<string,string|int> $repl */
    public function t(string $key, array $repl = []): string
    {
        $text = $this->strings[$key] ?? $this->fallback[$key] ?? $key;
        foreach ($repl as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    public function has(string $key): bool
    {
        return isset($this->strings[$key]) || isset($this->fallback[$key]);
    }

    /** Zahlformat je Sprache (1.234 / 1,234). */
    public function num(int $n): string
    {
        return $this->lang === 'de' ? number_format($n, 0, ',', '.') : number_format($n);
    }
}
