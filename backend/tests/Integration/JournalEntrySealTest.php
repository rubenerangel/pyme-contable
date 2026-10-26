<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// Sealing of the journal entry: a line can only be added in the transaction that inserted the entry. What is tested
// here is what RefreshDatabase can see: that savepoints inside the transaction do not seal (created_txid is the
// top-level transaction, not the subtransaction), and that the seal leaves the answer to the foreign key and to RLS
// when it cannot see the entry or the company. Sealing across real commits is tested in JournalEntryCommitTest. A SQL
// error aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own test and the
// control rows are inserted before it.
uses(RefreshDatabaseAsOwner::class);

/**
 * Enters a new company and builds its period, document type, draft document and two postable accounts, without the
 * entry: the test inserts it inside the savepoint it wants. The creation of the company already wrote in the outer
 * transaction, which therefore has its own xid.
 *
 * @return array{period: int, document: int, account: int, otherAccount: int}
 */
function sealEnterCompanyWithoutEntry(): array
{
    AccountFixtures::enterNewCompany();
    $period = LedgerFixtures::insertPeriod();
    $type = LedgerFixtures::insertDocumentType();

    return [
        'period' => $period,
        'document' => LedgerFixtures::insertDocument($type),
        'account' => LedgerFixtures::insertPostableAccount('T1'),
        'otherAccount' => LedgerFixtures::insertPostableAccount('T2'),
    ];
}

/**
 * Inserts a balanced pair of lines (debit 100.00 and credit 100.00) in the current transaction level.
 */
function sealInsertBalancedLines(int $entryId, int $accountId, int $otherAccountId): void
{
    LedgerFixtures::insertLine($entryId, $accountId, ['debit' => '100.00']);
    LedgerFixtures::insertLine($entryId, $otherAccountId, ['debit' => '0.00', 'credit' => '100.00']);
}

it('accepts lines inserted after the savepoint that inserted the entry was released', function (): void {
    $base = sealEnterCompanyWithoutEntry();

    // The outer transaction already wrote (the company, the document), so it has its own xid and the savepoint below
    // gets a different subtransaction xid. That is what tells created_txid (the top-level xid, from
    // pg_current_xact_id()) apart from xmin (which would hold the subtransaction xid and would seal the entry as soon
    // as the savepoint is released).
    $entry = DB::transaction(fn () => LedgerFixtures::insertEntry($base['document'], $base['period']));

    sealInsertBalancedLines($entry, $base['account'], $base['otherAccount']);

    DB::statement('set constraints all immediate');

    expect(DB::table('journal_entry_line')->where('journal_entry_id', $entry)->count())->toBe(2);
});

it('accepts an entry and its lines inserted in different savepoints', function (): void {
    $base = sealEnterCompanyWithoutEntry();

    $entry = DB::transaction(fn () => LedgerFixtures::insertEntry($base['document'], $base['period']));
    DB::transaction(fn () => sealInsertBalancedLines($entry, $base['account'], $base['otherAccount']));

    DB::statement('set constraints all immediate');

    expect(DB::table('journal_entry_line')->where('journal_entry_id', $entry)->count())->toBe(2);
});

it('reports a line pointing at an entry of another company as a foreign key violation (SQLSTATE 23503), not as sealed', function (): void {
    $companyA = LedgerFixtures::enterCompanyWithLedger();
    $companyB = LedgerFixtures::enterCompanyWithLedger();
    // Control: a line of company B in its own entry is accepted.
    LedgerFixtures::insertLine($companyB['entry'], $companyB['account']);

    // Company B cannot see the entry of company A (RLS), so the seal returns the row and the foreign key answers.
    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($companyA['entry'], $companyB['account']),
        '23503',
        'journal_entry_line_journal_entry_fkey',
    );
});

it('reports a line inserted without company context as a row level security violation (SQLSTATE 42501)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    // Control: with context the line is accepted.
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account']);
    app(DatabaseCompanyContext::class)->leave();

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account']),
        '42501',
        message: 'new row violates row-level security policy',
    );
});
