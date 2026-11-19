<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkProfile extends Model
{
    protected $fillable = ['key', 'name', 'probe_url', 'unifi_network_id', 'unifi_policy_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
