<?php

declare(strict_types=1);

namespace App\Domain\Shared\Organisation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class OrganisationScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (OrganisationContext::isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('organisation_id'), OrganisationContext::id());
    }
}
