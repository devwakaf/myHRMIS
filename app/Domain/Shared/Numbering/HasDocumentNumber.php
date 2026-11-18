<?php

declare(strict_types=1);

namespace App\Domain\Shared\Numbering;

use Illuminate\Database\Eloquent\Model;

/**
 * Assigns `number` at creation from the module-owned prefix.
 *
 * @mixin Model
 */
trait HasDocumentNumber
{
    abstract public static function documentNumberPrefix(): string;

    public static function bootHasDocumentNumber(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('number') !== null) {
                return;
            }

            $orgId = $model->getAttribute('organisation_id');
            $model->setAttribute('number', app(DocumentNumberGenerator::class)->next(
                static::documentNumberPrefix(),
                now(),
                $orgId === null ? null : (int) $orgId,
            ));
        });
    }
}
