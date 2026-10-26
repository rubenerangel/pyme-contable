<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Construction projects of a company (see docs/decisiones.md). Only code and name for now: the attributes of a
// project (client, contract, municipality, dates) are pending the accountant. Rules that depend on movements come
// with the lines (step 5).
return new class extends Migration
{
    public function up(): void
    {
        // Flat table, no hierarchy. UNIQUE (company_id, id) is the target of the composite foreign keys of the tables
        // that point at it (step 5), so a line cannot reference a construction project of another company.
        DB::statement(<<<'SQL'
            CREATE TABLE construction_project (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                code text NOT NULL CONSTRAINT construction_project_code_check CHECK (code <> '' AND code = btrim(code)),
                name text NOT NULL CONSTRAINT construction_project_name_check CHECK (btrim(name) <> ''),
                is_active boolean NOT NULL DEFAULT true,
                CONSTRAINT construction_project_company_id_code_key UNIQUE (company_id, code),
                CONSTRAINT construction_project_company_id_id_key UNIQUE (company_id, id)
            )
            SQL);

        // Tenant table pattern (docs/decisiones.md, Multi-tenant), same as account: company_id defaults to the active
        // company, the WITH CHECK rejects an insert without context (42501) and the select finds no rows. FORCE
        // applies the policy to the owner too, and the policy is only TO pyme_app.
        DB::statement('ALTER TABLE construction_project ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE construction_project FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON construction_project
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Same audit triggers as period and document_type (see audit_period_and_document_type).
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_insert_delete
                AFTER INSERT OR DELETE ON construction_project
                FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_update
                AFTER UPDATE ON construction_project
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION audit_log_capture()
            SQL);

        // INSERT and UPDATE per column, without id or company_id: both come from their DEFAULT and never change, so
        // the application cannot assign them. DELETE is there until the rules with movements (step 5) restrict it.
        DB::statement('GRANT SELECT, DELETE ON construction_project TO pyme_app');
        DB::statement('GRANT INSERT (code, name, is_active) ON construction_project TO pyme_app');
        DB::statement('GRANT UPDATE (code, name, is_active) ON construction_project TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE construction_project_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE construction_project');
    }
};
