<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\CorrectionType;
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
 * Pembetulan Payroll against one submitted slip (spec-04 §2.7, §4.11).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $salary_slip_id
 * @property CorrectionType $type
 * @property string|null $lwp_days_reversed
 * @property CarbonImmutable $payout_period
 * @property string $reason
 * @property DocumentStatus $status
 * @property-read SalarySlip $slip
 * @property-read Collection<int, PayrollCorrectionLine> $lines
 */
class PayrollCorrection extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'type' => CorrectionType::class,
            'lwp_days_reversed' => 'decimal:3',
            'payout_period' => 'immutable_date',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PBT';
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
     * @return BelongsTo<SalarySlip, $this>
     */
    public function slip(): BelongsTo
    {
        return $this->belongsTo(SalarySlip::class, 'salary_slip_id');
    }

    /**
     * @return HasMany<PayrollCorrectionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollCorrectionLine::class);
    }
}
