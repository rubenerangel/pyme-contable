<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryCommitTest.php::it rejects a line added to an entry committed in an earlier transaction (SQLSTATE 23514, sealed)
// kills: backend/tests/Integration/JournalEntryCommitTest.php::it rejects a balanced pair of lines added to an entry committed earlier
// The seal does not validate: lines can be added to an entry committed by an earlier transaction.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_line_seal() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                RETURN NEW;
            END;
            $$
            SQL);
    }
};
