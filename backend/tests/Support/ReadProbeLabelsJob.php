<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\Middleware\WithCompanyContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Probe job that carries company_id in its payload and enters the company through the WithCompanyContext middleware.
 * Reads the visible tenant_probe labels and leaves them in ProbeJobRecord.
 */
class ReadProbeLabelsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $companyId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithCompanyContext($this->companyId)];
    }

    public function handle(): void
    {
        ProbeJobRecord::record(DB::table('tenant_probe')->orderBy('label')->pluck('label')->all(), $this);
    }
}
