<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AssignmentReason;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\EffectiveDated;
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
 * Penugasan Struktur Gaji (spec-04 §2.4) — lifecycle document, effective-dated
 * series per employee (A9). Opening columns carry same-employer YTD at go-live.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $salary_structure_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $base_salary
 * @property AssignmentReason $reason
 * @property string|null $epf_employee_rate_override
 * @property string|null $epf_employer_rate_override
 * @property int|null $opening_year
 * @property string $opening_gross_normal
 * @property string $opening_gross_additional
 * @property string $opening_epf_employee
 * @property string $opening_pcb
 * @property string $opening_zakat
 * @property string $opening_cp38
 * @property string $opening_socso_employee
 * @property string $opening_eis_employee
 * @property string $opening_skbbk_employee
 * @property string $opening_lp
 * @property array<string, string>|null $opening_ea_breakdown
 * @property string $opening_source
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read SalaryStructure $structure
 * @property-read Collection<int, SalaryAssignmentAmount> $amounts
 */
class SalaryAssignment extends Model
{
    use BelongsToOrganisation, EffectiveDated, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    public const OPENING_COLUMNS = [
        'opening_gross_normal', 'opening_gross_additional', 'opening_epf_employee', 'opening_pcb', 'opening_zakat', 'opening_cp38',
        'opening_socso_employee', 'opening_eis_employee', 'opening_skbbk_employee', 'opening_lp',
    ];

    protected $guarded = ['id', 'organisation_id', 'number', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'reason' => 'new_hire', 'opening_source' => 'manual'];

    protected function casts(): array
    {
        $casts = [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'base_salary' => 'decimal:2',
            'reason' => AssignmentReason::class,
            'epf_employee_rate_override' => 'decimal:4',
            'epf_employer_rate_override' => 'decimal:4',
            'opening_year' => 'integer',
            'opening_ea_breakdown' => 'array',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
        foreach (self::OPENING_COLUMNS as $column) {
            $casts[$column] = 'decimal:2';
        }

        return $casts;
    }

    public static function documentNumberPrefix(): string
    {
        return 'PSG';
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
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['effective_to'];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['base_salary', ...self::OPENING_COLUMNS];
    }

    public function seriesKey(): array
    {
        return ['employee_id' => $this->employee_id];
    }

    public function participatesInSeries(): bool
    {
        return $this->status === DocumentStatus::Submitted;
    }

    /**
     * @param  Builder<SalaryAssignment>  $query
     * @return Builder<SalaryAssignment>
     */
    public function scopeParticipating(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Submitted->value);
    }

    /** The submitted assignment of an employee effective on a date. */
    public static function effectiveFor(int $employeeId, CarbonImmutable $date): ?self
    {
        return self::query()->where('employee_id', $employeeId)
            ->where('status', DocumentStatus::Submitted->value)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<SalaryStructure, $this>
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    /**
     * @return HasMany<SalaryAssignmentAmount, $this>
     */
    public function amounts(): HasMany
    {
        return $this->hasMany(SalaryAssignmentAmount::class);
    }
}
