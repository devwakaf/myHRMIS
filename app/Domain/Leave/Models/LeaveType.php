<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\AccrualFrequency;
use App\Domain\Leave\Enums\AccrualTiming;
use App\Domain\Leave\Enums\AttachmentRule;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\NoticeEnforcement;
use App\Domain\Leave\Enums\PayMode;
use App\Domain\Leave\Enums\ProrationMethod;
use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Leave\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis Cuti (spec-02 §2.1).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property string $colour
 * @property EntitlementBasis $entitlement_basis
 * @property PayMode $pay_mode
 * @property string $paid_fraction
 * @property bool $counts_holidays
 * @property bool $allow_half_day
 * @property bool $requires_reason
 * @property AttachmentRule $requires_attachment
 * @property string|null $attachment_over_days
 * @property int $min_service_days_to_apply
 * @property int $min_notice_days
 * @property NoticeEnforcement $notice_enforcement
 * @property int|null $max_backdate_days
 * @property string|null $max_days_per_application
 * @property string|null $max_consecutive_days
 * @property string|null $max_days_per_period
 * @property bool $allow_negative
 * @property string $max_negative_days
 * @property bool $allow_carry_forward
 * @property string|null $max_carry_forward_days
 * @property int|null $carry_forward_expiry_days
 * @property bool $carry_includes_carried
 * @property bool $allow_encashment
 * @property string|null $max_encashable_days
 * @property string $encashment_reserve_days
 * @property int|null $encashment_salary_component_id
 * @property AccrualFrequency|null $accrual_frequency
 * @property AccrualTiming|null $accrual_timing
 * @property string|null $rounding_step
 * @property ProrationMethod $proration_method
 * @property bool $prorate_on_separation
 * @property string|null $per_event_max_days
 * @property int|null $max_events_per_service
 * @property int|null $max_events_per_period
 * @property bool $requires_event_date
 * @property string|null $eligible_gender
 * @property list<string>|null $eligible_marital_statuses
 * @property int|null $compensatory_expiry_days
 * @property bool $is_system
 * @property int $sort_order
 * @property bool $is_active
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = [
        'paid_fraction' => '1',
        'requires_attachment' => 'never',
        'notice_enforcement' => 'warn',
        'proration_method' => 'none',
        'max_negative_days' => '0',
        'encashment_reserve_days' => '0',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_basis' => EntitlementBasis::class,
            'pay_mode' => PayMode::class,
            'requires_attachment' => AttachmentRule::class,
            'notice_enforcement' => NoticeEnforcement::class,
            'accrual_frequency' => AccrualFrequency::class,
            'accrual_timing' => AccrualTiming::class,
            'proration_method' => ProrationMethod::class,
            'paid_fraction' => 'decimal:4',
            'attachment_over_days' => 'decimal:3',
            'max_days_per_application' => 'decimal:3',
            'max_consecutive_days' => 'decimal:3',
            'max_days_per_period' => 'decimal:3',
            'max_negative_days' => 'decimal:3',
            'max_carry_forward_days' => 'decimal:3',
            'max_encashable_days' => 'decimal:3',
            'encashment_reserve_days' => 'decimal:3',
            'rounding_step' => 'decimal:2',
            'per_event_max_days' => 'decimal:3',
            'eligible_marital_statuses' => 'array',
            'counts_holidays' => 'boolean',
            'allow_half_day' => 'boolean',
            'requires_reason' => 'boolean',
            'allow_negative' => 'boolean',
            'allow_carry_forward' => 'boolean',
            'carry_includes_carried' => 'boolean',
            'allow_encashment' => 'boolean',
            'prorate_on_separation' => 'boolean',
            'requires_event_date' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    /** Whether the type keeps a balance (lots, allocations, sufficiency checks). */
    public function hasBalance(): bool
    {
        return in_array($this->entitlement_basis, [EntitlementBasis::PerPeriod, EntitlementBasis::Earned, EntitlementBasis::Compensatory], true)
            && $this->pay_mode !== PayMode::Unpaid;
    }

    /** Unpaid share of a deducted day: 1 − paid_fraction (single definition). */
    public function unpaidFraction(): string
    {
        return bcsub('1', (string) $this->paid_fraction, 4);
    }
}
