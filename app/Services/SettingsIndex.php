<?php

namespace App\Services;

use App\Http\Controllers\Admin\SettingsController;
use App\Services\Notifications\EmailTemplates;
use Illuminate\Support\Facades\Cache;

/**
 * Everything on the Settings pages, for the search box at the top of them.
 *
 * Read from the settings view itself (headings, field labels, switches and
 * the help under them), so a new setting is searchable as soon as it is on
 * the page. A few admin pages that hold settings of their own are added by
 * hand, with words people are likely to search for.
 */
class SettingsIndex
{
    /** Settings that live on other admin pages. */
    private const PAGES = [
        ['title' => 'Firewall IP lists and feeds', 'route' => 'admin.policy.blocklists.index', 'where' => 'Protection', 'help' => 'Block lists, AbuseIPDB and other IP feeds, UniFi IP lists, addresses per list, list parts, firewall rules.'],
        ['title' => 'Addresses per UniFi IP list', 'route' => 'admin.policy.blocklists.index', 'where' => 'Protection', 'help' => 'How many addresses go in one UniFi IP list before it is split into parts. Also under Network provider.'],
        ['title' => 'Firewall allow lists', 'route' => 'admin.policy.firewall-allow.index', 'where' => 'Policies', 'help' => 'Sites and addresses the firewall lets through, zones, source networks, rule placement, nightly re-check.'],
        ['title' => 'Restore to a point in time', 'route' => 'admin.restore.index', 'where' => 'Changes', 'help' => 'Put every content filter and firewall allow list back to how it was at a date and time. Versions, history, undo, rollback.'],
        ['title' => 'Users and roles', 'route' => 'admin.users.index', 'where' => 'Admin', 'help' => 'Add people, change roles, view as someone, submit a request on someone\'s behalf, two-factor reset.'],
        ['title' => 'Submit a request on someone\'s behalf', 'route' => 'admin.users.index', 'where' => 'Users', 'help' => 'Switch to a person, even one who has not signed in yet, and submit a request as them.'],
        ['title' => 'Audit log', 'route' => 'admin.audit', 'where' => 'Admin', 'help' => 'Who changed what and when.'],
        ['title' => 'Networks and filters', 'route' => 'admin.policy.networks.index', 'where' => 'Policies', 'help' => 'Link UniFi networks to content filters and policies. Follow UniFi.'],
        ['title' => 'Filtering map', 'route' => 'admin.policy.map', 'where' => 'Policies', 'help' => 'How networks, filters, policies and lists fit together.'],
        ['title' => 'Website categories per filter', 'route' => 'admin.policy.index', 'where' => 'Policies', 'help' => 'UniFi categories blocked by each content filter; copy categories to another filter.'],
    ];

    /** @return list<array{title: string, help: string, where: string, url: string}> */
    public function all(): array
    {
        $view = resource_path('views/admin/settings.blade.php');
        $key = 'settings.index.'.config('version.app').'.'.(@filemtime($view) ?: 0);

        $entries = Cache::rememberForever($key, fn () => $this->build($view));

        return array_map(fn (array $entry) => $entry + ['url' => isset($entry['section'])
            ? route('admin.settings.show', ['section' => $entry['section'], 'find' => $entry['title']])
            : route($entry['route'])], $entries);
    }

    /** @return list<array{title: string, help: string, where: string, url: string}> best first */
    public function search(string $query, int $limit = 20): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($query))) ?: [], fn ($word) => mb_strlen($word) >= 2));
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($this->all() as $entry) {
            $title = mb_strtolower($entry['title']);
            $text = $title.' '.mb_strtolower($entry['help'].' '.$entry['where']);
            $score = 0;
            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    continue 2;
                }
                $score += str_contains($title, $word) ? (str_starts_with($title, $word) ? 4 : 3) : 1;
            }
            $scored[] = [$score, $entry];
        }
        usort($scored, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_slice(array_column($scored, 1), 0, $limit);
    }

    /** @return list<array{title: string, help: string, where: string, section?: string, route?: string}> */
    private function build(string $view): array
    {
        $source = (string) @file_get_contents($view);
        $sections = SettingsController::SECTIONS;
        $entries = [];
        $seen = [];
        $add = function (string $section, string $title, string $help = '') use (&$entries, &$seen, $sections): void {
            $title = $this->clean($title);
            $help = $this->clean($help);
            if (mb_strlen($title) < 3 || str_contains($title, '$') || isset($seen[$section.'|'.mb_strtolower($title)])) {
                return;
            }
            $seen[$section.'|'.mb_strtolower($title)] = true;
            $entries[] = ['title' => $title, 'help' => mb_strimwidth($help, 0, 220, '…'), 'where' => $sections[$section] ?? $section, 'section' => $section];
        };

        // Split the view at each @if/@elseif($section === '…').
        $parts = preg_split('/@(?:else)?if\(\$section === \'([a-z_]+)\'\)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $section = $parts[$i];
            $body = $parts[$i + 1];
            if (! isset($sections[$section])) {
                continue;
            }
            $add($section, $sections[$section], 'Settings section');
            preg_match_all('#<h[23][^>]*>(.*?)</h[23]>(?:\s*(?:</div>)?\s*<p[^>]*>(.*?)</p>)?#s', $body, $headings, PREG_SET_ORDER);
            foreach ($headings as $match) {
                $add($section, $match[1], $match[2] ?? '');
            }
            preg_match_all('#<(?:legend|summary)>(.*?)</(?:legend|summary)>(?:\s*<p[^>]*>(.*?)</p>)?#s', $body, $legends, PREG_SET_ORDER);
            foreach ($legends as $match) {
                $add($section, $match[1], $match[2] ?? '');
            }
            preg_match_all('#<label\b([^>]*)>(.*?)</label>#s', $body, $labels, PREG_SET_ORDER);
            foreach ($labels as [, $attributes, $inner]) {
                $help = preg_match('#<small[^>]*>(.*?)</small>#s', $inner, $small) ? $small[1] : '';
                if (preg_match('#<strong>(.*?)</strong>#s', $inner, $strong)) {
                    $add($section, $strong[1], $help);
                } elseif (preg_match('#^\s*([^<]+)#', $inner, $lead)) {
                    $add($section, $lead[1], $help);
                }
            }
            // Choices made in a loop: @foreach(['key' => 'Label', …] as …).
            preg_match_all('#@foreach\(\[(.*?)\]\s+as#s', $body, $loops);
            foreach ($loops[1] as $loop) {
                preg_match_all("#'[a-z_]+'\s*=>\s*'((?:[^'\\\\]|\\\\.){6,})'#", $loop, $labels);
                foreach ($labels[1] as $label) {
                    $add($section, stripslashes($label));
                }
            }
        }

        foreach (EmailTemplates::DEFINITIONS as $template) {
            $add('templates', 'Email: '.$template['label'], $template['group'].' email template: subject, greeting, body and button.');
        }
        foreach (Wording::FIELDS as $field) {
            $add('general', $field['label'], 'Wording people see. Default: '.$field['default']);
        }
        foreach (self::PAGES as $page) {
            $entries[] = $page;
        }

        return $entries;
    }

    private function clean(string $text): string
    {
        $text = preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/@[a-zA-Z]+(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/', '/<[^>]+>/'], ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?? $text, " \t\n:·");
    }
}
