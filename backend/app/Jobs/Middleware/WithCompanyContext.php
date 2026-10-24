<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Closure;
use Illuminate\Container\Container;

/**
 * Job middleware that runs the job inside a company context. Business jobs declare it in middleware() with the
 * company_id of their payload; only that id is serialized, the context is resolved when the job runs.
 */
final class WithCompanyContext
{
    public function __construct(public readonly int $companyId) {}

    public function handle(object $job, Closure $next): mixed
    {
        // The pipeline calls handle() on the instance as is, without container injection, so it is resolved here.
        $context = Container::getInstance()->make(DatabaseCompanyContext::class);

        $context->enter($this->companyId);

        try {
            return $next($job);
        } finally {
            $context->leave();
        }
    }
}
