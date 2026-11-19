<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $metadata = [], ?Request $request = null): void
    {
        $request ??= request();

        AuditEvent::query()->create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => str($request?->userAgent() ?? '')->limit(1000),
            'metadata' => $metadata,
        ]);
    }
}
