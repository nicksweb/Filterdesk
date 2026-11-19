<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteCheck extends Model
{
    protected $fillable = ['user_id', 'url', 'hostname', 'results', 'duration_ms'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }
}
