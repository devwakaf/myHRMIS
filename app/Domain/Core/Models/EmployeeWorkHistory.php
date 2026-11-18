<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sejarah Perkhidmatan (B1.12). Exactly one open row (to_date null) per active employee.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable|null $to_date
 * @property int|null $branch_id
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $grade_id
 * @property int|null $employment_type_id
 * @property int|null $reports_to_id
 * @property WorkHistorySource $source_type
 * @property int|null $source_id
 */
class EmployeeWorkHistory extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $table = 'employee_work_history';

    protected $fillable = [
        'organisation_id', 'employee_id', 'from_date', 'to_date', 'branch_id', 'department_id', 'designation_id',
        'grade_id', 'employment_type_id', 'reports_to_id', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'source_type' => WorkHistorySource::class,
        ];
    }

    /**
     * @param  Builder<EmployeeWorkHistory>  $query
     * @return Builder<EmployeeWorkHistory>
     */
    public function scopeOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $d = CarbonImmutable::parse($date)->toDateString();

        return $query->whereDate('from_date', '<=', $d)
            ->where(fn (Builder $q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', $d));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * @return BelongsTo<EmploymentType, $this>
     */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }
}
