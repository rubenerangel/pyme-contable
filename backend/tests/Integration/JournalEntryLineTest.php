<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// The journal_entry_line table: the CHECK on the amounts (exactly one side, never negative), the composite foreign keys
// to the journal entry and the dimensions (a line points only at rows of its own company), the simple one to the global
// municipality catalog, the defaults and the exact reading of the amounts. Balancing of the entry, sealing and the
// account rules (postable, dimensions required) come in steps 5b to 5d and are not tested here. The company context is
// set with DatabaseCompanyContext and company_id is never passed. A SQL error aborts the RefreshDatabase transaction in
// Postgres, so each failing statement lives in its own test and the control rows are inserted before it.
uses(RefreshDatabaseAsOwner::class);

// Amounts.

it('rejects an amount pair that is not exactly one positive side with journal_entry_line_amounts_check (SQLSTATE 23514)', function (string $debit, string $credit): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    // Control: a valid debit line and a valid credit line.
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '100.00', 'credit' => '0.00']);
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '0.00', 'credit' => '100.00']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => $debit, 'credit' => $credit]),
        '23514',
        'journal_entry_line_amounts_check',
    );
})->with([
    'both zero' => ['0', '0'],
    'both positive' => ['100.00', '100.00'],
    'negative debit' => ['-100.00', '0'],
    'negative credit' => ['0', '-100.00'],
    'negative debit against a positive credit' => ['-100.00', '100.00'],
    'debit that rounds to 0.00' => ['0.004', '0'],
    'credit that rounds to 0.00' => ['0', '0.004'],
]);

it('reads the amounts back as exact strings, with the missing side at 0.00 from its DEFAULT', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    // 1234567890123456.78 does not survive a float.
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '1234567890123456.78']);
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '0', 'credit' => '100']);
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['debit' => '0.015']);

    $rows = [];
    foreach (DB::select('select debit, credit from journal_entry_line order by id') as $row) {
        $rows[] = [$row->debit, $row->credit];
    }

    expect($rows)->toBe([
        ['1234567890123456.78', '0.00'],
        ['0.00', '100.00'],
        ['0.02', '0.00'],
    ]);
});

it('takes the company of the context and leaves the dimensions and the description empty', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();

    $id = LedgerFixtures::insertLine($ledger['entry'], $ledger['account']);

    $row = DB::selectOne('select * from journal_entry_line where id = ?', [$id]);

    expect([
        (int) $row->company_id,
        (int) $row->journal_entry_id,
        (int) $row->account_id,
        $row->third_party_id,
        $row->cost_center_id,
        $row->construction_project_id,
        $row->municipality_id,
        $row->description,
    ])->toBe([$ledger['company'], $ledger['entry'], $ledger['account'], null, null, null, null, null]);
});

it('rejects a blank description with journal_entry_line_description_check (SQLSTATE 23514)', function (string $description): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['description' => 'Test control']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['description' => $description]),
        '23514',
        'journal_entry_line_description_check',
    );
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

// References to rows of its own company.

it('accepts a line with every dimension of its own company and an existing municipality', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger(withDimensions: true);

    $id = LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], [
        'third_party_id' => $ledger['thirdParty'],
        'cost_center_id' => $ledger['costCenter'],
        'construction_project_id' => $ledger['constructionProject'],
        'municipality_id' => $ledger['municipality'],
        'description' => 'Test line',
    ]);

    $row = DB::selectOne('select third_party_id, cost_center_id, construction_project_id, municipality_id from journal_entry_line where id = ?', [$id]);

    expect([(int) $row->third_party_id, (int) $row->cost_center_id, (int) $row->construction_project_id, (int) $row->municipality_id])
        ->toBe([$ledger['thirdParty'], $ledger['costCenter'], $ledger['constructionProject'], $ledger['municipality']]);
});

it('rejects a reference to a row of another company with the composite foreign key of the column (SQLSTATE 23503)', function (string $column, string $key, string $constraint): void {
    $other = LedgerFixtures::enterCompanyWithLedger(withDimensions: true);

    $own = LedgerFixtures::enterCompanyWithLedger(withDimensions: true);
    // Control: the line with every reference of its own company passes.
    LedgerFixtures::insertLine($own['entry'], $own['account'], [
        'third_party_id' => $own['thirdParty'],
        'cost_center_id' => $own['costCenter'],
        'construction_project_id' => $own['constructionProject'],
    ]);

    $line = [
        'journal_entry_id' => $own['entry'],
        'account_id' => $own['account'],
        'third_party_id' => $own['thirdParty'],
        'cost_center_id' => $own['costCenter'],
        'construction_project_id' => $own['constructionProject'],
        $column => $other[$key],
    ];

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($line['journal_entry_id'], $line['account_id'], $line),
        '23503',
        $constraint,
    );
})->with([
    'journal entry' => ['journal_entry_id', 'entry', 'journal_entry_line_journal_entry_fkey'],
    'account' => ['account_id', 'account', 'journal_entry_line_account_fkey'],
    'third party' => ['third_party_id', 'thirdParty', 'journal_entry_line_third_party_fkey'],
    'cost center' => ['cost_center_id', 'costCenter', 'journal_entry_line_cost_center_fkey'],
    'construction project' => ['construction_project_id', 'constructionProject', 'journal_entry_line_construction_project_fkey'],
]);

it('rejects a municipality that does not exist with journal_entry_line_municipality_fkey (SQLSTATE 23503)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger(withDimensions: true);
    LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['municipality_id' => $ledger['municipality']]);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertLine($ledger['entry'], $ledger['account'], ['municipality_id' => LedgerFixtures::missingMunicipalityId()]),
        '23503',
        'journal_entry_line_municipality_fkey',
    );
});
