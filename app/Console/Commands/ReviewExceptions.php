<?php

namespace App\Console\Commands;

use App\Services\Policy\ExceptionReviews;
use Illuminate\Console\Command;

/** Daily: tell owners about exceptions due for review and expire those left unreviewed. */
class ReviewExceptions extends Command
{
    protected $signature = 'exceptions:review';

    protected $description = 'Email owners about website exceptions due for review and expire overdue ones';

    public function handle(ExceptionReviews $reviews): int
    {
        $this->line('Notified: '.$reviews->notifyDue());
        $this->line('Expired unreviewed: '.$reviews->expireOverdue());

        return self::SUCCESS;
    }
}
