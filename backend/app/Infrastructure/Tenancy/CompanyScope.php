<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Second read barrier next to RLS (see docs/decisiones.md, Multi-tenant). It compares company_id with the same
 * expression as the RLS policies, on purpose: it does not read DatabaseCompanyContext, the Laravel session or config,
 * so it costs no extra query and fails closed the same way (without context the expression is NULL and no row
 * matches). It reads the same setting as RLS, so it guards against RLS being misconfigured or off, not against a wrong
 * context. The column is qualified with the table of the model so the scope works in joins; a query that renames the
 * table with an alias in FROM breaks loudly (the qualified column does not exist there). Eloquent paths that build
 * their query without global scopes (fresh, refresh, save, delete, increment, restoring models in jobs) rely on RLS
 * alone: see docs/decisiones.md, Multi-tenant.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn('company_id'),
            '=',
            $builder->getQuery()->raw("nullif(current_setting('app.company_id', true), '')::bigint"),
        );
    }
}
