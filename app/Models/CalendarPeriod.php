<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A term, holiday or exam period in the school calendar. */
class CalendarPeriod extends Model
{
    public const KINDS = ['term' => 'Term', 'holiday' => 'Holiday', 'exam' => 'Exam period'];

    protected $fillable = ['name', 'kind', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }
}
