<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Somewhere to send request and policy events: a JSON receiver (signed) or a Microsoft Teams channel. */
class WebhookEndpoint extends Model
{
    public const EVENTS = [
        'request.submitted' => 'A request is submitted',
        'request.awaiting_second' => 'A request needs a second IT officer',
        'request.approved' => 'A request is approved',
        'request.declined' => 'A request is declined',
        'policy.published' => 'A policy version is published',
    ];

    public const FORMATS = ['json' => 'JSON (signed)', 'teams' => 'Microsoft Teams card'];

    protected $fillable = ['name', 'url', 'format', 'secret', 'events', 'enabled', 'last_delivered_at', 'last_status'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'enabled' => 'boolean', 'secret' => 'encrypted', 'last_delivered_at' => 'datetime'];
    }
}
