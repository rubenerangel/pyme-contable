<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\NormalBalance;
use App\Infrastructure\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// company_id comes from the DEFAULT of the database and is not assignable: fillable drops it and the database denies
// the column privilege. After create() the company_id attribute is not in memory until refresh().
#[Table('account', timestamps: false)]
#[ScopedBy(CompanyScope::class)]
#[Fillable([
    'code',
    'name',
    'parent_id',
    'is_postable',
    'normal_balance',
    'requires_third_party',
    'requires_cost_center',
    'requires_construction_project',
    'requires_municipality',
    'requires_tax_detail',
    'is_active',
])]
class Account extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'normal_balance' => NormalBalance::class,
            'requires_third_party' => 'boolean',
            'requires_cost_center' => 'boolean',
            'requires_construction_project' => 'boolean',
            'requires_municipality' => 'boolean',
            'requires_tax_detail' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
