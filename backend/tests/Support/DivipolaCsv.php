<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Reads the official DIVIPOLA file of DANE (backend/database/data/divipola.csv) so the tests never write a DANE code or
 * name by hand: every expected value comes from the file. Columns used: 0 department code, 1 department name,
 * 2 municipality code, 3 municipality name. The file is UTF-8, comma separated, with a header line.
 */
final class DivipolaCsv
{
    /**
     * One entry per data row, sorted by municipality code in byte order (the same order as Postgres with COLLATE "C").
     * It is a list and not a map keyed by code on purpose: PHP turns a key such as '11001' into an int.
     *
     * @return list<array{code: string, name: string, department_code: string, department_name: string}>
     */
    public static function rows(): array
    {
        $path = base_path('database/data/divipola.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $rows = [];
        try {
            fgetcsv($handle, null, ',', '"', ''); // header
            while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($fields === [null]) {
                    continue; // blank line
                }
                $rows[] = [
                    'code' => (string) $fields[2],
                    'name' => (string) $fields[3],
                    'department_code' => (string) $fields[0],
                    'department_name' => (string) $fields[1],
                ];
            }
        } finally {
            fclose($handle);
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /**
     * Valid five-digit codes that are not in the file, to insert rows that are valid and cannot collide with the seed.
     *
     * @return list<string>
     */
    public static function unusedCodes(int $count): array
    {
        $taken = array_flip(array_column(self::rows(), 'code'));
        $codes = [];
        for ($n = 0; $n <= 99999 && count($codes) < $count; $n++) {
            $code = sprintf('%05d', $n);
            if (! isset($taken[$code])) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
