<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryCommitTest.php::it rejects at COMMIT a line that slipped past the insert-time seal even when the committed entry has lines with greater ids
// The line trigger rechecks the seal only on the line with the greatest id: a line that slipped past the insert-time seal with a smaller id than the committed lines joins the entry unchecked.

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
                IF NOT EXISTS (
                    SELECT 1 FROM journal_entry_line
                    WHERE journal_entry_id = NEW.journal_entry_id AND id > NEW.id
                ) THEN
                    SELECT created_txid INTO v_created_txid
                    FROM journal_entry
                    WHERE id = NEW.journal_entry_id;

                    IF FOUND AND v_created_txid <> pg_current_xact_id() THEN
                        RAISE EXCEPTION 'journal entry % is sealed', NEW.journal_entry_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    PERFORM journal_entry_assert_balanced(NEW.journal_entry_id);
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);
    }
};
