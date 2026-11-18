<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Payroll\Adapters\AdditionalPayAdapter;
use App\Domain\Payroll\Adapters\PayrollCompensationAdapter;
use App\Domain\Payroll\Adapters\PayrollLockAdapter;
use App\Domain\Payroll\Console\BackfillPendingEncashments;
use App\Domain\Payroll\Listeners\PayrollEventSubscriber;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\Arrear;
use App\Domain\Payroll\Models\PayrollCorrection;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Policies\PayrollRunPolicy;
use App\Domain\Payroll\Policies\SalarySlipPolicy;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Statutory\Console\StatutoryImport;
use App\Domain\Statutory\Console\StatutoryPlaceholder;
use App\Domain\Statutory\Console\StatutoryVerify;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Payroll + Statutori module (spec-04): replaces the M2/M3 Null bindings of the
 * Payroll-implemented ports with the real adapters (bind() wins over bindIf),
 * registers policies, the OrganisationCreated listener, console commands and
 * routes. Registered after Leave and Attendance in bootstrap/providers.php.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayrollLockQuery::class, PayrollLockAdapter::class);
        $this->app->bind(AdditionalPayPort::class, AdditionalPayAdapter::class);
        $this->app->bind(CompensationQuery::class, PayrollCompensationAdapter::class);
        $this->app->bind(OtWageBaseProvider::class, PayrollCompensationAdapter::class);

        $this->app->scoped(StatutoryRateRepository::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'payroll_run' => PayrollRun::class,
            'salary_slip' => SalarySlip::class,
            'salary_assignment' => SalaryAssignment::class,
            'additional_salary' => AdditionalSalary::class,
            'arrear' => Arrear::class,
            'payroll_correction' => PayrollCorrection::class,
            'salary_withholding' => SalaryWithholding::class,
            'tax_declaration' => TaxDeclaration::class,
        ]);

        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(SalarySlip::class, SalarySlipPolicy::class);
        Event::subscribe(PayrollEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([StatutoryImport::class, StatutoryVerify::class, StatutoryPlaceholder::class, BackfillPendingEncashments::class]);
        }

        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Payroll/routes.php'));
        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Statutory/routes.php'));
    }
}
