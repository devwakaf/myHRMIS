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
 * Tahan Gaji (spec-04 §2.7): slips of the covered months are generated as
 * payment status Ditahan and left out of bank files until a cycle is released.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property CarbonImmutable $from_period
 * @property int|null $cycles
 * @property string $reason
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read Collection<int, SalaryWithholdingCycle> $cycleRows
 */
class SalaryWithholding extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'from_period' => 'immutable_date',
            'cycles' => 'integer',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'THN';
    }

    public static function activityLogName(): string
    {
        return 'payroll';
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    public function covers(CarbonImmutable $period): bool
    {
        $p = $period->startOfMonth();
        if ($p->lessThan($this->from_period)) {
            return false;
        }

        return $this->cycles === null || $p->lessThan($this->from_period->addMonths($this->cycles));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<SalaryWithholdingCycle, $this>
     */
    public function cycleRows(): HasMany
    {
        return $this->hasMany(SalaryWithholdingCycle::class)->orderBy('period');
    }
}
