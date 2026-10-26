<?php

declare(strict_types=1);

namespace App\Models;

use App\Infrastructure\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// company_id comes from the DEFAULT of the database and is not assignable: fillable drops it and the database denies
// the column privilege. After create() the company_id attribute is not in memory until refresh().
#[Table('construction_project', timestamps: false)]
#[ScopedBy(CompanyScope::class)]
#[Fillable([
    'code',
    'name',
    'is_active',
])]
class ConstructionProject extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
