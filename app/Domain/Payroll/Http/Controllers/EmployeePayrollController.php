<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tab "Payroll" on Butiran Kakitangan (sensitive): the employee's salary assignments,
 * latest slips and withholding status. Only roles that may see every slip get it; the
 * structure and base salary additionally need payroll.config.view|manage. Self-service
 * employees use Slip Gaji Saya instead.
 */
final class EmployeePayrollController extends Controller
{
    public function show(Request $request, int $employee): JsonResponse
    {
        $this->requirePermission('payroll.slip.view-all');
        $person = Employee::query()->findOrFail($employee);
        $user = $request->user();
        $seeConfig = $user->is_super_admin || $user->can('payroll.config.manage') || $user->can('payroll.config.view');

        $assignments = $seeConfig ? SalaryAssignment::query()->with(['employee', 'structure'])->where('employee_id', $person->id)
            ->where('status', DocumentStatus::Submitted->value)->orderByDesc('effective_from')->limit(8)->get()
            ->map(fn (SalaryAssignment $a) => PayrollPresenter::assignment($a))->all() : [];

        $slips = SalarySlip::query()->where('employee_id', $person->id)->where('status', DocumentStatus::Submitted->value)
            ->orderByDesc('period')->limit(12)->get()->map(fn (SalarySlip $s) => [
                'id' => $s->id, 'number' => $s->number, 'period' => $s->period->format('Y-m'), 'gross_pay' => (string) $s->gross_pay,
                'net_pay' => (string) $s->net_pay, 'payment_status' => $s->payment_status->value, 'payment_status_label' => $s->payment_status->label(),
            ])->all();

        return new JsonResponse([
            'employee' => ['id' => $person->id, 'employee_no' => $person->employee_no, 'full_name' => $person->full_name],
            'assignments' => array_map(fn (array $a) => [
                'id' => $a['id'], 'number' => $a['number'], 'structure' => $a['structure'], 'effective_from' => $a['effective_from'], 'effective_to' => $a['effective_to'],
                'base_salary' => $a['base_salary'], 'reason_label' => $a['reason_label'],
            ], $assignments),
            'slips' => $slips,
            'withheld' => SalaryWithholding::query()->where('employee_id', $person->id)->where('status', DocumentStatus::Submitted->value)->exists(),
            'can' => ['config' => $seeConfig, 'assign' => $user->can('payroll.config.manage'), 'adjust' => $user->can('payroll.adjustments.manage')],
        ]);
    }
}
