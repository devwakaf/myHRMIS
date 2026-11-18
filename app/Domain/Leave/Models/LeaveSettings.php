<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\ApplicantCancelRule;
use App\Domain\Leave\Enums\CalendarVisibility;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tetapan Cuti (spec-02 §2.14) — one row per Organisasi, created on first use.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $default_leave_policy_id
 * @property int $default_max_backdate_days
 * @property CalendarVisibility $calendar_colleague_visibility
 * @property int $expiry_reminder_days
 * @property EncashmentRateBasis $encashment_rate_basis
 * @property ApplicantCancelRule $applicant_may_cancel_approved
 */
class LeaveSettings extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $table = 'leave_settings';

    protected $fillable = [
        'organisation_id', 'default_leave_policy_id', 'default_max_backdate_days', 'calendar_colleague_visibility',
        'expiry_reminder_days', 'encashment_rate_basis', 'applicant_may_cancel_approved',
    ];

    protected $attributes = [
        'default_max_backdate_days' => 7,
        'calendar_colleague_visibility' => 'off',
        'expiry_reminder_days' => 30,
        'encashment_rate_basis' => 'org_daily_rate',
        'applicant_may_cancel_approved' => 'before_start',
    ];

    protected function casts(): array
    {
        return [
            'calendar_colleague_visibility' => CalendarVisibility::class,
            'encashment_rate_basis' => EncashmentRateBasis::class,
            'applicant_may_cancel_approved' => ApplicantCancelRule::class,
            'default_max_backdate_days' => 'integer',
            'expiry_reminder_days' => 'integer',
        ];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    public static function current(?int $organisationId = null): self
    {
        $orgId = $organisationId ?? OrganisationContext::id();

        return static::acrossOrganisations()->firstOrCreate(['organisation_id' => $orgId]);
    }
}
