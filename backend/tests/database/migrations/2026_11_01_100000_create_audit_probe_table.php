<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tests-only table (never in dev or production): it exists because no real table lets pyme_app DELETE, and the audit
// trigger has to be proven for INSERT, UPDATE and DELETE. It follows the tenant table pattern (see account) and
// carries the two audit triggers that the audited tables of block 4 carry. Its grants are per column, without id or
// company_id, like the real tables. It must run after the audit_log migration, which creates audit_log_capture(), so
// its date is later than every audit migration.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_probe (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                label text NOT NULL
            )
            SQL);

        DB::statement('ALTER TABLE audit_probe ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_probe FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON audit_probe
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // The foreign key to companies needs an index that starts with company_id, like the real tables get from their
        // composite unique keys (ForeignKeyCatalogTest). The probe has no such key.
        DB::statement('CREATE INDEX audit_probe_company_id_index ON audit_probe (company_id)');

        DB::statement('GRANT SELECT, DELETE ON audit_probe TO pyme_app');
        DB::statement('GRANT INSERT (label) ON audit_probe TO pyme_app');
        DB::statement('GRANT UPDATE (label) ON audit_probe TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE audit_probe_id_seq TO pyme_app');

        // Same pair of triggers as the audited tables. The UPDATE one skips rows that did not really change.
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_insert_delete
                AFTER INSERT OR DELETE ON audit_probe
                FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_update
                AFTER UPDATE ON audit_probe
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION audit_log_capture()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE audit_probe');
    }
};
