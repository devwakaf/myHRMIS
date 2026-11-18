<?php

declare(strict_types=1);

namespace App\Domain\Shared\Audit;

use App\Domain\Shared\Organisation\OrganisationContext;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Activity log row with the organisation it belongs to (for per-org audit views).
 *
 * @property int|null $organisation_id
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->organisation_id !== null) {
                return;
            }

            $subject = $activity->subject;
            $fromSubject = $subject?->getAttribute('organisation_id');

            $activity->organisation_id = is_numeric($fromSubject)
                ? (int) $fromSubject
                : OrganisationContext::idOrNull();
        });
    }
}
