<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Slip Gaji (spec-04 §2.5). Frozen once submitted (A5.4 lock guard): only
 * payment / delivery columns may change afterwards.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property CarbonImmutable $period
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $actual_start
 * @property CarbonImmutable $actual_end
 * @property CarbonImmutable $payment_date
 * @property DocumentStatus $status
 * @property PaymentStatus $payment_status
 * @property CarbonImmutable|null $paid_at
 * @property int|null $salary_withholding_cycle_id
 * @property CarbonImmutable|null $released_at
 * @property string|null $active_period_key
 * @property int $salary_assignment_id
 * @property int $salary_structure_id
 * @property int $structure_revision
 * @property string $calc_version
 * @property string $employee_no
 * @property string $full_name
 * @property string|null $identity_type
 * @property string|null $ic_no
 * @property string|null $old_ic_no
 * @property string|null $passport_no
 * @property string|null $passport_country
 * @property string $citizenship_status
 * @property CarbonImmutable $date_of_birth
 * @property int $age_years
 * @property int|null $branch_id
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $grade_id
 * @property string|null $cost_centre_code
 * @property string $payment_mode
 * @property int|null $bank_id
 * @property string|null $bank_account_no
 * @property string|null $bank_account_name
 * @property string|null $kwsp_no
 * @property string|null $perkeso_no
 * @property string|null $tin
 * @property string|null $tax_residency
 * @property int $pcb_category
 * @property string|null $mtd_scheme
 * @property array<string, int>|null $children
 * @property string $calendar_days
 * @property string|null $working_days
 * @property string $rate_basis
 * @property string $divisor
 * @property string $outside_employment_days
 * @property string $lwp_days
 * @property string $absent_days
 * @property string $unmarked_days
 * @property string $unpaid_days_total
 * @property string $payment_fraction
 * @property string $payment_days
 * @property string|null $lwp_override_days
 * @property string|null $lwp_override_reason
 * @property string $gross_pay
 * @property string $total_deductions
 * @property string $net_pay
 * @property string $net_rounding_adjustment
 * @property string $employer_total
 * @property string|null $pcb_override_amount
 * @property string|null $pcb_override_reason
 * @property list<string>|null $warnings
 * @property bool $has_errors
 * @property array<string, mixed>|null $calc_trace
 * @property string|null $pdf_path
 * @property CarbonImmutable|null $emailed_at
 * @property-read Employee $employee
 * @property-read PayrollRun $run
 * @property-read Collection<int, SalarySlipLine> $lines
 * @property-read SalarySlipStatutory|null $statutory
 */
class SalarySlip extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, TracksAuthor;

    public const CALC_VERSION = 'm4-1';

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'payment_status' => 'unpaid'];

    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'actual_start' => 'immutable_date',
            'actual_end' => 'immutable_date',
            'payment_date' => 'immutable_date',
            'date_of_birth' => 'immutable_date',
            'status' => DocumentStatus::class,
            'payment_status' => PaymentStatus::class,
            'paid_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
            'children' => 'array',
            'calendar_days' => 'decimal:3',
            'working_days' => 'decimal:3',
            'divisor' => 'decimal:2',
            'outside_employment_days' => 'decimal:3',
            'lwp_days' => 'decimal:3',
            'absent_days' => 'decimal:3',
            'unmarked_days' => 'decimal:3',
            'unpaid_days_total' => 'decimal:3',
            'payment_fraction' => 'decimal:10',
            'payment_days' => 'decimal:3',
            'lwp_override_days' => 'decimal:3',
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'net_rounding_adjustment' => 'decimal:2',
            'employer_total' => 'decimal:2',
            'pcb_override_amount' => 'decimal:2',
            'warnings' => 'array',
            'has_errors' => 'boolean',
            'calc_trace' => 'array',
            'pcb_category' => 'integer',
            'age_years' => 'integer',
            'structure_revision' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'SLP';
    }

    public static function activityLogName(): string
    {
        return 'payroll';
    }

    /**
     * Payment, delivery and withholding columns change after submit.
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return [
            'payment_status', 'paid_at', 'salary_withholding_cycle_id', 'released_at', 'released_by', 'active_period_key',
            'pdf_path', 'pdf_template_version', 'emailed_at',
        ];
    }

    /**
     * @param  Builder<SalarySlip>  $query
     * @return Builder<SalarySlip>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Submitted->value);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<SalarySlipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalarySlipLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasOne<SalarySlipStatutory, $this>
     */
    public function statutory(): HasOne
    {
        return $this->hasOne(SalarySlipStatutory::class);
    }

    /**
     * @return HasMany<AdditionalSalaryApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(AdditionalSalaryApplication::class);
    }
}
