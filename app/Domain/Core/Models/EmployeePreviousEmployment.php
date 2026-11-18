<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengalaman kerja luar (B1.12).
 *
 * @property int $id
 * @property int $employee_id
 * @property string $employer_name
 * @property string|null $position
 * @property CarbonImmutable|null $from_date
 * @property CarbonImmutable|null $to_date
 * @property Money|null $last_salary
 * @property string|null $reason_left
 */
class EmployeePreviousEmployment extends Model
{
    use LogsBusinessActivity;

    protected $fillable = ['employee_id', 'employer_name', 'position', 'from_date', 'to_date', 'last_salary', 'reason_left'];

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'last_salary' => MoneyCast::class];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['last_salary'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
