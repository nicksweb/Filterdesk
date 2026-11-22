<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One period of the school day, by its bell times (HH:MM, school time). */
class BellPeriod extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'sort_order'];

    public function label(): string
    {
        return "{$this->name} ({$this->starts_at}–{$this->ends_at})";
    }
}
