<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Document type of a company (invoice, payment, ...): the key of its consecutive numbering.
return new class extends Migration
{
    public function up(): void
    {
        // resets_yearly has no DEFAULT on purpose: whoever creates the type decides whether its numbering restarts
        // each year. UNIQUE (company_id, id) is the target of composite foreign keys, so that a row that points at a
        // document type cannot point at one of another company.
        DB::statement(<<<'SQL'
            CREATE TABLE document_type (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                code text NOT NULL,
                name text NOT NULL,
                resets_yearly boolean NOT NULL,
                CONSTRAINT document_type_company_id_code_key UNIQUE (company_id, code),
                CONSTRAINT document_type_company_id_id_key UNIQUE (company_id, id)
            )
            SQL);

        DB::statement('ALTER TABLE document_type ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_type FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON document_type
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // code and resets_yearly define how existing documents are numbered, so they are fixed once created. No DELETE.
        DB::statement('GRANT SELECT, INSERT ON document_type TO pyme_app');
        DB::statement('GRANT UPDATE (name) ON document_type TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE document_type_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE document_type');
    }
};
