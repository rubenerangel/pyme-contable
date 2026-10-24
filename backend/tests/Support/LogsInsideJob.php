<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\Middleware\WithCompanyContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Probe job that enters the company through WithCompanyContext and writes one log record while it runs.
 */
class LogsInsideJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $companyId, public readonly int $userId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithCompanyContext($this->companyId, $this->userId)];
    }

    public function handle(): void
    {
        Log::info('inside job');
    }
}
