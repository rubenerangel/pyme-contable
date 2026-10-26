<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// Balancing and sealing of the journal entry at real COMMIT: the deferred constraint triggers only fire when the
// transaction commits, and the seal only shows when the entry was committed by an earlier transaction, which
// RefreshDatabase cannot reproduce. One pyme_app connection (the default) is enough: each step is its own transaction
// with real commit. DatabaseTruncationAsOwner truncates the tables between tests.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with a committed company context and a committed setup (period, document type, draft document and two
 * postable accounts), so that the transactions of the test only hold what is under test. Whatever the test leaves
 * open is rolled back and the context is left in a finally.
 *
 * $test receives: period id, document id, account id, other account id.
 */
function journalEntryCommitRun(Closure $test): void
{
    $company = Company::factory()->create();
    $userId = User::factory()->create()->id;
    $connection = DB::connection();
    $context = new DatabaseCompanyContext($connection);

    try {
        $context->enter($company->id, $userId);

        $setup = DB::transaction(function (): array {
            $type = LedgerFixtures::insertDocumentType();

            return [
                LedgerFixtures::insertPeriod(),
                LedgerFixtures::insertDocument($type),
                LedgerFixtures::insertPostableAccount('T1'),
                LedgerFixtures::insertPostableAccount('T2'),
            ];
        });

        $test(...$setup);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $context->leave();
    }
}

/**
 * Inserts the entry of the document with a debit line of $debit and a credit line of $credit, in the current
 * transaction.
 */
function journalEntryCommitInsertEntryWithLines(int $period, int $document, int $account, int $otherAccount, string $debit, string $credit): int
{
    $entry = LedgerFixtures::insertEntry($document, $period);
    LedgerFixtures::insertLine($entry, $account, ['debit' => $debit]);
    LedgerFixtures::insertLine($entry, $otherAccount, ['debit' => '0.00', 'credit' => $credit]);

    return $entry;
}

/**
 * Runs the closure and returns the PDOException it throws (QueryException included), or null if it does not throw.
 */
function journalEntryCommitCatch(Closure $statement): ?PDOException
{
    try {
        $statement();
    } catch (PDOException $e) {
        return $e;
    }

    return null;
}

/**
 * The SQLSTATE of a PDOException: errorInfo[0] or, if it is missing, its code.
 */
function journalEntryCommitSqlState(PDOException $e): string
{
    return (string) ($e->errorInfo[0] ?? $e->getCode());
}

it('rejects an unbalanced entry at COMMIT and persists neither the entry nor its lines', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        DB::beginTransaction();
        journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '90.00');

        $thrown = journalEntryCommitCatch(fn () => DB::commit());

        expect($thrown)->toBeInstanceOf(PDOException::class);
        expect(journalEntryCommitSqlState($thrown))->toBe('23514');
        expect($thrown->getMessage())->toContain('is unbalanced');
        expect([
            'entries' => DB::table('journal_entry')->where('document_id', $document)->count(),
            'lines' => DB::table('journal_entry_line')->count(),
        ])->toBe(['entries' => 0, 'lines' => 0]);
    });
});

it('rejects a line added to an entry committed in an earlier transaction (SQLSTATE 23514, sealed)', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        $entry = DB::transaction(fn () => journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '100.00'));

        DB::beginTransaction();
        SqlAssertions::expectRejected(
            fn () => LedgerFixtures::insertLine($entry, $account, ['debit' => '100.00']),
            '23514',
            message: 'is sealed',
        );
    });
});

it('rejects a balanced pair of lines added to an entry committed earlier', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        $entry = DB::transaction(fn () => journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '100.00'));

        // The pair is balanced and goes in one multi-row INSERT, so balancing would accept it: only the seal stops it.
        DB::beginTransaction();
        SqlAssertions::expectRejected(
            fn () => DB::table('journal_entry_line')->insert([
                ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => '50.00', 'credit' => '0.00'],
                ['journal_entry_id' => $entry, 'account_id' => $otherAccount, 'debit' => '0.00', 'credit' => '50.00'],
            ]),
            '23514',
            message: 'is sealed',
        );
    });
});

