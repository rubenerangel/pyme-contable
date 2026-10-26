<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal valid rows of the company master tables (third_party, cost_center, construction_project), shared by their
 * tests and by AppPermissionsTest. Every value is made up on purpose ('Test', identification numbers starting with
 * 9000000) and resembles no real person or company. company_id and id are never part of a row: company_id comes from
 * the context of the connection and id from the sequence.
 */
final class MasterDataFixtures
{
    /**
     * The name columns of a natural person: first name and first surname are required, the rest is optional.
     *
     * @return array{person_type: string, first_name: string, middle_names: null, first_surname: string, second_surname: null, business_name: null}
     */
    public static function naturalNames(): array
    {
        return [
            'person_type' => 'natural',
            'first_name' => 'Test',
            'middle_names' => null,
            'first_surname' => 'Sample',
            'second_surname' => null,
            'business_name' => null,
        ];
    }

    /**
     * The name columns of a legal person: only business_name, the four personal name columns are NULL.
     *
     * @return array{person_type: string, first_name: null, middle_names: null, first_surname: null, second_surname: null, business_name: string}
     */
    public static function legalNames(): array
    {
        return [
            'person_type' => 'legal',
            'first_name' => null,
            'middle_names' => null,
            'first_surname' => null,
            'second_surname' => null,
            'business_name' => 'Test Company',
        ];
    }

    /**
     * A minimal valid third_party row (a natural person identified with id_type 13), with the given columns replaced.
     * is_active is not part of it: it takes its DEFAULT. A column can be omitted by unsetting it from the result.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function thirdPartyRow(array $overrides = []): array
    {
        return array_merge([
            'id_type' => '13',
            'id_number' => '900000001',
            'country' => 'CO',
            ...self::naturalNames(),
        ], $overrides);
    }

    /**
     * A minimal valid cost_center row, with the given columns replaced. is_active takes its DEFAULT.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function costCenterRow(array $overrides = []): array
    {
        return array_merge(['code' => 'CC1', 'name' => 'Test cost center'], $overrides);
    }

    /**
     * A minimal valid construction_project row, with the given columns replaced. is_active takes its DEFAULT.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function constructionProjectRow(array $overrides = []): array
    {
        return array_merge(['code' => 'P1', 'name' => 'Test construction project'], $overrides);
    }
}
