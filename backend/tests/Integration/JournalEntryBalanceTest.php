<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// Balancing of the journal entry: the deferred constraint triggers that reject an entry with fewer than two lines or
// with sum(debit) <> sum(credit). RefreshDatabase never reaches the COMMIT, so the deferred checks are fired on
// purpose with SET CONSTRAINTS ALL IMMEDIATE, always as the last statement of the test (or the one under test), never
// at the start: it would fail entries that are valid but inserted in several statements. The COMMIT itself is tested
// in JournalEntryCommitTest. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing statement
// lives in its own test and the control rows are inserted before it.
uses(RefreshDatabaseAsOwner::class);

/**
 * Inserts a credit line (debit 0.00) of the given amount.
 */
function balanceInsertCredit(int $entryId, int $accountId, string $amount): int
{
    return LedgerFixtures::insertLine($entryId, $accountId, ['debit' => '0.00', 'credit' => $amount]);
}

/**
 * Number of lines of the entry, read through the company context.
 */
function balanceLineCount(int $entryId): int
{
    return DB::table('journal_entry_line')->where('journal_entry_id', $entryId)->count();
}

// Rejections when the deferred checks run.

it('rejects an entry without lines when deferred constraints are checked (SQLSTATE 23514, fewer than two lines)', function (): void {
    // enterCompanyWithLedger leaves an entry without lines.
    LedgerFixtures::enterCompanyWithLedger();

    SqlAssertions::expectRejected(
        fn () => DB::statement('set constraints all immediate'),
        '23514',
        message: 'has fewer than two lines',
    );
});

it('rejects an entry with two unbalanced lines (SQLSTATE 23514, unbalanced)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '90.00');

    SqlAssertions::expectRejected(
        fn () => DB::statement('set constraints all immediate'),
        '23514',
        message: 'is unbalanced',
    );
});

it('rejects a line added after SET CONSTRAINTS ALL IMMEDIATE validated a balanced entry', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '100.00');

    // Control: the balanced entry passes the check.
    DB::statement('set constraints all immediate');

    // In immediate mode the trigger runs at the end of the statement, so the third line is rejected on its own INSERT.
    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '10.00']),
        '23514',
        message: 'is unbalanced',
    );
});

it('rejects at the deferred check an unbalanced line when the company context is gone (fails closed)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '100.00');

    // Control: this consumes the deferred event of the entry itself (and passes, the entry is balanced), so that the
    // only pending events left are the ones of the lines.
    DB::statement('set constraints journal_entry_balance immediate');

    // The third line leaves the entry unbalanced, but its check is still deferred.
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '10.00']);

    // Same way to lose the context as the RLS test in JournalEntrySealTest: from now on RLS hides every line. The
    // check must not be skipped because it sees nothing: it must see zero lines and reject.
    app(DatabaseCompanyContext::class)->leave();

    SqlAssertions::expectRejected(
        fn () => DB::statement('set constraints all immediate'),
        '23514',
        message: 'has fewer than two lines',
    );
});

it('rejects an unbalanced line whose later sibling was rolled back with its savepoint', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '100.00');

    // Control: consumes the deferred event of the entry itself, so that only the events of the lines are left.
    DB::statement('set constraints journal_entry_balance immediate');

    // The unbalancing debit goes in a savepoint that is released: its event stays pending.
    DB::transaction(fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '10.00']));

    // The credit that would balance it goes in a savepoint that is rolled back. Its line has the greatest id, but the
    // rollback discards its deferred event, so the check has to come from the released line, not from the last id
    // handed out by the sequence.
    DB::beginTransaction();
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '10.00');
    DB::rollBack();

    SqlAssertions::expectRejected(
        fn () => DB::statement('set constraints all immediate'),
        '23514',
        message: 'is unbalanced',
    );
});

// Acceptances.

it('accepts an entry with two balanced lines inserted one per statement', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '100.00');

    DB::statement('set constraints all immediate');

    expect(balanceLineCount($ledger['entry']))->toBe(2);
});

it('accepts a balanced entry of three lines (100.00 = 60.00 + 40.00)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '60.00');
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '40.00');

    DB::statement('set constraints all immediate');

    expect(balanceLineCount($ledger['entry']))->toBe(3);
});

it('accepts amounts that do not add up in binary floating point (0.10 + 0.20 = 0.30)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '0.10']);
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '0.20']);
    balanceInsertCredit($ledger['entry'], $ledger['otherAccount'], '0.30');

    DB::statement('set constraints all immediate');

    expect(balanceLineCount($ledger['entry']))->toBe(3);
});