it('rejects at COMMIT a balanced pair added to an entry committed earlier when the insert-time seal did not see it', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        $entry = DB::transaction(fn () => journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '100.00'));

        $owner = DB::connection('pgsql_owner');

        try {
            // Simulates the READ COMMITTED race without depending on timing. When another transaction commits the entry
            // after the seal's own SELECT but before the foreign key check at the end of the statement, the BEFORE
            // INSERT seal does not see the entry and lets the row through. Turning the seal off (real commit, as the owner) is
            // exactly what the statement sees when that happens: the seal did not act. At COMMIT everything committed
            // is visible, so the deferred check has to revalidate the seal.
            $owner->statement('ALTER TABLE journal_entry_line DISABLE TRIGGER journal_entry_line_seal');

            // A balanced pair in one multi-row INSERT: balancing alone would accept it.
            DB::beginTransaction();
            DB::table('journal_entry_line')->insert([
                ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => '50.00', 'credit' => '0.00'],
                ['journal_entry_id' => $entry, 'account_id' => $otherAccount, 'debit' => '0.00', 'credit' => '50.00'],
            ]);

            $thrown = journalEntryCommitCatch(fn () => DB::commit());

            expect($thrown)->toBeInstanceOf(PDOException::class, 'the COMMIT must reject the lines added to a sealed entry');
            expect(journalEntryCommitSqlState($thrown))->toBe('23514');
            expect($thrown->getMessage())->toContain('is sealed');
            expect(DB::table('journal_entry_line')->where('journal_entry_id', $entry)->count())->toBe(2);
        } finally {
            // Always, even if the test fails: DatabaseTruncation truncates tables but does not recreate triggers, so a
            // trigger left disabled would stay off for every later test. A transaction left open by pyme_app would hold
            // a row lock that blocks the ALTER, so it is rolled back first.
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $owner->statement('ALTER TABLE journal_entry_line ENABLE TRIGGER journal_entry_line_seal');

            // Read back as the owner: 'O' is "enabled in origin and local mode". A trigger still off must not go unseen.
            $state = $owner->selectOne(
                "select tgenabled::text as state from pg_trigger where tgrelid = 'journal_entry_line'::regclass and tgname = 'journal_entry_line_seal'",
            );
            if ($state?->state !== 'O') {
                throw new RuntimeException('journal_entry_line_seal was left disabled by the test, state: '.var_export($state?->state, true));
            }
        }
    });
});

it('rejects at COMMIT a line that slipped past the insert-time seal even when the committed entry has lines with greater ids', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        $owner = DB::connection('pgsql_owner');

        try {
            // The committed entry gets ids above 1000000.
            $owner->statement("select setval('journal_entry_line_id_seq', 1000000)");
            $entry = DB::transaction(fn () => journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '100.00'));

            // The injected line must get an id BELOW the committed lines: the tables are truncated at the start of each
            // test, so the 500000 range is free. The injected line is then not the line with the greatest id of the
            // entry, so the balancing of that greatest-id line does not look at it: only the seal revalidation done on
            // every line detects it. If the revalidation lived only in the greatest-id line branch, this entry would
            // commit unbalanced.
            $owner->statement("select setval('journal_entry_line_id_seq', 500000)");
            $owner->statement('ALTER TABLE journal_entry_line DISABLE TRIGGER journal_entry_line_seal');

            // A single debit line of 10.00: the entry would end up unbalanced if it committed.
            DB::beginTransaction();
            LedgerFixtures::insertLine($entry, $account, ['debit' => '10.00']);

            $thrown = journalEntryCommitCatch(fn () => DB::commit());

            expect($thrown)->toBeInstanceOf(PDOException::class, 'the COMMIT must reject a line added to a sealed entry');
            expect(journalEntryCommitSqlState($thrown))->toBe('23514');
            expect($thrown->getMessage())->toContain('is sealed');
            expect(DB::table('journal_entry_line')->where('journal_entry_id', $entry)->count())->toBe(2);
        } finally {
            // Always, even if the test fails: roll back whatever pyme_app left open (its row lock would block the
            // ALTER), turn the seal back on and verify it, and leave the sequence above every id used.
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $owner->statement('ALTER TABLE journal_entry_line ENABLE TRIGGER journal_entry_line_seal');
            $owner->statement("select setval('journal_entry_line_id_seq', 1000002)");

            // Read back as the owner: 'O' is "enabled in origin and local mode". A trigger still off must not go unseen.
            $state = $owner->selectOne(
                "select tgenabled::text as state from pg_trigger where tgrelid = 'journal_entry_line'::regclass and tgname = 'journal_entry_line_seal'",
            );
            if ($state?->state !== 'O') {
                throw new RuntimeException('journal_entry_line_seal was left disabled by the test, state: '.var_export($state?->state, true));
            }
        }
    });
});

it('commits a balanced entry with its lines', function (): void {
    journalEntryCommitRun(function (int $period, int $document, int $account, int $otherAccount): void {
        DB::transaction(fn () => journalEntryCommitInsertEntryWithLines($period, $document, $account, $otherAccount, '100.00', '100.00'));

        expect([
            'entries' => DB::table('journal_entry')->where('document_id', $document)->count(),
            'lines' => DB::table('journal_entry_line')->count(),
        ])->toBe(['entries' => 1, 'lines' => 2]);
    });
});
