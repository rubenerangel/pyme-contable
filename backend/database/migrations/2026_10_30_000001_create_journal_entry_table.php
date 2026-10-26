<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Journal entry: the sealed record of a posted document, one per document (see docs/decisiones.md). It exists only when
// the document is posted, so the state of a document is derived from it. It is the book: insert-only for pyme_app.
return new class extends Migration
{
    public function up(): void
    {
        // company_id has no REFERENCES companies: the composite foreign keys to document and document_type already imply
        // it. That is one check less per row and no shared FOR KEY SHARE on the companies row between concurrent
        // transactions of the same company (as in journal_entry_line).
        // document_type_id and number_year are derived by the trigger from the document and its period; they are
        // columns of the entry so that the numbering key can be a plain UNIQUE. number_year is NULL for a type that
        // does not restart each year, hence NULLS NOT DISTINCT: without it two entries with the same number would
        // pass. created_txid is the transaction that inserted the entry; the seal of the next migration compares it
        // with the current one to tell lines added in the same transaction from lines added later. accounting_framework has a
        // single value for now (financial); the CHECK widens when another framework exists.
        DB::statement(<<<'SQL'
            CREATE TABLE journal_entry (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint,
                document_id bigint NOT NULL,
                document_type_id bigint NOT NULL,
                period_id bigint NOT NULL,
                accounting_date date NOT NULL,
                number integer NOT NULL CONSTRAINT journal_entry_number_check CHECK (number > 0),
                number_year smallint,
                accounting_framework text NOT NULL DEFAULT 'financial'
                    CONSTRAINT journal_entry_accounting_framework_check CHECK (accounting_framework IN ('financial')),
                posted_at timestamptz NOT NULL DEFAULT now(),
                created_txid xid8 NOT NULL DEFAULT pg_current_xact_id(),
                CONSTRAINT journal_entry_company_id_id_key UNIQUE (company_id, id),
                CONSTRAINT journal_entry_company_id_document_id_key UNIQUE (company_id, document_id),
                CONSTRAINT journal_entry_number_key
                    UNIQUE NULLS NOT DISTINCT (company_id, document_type_id, number_year, number),
                CONSTRAINT journal_entry_document_fkey FOREIGN KEY (company_id, document_id)
                    REFERENCES document (company_id, id),
                CONSTRAINT journal_entry_document_type_fkey FOREIGN KEY (company_id, document_type_id)
                    REFERENCES document_type (company_id, id),
                CONSTRAINT journal_entry_period_fkey FOREIGN KEY (company_id, period_id)
                    REFERENCES period (company_id, id)
            )
            SQL);

        DB::statement('CREATE INDEX journal_entry_company_id_period_id_index ON journal_entry (company_id, period_id)');

        // Tenant table pattern (docs/decisiones.md, Multi-tenant), same as account.
        DB::statement('ALTER TABLE journal_entry ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE journal_entry FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON journal_entry
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Derivation of document_type_id and number_year. The function runs as pyme_app (not SECURITY DEFINER), so RLS
        // limits what it reads to the company in context: a document or period of another company looks missing and
        // is reported as a foreign key violation (23503), the same code the composite foreign key would raise. The
        // document type always exists (the document points at it through a composite key). The derived values replace
        // whatever the INSERT carries (pyme_app has no privilege on those columns anyway).
        // A NULL company_id (no context: the DEFAULT gave NULL) or document_id returns early without deriving. A NULL
        // period_id returns after deriving document_type_id, because Postgres checks NOT NULL in column order and a
        // NULL document_type_id would be reported instead of period_id. This way the WITH CHECK of RLS (42501) and
        // NOT NULL (23502) answer for the right column. Without this, a forgotten context would look like "document
        // not found" (23503).
        // Step 5c adds FOR SHARE on the document and the period here, and the period and reversal rules.
        // migrate:fresh does not drop functions and CREATE OR REPLACE keeps the ACL and the owner of the previous one,
        // so the function is dropped first (no CASCADE: the table and its trigger are already gone).
        // search_path is pinned although the function is not SECURITY DEFINER: the trigger runs with the caller's
        // search_path, and without pg_temp last a temporary table could shadow document.
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_enforce_posting()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION journal_entry_enforce_posting() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_document_type_id bigint;
                v_resets_yearly boolean;
                v_period_year smallint;
            BEGIN
                IF NEW.company_id IS NULL OR NEW.document_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT d.document_type_id, t.resets_yearly INTO v_document_type_id, v_resets_yearly
                FROM document d
                JOIN document_type t ON t.id = d.document_type_id
                WHERE d.id = NEW.document_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'journal entry: document % not found', NEW.document_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                NEW.document_type_id := v_document_type_id;

                IF NEW.period_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT year INTO v_period_year
                FROM period
                WHERE id = NEW.period_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'journal entry: period % not found', NEW.period_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                NEW.number_year := CASE WHEN v_resets_yearly THEN v_period_year END;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_entry_enforce_posting
                BEFORE INSERT ON journal_entry
                FOR EACH ROW EXECUTE FUNCTION journal_entry_enforce_posting()
            SQL);

        // The book is insert-only: SELECT and INSERT per column, without id, company_id or the derived and defaulted
        // columns (document_type_id, number_year, accounting_framework, posted_at, created_txid). No UPDATE and no
        // DELETE of any kind. No audit triggers: auditing it would duplicate every entry.
        DB::statement('GRANT SELECT ON journal_entry TO pyme_app');
        DB::statement('GRANT INSERT (document_id, period_id, accounting_date, number) ON journal_entry TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE journal_entry_id_seq TO pyme_app');
    }

    public function down(): void
    {
        // migrate:fresh does not drop functions, so it goes away explicitly, after the table and its trigger.
        DB::statement('DROP TABLE journal_entry');
        DB::statement('DROP FUNCTION journal_entry_enforce_posting()');
    }
};
