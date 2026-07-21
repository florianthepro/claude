<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;
use Nexus\Services\Quota;

final class Files
{
    private static function root(array $u): string
    {
        $root = NX_DATA . '/files/' . $u['id'];
        if (!is_dir($root)) {
            @mkdir($root, 0770, true);
        }
        return realpath($root) ?: $root;
    }

    /** Sicherer Pfad innerhalb der Sandbox (verhindert ../). */
    private static function resolve(array $u, string $rel): ?string
    {
        $root = self::root($u);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $full = $root . ($rel === '' ? '' : '/' . $rel);
        $real = realpath($full);
        if ($real === false) {
            $real = realpath(dirname($full));
            if ($real === false || strncmp($real, $root, strlen($root)) !== 0) {
                return null;
            }
            return $full;
        }
        return strncmp($real, $root, strlen($root)) === 0 ? $real : null;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public static function handle(array $u, string $action): void
    {
        $rel = trim(param('p'), '/');
        if ($action === 'upload') {
            Security::verifyPost();
            $dir = self::resolve($u, $rel);
            if ($dir && is_dir($dir) && !empty($_FILES['files'])) {
                $n = 0;
                foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
                    if (!is_uploaded_file($tmp)) {
                        continue;
                    }
                    $size = (int) ($_FILES['files']['size'][$i] ?? 0);
                    if (!Quota::canStore((int) $u['id'], $size)) {
                        flash('Speicher voll – Upload abgebrochen (Quota überschritten).', 'err');
                        redirect(url('files', ['p' => $rel]));
                    }
                    $name = basename($_FILES['files']['name'][$i]);
                    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name);
                    if ($name === '' || $name[0] === '.') {
                        $name = 'datei_' . $i;
                    }
                    @move_uploaded_file($tmp, $dir . '/' . $name);
                    $n++;
                }
                flash($n . ' Datei(en) hochgeladen.');
            }
            redirect(url('files', ['p' => $rel]));
        }
        if ($action === 'mkdir') {
            Security::verifyPost();
            $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', trim(param('name')));
            if ($name !== '' && $name[0] !== '.') {
                $parent = self::resolve($u, $rel);
                if ($parent && is_dir($parent)) {
                    @mkdir($parent . '/' . $name, 0770);
                }
                flash('Ordner erstellt.');
            }
            redirect(url('files', ['p' => $rel]));
        }
        if ($action === 'rm') {
            Security::verifyGet();
            $target = self::resolve($u, $rel);
            $root = self::root($u);
            if ($target && $target !== $root && strncmp($target, $root, strlen($root)) === 0) {
                is_dir($target) ? self::rrmdir($target) : @unlink($target);
                flash('Gelöscht.');
            }
            redirect(url('files', ['p' => dirname($rel) === '.' ? '' : dirname($rel)]));
        }
        if ($action === 'dl') {
            $target = self::resolve($u, $rel);
            $root = self::root($u);
            if ($target && is_file($target) && strncmp($target, $root, strlen($root)) === 0) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($target) . '"');
                header('Content-Length: ' . filesize($target));
                readfile($target);
                exit;
            }
            http_response_code(404);
            exit('Nicht gefunden.');
        }
    }

    public static function render(array $u): void
    {
        $rel = trim(param('p'), '/');
        $dir = self::resolve($u, $rel);
        if ($dir === null || !is_dir($dir)) {
            $rel = '';
            $dir = self::root($u);
        }

        $free = human_size(Quota::remaining((int) $u['id']));
        View::topbar('Dateien', 'Frei: ' . $free,
            '<form method="post" action="?app=files&action=mkdir" style="display:flex;gap:8px">' . Security::field()
            . '<input type="hidden" name="p" value="' . h($rel) . '">'
            . '<input class="input" name="name" placeholder="Neuer Ordner" style="width:150px;padding:7px 11px">'
            . '<button class="btn ghost sm">' . Icons::icon('plus') . '</button></form>');

        echo '<div class="crumb"><a href="' . url('files') . '">' . Icons::icon('folder', 15) . ' home</a>';
        $acc = '';
        foreach (array_filter(explode('/', $rel)) as $part) {
            $acc .= ($acc ? '/' : '') . $part;
            echo ' / <a href="' . url('files', ['p' => $acc]) . '">' . h($part) . '</a>';
        }
        echo '</div>';

        echo '<form method="post" action="?app=files&action=upload" enctype="multipart/form-data" id="uploadForm">' . Security::field();
        echo '<input type="hidden" name="p" value="' . h($rel) . '">';
        echo '<input type="file" name="files[]" id="fileInput" multiple hidden>';
        echo '<div class="dropzone" id="dropzone">' . Icons::icon('upload', 28) . '<div style="margin-top:6px">Dateien hierher ziehen oder klicken</div></div></form>';

        $entries = @scandir($dir) ?: [];
        $dirs = $files = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir($dir . '/' . $e) ? $dirs[] = $e : $files[] = $e;
        }
        sort($dirs);
        sort($files);

        if (!$dirs && !$files) {
            echo '<div class="empty">' . Icons::icon('folder', 40) . '<h3>Leerer Ordner</h3><p>Lade Dateien hoch oder erstelle einen Unterordner.</p></div>';
            return;
        }
        echo '<div class="file-grid">';
        foreach ($dirs as $d) {
            $child = ($rel ? $rel . '/' : '') . $d;
            echo '<a class="file-card" href="' . url('files', ['p' => $child]) . '">';
            echo '<a class="fdel" href="?app=files&action=rm&p=' . urlencode($child) . '&_csrf=' . Security::token() . '" onclick="event.stopPropagation();return confirm(\'Ordner inkl. Inhalt löschen?\')">' . Icons::icon('trash', 14) . '</a>';
            echo '<div class="fi">' . Icons::icon('folder', 30) . '</div><div class="fn">' . h($d) . '</div><div class="fs">Ordner</div></a>';
        }
        foreach ($files as $f) {
            $child = ($rel ? $rel . '/' : '') . $f;
            echo '<div class="file-card">';
            echo '<a class="fdel" href="?app=files&action=rm&p=' . urlencode($child) . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Datei löschen?\')">' . Icons::icon('trash', 14) . '</a>';
            echo '<a href="?app=files&action=dl&p=' . urlencode($child) . '">';
            echo '<div class="fi">' . Icons::icon('file', 30) . '</div><div class="fn">' . h($f) . '</div><div class="fs">' . human_size((int) @filesize($dir . '/' . $f)) . '</div></a></div>';
        }
        echo '</div>';
    }
}
