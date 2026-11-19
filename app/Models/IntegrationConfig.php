<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConfig extends Model
{
    protected $fillable = [
        'provider', 'name', 'mode', 'configuration', 'credentials', 'enabled',
        'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }
}
