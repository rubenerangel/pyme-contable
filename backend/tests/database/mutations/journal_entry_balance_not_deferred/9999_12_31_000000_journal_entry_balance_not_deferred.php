<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it accepts an entry with two balanced lines inserted one per statement
// kills: backend/tests/Integration/JournalEntryCommitTest.php::it commits a balanced entry with its lines
// The entry trigger is not deferrable: it runs at the end of the INSERT of the entry, before any line exists, and every entry is rejected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER journal_entry_balance ON journal_entry');
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_entry_balance
                AFTER INSERT ON journal_entry
                NOT DEFERRABLE
                FOR EACH ROW EXECUTE FUNCTION journal_entry_balance()
            SQL);
    }
};
