<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ThirdParties\PersonType;
use App\Infrastructure\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// company_id comes from the DEFAULT of the database and is not assignable: fillable drops it and the database denies
// the column privilege. After create() the company_id attribute is not in memory until refresh().
#[Table('third_party', timestamps: false)]
#[ScopedBy(CompanyScope::class)]
#[Fillable([
    'id_type',
    'id_number',
    'country',
    'person_type',
    'first_name',
    'middle_names',
    'first_surname',
    'second_surname',
    'business_name',
    'is_active',
])]
class ThirdParty extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'is_active' => 'boolean',
        ];
    }
}
