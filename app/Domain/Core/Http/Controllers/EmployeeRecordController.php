<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\CorrectEmployeeAssignment;
use App\Domain\Core\Actions\CreateEmployeeUserAccount;
use App\Domain\Core\Actions\ReassignDirectReports;
use App\Domain\Core\Actions\RecordLindung24Election;
use App\Domain\Core\Actions\RehireEmployee;
use App\Domain\Core\Actions\SaveEmergencyContacts;
use App\Domain\Core\Actions\SaveEmployeeApprovers;
use App\Domain\Core\Actions\SeparateEmployee;
use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Core\Enums\QualificationLevel;
use App\Domain\Core\Enums\SeparationReason;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeePreviousEmployment;
use App\Domain\Core\Models\EmployeeQualification;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Actions on a Kakitangan record from the Butiran page (B4).
 */
final class EmployeeRecordController extends Controller
{
    public function correct(Request $request, Employee $employee, CorrectEmployeeAssignment $correct): RedirectResponse
    {
        $this->authorize('correct', $employee);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['required', 'integer'],
            'designation_id' => ['required', 'integer'],
            'grade_id' => ['nullable', 'integer'],
            'employment_type_id' => ['required', 'integer'],
            'reports_to_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $correct->handle($employee, $data, $request->user());

        return $this->done('core.employee.corrected');
    }

    public function separate(Request $request, Employee $employee, SeparateEmployee $separate): RedirectResponse
    {
        $this->authorize('separate', $employee);
        $data = $request->validate([
            'resignation_date' => ['nullable', 'date'],
            'relieving_date' => ['required', 'date'],
            'separation_reason' => ['required', Rule::enum(SeparationReason::class)],
            'separation_note' => ['nullable', 'string', 'max:2000'],
            'eligible_for_rehire' => ['nullable', 'boolean'],
        ]);
        $warnings = $separate->handle($employee, $data);

        return $this->done('core.separation.saved', $warnings);
    }

    public function reassignReports(Request $request, Employee $employee, ReassignDirectReports $reassign): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate(['reports_to_id' => ['nullable', 'integer']]);
        $count = $reassign->handle($employee, isset($data['reports_to_id']) ? (int) $data['reports_to_id'] : null, $request->user());

        return back()->with('success', __('core.separation.reports_moved', ['count' => $count]));
    }

    public function createUser(Request $request, Employee $employee, CreateEmployeeUserAccount $create): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::defaults()],
            'locale' => ['nullable', Rule::in(config('myhrmis.locales'))],
        ]);
        $create->handle($employee, $data);

        return $this->done('core.employee.user_created');
    }

    public function rehire(Request $request, Employee $employee, RehireEmployee $rehire): RedirectResponse
    {
        $this->authorize('create', Employee::class);
        $data = $request->validate([
            'date_of_joining' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['required', 'integer'],
            'designation_id' => ['required', 'integer'],
            'grade_id' => ['nullable', 'integer'],
            'employment_type_id' => ['required', 'integer'],
            'reports_to_id' => ['nullable', 'integer'],
            'contract_end_date' => ['nullable', 'date'],
            'continuous_service_from' => ['nullable', 'date'],
            'continuous_service_reason' => ['nullable', 'required_with:continuous_service_from', 'string', 'max:500'],
        ]);
        $reason = $data['continuous_service_reason'] ?? null;
        unset($data['continuous_service_reason']);

        $result = $rehire->handle($employee, $data);
        if ($reason !== null) {
            activity('core')->performedOn($result['employee'])->causedBy($request->user())
                ->event('continuous_service')->withProperties(['reason' => $reason])->log('continuous_service');
        }

        $warnings = $result['warnings'];
        if ($result['needs_ytd_review']) {
            $warnings[] = (string) __('core.rehire.review_ytd');
        }

        return $this->done('core.rehire.done', $warnings, '/employees/'.$result['employee']->id);
    }

    public function approvers(Request $request, Employee $employee, SaveEmployeeApprovers $save): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate(['approvers' => ['present', 'array'], 'approvers.*' => ['nullable', 'integer']]);
        $save->handle($employee, $data['approvers']);

        return $this->done('common.saved');
    }

    public function emergencyContacts(Request $request, Employee $employee, SaveEmergencyContacts $save): RedirectResponse
    {
        abort_unless($request->user()->can('update', $employee) || $request->user()->can('selfEdit', $employee), 403);
        $data = $request->validate([
            'emergency_contacts' => ['present', 'array'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['required', 'string'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:30'],
            'emergency_contacts.*.alt_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contacts.*.address' => ['nullable', 'string', 'max:1000'],
            'emergency_contacts.*.is_primary' => ['boolean'],
        ]);
        $save->handle($employee, $data['emergency_contacts']);

        return $this->done('common.saved');
    }

    public function addQualification(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate([
            'level' => ['required', Rule::enum(QualificationLevel::class)],
            'institution' => ['required', 'string', 'max:255'],
            'field' => ['nullable', 'string', 'max:255'],
            'year_completed' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'grade_result' => ['nullable', 'string', 'max:60'],
        ]);
        $employee->qualifications()->create($data);

        return $this->done('common.saved');
    }

    public function deleteQualification(Request $request, Employee $employee, EmployeeQualification $qualification): RedirectResponse
    {
        $this->authorize('update', $employee);
        abort_unless($qualification->employee_id === $employee->id, 404);
        $qualification->delete();

        return $this->done('common.deleted');
    }

    public function addPreviousEmployment(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate([
            'employer_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'last_salary' => ['nullable', 'numeric', 'min:0'],
            'reason_left' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($data['last_salary'])) {
            $data['last_salary'] = (string) $data['last_salary'];
        }
        $employee->previousEmployments()->create($data);

        return $this->done('common.saved');
    }

    public function deletePreviousEmployment(Request $request, Employee $employee, EmployeePreviousEmployment $previous): RedirectResponse
    {
        $this->authorize('update', $employee);
        abort_unless($previous->employee_id === $employee->id, 404);
        $previous->delete();

        return $this->done('common.deleted');
    }

    public function lindung24(Request $request, Employee $employee, RecordLindung24Election $record): RedirectResponse
    {
        $this->authorize('update', $employee);
        $data = $request->validate([
            'status' => ['required', Rule::enum(Lindung24Status::class)],
            'month' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $record->handle($employee, Lindung24Status::from($data['status']), $data['month'], $request->user(), $data['note'] ?? null);

        return $this->done('common.saved');
    }
}
