<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Accounting period of a company: month 1 to 12, opening period 0 and adjustment period 13 (see docs/decisiones.md).
return new class extends Migration
{
    public function up(): void
    {
        // company_id defaults to the active company, so the application never assigns it (tenant table pattern, see
        // docs/decisiones.md, Multi-tenant). type, start_date and end_date are derived from (year, number) and nobody writes them.
        // Postgres computes the generated columns before it checks the CHECK constraints, so their expressions must
        // not fail for a number outside 0 to 13: every CASE falls to NULL there and the insert fails with the CHECK
        // (23514), not with a date error (22008 from make_date). make_date and the integer arithmetic on dates are
        // IMMUTABLE, which generated columns require. The end of month n is the day before the first of month n + 1;
        // 12 and 13 end on December 31 and 0 is the single day of January 1. UNIQUE (company_id, id) is the target of the
        // composite foreign key from journal_entry, so an entry cannot point at a period of another company.
        DB::statement(<<<'SQL'
            CREATE TABLE period (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                year smallint NOT NULL,
                number smallint NOT NULL CONSTRAINT period_number_check CHECK (number BETWEEN 0 AND 13),
                status text NOT NULL DEFAULT 'open' CONSTRAINT period_status_check CHECK (status IN ('open', 'closed')),
                type text GENERATED ALWAYS AS (
                    CASE
                        WHEN number = 0 THEN 'opening'
                        WHEN number BETWEEN 1 AND 12 THEN 'regular'
                        WHEN number = 13 THEN 'adjustment'
                    END
                ) STORED,
                start_date date GENERATED ALWAYS AS (
                    CASE
                        WHEN number = 0 THEN make_date(year::int, 1, 1)
                        WHEN number BETWEEN 1 AND 12 THEN make_date(year::int, number::int, 1)
                        WHEN number = 13 THEN make_date(year::int, 12, 31)
                    END
                ) STORED,
                end_date date GENERATED ALWAYS AS (
                    CASE
                        WHEN number = 0 THEN make_date(year::int, 1, 1)
                        WHEN number BETWEEN 1 AND 11 THEN make_date(year::int, number::int + 1, 1) - 1
                        WHEN number IN (12, 13) THEN make_date(year::int, 12, 31)
                    END
                ) STORED,
                CONSTRAINT period_company_id_year_number_key UNIQUE (company_id, year, number),
                CONSTRAINT period_company_id_id_key UNIQUE (company_id, id)
            )
            SQL);

        // FORCE applies the policy to the owner too; the policy is only TO pyme_app (see docs/decisiones.md, Multi-tenant).
        DB::statement('ALTER TABLE period ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE period FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON period
                FOR ALL TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // INSERT goes per column, without id or company_id, so the application cannot assign them; the generated
        // columns stay out too. The application only closes and reopens: UPDATE on status alone. year and number never
        // change and a period is never deleted. UPDATE on a column also lets it run SELECT ... FOR SHARE and FOR UPDATE
        // on the row.
        DB::statement('GRANT SELECT ON period TO pyme_app');
        DB::statement('GRANT INSERT (year, number, status) ON period TO pyme_app');
        DB::statement('GRANT UPDATE (status) ON period TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE period_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE period');
    }
};
