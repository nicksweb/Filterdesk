<?php

namespace App\Http\Controllers;

use App\Services\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The browser icon (or the sign-in page logo): uploaded under Settings → General, or the built-in icon. */
class BrandIconController extends Controller
{
    public function __invoke(Request $request, SettingsRepository $settings, string $kind = 'icon'): Response
    {
        $branding = (array) $settings->get('branding', []);
        $field = $kind === 'logo' ? 'logo' : 'icon';
        $custom = filled($branding["{$field}_data"] ?? null) ? base64_decode((string) $branding["{$field}_data"], true) : false;
        abort_if($field === 'logo' && $custom === false, 404);
        [$body, $type] = $custom !== false
            ? [$custom, (string) ($branding["{$field}_mime"] ?? 'image/png')]
            : [(string) @file_get_contents(public_path('favicon.ico')), 'image/x-icon'];
        $etag = '"'.md5($body).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded SVG is an image only: no scripts, no external loads.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
