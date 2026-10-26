<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Line of a journal entry: one debit or one credit on an account, with the dimensions that qualify it. Insert-only for
// pyme_app, like the entry. Balancing and sealing (step 5b) and the account rules (step 5d) come later.
return new class extends Migration
{
    public function up(): void
    {
        // company_id has no REFERENCES companies: the composite foreign key to journal_entry already implies it. Every
        // foreign key to a table of the company is composite, so a line cannot point at a row of another company. The
        // municipality is a global catalog (no company_id), hence a simple foreign key. debit and credit are
        // NUMERIC(18,2); the CHECK allows exactly one of them above zero, so a value that rounds to 0.00 (0.004)
        // counts as zero when the check runs. No cascade: nothing is deleted from the book.
        DB::statement(<<<'SQL'
            CREATE TABLE journal_entry_line (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint,
                journal_entry_id bigint NOT NULL,
                account_id bigint NOT NULL,
                debit numeric(18,2) NOT NULL DEFAULT 0,
                credit numeric(18,2) NOT NULL DEFAULT 0,
                third_party_id bigint,
                cost_center_id bigint,
                construction_project_id bigint,
                municipality_id bigint,
                description text
                    CONSTRAINT journal_entry_line_description_check CHECK (description IS NULL OR btrim(description) <> ''),
                CONSTRAINT journal_entry_line_amounts_check
                    CHECK (debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0)),
                CONSTRAINT journal_entry_line_journal_entry_fkey FOREIGN KEY (company_id, journal_entry_id)
                    REFERENCES journal_entry (company_id, id),
                CONSTRAINT journal_entry_line_account_fkey FOREIGN KEY (company_id, account_id)
                    REFERENCES account (company_id, id),
                CONSTRAINT journal_entry_line_third_party_fkey FOREIGN KEY (company_id, third_party_id)
                    REFERENCES third_party (company_id, id),
                CONSTRAINT journal_entry_line_cost_center_fkey FOREIGN KEY (company_id, cost_center_id)
                    REFERENCES cost_center (company_id, id),
                CONSTRAINT journal_entry_line_construction_project_fkey FOREIGN KEY (company_id, construction_project_id)
                    REFERENCES construction_project (company_id, id),
                CONSTRAINT journal_entry_line_municipality_fkey FOREIGN KEY (municipality_id)
                    REFERENCES municipality (id)
            )
            SQL);

        // Every foreign key has an index that starts with its columns, except the one to municipality: only pyme_owner
        // deletes or changes a municipality, in migrations, so that index would be paid on every line without serving the
        // application (declared exception in ForeignKeyCatalogTest). The optional dimensions get partial indexes: most
        // lines have no value there, and a foreign key never looks up a NULL.
        DB::statement(<<<'SQL'
            CREATE INDEX journal_entry_line_company_id_journal_entry_id_id_index
                ON journal_entry_line (company_id, journal_entry_id, id)
            SQL);
        DB::statement('CREATE INDEX journal_entry_line_company_id_account_id_index ON journal_entry_line (company_id, account_id)');
        DB::statement(<<<'SQL'
            CREATE INDEX journal_entry_line_company_id_third_party_id_index
                ON journal_entry_line (company_id, third_party_id) WHERE third_party_id IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX journal_entry_line_company_id_cost_center_id_index
                ON journal_entry_line (company_id, cost_center_id) WHERE cost_center_id IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX journal_entry_line_company_id_construction_project_id_index
                ON journal_entry_line (company_id, construction_project_id) WHERE construction_project_id IS NOT NULL
            SQL);

        // Tenant table pattern (docs/decisiones.md, Multi-tenant), same as account.
        DB::statement('ALTER TABLE journal_entry_line ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE journal_entry_line FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON journal_entry_line
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Insert-only: SELECT and INSERT per column, without id or company_id. No UPDATE and no DELETE of any kind. No
        // audit triggers: the book is immutable and auditing it would duplicate every line.
        DB::statement('GRANT SELECT ON journal_entry_line TO pyme_app');
        DB::statement(<<<'SQL'
            GRANT INSERT (
                journal_entry_id, account_id, debit, credit, third_party_id, cost_center_id, construction_project_id,
                municipality_id, description
            ) ON journal_entry_line TO pyme_app
            SQL);
        DB::statement('GRANT USAGE ON SEQUENCE journal_entry_line_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE journal_entry_line');
    }
};
