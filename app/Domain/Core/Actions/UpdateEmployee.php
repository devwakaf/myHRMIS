<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeTaxProfile;
use App\Domain\Core\Services\EmployeeValidator;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edit Kakitangan (HR). Organisation-assignment fields are changed only through
 * movements or a correction (B3.6), so they are rejected here when they differ.
 */
final class UpdateEmployee
{
    public function __construct(private readonly EmployeeValidator $validator) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> warnings
     */
    public function handle(Employee $employee, array $data, bool $includeTax = true): array
    {
        $errors = [];
        foreach (Employee::ASSIGNMENT_FIELDS as $field) {
            if (array_key_exists($field, $data) && (string) ($data[$field] ?? '') !== (string) ($employee->getAttribute($field) ?? '')) {
                $errors[$field] = __('core.validation.use_movement');
            }
        }
        DomainValidation::throwIf($errors);

        $editable = array_values(array_diff(Employee::FORM_FIELDS, Employee::ASSIGNMENT_FIELDS));
        $changes = Arr::only($data, $editable);

        $currentTax = $employee->taxProfile?->attributesToArray() ?? [];
        $currentTax['zakat_monthly_amount'] = $employee->taxProfile?->zakat_monthly_amount?->toDecimalString();
        $tax = $includeTax ? array_merge($currentTax, Arr::only($data['tax'] ?? [], CreateEmployee::taxFields())) : null;

        $merged = array_merge($this->currentValues($employee), $changes);
        if ($tax !== null) {
            $merged['tax'] = $tax;
        }

        $result = $this->validator->validate($merged, $employee);

        DB::transaction(function () use ($employee, $result, $editable, $tax): void {
            $employee->fill(Arr::only($result->data, $editable));
            $employee->retirement_date = $result->data['retirement_date'] ?? $employee->retirement_date;
            $employee->save();

            if ($tax !== null) {
                EmployeeTaxProfile::query()->updateOrCreate(
                    ['employee_id' => $employee->id],
                    Arr::only($result->data['tax'] ?? [], CreateEmployee::taxFields()),
                );
            }
        });

        return $result->warnings;
    }

    /**
     * Raw-ish current values for re-validation (enums as values, dates as Y-m-d).
     *
     * @return array<string, mixed>
     */
    public function currentValues(Employee $employee): array
    {
        $values = [];
        foreach (array_merge(Employee::FORM_FIELDS, ['user_id', 'resignation_date', 'relieving_date']) as $field) {
            $value = $employee->getAttribute($field);
            $values[$field] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return $values;
    }
}
