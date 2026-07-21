<?php
declare(strict_types=1);

namespace Nexus\Core;

/** Inline-SVG-Icons und das Nexus-Logo. */
final class Icons
{
    public static function icon(string $name, int $size = 20): string
    {
        $p = [
            'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'note'     => '<path d="M4 4h16v12l-4 4H4z"/><path d="M16 20v-4h4"/><path d="M8 9h8M8 13h5"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
            'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'link'     => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
            'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
            'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
            'plus'     => '<path d="M12 5v14M5 12h14"/>',
            'trash'    => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
            'pin'      => '<path d="M12 17v5M9 3h6l-1 6 3 3H7l3-3z"/>',
            'chevL'    => '<path d="M15 18l-6-6 6-6"/>',
            'chevR'    => '<path d="M9 18l6-6-6-6"/>',
            'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'send'     => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
            'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5 5h14l3 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
            'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/>',
            'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
            'search'   => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4-4"/>',
            'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
            'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M3 21a6 6 0 0 1 12 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 6M18 21a6 6 0 0 0-4-5.7"/>',
            'back'     => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
            'reply'    => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v2"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'check'    => '<path d="M20 6L9 17l-5-5"/>',
            'checkbox' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/>',
            'square'   => '<rect x="3" y="3" width="18" height="18" rx="3"/>',
            'shield'   => '<path d="M12 3l8 3v5c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/>',
            'ban'      => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
            'ticket'   => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2 2 0 0 0 0-4z"/><path d="M13 6v12"/>',
            'phone'    => '<path d="M5 4h4l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'db'       => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        ];
        $body = $p[$name] ?? '<circle cx="12" cy="12" r="9"/>';
        return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . $body . '</svg>';
    }

    /* ---------------- Logo ---------------- */
    public static function logoGlyph(string $color): string
    {
        return '<path d="M12 12L12 4.6M12 12L5.4 17.6M12 12L18.6 17.6" stroke="' . $color . '" stroke-width="1.7" stroke-linecap="round"/>'
            . '<circle cx="12" cy="4.6" r="1.9" fill="' . $color . '"/>'
            . '<circle cx="5.4" cy="17.6" r="1.9" fill="' . $color . '"/>'
            . '<circle cx="18.6" cy="17.6" r="1.9" fill="' . $color . '"/>'
            . '<circle cx="12" cy="12" r="2.6" fill="' . $color . '"/>';
    }

    public static function logoMark(int $size = 20): string
    {
        return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none">'
            . self::logoGlyph('currentColor') . '</svg>';
    }

    public static function logoFavicon(string $accent): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<rect width="24" height="24" rx="5" fill="' . $accent . '"/>'
            . '<g transform="translate(3.6 3.6) scale(0.7)">' . self::logoGlyph('#ffffff') . '</g></svg>';
    }
}
