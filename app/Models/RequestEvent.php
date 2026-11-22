<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestEvent extends Model
{
    protected $fillable = ['website_request_id', 'actor_id', 'type', 'message', 'metadata', 'internal'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'internal' => 'boolean'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
