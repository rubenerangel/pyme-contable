<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Probe job with no company in its payload and no job middleware: writes one log record while it runs.
 */
class LogsWithoutContextJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('job without context');
    }
}
