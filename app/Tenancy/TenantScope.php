<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(TenantContext::class)->accessibleTenantIds();

        if ($ids === null) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if ($ids === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($column, $ids);
    }
}
