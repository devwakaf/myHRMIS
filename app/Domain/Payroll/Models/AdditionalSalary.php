<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
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

/**
 * Elaun/Potongan Tambahan (spec-04 §2.6). System postings (OT claims, leave
 * encashment, arrears, corrections) are created already submitted and are
 * idempotent through `source_ref` (unique among non-cancelled rows).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $salary_component_id
 * @property AdditionalKind $kind
 * @property CarbonImmutable|null $period
 * @property CarbonImmutable|null $from_period
 * @property CarbonImmutable|null $to_period
 * @property string $amount
 * @property AdditionalMode $mode
 * @property bool $prorate
 * @property string|null $quantity
 * @property AdditionalSource $source_type
 * @property int|null $source_id
 * @property string|null $source_ref
 * @property int|null $payroll_run_id
 * @property string|null $note
 * @property string $reason
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read SalaryComponent $component
 * @property-read Collection<int, AdditionalSalaryApplication> $applications
 */
class AdditionalSalary extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    public const MAX_RECURRING_MONTHS = 24;

    protected $guarded = ['id', 'organisation_id', 'number', 'active_source_ref', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'kind' => 'one_off', 'mode' => 'add', 'prorate' => false, 'source_type' => 'manual'];

    protected function casts(): array
    {
        return [
            'kind' => AdditionalKind::class,
            'mode' => AdditionalMode::class,
            'source_type' => AdditionalSource::class,
            'period' => 'immutable_date',
            'from_period' => 'immutable_date',
            'to_period' => 'immutable_date',
            'amount' => 'decimal:2',
            'quantity' => 'decimal:3',
            'prorate' => 'boolean',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'TMB';
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
     * System postings may be refreshed while their run is still a draft (OT regeneration).
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['payroll_run_id', 'note'];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['amount'];
    }

    public function appliesTo(CarbonImmutable $period): bool
    {
        $p = $period->startOfMonth();
        if ($this->kind === AdditionalKind::OneOff) {
            return $this->period !== null && $this->period->equalTo($p);
        }

        return $this->from_period !== null && $this->from_period->lessThanOrEqualTo($p)
            && ($this->to_period === null || $this->to_period->greaterThanOrEqualTo($p));
    }

    /**
     * Submitted rows of an employee that apply to a month.
     *
     * @param  Builder<AdditionalSalary>  $query
     * @return Builder<AdditionalSalary>
     */
    public function scopeForPeriod(Builder $query, int $employeeId, CarbonImmutable $period): Builder
    {
        $p = $period->startOfMonth()->toDateString();

        return $query->where('employee_id', $employeeId)
            ->where('status', DocumentStatus::Submitted->value)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $one) => $one->where('kind', AdditionalKind::OneOff->value)->whereDate('period', $p))
                ->orWhere(fn (Builder $rec) => $rec->where('kind', AdditionalKind::Recurring->value)
                    ->whereDate('from_period', '<=', $p)
                    ->where(fn (Builder $to) => $to->whereNull('to_period')->orWhereDate('to_period', '>=', $p))));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<SalaryComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /**
     * @return HasMany<AdditionalSalaryApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(AdditionalSalaryApplication::class);
    }
}
