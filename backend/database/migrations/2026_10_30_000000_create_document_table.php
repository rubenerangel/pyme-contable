<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Document of a company (see docs/decisiones.md): the business event a journal entry posts. There is no status column:
// a document is posted when it has a journal entry, and until then it is a draft that can be changed and deleted.
return new class extends Migration
{
    public function up(): void
    {
        // company_id has no REFERENCES companies: the composite foreign key to document_type already implies it. That is
        // one check less per row and no shared FOR KEY SHARE on the companies row between concurrent transactions of the
        // same company (as in journal_entry_line).
        // The composite foreign keys keep the document type and the reversed document inside the company. A document is
        // reversed at most once: UNIQUE (company_id, reverses_document_id) allows many NULLs (the default), so it only
        // limits the real reversals, and it is also the index of the foreign key. The rules that need the posted state
        // of the original (it must be posted, the reverse mirrors it) come with the immutability rules (step 5c).
        DB::statement(<<<'SQL'
            CREATE TABLE document (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint,
                document_type_id bigint NOT NULL,
                document_date date NOT NULL,
                description text CONSTRAINT document_description_check CHECK (description IS NULL OR btrim(description) <> ''),
                reverses_document_id bigint
                    CONSTRAINT document_reverses_document_id_check CHECK (reverses_document_id IS NULL OR reverses_document_id <> id),
                CONSTRAINT document_company_id_id_key UNIQUE (company_id, id),
                CONSTRAINT document_document_type_fkey FOREIGN KEY (company_id, document_type_id)
                    REFERENCES document_type (company_id, id),
                CONSTRAINT document_reverses_document_fkey FOREIGN KEY (company_id, reverses_document_id)
                    REFERENCES document (company_id, id),
                CONSTRAINT document_company_id_reverses_document_id_key UNIQUE (company_id, reverses_document_id)
            )
            SQL);

        DB::statement('CREATE INDEX document_company_id_document_type_id_index ON document (company_id, document_type_id)');

        // Tenant table pattern (docs/decisiones.md, Multi-tenant), same as account: company_id defaults to the active
        // company, the WITH CHECK rejects an insert without context (42501) and the select finds no rows. FORCE
        // applies the policy to the owner too, and the policy is only TO pyme_app.
        DB::statement('ALTER TABLE document ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON document
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Same audit triggers as period and document_type (see audit_period_and_document_type).
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_insert_delete
                AFTER INSERT OR DELETE ON document
                FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_update
                AFTER UPDATE ON document
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION audit_log_capture()
            SQL);

        // INSERT and UPDATE per column, without id or company_id: both come from their DEFAULT and never change, so
        // the application cannot assign them. DELETE is there for drafts; the foreign key from journal_entry blocks a
        // posted document, and the immutability rules (step 5c) cover the rest.
        DB::statement('GRANT SELECT, DELETE ON document TO pyme_app');
        DB::statement(<<<'SQL'
            GRANT INSERT (document_type_id, document_date, description, reverses_document_id) ON document TO pyme_app
            SQL);
        DB::statement(<<<'SQL'
            GRANT UPDATE (document_type_id, document_date, description, reverses_document_id) ON document TO pyme_app
            SQL);
        DB::statement('GRANT USAGE ON SEQUENCE document_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE document');
    }
};
