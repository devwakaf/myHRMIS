<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tunggakan Gaji (spec-04 §2.7, §4.10).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $salary_assignment_id
 * @property CarbonImmutable $from_period
 * @property CarbonImmutable $to_period
 * @property CarbonImmutable $payout_period
 * @property string $total_amount
 * @property CarbonImmutable|null $computed_at
 * @property string|null $notes
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read SalaryAssignment $assignment
 * @property-read Collection<int, ArrearLine> $lines
 */
class Arrear extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'total_amount' => '0'];

    protected function casts(): array
    {
        return [
            'from_period' => 'immutable_date',
            'to_period' => 'immutable_date',
            'payout_period' => 'immutable_date',
            'total_amount' => 'decimal:2',
            'computed_at' => 'immutable_datetime',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'TGK';
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<SalaryAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SalaryAssignment::class, 'salary_assignment_id');
    }

    /**
     * @return HasMany<ArrearLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ArrearLine::class)->orderBy('period')->orderBy('id');
    }
}
