<?php

declare(strict_types=1);

namespace App\Domain\Shared\Organisation;

use App\Domain\Core\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every org-scoped model. Adds the fail-closed OrganisationScope and fills
 * organisation_id from the active context on create.
 *
 * @mixin Model
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new OrganisationScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organisation_id') === null) {
                $model->setAttribute('organisation_id', OrganisationContext::id());
            }
        });
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Query without the organisation scope (deliberate cross-org access only).
     *
     * @return Builder<static>
     */
    public static function acrossOrganisations(): Builder
    {
        return static::query()->withoutGlobalScope(OrganisationScope::class);
    }
}
