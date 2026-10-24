<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Chart of accounts of a company, a tree through parent_id (see docs/decisiones.md, Catálogo de cuentas). Rules that
// depend on movements (an account with movements gets no children, is not deleted, keeps its attributes) come with the
// lines. The code format (PUC prefixes) and the flags per account are an accountant's call and are not decided here.
return new class extends Migration
{
    public function up(): void
    {
        // is_postable and normal_balance have no DEFAULT on purpose: whoever creates the account decides both. The
        // requires_* flags default to false and is_active to true. UNIQUE (company_id, id) is the target of composite
        // foreign keys, so the parent of an account cannot be one of another company (the same pattern applies to the
        // tables that point at an account). No cascade: a parent with children cannot be deleted (23503).
        DB::statement(<<<'SQL'
            CREATE TABLE account (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                code text NOT NULL CONSTRAINT account_code_check CHECK (code <> '' AND code = btrim(code)),
                name text NOT NULL CONSTRAINT account_name_check CHECK (btrim(name) <> ''),
                parent_id bigint CONSTRAINT account_parent_id_check CHECK (parent_id IS NULL OR parent_id <> id),
                is_postable boolean NOT NULL,
                normal_balance text NOT NULL
                    CONSTRAINT account_normal_balance_check CHECK (normal_balance IN ('debit', 'credit')),
                requires_third_party boolean NOT NULL DEFAULT false,
                requires_cost_center boolean NOT NULL DEFAULT false,
                requires_construction_project boolean NOT NULL DEFAULT false,
                requires_municipality boolean NOT NULL DEFAULT false,
                requires_tax_detail boolean NOT NULL DEFAULT false,
                is_active boolean NOT NULL DEFAULT true,
                CONSTRAINT account_company_id_code_key UNIQUE (company_id, code),
                CONSTRAINT account_company_id_id_key UNIQUE (company_id, id),
                CONSTRAINT account_company_id_parent_id_fkey FOREIGN KEY (company_id, parent_id)
                    REFERENCES account (company_id, id)
            )
            SQL);

        DB::statement('CREATE INDEX account_company_id_parent_id_index ON account (company_id, parent_id)');

        DB::statement('ALTER TABLE account ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE account FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON account
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // Hierarchy rules: the parent is not postable, no cycles, a postable account has no children. The function runs
        // as pyme_app (not SECURITY DEFINER), so RLS limits what it sees to the company in context. A parent that is
        // not visible (another company or missing) is not decided here: the composite foreign key rejects it (23503).
        // Every read of another row is FOR SHARE, which conflicts with the row lock of an UPDATE, so two concurrent
        // changes that break a rule together do not both pass: one waits for the other and sees its committed state,
        // or Postgres aborts one for deadlock.
        //  - Creating a child takes FOR SHARE on the parent, and marking the parent postable needs its row lock.
        //  - FOR SHARE is not allowed in a recursive CTE, so the ancestors are walked in a loop, locking each one. Two
        //    crossed moves (A under B and B under A) lock the same rows in opposite order.
        // The postable check looks for children without locking them: the lock on the account itself is the one that
        // serializes against the creation of a child.
        // migrate:fresh does not drop functions and CREATE OR REPLACE keeps the ACL and the owner of the previous one,
        // so the function is dropped first (no CASCADE: the table and its trigger are already gone).
        DB::statement('DROP FUNCTION IF EXISTS account_enforce_hierarchy()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION account_enforce_hierarchy() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_parent_is_postable boolean;
                v_ancestor_id bigint;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT is_postable INTO v_parent_is_postable
                    FROM account
                    WHERE id = NEW.parent_id
                    FOR SHARE;

                    IF FOUND AND v_parent_is_postable THEN
                        RAISE EXCEPTION 'account hierarchy: the parent account % is postable and cannot have children', NEW.parent_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    v_ancestor_id := NEW.parent_id;
                    WHILE v_ancestor_id IS NOT NULL LOOP
                        IF v_ancestor_id = NEW.id THEN
                            RAISE EXCEPTION 'account hierarchy: moving account % under account % creates a cycle', NEW.id, NEW.parent_id
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT parent_id INTO v_ancestor_id
                        FROM account
                        WHERE id = v_ancestor_id
                        FOR SHARE;
                    END LOOP;
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.is_postable AND EXISTS (SELECT 1 FROM account WHERE parent_id = NEW.id) THEN
                    RAISE EXCEPTION 'account hierarchy: account % has children and cannot be postable', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER account_enforce_hierarchy
                BEFORE INSERT OR UPDATE OF parent_id, is_postable ON account
                FOR EACH ROW EXECUTE FUNCTION account_enforce_hierarchy()
            SQL);

        // Same audit triggers as period and document_type (see audit_period_and_document_type).
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_insert_delete
                AFTER INSERT OR DELETE ON account
                FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_capture_update
                AFTER UPDATE ON account
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION audit_log_capture()
            SQL);

        // UPDATE per column: id and company_id never change. A column UPDATE also lets the trigger run SELECT ... FOR
        // SHARE on the rows (see period). DELETE is there for leaf accounts; the foreign key blocks a parent.
        DB::statement('GRANT SELECT, INSERT, DELETE ON account TO pyme_app');
        DB::statement(<<<'SQL'
            GRANT UPDATE (
                code, name, parent_id, is_postable, normal_balance,
                requires_third_party, requires_cost_center, requires_construction_project,
                requires_municipality, requires_tax_detail, is_active
            ) ON account TO pyme_app
            SQL);
        DB::statement('GRANT USAGE ON SEQUENCE account_id_seq TO pyme_app');
    }

    public function down(): void
    {
        // migrate:fresh does not drop functions, so it goes away explicitly, after the table and its triggers.
        DB::statement('DROP TABLE account');
        DB::statement('DROP FUNCTION account_enforce_hierarchy()');
    }
};
