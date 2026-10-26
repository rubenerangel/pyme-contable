<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntrySealTest.php::it accepts lines inserted after the savepoint that inserted the entry was released
// kills: backend/tests/Integration/JournalEntrySealTest.php::it accepts an entry and its lines inserted in different savepoints
// The seal compares the xmin of the entry instead of created_txid: an entry inserted inside a released savepoint carries the subtransaction xid and its lines are rejected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_line_seal() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_xmin xid;
            BEGIN
                IF NEW.company_id IS NULL OR NEW.journal_entry_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT xmin INTO v_xmin
                FROM journal_entry
                WHERE id = NEW.journal_entry_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF v_xmin <> pg_current_xact_id()::xid THEN
                    RAISE EXCEPTION 'journal entry % is sealed', NEW.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
    }
};
