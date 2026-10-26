<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntrySealTest.php::it reports a line pointing at an entry of another company as a foreign key violation (SQLSTATE 23503), not as sealed
// kills: backend/tests/Integration/JournalEntrySealTest.php::it reports a line inserted without company context as a row level security violation (SQLSTATE 42501)
// The seal reports a missing context or an entry it cannot see as sealed, hiding the RLS and foreign key errors.

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
                v_created_txid xid8;
            BEGIN
                SELECT created_txid INTO v_created_txid
                FROM journal_entry
                WHERE id = NEW.journal_entry_id;

                IF v_created_txid IS DISTINCT FROM pg_current_xact_id() THEN
                    RAISE EXCEPTION 'journal entry % is sealed', NEW.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
    }
};
