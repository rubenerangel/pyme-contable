<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Probe job that reads inside the company context and then throws, so the exception happens after entering it.
 */
class ReadAccountCodesThenFailJob extends ReadAccountCodesJob
{
    public function handle(): void
    {
        ProbeJobRecord::record(DB::table('account')->orderBy('code')->pluck('code')->all(), $this);

        throw new RuntimeException('Probe job failure after entering the company context.');
    }
}
