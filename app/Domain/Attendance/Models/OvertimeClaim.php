<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\ClaimEligibility;
use App\Domain\Attendance\Enums\WageSource;
use App\Domain\Attendance\Services\OvertimeClaimApproval;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\Concerns\HasApprovalRequest;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tuntutan OT — one per employee per month (spec-03 §3.12), Approvable key
 * `overtime_claim`. Amounts and line hashes freeze at submit (A5.4).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property CarbonImmutable $period_month
 * @property ApprovalStatus $status
 * @property int|null $prepared_by
 * @property int|null $posted_payroll_run_id
 * @property string|null $monthly_wage_snapshot
 * @property WageSource|null $wage_source
 * @property string $orp
 * @property string $hrp
 * @property string $contractual_daily_hours
 * @property ClaimEligibility $eligibility
 * @property int $total_ot_minutes
 * @property int $cap_counted_minutes
 * @property int $cap_minutes
 * @property bool $cap_exceeded
 * @property string|null $cap_override_reason
 * @property string $total_amount
 * @property CarbonImmutable|null $effects_applied_at
 * @property int|null $submitted_by
 * @property-read Employee $employee
 * @property-read Collection<int, OvertimeClaimLine> $lines
 */
class OvertimeClaim extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'number', 'status', 'active_key', 'created_by', 'updated_by', 'submitted_at', 'submitted_by', 'cancelled_at', 'cancelled_by'];

    protected $attributes = [
        'status' => 'draft', 'orp' => '0', 'hrp' => '0', 'contractual_daily_hours' => '8', 'eligibility' => 'not_eligible',
        'total_ot_minutes' => 0, 'cap_counted_minutes' => 0, 'cap_minutes' => 0, 'cap_exceeded' => false, 'total_amount' => '0',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'status' => ApprovalStatus::class,
            'employee_id' => 'integer',
            'prepared_by' => 'integer',
            'posted_payroll_run_id' => 'integer',
            'monthly_wage_snapshot' => 'decimal:2',
            'wage_source' => WageSource::class,
            'orp' => 'decimal:6',
            'hrp' => 'decimal:6',
            'contractual_daily_hours' => 'decimal:2',
            'eligibility' => ClaimEligibility::class,
            'total_ot_minutes' => 'integer',
            'cap_counted_minutes' => 'integer',
            'cap_minutes' => 'integer',
            'cap_exceeded' => 'boolean',
            'total_amount' => 'decimal:2',
            'effects_applied_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'OTC';
    }

    public static function activityLogName(): string
    {
        return 'attendance';
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['posted_payroll_run_id', 'effects_applied_at', 'prepared_by'];
    }

    public function totalHours(): string
    {
        return bcdiv((string) $this->total_ot_minutes, '60', 2);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<OvertimeClaimLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OvertimeClaimLine::class)->orderBy('work_date')->orderBy('id');
    }

    // ------------------------------------------------------------------ Approvable

    public function approvalType(): string
    {
        return 'overtime_claim';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return app(OvertimeClaimApproval::class)->attributes($this);
    }

    public function approvalSummary(): array
    {
        return app(OvertimeClaimApproval::class)->summary($this);
    }

    public function decisionContext(User $viewer): array
    {
        return app(OvertimeClaimApproval::class)->decisionContext($this, $viewer);
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        return app(OvertimeClaimApproval::class)->checkCompletion($this);
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        app(OvertimeClaimApproval::class)->completed($this);
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        app(OvertimeClaimApproval::class)->releaseLines($this);
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        app(OvertimeClaimApproval::class)->cancelled($this, $wasApproved);
    }
}
