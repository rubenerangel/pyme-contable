<?php

declare(strict_types=1);

use App\Infrastructure\Ledger\AccountLocks;
use App\Infrastructure\Ledger\AccountNotFound;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;

// AccountLocks::lockForPosting() inside the RefreshDatabase transaction, which is what it requires. Waiting behavior
// and the ascending lock order live in PostingLockConcurrencyTest, and so does the check outside a transaction.
uses(RefreshDatabaseAsOwner::class);

it('throws AccountNotFound for a missing id and for an account of another company', function (string $case): void {
    $otherCompanyAccountId = null;
    if ($case === 'account of another company') {
        AccountFixtures::enterNewCompany();
        $otherCompanyAccountId = AccountFixtures::insert(DB::connection(), 'T1', null, true);
    }

    AccountFixtures::enterNewCompany();
    $ownAccountId = AccountFixtures::insert(DB::connection(), 'T9', null, true);
    // Well above any id the sequence has handed out in this test.
    $unavailableId = $otherCompanyAccountId ?? $ownAccountId + 1_000_000;

    $locks = new AccountLocks(DB::connection());

    $thrown = null;
    try {
        $locks->lockForPosting([$ownAccountId, $unavailableId]);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(AccountNotFound::class);
    expect($thrown->getMessage())->toContain((string) $unavailableId);
})->with(['missing id', 'account of another company']);

it('accepts repeated ids', function (): void {
    AccountFixtures::enterNewCompany();
    $a = AccountFixtures::insert(DB::connection(), 'T1', null, true);
    $b = AccountFixtures::insert(DB::connection(), 'T2', null, true);

    $locks = new AccountLocks(DB::connection());

    // A repeated id is not a missing account: = ANY returns each row once, so nothing is reported missing.
    expect($locks->lockForPosting([$a, $a, $b]))->toBeNull();
});

it('rejects account ids that are not int (InvalidArgumentException) before querying', function (mixed $notAnInt): void {
    AccountFixtures::enterNewCompany();
    $validId = AccountFixtures::insert(DB::connection(), 'T1', null, true);

    $locks = new AccountLocks(DB::connection());
    $accountIds = [$validId, $notAnInt];

    // A string like '7' or a float like 7.0 would pass through intval and look valid; null would become 0 and be
    // reported as a missing account. All three are a caller bug and must be refused as such, before touching account.
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $thrown = null;
    try {
        $locks->lockForPosting($accountIds);
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        $accountQueries = array_values(array_filter(
            array_column($connection->getQueryLog(), 'query'),
            static fn (string $query): bool => str_contains($query, 'account'),
        ));
        $connection->disableQueryLog();
    }

    expect($thrown)->toBeInstanceOf(
        InvalidArgumentException::class,
        'lockForPosting must throw InvalidArgumentException for a non-int id, got '.($thrown === null ? 'nothing' : $thrown::class),
    );
    expect($accountQueries)->toBe([], 'no query to account may run before the ids are validated');
})->with([
    'string' => ['7'],
    'float' => [7.0],
    'null' => [null],
]);
