<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Balancing and sealing of the journal entry (see docs/decisiones.md, Integridad). Balancing: an entry with fewer than
// two lines or with sum(debit) <> sum(credit) does not commit, checked by constraint triggers deferred to COMMIT.
// Sealing: lines can only be added in the transaction that inserted the entry, so a posted entry never changes.
return new class extends Migration
{
    public function up(): void
    {
        // The rule lives only here, so the two balance triggers cannot drift apart. The sums are numeric and compared
        // as numeric, never float. It reads the lines as the caller, with RLS: if the company context is missing at
        // COMMIT it sees no lines and rejects with "fewer than two lines", so it fails closed. Both balance triggers
        // call it unconditionally when they validate, so neither one can skip the check for lack of visibility.
        // migrate:fresh does not drop functions and CREATE OR REPLACE keeps the ACL and the owner of the previous one,
        // so every function is dropped first (no CASCADE: the tables and their triggers are already gone).
        // search_path is pinned although the functions are not SECURITY DEFINER: the trigger runs with the caller's
        // search_path, and without pg_temp last a temporary table could shadow journal_entry_line.
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_assert_balanced(bigint)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION journal_entry_assert_balanced(p_entry_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_lines bigint;
                v_debit numeric;
                v_credit numeric;
            BEGIN
                SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0) INTO v_lines, v_debit, v_credit
                FROM journal_entry_line
                WHERE journal_entry_id = p_entry_id;

                IF v_lines < 2 THEN
                    RAISE EXCEPTION 'journal entry % has fewer than two lines', p_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_debit <> v_credit THEN
                    RAISE EXCEPTION 'journal entry % is unbalanced', p_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$
            SQL);

        // Balancing from the entry: catches the entry that never gets lines, which no line trigger would ever see.
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_balance()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION journal_entry_balance() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                PERFORM journal_entry_assert_balanced(NEW.id);

                RETURN NULL;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_entry_balance
                AFTER INSERT ON journal_entry
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_balance()
            SQL);

        // Balancing from the lines: catches lines added to an entry after SET CONSTRAINTS ALL IMMEDIATE validated it
        // (in immediate mode the trigger runs at the end of each statement). A row trigger fires once per line, and
        // checking the entry once per line would be quadratic, so only the line with the greatest id of its entry
        // validates: by the time a deferred trigger runs (end of statement or COMMIT) every line of the transaction
        // is visible, so that line sees all the others. Without set_config marks: no state to leak between
        // transactions or to forget on rollback of a savepoint (the event of a rolled back line is discarded with it,
        // and then the greatest id is another line that has its own event). The rule relies on ids growing with
        // insertion order within the entry, which holds because the seal keeps all lines of an entry in one
        // transaction and pyme_app can neither insert an explicit id nor setval the sequence backwards. A line of another
        // transaction that slipped past the insert-time seal (the race described below) can have a smaller id, which is
        // why the seal is rechecked on every line, before the greatest id rule decides whether to validate.
        // It first rechecks the seal: under READ COMMITTED the BEFORE trigger may not see an entry that another
        // transaction commits before the foreign key check of the same statement, so a line could join an entry that
        // was already committed. At this point everything committed is visible. An entry that is not found does not
        // raise here: the balance check below answers. The check is "no visible line with a greater id" and not
        // NEW.id = max(id): if RLS hides the lines (missing context) max is NULL, the comparison is NULL and the
        // check would be skipped, failing open. With NOT EXISTS it validates, assert_balanced sees no lines and
        // rejects, so this trigger fails closed like assert_balanced.
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_line_balance()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION journal_entry_line_balance() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_created_txid xid8;
            BEGIN
                SELECT created_txid INTO v_created_txid
                FROM journal_entry
                WHERE id = NEW.journal_entry_id;

                IF FOUND AND v_created_txid <> pg_current_xact_id() THEN
                    RAISE EXCEPTION 'journal entry % is sealed', NEW.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM journal_entry_line
                    WHERE journal_entry_id = NEW.journal_entry_id AND id > NEW.id
                ) THEN
                    PERFORM journal_entry_assert_balanced(NEW.journal_entry_id);
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_entry_line_balance
                AFTER INSERT ON journal_entry_line
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_line_balance()
            SQL);

        // Seal: a line may only join an entry inserted by the same transaction. created_txid is compared, not xmin:
        // the entry can be inserted inside a savepoint that is then released, and a row inserted in a subtransaction
        // carries the subtransaction xid in xmin, which differs from the top-level xid that the later lines see.
        // pg_current_xact_id() is the top-level xid even inside a savepoint, and created_txid took it as its DEFAULT.
        // It returns NEW, and leaves the answer to the proper constraint, when the context is missing (NULL
        // company_id: the WITH CHECK of RLS answers, 42501, and NOT NULL for journal_entry_id) or the entry is not
        // visible (another company through RLS, or nonexistent: the composite foreign key answers, 23503). Without
        // this, a forgotten context or a foreign entry would be reported as "sealed", which would hide the cause.
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_line_seal()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION journal_entry_line_seal() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_created_txid xid8;
            BEGIN
                IF NEW.company_id IS NULL OR NEW.journal_entry_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT created_txid INTO v_created_txid
                FROM journal_entry
                WHERE id = NEW.journal_entry_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF v_created_txid <> pg_current_xact_id() THEN
                    RAISE EXCEPTION 'journal entry % is sealed', NEW.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_entry_line_seal
                BEFORE INSERT ON journal_entry_line
                FOR EACH ROW EXECUTE FUNCTION journal_entry_line_seal()
            SQL);
    }

    public function down(): void
    {
        // migrate:fresh does not drop functions, so they go away explicitly, after their triggers and the shared
        // function last.
        DB::statement('DROP TRIGGER journal_entry_line_seal ON journal_entry_line');
        DB::statement('DROP TRIGGER journal_entry_line_balance ON journal_entry_line');
        DB::statement('DROP TRIGGER journal_entry_balance ON journal_entry');
        DB::statement('DROP FUNCTION journal_entry_line_seal()');
        DB::statement('DROP FUNCTION journal_entry_line_balance()');
        DB::statement('DROP FUNCTION journal_entry_balance()');
        DB::statement('DROP FUNCTION journal_entry_assert_balanced(bigint)');
    }
};
