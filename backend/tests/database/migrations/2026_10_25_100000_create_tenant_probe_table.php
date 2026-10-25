<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tests-only table (never in dev or production): proves row level security by company in isolation. It is the RLS
// pattern the tenant tables of block 4 copy. Its table-level grants are deliberate: they let the tests try the WITH
// CHECK with a foreign company_id. The real tables grant INSERT and UPDATE per column, without id or company_id.
return new class extends Migration
{
    public function up(): void
    {
        // company_id defaults to the active company, so the application never assigns it. Without context the
        // expression is NULL: on insert the WITH CHECK of the policy rejects it (42501, Postgres evaluates RLS
        // before constraints) and NOT NULL stays as a second barrier; on select, update and delete the policy
        // finds no rows (fails closed).
        DB::statement(<<<'SQL'
            CREATE TABLE tenant_probe (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                label text NOT NULL
            )
            SQL);

        // FORCE applies the policies to the table owner too. The policy is only TO pyme_app, so with FORCE the
        // owner sees and writes no rows (no applicable policy, everything is denied).
        DB::statement('ALTER TABLE tenant_probe ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenant_probe FORCE ROW LEVEL SECURITY');

        // USING filters the rows that can be read, updated or deleted; WITH CHECK rejects writes of rows that
        // would end up in another company (insert, and update that moves a row).
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON tenant_probe
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_probe TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE tenant_probe_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE tenant_probe');
    }
};
