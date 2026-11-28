<?php

namespace App\Services;

use App\Models\User;
use Throwable;

/** What changed since an administrator last looked: a few lines per release, with where to find each setting. */
class WhatsNew
{
    /** Releases shown at most, for someone who skipped several. */
    private const MAX_RELEASES = 4;

    /**
     * @return list<array{version: string, notes: string, items: list<array{text: string, url: ?string}>}> newest first; empty once seen
     */
    public function for(User $user): array
    {
        $current = (string) config('version.app');
        if (! $user->isAdmin() || $user->whats_new_seen === $current) {
            return [];
        }
        $releases = [];
        foreach ((array) config('whats-new', []) as $version => $items) {
            $version = (string) $version;
            // Releases after the one they last saw, up to this one; someone new sees only this release.
            if (version_compare($version, $current, '>') || ($user->whats_new_seen === null ? $version !== $current : version_compare($version, $user->whats_new_seen, '<='))) {
                continue;
            }
            $releases[] = [
                'version' => $version,
                'notes' => rtrim((string) config('app.project_url'), '/')."/releases/tag/v{$version}",
                'items' => array_map(fn (array $item) => ['text' => $item['text'], 'url' => $this->url($item)], $items),
            ];
        }

        return array_slice($releases, 0, self::MAX_RELEASES);
    }

    public function dismiss(User $user): void
    {
        $user->forceFill(['whats_new_seen' => (string) config('version.app')])->save();
    }

    private function url(array $item): ?string
    {
        if (! isset($item['route'])) {
            return null;
        }
        try {
            return route($item['route'], $item['params'] ?? []);
        } catch (Throwable) {
            return null;
        }
    }
}
