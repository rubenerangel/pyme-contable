<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Counter table for consecutive numbers without gaps (never sequences, see docs/decisiones.md): the increment runs
// inside the transaction of the document, so a rollback gives the number back.
return new class extends Migration
{
    public function up(): void
    {
        // year NULL is the counter of a document type that does not restart each year. NULLS NOT DISTINCT makes
        // that NULL a single counter instead of allowing many. The composite foreign key keeps the counter in the
        // same company as its document type.
        DB::statement(<<<'SQL'
            CREATE TABLE document_counter (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                document_type_id bigint NOT NULL,
                year smallint,
                last_number bigint NOT NULL,
                CONSTRAINT document_counter_company_id_document_type_id_year_key
                    UNIQUE NULLS NOT DISTINCT (company_id, document_type_id, year),
                CONSTRAINT document_counter_document_type_fk
                    FOREIGN KEY (company_id, document_type_id) REFERENCES document_type (company_id, id)
            )
            SQL);

        DB::statement('ALTER TABLE document_counter ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_counter FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON document_counter
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // The counter is born at 1 and only moves forward one by one. The trigger enforces it even against a direct
        // write, so that a gap or a repeated number cannot be stored. The error is a check_violation (23514) that
        // names the table. A new row must start at 1 because the first number is handed out in the same statement
        // that creates the counter (INSERT ... ON CONFLICT DO UPDATE).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_counter_check_increment() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.last_number <> 1 THEN
                    RAISE EXCEPTION 'document_counter must start at 1, got %', NEW.last_number
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.last_number <> OLD.last_number + 1 THEN
                    RAISE EXCEPTION 'document_counter must advance by exactly 1, from % to %', OLD.last_number, NEW.last_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER document_counter_check_increment
                BEFORE INSERT OR UPDATE ON document_counter
                FOR EACH ROW EXECUTE FUNCTION document_counter_check_increment()
            SQL);

        // UPDATE on last_number alone: the type, the year and the company of a counter never change. No DELETE.
        DB::statement('GRANT SELECT, INSERT ON document_counter TO pyme_app');
        DB::statement('GRANT UPDATE (last_number) ON document_counter TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE document_counter_id_seq TO pyme_app');
    }

    public function down(): void
    {
        // migrate:fresh does not drop functions, so the trigger function goes away explicitly (and its trigger with it).
        DB::statement('DROP TABLE document_counter');
        DB::statement('DROP FUNCTION document_counter_check_increment()');
    }
};
