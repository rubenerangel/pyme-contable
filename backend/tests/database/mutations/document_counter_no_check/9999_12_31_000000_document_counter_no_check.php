<?php

declare(strict_types=1);

// kills: backend/tests/Integration/DocumentCounterTest.php::it rejects a direct insert of a counter with last_number other than 1 (trigger, SQLSTATE 23514)
// kills: backend/tests/Integration/DocumentCounterTest.php::it rejects an update that skips a number (+2, trigger, SQLSTATE 23514)
// kills: backend/tests/Integration/DocumentCounterTest.php::it rejects an update that goes backwards (trigger, SQLSTATE 23514)
// kills: backend/tests/Integration/DocumentCounterTest.php::it rejects an update that leaves the number unchanged (it must be exactly +1, trigger, SQLSTATE 23514)
// The counter trigger lets any value through: a direct insert or update can leave a gap or repeat a number.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_counter_check_increment() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                RETURN NEW;
            END;
            $$
            SQL);
    }
};
