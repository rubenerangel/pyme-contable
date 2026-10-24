<?php

namespace App\Providers;

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SPA only, no API tokens: there is no personal_access_tokens table. An empty token makes the Sanctum guard
        // skip the token lookup, so a Bearer header ends in 401 instead of a query on a table that does not exist.
        Sanctum::getAccessTokenFromRequestUsing(static fn (Request $request): ?string => null);

        // A job never inherits the company context of a previous one, not even if the worker process died halfway
        // through a job. Resolved inside the listener, not here, so it takes the connection in force at that moment.
        // The log context names the job. It must run after Laravel hydrates the Context of the payload, which flushes
        // it first: that listener is registered earlier (ContextServiceProvider), and listeners run in order.
        Queue::before(function (JobProcessing $event): void {
            $this->app->make(DatabaseCompanyContext::class)->leave();
            Context::add(['job' => $event->job->resolveName(), 'job_id' => $event->job->uuid()]);
        });
    }
}
