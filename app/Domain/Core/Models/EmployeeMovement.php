<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\MovementType;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Database\Factories\Core\EmployeeMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pergerakan Kakitangan (B1.14).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property MovementType $type
 * @property CarbonImmutable $effective_date
 * @property int|null $new_branch_id
 * @property int|null $new_department_id
 * @property int|null $new_designation_id
 * @property int|null $new_grade_id
 * @property int|null $new_employment_type_id
 * @property int|null $new_reports_to_id
 * @property CarbonImmutable|null $new_contract_end_date
 * @property int|null $target_organisation_id
 * @property int|null $target_employee_id
 * @property array<string, mixed>|null $before_values
 * @property array<string, mixed>|null $after_values
 * @property CarbonImmutable|null $applied_at
 * @property string|null $reason
 * @property string|null $salary_note
 * @property DocumentStatus $status
 * @property int|null $amended_from_id
 * @property-read Employee $employee
 */
class EmployeeMovement extends Model
{
    /** @use HasFactory<EmployeeMovementFactory> */
    use BelongsToOrganisation, HasDocumentNumber, HasFactory, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    /** "new_*" column => employee column. */
    public const FIELD_MAP = [
        'new_branch_id' => 'branch_id',
        'new_department_id' => 'department_id',
        'new_designation_id' => 'designation_id',
        'new_grade_id' => 'grade_id',
        'new_employment_type_id' => 'employment_type_id',
        'new_reports_to_id' => 'reports_to_id',
        'new_contract_end_date' => 'contract_end_date',
    ];

    protected $fillable = [
        'organisation_id', 'employee_id', 'type', 'effective_date', 'new_branch_id', 'new_department_id',
        'new_designation_id', 'new_grade_id', 'new_employment_type_id', 'new_reports_to_id', 'new_contract_end_date',
        'target_organisation_id', 'reason', 'salary_note', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'effective_date' => 'immutable_date',
            'new_contract_end_date' => 'immutable_date',
            'before_values' => 'array',
            'after_values' => 'array',
            'applied_at' => 'immutable_datetime',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PGK';
    }

    /**
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['before_values', 'after_values', 'applied_at', 'target_employee_id'];
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function targetOrganisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'target_organisation_id');
    }
}
