<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Closure;
use Illuminate\Container\Container;

/**
 * Job middleware that runs the job inside a company context. Business jobs declare it in middleware() with the
 * company_id of their payload and the user that originated the job; only those two ids are serialized, the context
 * is resolved when the job runs.
 */
final class WithCompanyContext
{
    public function __construct(public readonly int $companyId, public readonly int $userId) {}

    public function handle(object $job, Closure $next): mixed
    {
        // The pipeline calls handle() on the instance as is, without container injection, so it is resolved here.
        $context = Container::getInstance()->make(DatabaseCompanyContext::class);

        $context->enter($this->companyId, $this->userId);

        try {
            return $next($job);
        } finally {
            $context->leave();
        }
    }
}
