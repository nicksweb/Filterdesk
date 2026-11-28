<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One version of a content filter's lists or a firewall allow list's entries (see StateHistory). */
class ConfigSnapshot extends Model
{
    public const CONTENT_FILTER = 'content_filter';

    public const FIREWALL_ALLOW = 'firewall_allow';

    public $timestamps = false;

    protected $fillable = ['kind', 'reference', 'name', 'data', 'hash', 'source', 'taken_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'taken_at' => 'datetime'];
    }
}
