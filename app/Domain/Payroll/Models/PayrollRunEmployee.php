<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee of a run with its generation outcome (spec-04 §2.5).
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property bool $included
 * @property string|null $exclusion_reason
 * @property GenerationStatus $generation_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property list<string>|null $warnings
 * @property int $attempts
 * @property string|null $lwp_override_days
 * @property string|null $lwp_override_reason
 * @property string|null $pcb_override_amount
 * @property string|null $pcb_override_reason
 * @property int|null $salary_slip_id
 * @property string|null $active_period_key
 * @property-read Employee $employee
 * @property-read PayrollRun $run
 */
class PayrollRunEmployee extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['included' => true, 'generation_status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'included' => 'boolean',
            'generation_status' => GenerationStatus::class,
            'warnings' => 'array',
            'attempts' => 'integer',
            'lwp_override_days' => 'decimal:3',
            'pcb_override_amount' => 'decimal:2',
        ];
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
}
