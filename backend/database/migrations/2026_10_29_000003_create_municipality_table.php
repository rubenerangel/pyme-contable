<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Municipalities of Colombia (DIVIPOLA), a global catalog (see docs/decisiones.md, Multi-tenant). Source: dataset
// "DIVIPOLA - Códigos municipios" of DANE on datos.gov.co (id gdxc-w37w,
// https://www.datos.gov.co/api/views/gdxc-w37w/rows.csv?accessType=DOWNLOAD), cut of 2024-12-30, downloaded on
// 2026-10-25, sha256 159b4b84595a11be5bf623fdf25c6fd5c9ac8c3ed898cf7928b2b3f321b9b492, license CC BY-SA 4.0
// (attribution: DANE). The file is versioned unmodified in database/data/divipola.csv. The type of municipality and the
// coordinates are not loaded (no consumer). The migration checks the hash of the file before seeding, so every database
// seeds exactly the same file. An update of DIVIPOLA is a new file with its own hash and its own migration (with an
// upsert by code); replacing this file breaks migrate:fresh on purpose.
return new class extends Migration
{
    private const SHA256 = '159b4b84595a11be5bf623fdf25c6fd5c9ac8c3ed898cf7928b2b3f321b9b492';

    private const BATCH_SIZE = 500;

    public function up(): void
    {
        // No company_id, no RLS and no audit triggers: only the owner writes it (in migrations, the trace is git).
        // department_code is derived from code, so the two cannot disagree.
        DB::statement(<<<'SQL'
            CREATE TABLE municipality (
                id bigserial PRIMARY KEY,
                code text NOT NULL CONSTRAINT municipality_code_check CHECK (code ~ '^[0-9]{5}$'),
                name text NOT NULL CONSTRAINT municipality_name_check CHECK (btrim(name) <> ''),
                department_code text GENERATED ALWAYS AS (left(code, 2)) STORED,
                department_name text NOT NULL
                    CONSTRAINT municipality_department_name_check CHECK (btrim(department_name) <> ''),
                CONSTRAINT municipality_code_key UNIQUE (code)
            )
            SQL);

        // SELECT only, and no USAGE on the sequence: a writable global catalog would let one company change data that
        // every company sees.
        DB::statement('GRANT SELECT ON municipality TO pyme_app');

        $this->seed();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE municipality');
    }

    // Seeded here and not by a seeder or COPY: every database needs the reference data (pyme_test included), and COPY
    // FROM a file requires a server privilege (pg_read_server_files). The hash is checked before opening the file so
    // that a different file is never seeded silently.
    private function seed(): void
    {
        $path = base_path('database/data/divipola.csv');
        if (hash_file('sha256', $path) !== self::SHA256) {
            throw new RuntimeException("Unexpected sha256 for {$path}.");
        }

        $file = new SplFileObject($path, 'rb');
        $file->fgetcsv(',', '"', ''); // header

        $batch = [];
        while (! $file->eof()) {
            $fields = $file->fgetcsv(',', '"', '');
            if ($fields === [null] || $fields === false) {
                continue; // blank line
            }

            array_push($batch, $fields[2], $fields[3], $fields[1]);
            if (count($batch) === self::BATCH_SIZE * 3) {
                $this->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insert($batch);
        }
    }

    /**
     * @param  list<string>  $bindings  code, name and department_name of each row, in that order
     */
    private function insert(array $bindings): void
    {
        $placeholders = implode(', ', array_fill(0, intdiv(count($bindings), 3), '(?, ?, ?)'));

        DB::insert("INSERT INTO municipality (code, name, department_name) VALUES {$placeholders}", $bindings);
    }
};
