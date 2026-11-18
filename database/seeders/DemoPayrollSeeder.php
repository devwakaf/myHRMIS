<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Actions\AssignmentActions;
use App\Domain\Payroll\Actions\PayrollRunActions;
use App\Domain\Payroll\Actions\StartPayrollGeneration;
use App\Domain\Payroll\Actions\SubmitPayrollRun;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Demo data for Payroll (M4 UI) in the Pahang demo Organisasi: the standard Struktur Gaji assigned
 * to every demo Kakitangan, a Pelulus Payroll and a Kewangan login, and ONE sample Proses Gaji for
 * last month run through to Dihantar. It is a PLACEHOLDER dataset: the KWSP, PERKESO and SIP rate
 * sets are filled with generated placeholder bands (flagged is_placeholder, never verifiable), so
 * every statutory figure on the demo slips is NOT an official amount. Idempotent; never runs in
 * production.
 *
 * `php artisan db:seed --class=DemoPayrollSeeder`
 */
class DemoPayrollSeeder extends Seeder
{
    /** Gaji Pokok by position in the employee list (cycled). */
    private const BASES = ['3200.00', '4500.00', '5800.00', '7200.00', '2650.00', '9500.00'];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $org = Organisation::query()->where('short_code', 'SDT')->first();
        $po = User::query()->where('email', 'payroll@myhrmis.test')->first();
        if ($org === null || $po === null) {
            return;
        }

        OrganisationContext::run($org, function () use ($org, $po): void {
            $structure = SalaryStructure::query()->where('code', 'STANDARD')->first();
            if ($structure === null) {
                $this->warn('Tiada Struktur Gaji lalai; jalankan PayrollDefaultsSeeder dahulu.');

                return;
            }
            if (PayrollRun::query()->exists()) {
                return;
            }

            // The demo run uses unverified placeholder sets; the default setting would refuse to submit it.
            PayrollSettings::current()->forceFill(['block_unverified_rates' => false])->save();

            $approver = $this->login($org, 'payroll.approver@myhrmis.test', 'Pelulus Payroll Demo', PermissionMatrix::PELULUS_PAYROLL);
            $this->login($org, 'finance@myhrmis.test', 'Kewangan Demo', PermissionMatrix::KEWANGAN);

            // PLACEHOLDER bands so the unverified sets can calculate on the demo data (dev only).
            foreach (['EPF-2025-10', 'SOCSO-2026-06', 'EIS-2024-10'] as $code) {
                Artisan::call('statutory:placeholder', ['code' => $code]);
            }

            $this->assign($structure, $po);
            $this->payOneMonth($po, $approver);
        });
    }

    private function assign(SalaryStructure $structure, User $actor): void
    {
        $actions = app(AssignmentActions::class);
        $i = 0;
        foreach (Employee::query()->notLeft()->orderBy('employee_no')->get() as $employee) {
            $base = self::BASES[$i % count(self::BASES)];
            $i++;
            if (SalaryAssignment::query()->where('employee_id', $employee->id)->exists()) {
                continue;
            }
            try {
                $draft = $actions->saveDraft(null, [
                    'employee_id' => $employee->id, 'salary_structure_id' => $structure->id, 'base_salary' => $base,
                    'effective_from' => $employee->date_of_joining->toDateString(), 'reason' => 'new_hire', 'remarks' => 'Data demo',
                ]);
                $actions->submit($draft, $actor);
            } catch (Throwable $e) {
                $this->warn('Penugasan '.$employee->employee_no.' dilangkau: '.$e->getMessage());
            }
        }
    }

    private function payOneMonth(User $po, User $approver): void
    {
        $period = max(CarbonImmutable::today()->startOfMonth()->subMonth(), CarbonImmutable::parse('2026-06-01'));
        $previousQueue = config('queue.default');
        $previousMail = config('mail.default');
        config(['queue.default' => 'sync', 'mail.default' => 'log']); // generate and notify inline

        try {
            $run = app(PayrollRunActions::class)->create(['period' => $period->toDateString(), 'notes' => 'Data demo — kadar KWSP/PERKESO/SIP ialah pemegang tempat, bukan kadar rasmi.'], $po);
            $run = app(StartPayrollGeneration::class)->handle($run, $po)->refresh();
            app(SubmitPayrollRun::class)->handle($run, $approver, true);
        } catch (Throwable $e) {
            $this->warn('Proses Gaji demo tidak lengkap: '.$e->getMessage());
        } finally {
            config(['queue.default' => $previousQueue, 'mail.default' => $previousMail]);
        }
    }

    private function login(Organisation $org, string $email, string $name, string $role): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name, 'password' => DemoSeeder::PASSWORD, 'locale' => 'ms', 'is_active' => true,
            'email_verified_at' => now(), 'current_organisation_id' => $org->id,
        ])->save();
        app(RoleService::class)->assign($user, $org->id, $role);

        return $user;
    }

    private function warn(string $message): void
    {
        $this->command->warn($message);
    }
}
