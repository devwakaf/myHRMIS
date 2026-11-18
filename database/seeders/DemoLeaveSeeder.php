<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\CancelLeaveApplication;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Demo data for the Cuti module (M2 UI): bulk-assigns the organisation's default
 * Leave Policy to every demo Kakitangan without an assignment, and files a few
 * sample applications in different states (draf, menunggu, diluluskan, ditolak,
 * dibatalkan) for the Pahang demo Organisasi. Idempotent; never runs in production.
 *
 * `php artisan db:seed --class=DemoLeaveSeeder`
 */
class DemoLeaveSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (['SDT' => 'hr.manager@myhrmis.test', 'DLS' => 'hr.selangor@myhrmis.test'] as $code => $hrEmail) {
            $org = Organisation::query()->where('short_code', $code)->first();
            $hr = User::query()->where('email', $hrEmail)->first();
            if ($org === null || $hr === null) {
                continue;
            }

            OrganisationContext::run($org, function () use ($code, $hr): void {
                $this->assignDefaultPolicy($hr);
                if ($code === 'SDT') {
                    $this->sampleApplications($hr);
                }
            });
        }
    }

    private function assignDefaultPolicy(User $hr): void
    {
        $policyId = LeaveSettings::current()->default_leave_policy_id;
        $policy = $policyId === null ? null : LeavePolicy::query()->find($policyId);
        if ($policy === null) {
            $this->warn('Tiada Leave Policy lalai; jalankan LeaveDefaultsSeeder dahulu.');

            return;
        }

        $assigned = LeavePolicyAssignment::query()->pluck('employee_id')->all();
        $ids = Employee::query()->notLeft()->whereNotIn('id', $assigned)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }

        $results = app(PolicyAssignmentActions::class)->bulkAssign($policy, '2026-01-01', ['employee_ids' => $ids], $hr);
        $failed = array_filter($results, fn (array $r) => ! $r['ok']);
        foreach ($failed as $r) {
            $this->warn($r['employee'].': '.$r['message']);
        }
    }

    private function sampleApplications(User $hr): void
    {
        $tah = LeaveType::query()->where('code', 'TAH')->first();
        if ($tah === null) {
            return;
        }
        $this->mainSamples($hr, $tah);
        $this->cancelledSample($hr, $tah);
    }

    private function mainSamples(User $hr, LeaveType $tah): void
    {
        if (LeaveApplication::query()->where('reason', 'Urusan keluarga')->exists()) {
            return;
        }
        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        $employee = fn (string $email) => Employee::query()->where('user_id', User::query()->where('email', $email)->value('id'))->first();
        $byName = fn (string $name) => Employee::query()->where('full_name', $name)->first();
        $submit = app(SubmitLeaveApplication::class);

        $file = function (?Employee $who, CarbonImmutable $from, CarbonImmutable $to, ?string $reason = null) use ($submit, $tah, $hr): ?LeaveApplication {
            if ($who === null) {
                return null;
            }
            $actor = $who->user_id !== null ? User::query()->find($who->user_id) : $hr;
            try {
                return $submit->apply($who, ['leave_type_id' => $tah->id, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'reason' => $reason], $actor ?? $hr)['application'];
            } catch (Throwable $e) {
                $this->warn('Permohonan contoh gagal untuk '.$who->full_name.': '.$e->getMessage());

                return null;
            }
        };

        $staff = $employee('staff@myhrmis.test');
        $lead = $employee('lead.dev@myhrmis.test');

        // Diluluskan
        if (($app = $file($staff, $monday->addWeek(), $monday->addWeek()->addDay(), 'Urusan keluarga')) !== null) {
            $this->decide($app, true);
        }
        // Menunggu
        $file($staff, $monday->addWeeks(3), $monday->addWeeks(3)->addDays(2), 'Cuti rehat');
        // Draf
        if ($staff !== null) {
            try {
                $submit->saveDraft(null, $staff, ['leave_type_id' => $tah->id, 'from_date' => $monday->addWeeks(6)->addDays(4)->toDateString(), 'to_date' => $monday->addWeeks(6)->addDays(4)->toDateString(), 'reason' => 'Draf contoh'], User::query()->findOrFail($staff->user_id));
            } catch (Throwable $e) {
                $this->warn('Draf contoh gagal: '.$e->getMessage());
            }
        }
        // Diluluskan (5 hari) oleh Ketua Jabatan
        if (($app = $file($lead, $monday->addWeeks(2), $monday->addWeeks(2)->addDays(4), 'Percutian keluarga')) !== null) {
            $this->decide($app, true);
        }
        // Menunggu (bagi pihak kakitangan tanpa log masuk)
        $file($byName('Kavitha a/p Subramaniam'), $monday->addWeeks(2)->addDays(2), $monday->addWeeks(2)->addDays(2));
        // Ditolak
        if (($app = $file($byName('Haziq bin Rosli'), $monday->addWeeks(4), $monday->addWeeks(4), 'Urusan peribadi')) !== null) {
            $this->decide($app, false);
        }
    }

    /** Dibatalkan (ditarik balik): a pending application withdrawn by HR on behalf of the employee. */
    private function cancelledSample(User $hr, LeaveType $tah): void
    {
        if (LeaveApplication::query()->where('reason', 'Dibatalkan contoh')->exists()) {
            return;
        }
        $who = Employee::query()->where('full_name', 'Muhammad Irfan bin Zulkifli')->first();
        if ($who === null) {
            return;
        }
        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        try {
            $app = app(SubmitLeaveApplication::class)->apply($who, ['leave_type_id' => $tah->id, 'from_date' => $monday->addWeeks(8)->toDateString(), 'to_date' => $monday->addWeeks(8)->addDay()->toDateString(), 'reason' => 'Dibatalkan contoh'], $hr)['application'];
            app(CancelLeaveApplication::class)->withdraw($app, $hr, 'Rancangan berubah');
        } catch (Throwable $e) {
            $this->warn('Contoh dibatalkan gagal: '.$e->getMessage());
        }
    }

    /** Walk every approval step: approve (or reject at the first step) as its pending assignee. */
    private function decide(LeaveApplication $application, bool $approve): void
    {
        $service = app(ApprovalService::class);
        $request = $application->approvalRequest()->first();
        for ($i = 0; $request !== null && $i < 6 && $request->refresh()->isPending(); $i++) {
            $assignee = $request->activeStep()?->assignees()->where('decision', AssigneeDecision::Pending->value)->first();
            $user = $assignee === null ? null : User::query()->find($assignee->user_id);
            if ($user === null) {
                return;
            }
            try {
                $approve ? $service->approve($request, $user) : $service->reject($request, $user, 'Contoh: tidak dapat dilulus pada tarikh ini.');
            } catch (Throwable $e) {
                $this->warn('Keputusan contoh gagal: '.$e->getMessage());

                return;
            }
        }
    }

    private function warn(string $message): void
    {
        $this->command?->warn($message);
    }
}
