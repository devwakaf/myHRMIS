<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeNoMode;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Events\EmployeeHired;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeTaxProfile;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Services\EmployeeNumberGenerator;
use App\Domain\Core\Services\EmployeeValidator;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Tambah Kakitangan: validate (B3), create the record, tax profile, the hire
 * work-history row, named approvers and emergency contacts; fire EmployeeHired.
 */
final class CreateEmployee
{
    public function __construct(
        private readonly EmployeeValidator $validator,
        private readonly EmployeeNumberGenerator $numbers,
        private readonly WorkHistoryRecorder $history,
        private readonly SaveEmployeeApprovers $approvers,
        private readonly SaveEmergencyContacts $contacts,
    ) {}

    /**
     * @param  array<string, mixed>  $data  employee fields + 'tax' + 'approvers' + 'emergency_contacts'
     * @param  array<string, mixed>  $extra  system fields (previous_employee_id, user_id)
     * @return array{employee: Employee, warnings: list<string>}
     */
    public function handle(array $data, array $extra = [], WorkHistorySource $source = WorkHistorySource::Hire): array
    {
        $data['tax'] ??= [];
        $result = $this->validator->validate(array_merge($data, $extra));

        $employee = DB::transaction(function () use ($data, $result, $extra, $source): Employee {
            $values = Arr::only($result->data, Employee::FORM_FIELDS);
            $values['employee_no'] = $this->employeeNumber($values['employee_no'] ?? null);
            $values['status'] = EmployeeStatus::Active;
            $values['retirement_date'] = $result->data['retirement_date'] ?? null;
            $values = array_merge($values, Arr::only($extra, ['previous_employee_id', 'user_id']));

            /** @var Employee $employee */
            $employee = Employee::query()->create($values);

            EmployeeTaxProfile::query()->create(array_merge(
                Arr::only($result->data['tax'] ?? [], self::taxFields()),
                ['employee_id' => $employee->id],
            ));

            $this->history->open($employee, $employee->date_of_joining, $source);

            if (! empty($data['approvers']) && is_array($data['approvers'])) {
                $this->approvers->handle($employee, $data['approvers']);
            }
            if (! empty($data['emergency_contacts']) && is_array($data['emergency_contacts'])) {
                $this->contacts->handle($employee, array_values($data['emergency_contacts']));
            }

            return $employee;
        });

        EmployeeHired::dispatch($employee);

        return ['employee' => $employee->refresh(), 'warnings' => $result->warnings];
    }

    private function employeeNumber(?string $requested): string
    {
        $settings = HrSettings::current();
        $requested = $requested === null ? '' : trim($requested);

        if ($requested !== '' && $settings->employee_no_mode === EmployeeNoMode::Manual) {
            if (Employee::query()->where('employee_no', $requested)->exists()) {
                throw DomainValidation::withMessages(['employee_no' => __('validation.unique', ['attribute' => __('validation.attributes.employee_no')])]);
            }

            return $requested;
        }

        $organisation = OrganisationContext::current();
        do {
            $number = $this->numbers->next($organisation);
        } while (Employee::query()->where('employee_no', $number)->exists());

        return $number;
    }

    /**
     * @return list<string>
     */
    public static function taxFields(): array
    {
        return [
            'tin', 'tax_residency', 'mtd_scheme', 'pcb_category', 'pcb_category_override_reason', 'spouse_working',
            'spouse_disabled', 'children_basic', 'children_higher_ed', 'children_disabled', 'children_disabled_higher_ed',
            'claims_child_relief', 'zakat_via_payroll', 'zakat_monthly_amount', 'zakat_authority', 'zakat_ref_no',
        ];
    }
}
