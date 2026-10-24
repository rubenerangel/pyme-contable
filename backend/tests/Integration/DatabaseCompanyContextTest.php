<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The company context of the connection: it is written with set_config and read back from the database.
// Expected values are read with raw SQL, not through the class under test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Raw value of the app.company_id setting in the database session (null if never defined).
 */
function rawCompanySetting(): ?string
{
    return DB::selectOne("select current_setting('app.company_id', true) as value")->value;
}

it('has no company in context when nothing was entered', function (): void {
    $context = app(DatabaseCompanyContext::class);

    expect($context->current())->toBeNull();
});

it('enter sets the company in the database setting and current returns it', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $context->enter(42);

    expect(['current' => $context->current(), 'setting' => rawCompanySetting()])->toBe([
        'current' => 42,
        'setting' => '42',
    ]);
});

it('leave clears the context and leaves the setting as an empty string', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $context->enter(42);

    $context->leave();

    expect(['current' => $context->current(), 'setting' => rawCompanySetting()])->toBe([
        'current' => null,
        'setting' => '',
    ]);
});

it('entering a second company replaces the first', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $context->enter(42);

    $context->enter(77);

    expect(['current' => $context->current(), 'setting' => rawCompanySetting()])->toBe([
        'current' => 77,
        'setting' => '77',
    ]);
});
