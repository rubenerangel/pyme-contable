<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Builders for the ledger tables (document, journal_entry, journal_entry_line) and the rows they point at, shared by
 * the ledger tests and by AppPermissionsTest. Every value is made up on purpose: accounts 'T1', 'T2', document types
 * 'TST', third parties in the 9000000xx series. company_id and id are never passed: company_id comes from the context
 * of the connection and id from the sequence. All inserts run on the default connection (pyme_app) in the company of
 * the current context, so a test that needs rows of two companies builds the first one, then enters the second.
 */
final class LedgerFixtures
{
    /**
     * Enters a new company and builds, in it: the period 2026/1, the document type 'TST' (restarts each year), a draft
     * document dated 2026-01-15, its journal entry number 1 and two postable accounts ('T1' and 'T2'). With
     * $withDimensions it also builds a third party, a cost center and a construction project, and picks an existing
     * municipality. The entry has no lines. Balancing is checked at COMMIT or by SET CONSTRAINTS ALL IMMEDIATE, and
     * tests with RefreshDatabase never reach the COMMIT, so the lines a test adds need not balance unless the test runs
     * SET CONSTRAINTS ALL IMMEDIATE (as its last statement, never before inserting the lines).
     *
     * @return array{company: int, period: int, type: int, document: int, entry: int, account: int, otherAccount: int, thirdParty: ?int, costCenter: ?int, constructionProject: ?int, municipality: ?int}
     */
    public static function enterCompanyWithLedger(bool $withDimensions = false): array
    {
        $company = AccountFixtures::enterNewCompany();
        $period = self::insertPeriod();
        $type = self::insertDocumentType();
        $document = self::insertDocument($type);

        return [
            'company' => $company,
            'period' => $period,
            'type' => $type,
            'document' => $document,
            'entry' => self::insertEntry($document, $period),
            'account' => self::insertPostableAccount('T1'),
            'otherAccount' => self::insertPostableAccount('T2'),
            'thirdParty' => $withDimensions ? self::insertThirdParty() : null,
            'costCenter' => $withDimensions ? self::insertCostCenter() : null,
            'constructionProject' => $withDimensions ? self::insertConstructionProject() : null,
            'municipality' => $withDimensions ? self::anyMunicipalityId() : null,
        ];
    }

    public static function insertPeriod(int $year = 2026, int $number = 1): int
    {
        return (int) DB::table('period')->insertGetId(['year' => $year, 'number' => $number]);
    }

    public static function insertDocumentType(string $code = 'TST', bool $resetsYearly = true): int
    {
        return (int) DB::table('document_type')->insertGetId([
            'code' => $code,
            'name' => 'Test '.$code,
            'resets_yearly' => $resetsYearly,
        ]);
    }

    /**
     * Inserts a draft document (a document without journal entry) with the given columns replaced.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertDocument(int $documentTypeId, array $overrides = []): int
    {
        return (int) DB::table('document')->insertGetId(array_merge(
            ['document_type_id' => $documentTypeId, 'document_date' => '2026-01-15'],
            $overrides,
        ));
    }

    /**
     * Inserts a journal entry for the document with the given columns replaced. document_type_id, number_year,
     * accounting_framework, posted_at and created_txid are never passed: they come from the trigger or a DEFAULT.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertEntry(int $documentId, int $periodId, array $overrides = []): int
    {
        return (int) DB::table('journal_entry')->insertGetId(array_merge(
            ['document_id' => $documentId, 'period_id' => $periodId, 'accounting_date' => '2026-01-15', 'number' => 1],
            $overrides,
        ));
    }

    /**
     * Inserts a journal entry line with a debit of 100.00 (credit 0.00 from its DEFAULT) unless the overrides say
     * otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertLine(int $entryId, int $accountId, array $overrides = []): int
    {
        return (int) DB::table('journal_entry_line')->insertGetId(array_merge(
            ['journal_entry_id' => $entryId, 'account_id' => $accountId, 'debit' => '100.00'],
            $overrides,
        ));
    }

    public static function insertPostableAccount(string $code): int
    {
        return AccountFixtures::insert(DB::connection(), $code, null, true);
    }

    public static function insertThirdParty(string $idNumber = '900000001'): int
    {
        return (int) DB::table('third_party')->insertGetId(MasterDataFixtures::thirdPartyRow(['id_number' => $idNumber]));
    }

    public static function insertCostCenter(): int
    {
        return (int) DB::table('cost_center')->insertGetId(MasterDataFixtures::costCenterRow());
    }

    public static function insertConstructionProject(): int
    {
        return (int) DB::table('construction_project')->insertGetId(MasterDataFixtures::constructionProjectRow());
    }

    /**
     * The id of an existing municipality, read from the table: the catalog is seeded by its migration and no DANE code
     * is written in a test.
     */
    public static function anyMunicipalityId(): int
    {
        return (int) DB::table('municipality')->orderBy('id')->value('id');
    }

    /**
     * An id that no municipality has, well above the largest one.
     */
    public static function missingMunicipalityId(): int
    {
        return (int) DB::table('municipality')->max('id') + 1_000_000;
    }
}
