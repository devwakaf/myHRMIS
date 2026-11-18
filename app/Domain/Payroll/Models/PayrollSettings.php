<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tetapan Payroll — one row per Organisasi (spec-04 §2.2), created lazily.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $days_source
 * @property string $unmarked_day_treatment
 * @property string $partial_month_basis
 * @property string $lwp_presentation
 * @property string $y2_basis
 * @property string $publish_slips_on
 * @property bool $email_slips
 * @property string $pdf_password_mode
 * @property bool $show_employer_contributions
 * @property bool $show_ytd
 * @property bool $show_leave_balance
 * @property string $ic_display
 * @property string $variance_threshold_percent
 * @property bool $block_unverified_rates
 * @property bool $include_withheld_in_statutory
 * @property bool $allow_submitter_mark_paid
 * @property bool $min_wage_check
 * @property int|null $default_bank_account_id
 * @property int|null $kwsp_file_format_id
 * @property string $net_pay_rounding
 */
class PayrollSettings extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    /** Allowed values of the enum-like settings (labels: payroll.settings_options.{field}.{value}). */
    public const OPTIONS = [
        'days_source' => ['leave_only', 'leave_and_attendance'],
        'unmarked_day_treatment' => ['present', 'absent'],
        'partial_month_basis' => ['follow_daily_rate', 'calendar_days'],
        'lwp_presentation' => ['reduce_earning', 'separate_deduction'],
        'y2_basis' => ['current_month', 'full_cycle'],
        'publish_slips_on' => ['submit', 'paid'],
        'pdf_password_mode' => ['none', 'ic_no', 'dob'],
        'ic_display' => ['full', 'masked'],
        'net_pay_rounding' => ['none', 'nearest_ringgit'],
    ];

    protected $table = 'payroll_settings';

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'email_slips' => 'boolean',
            'show_employer_contributions' => 'boolean',
            'show_ytd' => 'boolean',
            'show_leave_balance' => 'boolean',
            'variance_threshold_percent' => 'decimal:2',
            'block_unverified_rates' => 'boolean',
            'include_withheld_in_statutory' => 'boolean',
            'allow_submitter_mark_paid' => 'boolean',
            'min_wage_check' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    public static function current(?int $organisationId = null): self
    {
        $organisationId ??= OrganisationContext::id();

        return OrganisationContext::run($organisationId, function () use ($organisationId): self {
            $row = self::query()->where('organisation_id', $organisationId)->first();
            if ($row !== null) {
                return $row;
            }

            $row = new self;
            $row->forceFill(['organisation_id' => $organisationId])->save();

            return $row->refresh();
        });
    }
}
