<?php

declare(strict_types=1);

namespace Stimmwerk\View;

/**
 * Minimales Template-Rendering: PHP-Templates unter templates/, umschlossen
 * vom gemeinsamen Layout. Templates erhalten $app, $t (Übersetzung) und ihre
 * benannten Variablen; jede Ausgabe von Nutzerdaten läuft durch e().
 */
final class View
{
    private ?\Stimmwerk\App $app = null;

    public function setApp(\Stimmwerk\App $app): void
    {
        $this->app = $app;
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = [], int $status = 200): never
    {
        http_response_code($status);
        echo $this->renderToString($template, $vars, true);
        exit;
    }

    /** @param array<string,mixed> $vars */
    public function renderToString(string $template, array $vars, bool $withLayout): string
    {
        $app = $this->app;
        if ($app === null) {
            throw new \RuntimeException('View ohne App initialisiert.');
        }
        $t = static fn (string $key, array $repl = []): string => $app->i18n->t($key, $repl);

        $file = __DIR__ . '/templates/' . basename($template) . '.phtml';
        if (!is_file($file)) {
            throw new \RuntimeException('Template fehlt: ' . $template);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        if (!$withLayout) {
            return $content;
        }
        $title = isset($vars['title']) && is_string($vars['title']) ? $vars['title'] : $t('app.tagline');
        ob_start();
        require __DIR__ . '/templates/layout.phtml';
        return (string) ob_get_clean();
    }
}
