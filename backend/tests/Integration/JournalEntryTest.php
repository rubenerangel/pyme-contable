<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// The journal_entry table (the ledger header): what the BEFORE INSERT trigger derives (the document type of the
// document and the numbering year of the period), the defaults, the CHECK and UNIQUE constraints, the references to the
// document and the period (which the trigger reports as 23503 when it cannot see them, for a missing row or for one of
// another company) and the columns pyme_app cannot write. Balancing, sealing, period and date rules, account rules and
// the gap-free numbering come in steps 5b to 5e and are not tested here, so no test here balances its lines (there is
// no constraint trigger yet) and `number` is chosen by the test. The company context is set with DatabaseCompanyContext
// and company_id is never passed. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing
// statement lives in its own test and the control rows are inserted before it.
uses(RefreshDatabaseAsOwner::class);

// Derived columns.

it('copies the document type of the document, which the insert does not pass', function (): void {
    AccountFixtures::enterNewCompany();
    $period = LedgerFixtures::insertPeriod();
    $otherType = LedgerFixtures::insertDocumentType('TS2');
    $type = LedgerFixtures::insertDocumentType('TST');
    $document = LedgerFixtures::insertDocument($type);
    LedgerFixtures::insertDocument($otherType);

    $entry = LedgerFixtures::insertEntry($document, $period);

    expect((int) DB::table('journal_entry')->where('id', $entry)->value('document_type_id'))->toBe($type);
});

it('numbers by the year of the period when the document type restarts each year, and without year when it does not', function (bool $resetsYearly, ?int $expectedYear): void {
    AccountFixtures::enterNewCompany();
    $period = LedgerFixtures::insertPeriod(2027, 3);
    $type = LedgerFixtures::insertDocumentType('TST', $resetsYearly);
    $document = LedgerFixtures::insertDocument($type, ['document_date' => '2027-03-10']);

    $entry = LedgerFixtures::insertEntry($document, $period, ['accounting_date' => '2027-03-10']);

    $numberYear = DB::table('journal_entry')->where('id', $entry)->value('number_year');

    expect($numberYear === null ? null : (int) $numberYear)->toBe($expectedYear);
})->with([
    'restarts each year' => [true, 2027],
    'does not restart' => [false, null],
]);

// Defaults.

it('takes the company of the context and the defaults of the framework and the posting time', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();

    $row = DB::selectOne(<<<'SQL'
        select company_id, accounting_framework, number,
               posted_at is not null as has_posted_at,
               posted_at = now() as posted_now,
               created_txid is not null as has_txid,
               created_txid = pg_current_xact_id() as same_txid
        from journal_entry
        where id = ?
        SQL, [$ledger['entry']]);

    expect([
        (int) $row->company_id,
        $row->accounting_framework,
        (int) $row->number,
        (bool) $row->has_posted_at,
        (bool) $row->posted_now,
        (bool) $row->has_txid,
        (bool) $row->same_txid,
    ])->toBe([$ledger['company'], 'financial', 1, true, true, true, true]);
});

// CHECK constraint.

it('rejects number 0 with journal_entry_number_check (SQLSTATE 23514)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($document, $ledger['period'], ['number' => 0]),
        '23514',
        'journal_entry_number_check',
    );
});

// UNIQUE constraints.

it('rejects a second journal entry for the same document with journal_entry_company_id_document_id_key (SQLSTATE 23505)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();

    // Another number, so only the one-entry-per-document key can reject it.
    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($ledger['document'], $ledger['period'], ['number' => 2]),
        '23505',
        'journal_entry_company_id_document_id_key',
    );
});

it('rejects the same type, numbering year and number on another document with journal_entry_number_key (SQLSTATE 23505)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($document, $ledger['period'], ['number' => 1]),
        '23505',
        'journal_entry_number_key',
    );
});

it('rejects the same type and number on another document when the type does not restart each year, where number_year is NULL (NULLS NOT DISTINCT)', function (): void {
    AccountFixtures::enterNewCompany();
    $period = LedgerFixtures::insertPeriod();
    $type = LedgerFixtures::insertDocumentType('TST', false);
    LedgerFixtures::insertEntry(LedgerFixtures::insertDocument($type), $period, ['number' => 1]);
    $document = LedgerFixtures::insertDocument($type);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($document, $period, ['number' => 1]),
        '23505',
        'journal_entry_number_key',
    );
});

