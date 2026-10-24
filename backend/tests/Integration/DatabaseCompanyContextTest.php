<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The active company and user of the connection: both are written with session-level set_config and read back from
// the database. Expected values are read with raw SQL, not through the class under test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Raw value of the app.company_id setting in the database session (null if never defined).
 */
function rawCompanySetting(): ?string
{
    return DB::selectOne("select current_setting('app.company_id', true) as value")->value;
}

/**
 * Raw value of the app.user_id setting in the database session (null if never defined).
 */
function rawUserSetting(): ?string
{
    return DB::selectOne("select current_setting('app.user_id', true) as value")->value;
}

it('has no company and no user in context when nothing was entered', function (): void {
    $context = app(DatabaseCompanyContext::class);

    expect(['company' => $context->current(), 'user' => $context->currentUser()])->toBe([
        'company' => null,
        'user' => null,
    ]);
});

it('enter sets the company and the user in the database settings and the accessors return them', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $user = User::factory()->create();

    $context->enter(42, $user->id);

    expect([
        'current' => $context->current(),
        'currentUser' => $context->currentUser(),
        'companySetting' => rawCompanySetting(),
        'userSetting' => rawUserSetting(),
    ])->toBe([
        'current' => 42,
        'currentUser' => $user->id,
        'companySetting' => '42',
        'userSetting' => (string) $user->id,
    ]);
});

it('leave clears the company and the user and leaves both settings as an empty string', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $context->enter(42, User::factory()->create()->id);

    $context->leave();

    expect([
        'current' => $context->current(),
        'currentUser' => $context->currentUser(),
        'companySetting' => rawCompanySetting(),
        'userSetting' => rawUserSetting(),
    ])->toBe([
        'current' => null,
        'currentUser' => null,
        'companySetting' => '',
        'userSetting' => '',
    ]);
});

it('entering a second company and user replaces both', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $context->enter(42, $first->id);

    $context->enter(77, $second->id);

    expect([
        'current' => $context->current(),
        'currentUser' => $context->currentUser(),
        'companySetting' => rawCompanySetting(),
        'userSetting' => rawUserSetting(),
    ])->toBe([
        'current' => 77,
        'currentUser' => $second->id,
        'companySetting' => '77',
        'userSetting' => (string) $second->id,
    ]);
});

it('entering the same company with another user replaces only the user', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $context->enter(42, $first->id);

    $context->enter(42, $second->id);

    expect(['current' => $context->current(), 'currentUser' => $context->currentUser()])->toBe([
        'current' => 42,
        'currentUser' => $second->id,
    ]);
});

it('currentUser is an int, not a string, and comes from the database setting (a session-level set_config)', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $user = User::factory()->create();

    // Written behind the class's back: the accessor must read the database, not remember what enter() received.
    DB::statement("select set_config('app.user_id', ?, false)", [(string) $user->id]);

    expect($context->currentUser())->toBe($user->id);
});
