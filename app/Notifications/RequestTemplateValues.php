<?php

namespace App\Notifications;

use App\Models\WebsiteRequest;

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
        ];
    }
}
