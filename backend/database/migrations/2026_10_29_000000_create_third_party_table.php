<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Third parties of a company (see docs/decisiones.md, Terceros): per company, with no global identity. Out until the
// accountant or a consumer needs it: the fiscal attributes of the RUT (they go in the jsonb of the document), contact
// data (a separate table that can be suppressed) and the rules that depend on movements (step 5).
return new class extends Migration
{
    public function up(): void
    {
        // id_type is the DIAN code of the document type. The list changes by tax year and is closed against the
        // resolution of the exógena when it is modeled, so only the shape (two digits) is checked here; '31' is NIT.
        // The check digit (DV) is neither stored nor typed: the domain computes it when the API arrives. A NIT is
        // digits only; the other types may carry letters. country is ISO 3166-1 alpha-2 (the mapping to the DIAN table
        // of countries comes with the exógena). country and person_type have no DEFAULT on purpose: whoever creates
        // the third party decides both.
        // Each constraint has a single responsibility: third_party_names_check holds one implication per person type,
        // so an invalid person_type does not violate it (only third_party_person_type_check rejects it). The
        // implications never evaluate NULL (a CHECK that evaluates NULL passes): person_type is NOT NULL and the rest
        // are IS [NOT] NULL tests. The blank check applies to each name column on its own.
        DB::statement(<<<'SQL'
            CREATE TABLE third_party (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                id_type text NOT NULL CONSTRAINT third_party_id_type_check CHECK (id_type ~ '^[0-9]{2}$'),
                id_number text NOT NULL
                    CONSTRAINT third_party_id_number_check CHECK (id_number <> '' AND id_number = btrim(id_number)),
                country text NOT NULL CONSTRAINT third_party_country_check CHECK (country ~ '^[A-Z]{2}$'),
                person_type text NOT NULL
                    CONSTRAINT third_party_person_type_check CHECK (person_type IN ('natural', 'legal')),
                first_name text,
                middle_names text,
                first_surname text,
                second_surname text,
                business_name text,
                is_active boolean NOT NULL DEFAULT true,
                CONSTRAINT third_party_nit_digits_check CHECK (id_type <> '31' OR id_number ~ '^[0-9]+$'),
                CONSTRAINT third_party_names_check CHECK (
                    (person_type <> 'natural'
                        OR (first_name IS NOT NULL AND first_surname IS NOT NULL AND business_name IS NULL))
                    AND (person_type <> 'legal'
                        OR (business_name IS NOT NULL
                            AND first_name IS NULL AND middle_names IS NULL
                            AND first_surname IS NULL AND second_surname IS NULL))
                ),
                CONSTRAINT third_party_names_not_blank_check CHECK (
                    (first_name IS NULL OR btrim(first_name) <> '')
                    AND (middle_names IS NULL OR btrim(middle_names) <> '')
                    AND (first_surname IS NULL OR btrim(first_surname) <> '')
                    AND (second_surname IS NULL OR btrim(second_surname) <> '')
                    AND (business_name IS NULL OR btrim(business_name) <> '')
                ),
                CONSTRAINT third_party_company_id_id_type_id_number_country_key
                    UNIQUE (company_id, id_type, id_number, country),
                CONSTRAINT third_party_company_id_id_key UNIQUE (company_id, id)
            )
            SQL);

        // Tenant table pattern (docs/decisiones.md, Multi-tenant), same as account: company_id defaults to the active
        // company, the WITH CHECK rejects an insert without context (42501) and the select finds no rows. FORCE
        // applies the policy to the owner too, and the policy is only TO pyme_app.
        DB::statement('ALTER TABLE third_party ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE third_party FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON third_party
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Same audit triggers as period and document_type (see audit_period_and_document_type).
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_insert_delete
                AFTER INSERT OR DELETE ON third_party
                FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_update
                AFTER UPDATE ON third_party
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION audit_log_capture()
            SQL);

        // INSERT and UPDATE per column, without id or company_id: both come from their DEFAULT and never change, so
        // the application cannot assign them. DELETE is there until the rules with movements (step 5) restrict it.
        DB::statement('GRANT SELECT, DELETE ON third_party TO pyme_app');
        DB::statement(<<<'SQL'
            GRANT INSERT (
                id_type, id_number, country, person_type, first_name, middle_names, first_surname, second_surname,
                business_name, is_active
            ) ON third_party TO pyme_app
            SQL);
        DB::statement(<<<'SQL'
            GRANT UPDATE (
                id_type, id_number, country, person_type, first_name, middle_names, first_surname, second_surname,
                business_name, is_active
            ) ON third_party TO pyme_app
            SQL);
        DB::statement('GRANT USAGE ON SEQUENCE third_party_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE third_party');
    }
};
