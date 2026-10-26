<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it rejects at the deferred check an unbalanced line when the company context is gone (fails closed)
// The line trigger validates when NEW.id = max(id): without context RLS hides the lines, max is NULL, the check is skipped and an unbalanced entry commits.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_line_balance() RETURNS trigger
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

                IF NEW.id = (
                    SELECT max(id) FROM journal_entry_line WHERE journal_entry_id = NEW.journal_entry_id
                ) THEN
                    PERFORM journal_entry_assert_balanced(NEW.journal_entry_id);
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);
    }
};