it('accepts the same number for another document type or in another numbering year', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $otherPeriod = LedgerFixtures::insertPeriod(2027, 1);
    $otherType = LedgerFixtures::insertDocumentType('TS2');

    // Number 1 again with another document type.
    LedgerFixtures::insertEntry(LedgerFixtures::insertDocument($otherType), $ledger['period']);
    // Number 1 again in another year, because the type restarts each year.
    LedgerFixtures::insertEntry(LedgerFixtures::insertDocument($ledger['type']), $otherPeriod);

    // Another company is not a subcase: its document types differ from these ones, so the key could not collide there,
    // and ForeignKeyCatalogTest and TenantIsolationTest cover that company_id is part of the unique keys.
    expect(DB::table('journal_entry')->count())->toBe(3);
});

// References that the trigger resolves: it reports what it cannot see, a row that does not exist or one of another
// company that RLS hides, as a foreign key violation with its own message.

it('rejects a document that does not exist (SQLSTATE 23503, message of the trigger)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $missing = 900_000_001;

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($missing, $ledger['period'], ['number' => 2]),
        '23503',
        message: "journal entry: document {$missing} not found",
    );
});

it('rejects a document of another company (SQLSTATE 23503, message of the trigger)', function (): void {
    $other = LedgerFixtures::enterCompanyWithLedger();
    $documentOfOther = LedgerFixtures::insertDocument($other['type']);

    $own = LedgerFixtures::enterCompanyWithLedger();

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($documentOfOther, $own['period'], ['number' => 2]),
        '23503',
        message: "journal entry: document {$documentOfOther} not found",
    );
});

it('rejects a period of another company (SQLSTATE 23503, message of the trigger)', function (): void {
    $other = LedgerFixtures::enterCompanyWithLedger();

    $own = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($own['type']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($document, $other['period'], ['number' => 2]),
        '23503',
        message: "journal entry: period {$other['period']} not found",
    );
});

it('rejects a period that does not exist (SQLSTATE 23503, message of the trigger)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);
    $missing = 900_000_002;

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertEntry($document, $missing, ['number' => 2]),
        '23503',
        message: "journal entry: period {$missing} not found",
    );
});

// References the trigger cannot resolve without a company: with no context it returns the row untouched, so that the
// RLS policy and the NOT NULL constraints answer (and not a trigger message that would hide them).

it('rejects an insert without company context with the RLS policy (SQLSTATE 42501, not the message of the trigger)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);
    app(DatabaseCompanyContext::class)->leave();

    expect(fn () => LedgerFixtures::insertEntry($document, $ledger['period'], ['number' => 2]))
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('new row violates row-level security policy for table "journal_entry"');
        });
});

it('rejects a NULL document_id with the NOT NULL constraint (SQLSTATE 23502, not the message of the trigger)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();

    SqlAssertions::expectRejected(
        fn () => DB::table('journal_entry')->insert(['document_id' => null, 'period_id' => $ledger['period'], 'accounting_date' => '2026-01-15', 'number' => 2]),
        '23502',
        message: 'null value in column "document_id" of relation "journal_entry"',
    );
});

it('rejects a NULL period_id with the NOT NULL constraint (SQLSTATE 23502, not the message of the trigger)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);

    SqlAssertions::expectRejected(
        fn () => DB::table('journal_entry')->insert(['document_id' => $document, 'period_id' => null, 'accounting_date' => '2026-01-15', 'number' => 2]),
        '23502',
        message: 'null value in column "period_id" of relation "journal_entry"',
    );
});

// Columns that pyme_app cannot write: the trigger or a DEFAULT fills them. The row is otherwise valid, so the only
// reason to fail is the privilege on the column (Postgres 17 reports it as "permission denied for table").

it('does not let pyme_app insert a column that the trigger or a DEFAULT fills (SQLSTATE 42501)', function (string $column): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $document = LedgerFixtures::insertDocument($ledger['type']);

    $explicit = [
        'document_type_id' => $ledger['type'],
        'number_year' => 2026,
        'accounting_framework' => 'financial',
        'posted_at' => '2026-01-15 12:00:00+00',
        'created_txid' => DB::raw('pg_current_xact_id()'),
    ];

    expect(fn () => LedgerFixtures::insertEntry($document, $ledger['period'], ['number' => 2, $column => $explicit[$column]]))
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('permission denied for table journal_entry');
        });
})->with([
    'document_type_id',
    'number_year',
    'accounting_framework',
    'posted_at',
    'created_txid',
]);
