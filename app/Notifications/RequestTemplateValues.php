<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Requests\AccessGuide;
use Carbon\CarbonInterface;

/** The {placeholders} every request email can use. */
final class RequestTemplateValues
{
    /** @return array<string, string> */
    public static function for(WebsiteRequest $request, object $notifiable): array
    {
        return [
            'name' => (string) ($notifiable->name ?? ''),
            'reference' => (string) $request->reference,
            'websites' => implode(', ', $request->effectiveDomains()),
            'window' => $request->windowLabel(),
            'timezone' => (string) config('app.display_timezone'),
            'requester' => (string) ($request->requester?->name ?? ''),
            'scope' => (string) ($request->access_scope?->label() ?? ''),
            'category' => (string) $request->category,
            'reason' => (string) ($request->use_case ?? ''),
            'outcome' => $request->isBlock() ? 'blocked' : 'allowed',
            // "Your access is on its way" steps (Settings → Approval policy), or one short line.
            'tips' => self::tips($request, $notifiable),
            'until' => ($until = $request->accessUntil()) ? ' until '.self::when($until) : '',
            'starts' => ($from = $request->accessFrom()) ? self::when($from) : 'now',
            'ends' => $until ? self::when($until, true) : '',
        ];
    }

    private static function tips(WebsiteRequest $request, object $notifiable): string
    {
        $guide = app(AccessGuide::class);
        if (! $request->isBlock() && $notifiable instanceof User && $guide->appliesTo($request, $notifiable)) {
            return $guide->settings()['intro']."\n\n".collect($guide->steps())->map(fn (string $step, int $index) => ($index + 1).'. '.$step)->join("\n");
        }

        return $request->isBlock() ? '' : "If it still doesn't load, close the browser and try again.";
    }

    /** "Friday 12 December", with the time when it isn't the start or end of a day; no timezone. */
    private static function when(CarbonInterface $at, bool $withTime = false): string
    {
        $local = $at->local();
        $dayEdge = in_array($local->format('H:i'), ['00:00', '23:59'], true);

        return $local->format('l j F').($withTime || ! $dayEdge ? ($dayEdge ? '' : ' at '.$local->format('g:i a')) : '');
    }
}
