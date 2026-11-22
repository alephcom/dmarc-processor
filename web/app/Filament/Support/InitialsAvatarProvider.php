<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Generates initials avatars locally as SVG data URIs.
 * Filament's default provider fetches from ui-avatars.com, which would send every
 * user's and tenant's name to a third party.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    private const COLOURS = ['#0369a1', '#4338ca', '#047857', '#b45309', '#be123c', '#7e22ce', '#0f766e', '#334155'];

    public function get(Model $record): string
    {
        $name = $record instanceof Authenticatable
            ? Filament::getUserName($record)
            : (string) ($record->getAttribute('name') ?? '?');

        // Letters and digits only, so "Acme (demo)" gives "AD" rather than "A(".
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name))) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).mb_substr(count($words) > 1 ? end($words) : '', 0, 1));
        $colour = self::COLOURS[crc32($name) % count(self::COLOURS)];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$colour.'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="system-ui,sans-serif" font-size="26" font-weight="600" fill="#fff">'
            .htmlspecialchars($initials, ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
