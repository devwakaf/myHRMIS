<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proses Gaji / Payroll Run (spec-04 §2.5): one Organisasi × one month.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property CarbonImmutable $period
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $payment_date
 * @property PayrollRunStatus $status
 * @property ProcessingState $processing_state
 * @property string|null $batch_id
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $processing_finished_at
 * @property string|null $processing_error
 * @property array<string, mixed>|null $filters
 * @property string|null $notes
 * @property int|null $prepared_by
 * @property int|null $generated_by
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $paid_at
 * @property int|null $paid_by
 * @property string|null $payment_reference
 * @property int $employee_count
 * @property string $gross_total
 * @property string $deduction_total
 * @property string $net_total
 * @property string $employer_total
 * @property int $withheld_count
 * @property list<int>|null $rate_set_ids
 * @property bool $unverified_acknowledged
 * @property int $lock_version
 * @property CarbonImmutable|null $ytd_refreshed_at
 * @property-read Collection<int, PayrollRunEmployee> $runEmployees
 * @property-read Collection<int, SalarySlip> $slips
 */
class PayrollRun extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'processing_state' => 'idle'];

    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'payment_date' => 'immutable_date',
            'status' => PayrollRunStatus::class,
            'processing_state' => ProcessingState::class,
            'processing_started_at' => 'immutable_datetime',
            'processing_finished_at' => 'immutable_datetime',
            'filters' => 'array',
            'paid_at' => 'immutable_datetime',
            'gross_total' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'employer_total' => 'decimal:2',
            'rate_set_ids' => 'array',
            'unverified_acknowledged' => 'boolean',
            'employee_count' => 'integer',
            'withheld_count' => 'integer',
            'lock_version' => 'integer',
            'ytd_refreshed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PRL';
    }

    public static function activityLogName(): string
    {
        return 'payroll';
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    /**
     * Runs carry queue/totals/payment columns that change after submit.
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return [
            'processing_state', 'batch_id', 'processing_started_at', 'processing_finished_at', 'processing_error', 'generated_by',
            'paid_at', 'paid_by', 'payment_reference', 'employee_count', 'gross_total', 'deduction_total', 'net_total', 'employer_total',
            'withheld_count', 'rate_set_ids', 'unverified_acknowledged', 'lock_version', 'ytd_refreshed_at', 'notes', 'payment_date', 'filters',
        ];
    }

    /** "{employee_id}-{YYYYMM}": unique while a run/slip for that month is live. */
    public function periodKey(int $employeeId): string
    {
        return $employeeId.'-'.$this->period->format('Ym');
    }

    /**
     * @return HasMany<PayrollRunEmployee, $this>
     */
    public function runEmployees(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    /**
     * @return HasMany<SalarySlip, $this>
     */
    public function slips(): HasMany
    {
        return $this->hasMany(SalarySlip::class);
    }
}
