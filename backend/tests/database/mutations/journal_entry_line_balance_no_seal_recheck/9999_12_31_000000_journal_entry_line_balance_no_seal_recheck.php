<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryCommitTest.php::it rejects at COMMIT a balanced pair added to an entry committed earlier when the insert-time seal did not see it
// The line trigger does not recheck the seal: a line that slipped past the insert-time seal joins an entry committed earlier.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_line_balance() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
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
    }
};
