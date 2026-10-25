<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Probe job with no job middleware: it sees whatever company context the connection has when it starts.
 */
class ReadAccountCodesWithoutMiddlewareJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ProbeJobRecord::record(DB::table('account')->orderBy('code')->pluck('code')->all(), $this);
    }
}
