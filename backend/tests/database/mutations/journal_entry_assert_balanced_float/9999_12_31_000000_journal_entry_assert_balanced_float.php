<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it accepts amounts that do not add up in binary floating point (0.10 + 0.20 = 0.30)
// The balance rule sums in float8: 0.10 + 0.20 is not 0.30 in binary floating point and a balanced entry is rejected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_assert_balanced(p_entry_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_lines bigint;
                v_debit float8;
                v_credit float8;
            BEGIN
                SELECT count(*), coalesce(sum(debit::float8), 0), coalesce(sum(credit::float8), 0)
                INTO v_lines, v_debit, v_credit
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
    }
};
