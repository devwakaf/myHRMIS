<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\WageSource;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeClaimLine;
use App\Domain\Attendance\Services\OvertimeClaimGenerator;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tuntutan OT actions (spec-03 §2.14, §4.11): generate / refresh drafts, manual
 * wage and manual lines on drafts, submit for approval (HR), cancel (HR, refused
 * once posted to a payroll run).
 */
final class OvertimeClaimActions
{
    public function __construct(
        private readonly OvertimeClaimGenerator $generator,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @return list<OvertimeClaim>
     */
    public function generate(CarbonImmutable $month, ?int $employeeId = null): array
    {
        return $this->generator->generateForMonth($month, $employeeId);
    }

    public function setManualWage(OvertimeClaim $claim, ?string $wage): OvertimeClaim
    {
        $this->requireDraft($claim);
        $claim->forceFill([
            'monthly_wage_snapshot' => $wage,
            'wage_source' => $wage === null ? null : WageSource::Manual,
        ])->save();

        return $this->generator->refresh($claim);
    }

    public function setCapReason(OvertimeClaim $claim, ?string $reason): OvertimeClaim
    {
        $this->requireDraft($claim);
        $claim->forceFill(['cap_override_reason' => $reason])->save();

        return $claim;
    }

    /**
     * @param  array{work_date: string, day_type: string, worked_minutes: int, beyond_normal_minutes?: int, amount: string, note: string, overtime_type_id?: int|null}  $data
     */
    public function addManualLine(OvertimeClaim $claim, array $data): OvertimeClaimLine
    {
        $this->requireDraft($claim);
        if (trim($data['note']) === '') {
            throw DomainValidation::withMessages(['note' => __('attendance.errors.note_required')]);
        }

        $line = $claim->lines()->create([
            'work_date' => $data['work_date'],
            'day_type' => DayType::from($data['day_type']),
            'overtime_type_id' => $data['overtime_type_id'] ?? null,
            'worked_minutes' => $data['worked_minutes'],
            'beyond_normal_minutes' => $data['beyond_normal_minutes'] ?? $data['worked_minutes'],
            'amount' => (string) Rounding::halfUp($data['amount'], 2),
            'note' => $data['note'],
            'is_manual' => true,
        ]);
        $this->generator->refresh($claim);

        return $line;
    }

    public function removeManualLine(OvertimeClaim $claim, OvertimeClaimLine $line): void
    {
        $this->requireDraft($claim);
        if (! $line->is_manual || $line->overtime_claim_id !== $claim->id) {
            throw new AttendanceRuleViolation('attendance.errors.not_manual_line');
        }
        $line->delete();
        $this->generator->refresh($claim);
    }

    /**
     * @param  array<string, mixed>  $choices
     */
    public function submit(OvertimeClaim $claim, User $actor, ?string $capReason = null, array $choices = []): OvertimeClaim
    {
        return DB::transaction(function () use ($claim, $actor, $capReason, $choices): OvertimeClaim {
            $this->requireDraft($claim);
            if ($capReason !== null && trim($capReason) !== '') {
                $claim->forceFill(['cap_override_reason' => $capReason])->save();
            }

            $errors = [];
            if (! $claim->lines()->exists()) {
                $errors['lines'] = __('attendance.errors.claim_empty');
            }
            if ($claim->monthly_wage_snapshot === null) {
                $errors['monthly_wage_snapshot'] = __('attendance.errors.wage_required');
            }
            if ($claim->cap_exceeded && trim((string) $claim->cap_override_reason) === '') {
                $errors['cap_override_reason'] = __('attendance.errors.cap_reason_required');
            }
            DomainValidation::throwIf($errors);

            $claim->forceFill(['prepared_by' => $actor->id])->save();
            $this->approvals->submit($claim, $actor, $choices);

            return $claim->refresh();
        });
    }

    public function cancel(OvertimeClaim $claim, User $actor, string $reason): void
    {
        if (! in_array($claim->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)) {
            throw new AttendanceRuleViolation('attendance.errors.not_pending');
        }
        if ($claim->posted_payroll_run_id !== null) {
            throw new AttendanceRuleViolation('attendance.errors.claim_posted');
        }
        $this->approvals->cancel($claim->approvalRequest()->firstOrFail(), $actor, $reason);
    }

    public function deleteDraft(OvertimeClaim $claim): void
    {
        $this->requireDraft($claim);
        DB::transaction(function () use ($claim): void {
            $claim->lines()->delete();
            $claim->delete();
        });
    }

    private function requireDraft(OvertimeClaim $claim): void
    {
        if (! $claim->isDraft()) {
            throw new AttendanceRuleViolation('attendance.errors.not_draft');
        }
    }
}
