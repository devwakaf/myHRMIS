<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Resources;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\MalaysianIc;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full Kakitangan record for show/edit. Sensitive columns are removed unless the
 * viewer may see them (core.employee.view-sensitive or own record).
 *
 * @property Employee $resource
 */
final class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;
        $user = $request->user();
        $sensitive = $user !== null && $user->can('viewSensitive', $employee);

        $data = [];
        foreach (array_merge(Employee::FORM_FIELDS, [
            'id', 'status', 'retirement_date', 'user_id', 'previous_employee_id', 'resignation_date', 'relieving_date',
            'separation_reason', 'separation_note', 'eligible_for_rehire',
        ]) as $field) {
            $data[$field] = self::plain($employee->getAttribute($field));
        }

        $data['display_name'] = $employee->displayName();
        $data['service_start'] = $employee->serviceStart()->toDateString();
        $data['labels'] = [
            'identity_type' => $employee->identity_type->label(),
            'gender' => $employee->gender->label(),
            'citizenship_status' => $employee->citizenship_status->label(),
            'marital_status' => $employee->marital_status->label(),
            'status' => $employee->status->label(),
            'state' => $employee->state?->label(),
            'separation_reason' => $employee->separation_reason?->label(),
            'branch' => $employee->branch?->displayName(),
            'department' => $employee->department?->displayName(),
            'designation' => $employee->designation?->displayName(),
            'grade' => $employee->grade?->displayName(),
            'employment_type' => $employee->employmentType?->displayName(),
            'reports_to' => $employee->reportsTo?->full_name,
            'user' => $employee->user?->email,
            'bank' => $employee->bank?->name,
            'race' => $employee->race?->label(),
            'religion' => $employee->religion?->label(),
            'work_pass_type' => $employee->work_pass_type?->label(),
            'salary_payment_mode' => $employee->salary_payment_mode->label(),
        ];

        $tax = $employee->taxProfile;
        $data['tax'] = $tax === null ? null : array_map([self::class, 'plain'], array_merge($tax->only([
            'tin', 'tax_residency', 'mtd_scheme', 'pcb_category', 'pcb_category_override_reason', 'spouse_working',
            'spouse_disabled', 'children_basic', 'children_higher_ed', 'children_disabled', 'children_disabled_higher_ed',
            'claims_child_relief', 'zakat_via_payroll', 'zakat_authority', 'zakat_ref_no',
        ]), ['zakat_monthly_amount' => $tax->zakat_monthly_amount?->toDecimalString()]));

        if (! $sensitive) {
            foreach (Employee::SENSITIVE as $field) {
                unset($data[$field]);
            }
            unset($data['labels']['race'], $data['labels']['religion'], $data['labels']['bank'], $data['labels']['work_pass_type']);
            $data['tax'] = null;
            $data['ic_no_masked'] = MalaysianIc::mask($employee->ic_no);
        }

        $data['sensitive_visible'] = $sensitive;

        return $data;
    }

    public static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }
}
