<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\ScheduleDayKind;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Shared\Approval\Actions\PublishApprovalWorkflow;
use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepMode;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;

/**
 * Kehadiran defaults per Organisasi (spec-03 §3.1, §4.12): settings, the
 * statutory Jenis OT, two shift types, two Corak Roster examples and the seeded
 * approval routes (Penyelia step kept per lead instruction — deviates from A6.9).
 * Idempotent; workflows are replaced only while still the unused A6.9 default.
 */
final class SeedAttendanceDefaults
{
    public function __construct(private readonly PublishApprovalWorkflow $publish) {}

    public function handle(int $organisationId): void
    {
        OrganisationContext::run($organisationId, function () use ($organisationId): void {
            $settings = AttendanceSettings::current($organisationId);

            $ot = OvertimeType::query()->firstOrCreate(['code' => 'OT-AKTA'], [
                'name' => 'OT Akta Kerja', 'basis' => 'statutory', 'rounding_unit_minutes' => 1, 'min_minutes_per_day' => 0,
            ]);
            foreach (DayType::cases() as $dayType) {
                $ot->rules()->firstOrCreate(['day_type' => $dayType->value]);
            }
            if ($settings->default_overtime_type_id === null) {
                $settings->forceFill(['default_overtime_type_id' => $ot->id])->save();
            }

            $from = CarbonImmutable::today()->startOfMonth()->toDateString();
            $office = ShiftType::query()->firstOrCreate(['code' => 'PEJABAT'], [
                'name' => 'Waktu Pejabat', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60,
                'break_start_time' => '13:00:00', 'process_from' => $from, 'colour' => '#2563eb',
            ]);
            $half = ShiftType::query()->firstOrCreate(['code' => 'SEPARUH'], [
                'name' => 'Separuh Hari', 'start_time' => '08:00:00', 'end_time' => '13:00:00', 'break_minutes' => 0,
                'half_day_threshold_minutes' => 120, 'absent_threshold_minutes' => 60, 'process_from' => $from, 'colour' => '#0d9488',
            ]);

            if (! ShiftSchedule::query()->where('code', '5H-SS')->exists()) {
                $week = ShiftSchedule::query()->create(['code' => '5H-SS', 'name' => '5 hari + Sabtu separuh', 'cycle_length_days' => 7,
                    'notes' => 'Indeks 0 = Isnin (tetapkan tarikh rujukan pada hari Isnin).']);
                foreach (range(0, 6) as $i) {
                    $week->days()->create(match (true) {
                        $i < 5 => ['day_index' => $i, 'kind' => ScheduleDayKind::Shift, 'shift_type_id' => $office->id],
                        $i === 5 => ['day_index' => $i, 'kind' => ScheduleDayKind::Shift, 'shift_type_id' => $half->id],
                        default => ['day_index' => $i, 'kind' => ScheduleDayKind::Rest, 'shift_type_id' => null],
                    });
                }
            }
            if (! ShiftSchedule::query()->where('code', '4K4R')->exists()) {
                $cycle = ShiftSchedule::query()->create(['code' => '4K4R', 'name' => '4 kerja 4 rehat', 'cycle_length_days' => 8]);
                foreach (range(0, 7) as $i) {
                    $cycle->days()->create($i < 4
                        ? ['day_index' => $i, 'kind' => ScheduleDayKind::Shift, 'shift_type_id' => $office->id]
                        : ['day_index' => $i, 'kind' => ScheduleDayKind::Rest, 'shift_type_id' => null]);
                }
            }

            $this->seedWorkflows($organisationId);
        });
    }

    private function seedWorkflows(int $organisationId): void
    {
        $penyelia = fn (bool $skipSameAsPrevious = false) => [
            'name' => 'Penyelia', 'rule_type' => RuleType::ReportsTo->value, 'rule_config' => ['level' => 1],
            'mode' => StepMode::AnyOne->value, 'skip_if_applicant' => true, 'skip_if_same_as_previous' => $skipSameAsPrevious,
            'exclude_submitter' => true, 'on_no_approver' => OnNoApprover::Skip->value,
        ];
        $deptHead = fn (bool $skipIfApplicant, bool $skipSame) => [
            'name' => 'Ketua Jabatan', 'rule_type' => RuleType::DepartmentHead->value, 'rule_config' => ['walk_up' => true],
            'mode' => StepMode::AnyOne->value, 'skip_if_applicant' => $skipIfApplicant, 'skip_if_same_as_previous' => $skipSame,
            'exclude_submitter' => true, 'on_no_approver' => OnNoApprover::Fail->value,
        ];
        $role = fn (string $name, string $roleKey, ?array $condition = null) => [
            'name' => $name, 'rule_type' => RuleType::Role->value, 'rule_config' => ['role' => $roleKey],
            'mode' => StepMode::AnyOne->value, 'skip_if_applicant' => false, 'skip_if_same_as_previous' => true,
            'exclude_submitter' => true, 'on_no_approver' => OnNoApprover::Fail->value, 'condition' => $condition,
        ];

        $routes = [
            'attendance_request' => ['Kelulusan Kehadiran', [$penyelia(), $role('HR', PermissionMatrix::EKSEKUTIF_HR)]],
            'shift_request' => ['Kelulusan Tukar Shift', [$penyelia(), $deptHead(false, true)]],
            'overtime_claim' => ['Kelulusan Tuntutan OT', [
                $deptHead(true, false),
                $role('Pengurus HR', PermissionMatrix::PENGURUS_HR, [['attribute' => 'overtime_claim.needs_compliance_review', 'operator' => '=', 'value' => true]]),
            ]],
        ];

        foreach ($routes as $type => [$name, $steps]) {
            $current = ApprovalWorkflow::acrossOrganisations()->where('organisation_id', $organisationId)
                ->where('approval_type', $type)->where('is_active', true)->first();
            $isDefault = $current === null || ($current->version === 1 && $current->name === (string) __('approval.defaults.workflow_name', [], 'ms') && ! $current->isUsed());
            if (! $isDefault) {
                continue;
            }
            $this->publish->handle($organisationId, $type, ['name' => $name, 'prevent_self_approval' => true, 'allow_override' => true, 'steps' => $steps]);
        }
    }
}
