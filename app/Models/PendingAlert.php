<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An IT alert held for the next summary email (Settings → Email and notifications). */
class PendingAlert extends Model
{
    protected $fillable = ['kind', 'line', 'url', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
