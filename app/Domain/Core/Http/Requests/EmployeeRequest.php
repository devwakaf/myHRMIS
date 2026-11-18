<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use App\Domain\Core\Enums\BloodType;
use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\IdentityType;
use App\Domain\Core\Enums\MaritalStatus;
use App\Domain\Core\Enums\MtdScheme;
use App\Domain\Core\Enums\Race;
use App\Domain\Core\Enums\Religion;
use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Enums\TaxResidency;
use App\Domain\Core\Enums\WorkPassType;
use App\Domain\Shared\Support\Enums\Gender;
use App\Domain\Shared\Support\Enums\MalaysianState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field-level rules for the Kakitangan form; cross-field rules live in EmployeeValidator.
 */
final class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised in the controller via EmployeePolicy
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'employee_no' => ['nullable', 'string', 'max:30'],
            'full_name' => ['required', 'string', 'max:150'],
            'preferred_name' => ['nullable', 'string', 'max:60'],
            'identity_type' => ['required', Rule::enum(IdentityType::class)],
            'ic_no' => ['nullable', 'string', 'max:14'],
            'old_ic_no' => ['nullable', 'string', 'max:12'],
            'passport_no' => ['nullable', 'string', 'max:20'],
            'passport_country' => ['nullable', 'string', 'size:2'],
            'passport_expiry' => ['nullable', 'date'],
            'other_id_no' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'citizenship_status' => ['required', Rule::enum(CitizenshipStatus::class)],
            'nationality' => ['required', 'string', 'size:2'],
            'race' => ['nullable', Rule::enum(Race::class)],
            'religion' => ['nullable', Rule::enum(Religion::class)],
            'marital_status' => ['required', Rule::enum(MaritalStatus::class)],
            'blood_type' => ['nullable', Rule::enum(BloodType::class)],
            'is_disabled' => ['boolean'],
            'mobile_phone' => ['required', 'string', 'max:30'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'address_line3' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'regex:/^\d{5}$/'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::enum(MalaysianState::class)],
            'country' => ['nullable', 'string', 'size:2'],
            'permanent_same_as_current' => ['boolean'],
            'permanent_address_line1' => ['nullable', 'string', 'max:255'],
            'permanent_address_line2' => ['nullable', 'string', 'max:255'],
            'permanent_address_line3' => ['nullable', 'string', 'max:255'],
            'permanent_postcode' => ['nullable', 'regex:/^\d{5}$/'],
            'permanent_city' => ['nullable', 'string', 'max:255'],
            'permanent_state' => ['nullable', Rule::enum(MalaysianState::class)],
            'permanent_country' => ['nullable', 'string', 'size:2'],
            'date_of_joining' => ['required', 'date'],
            'continuous_service_from' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['required', 'integer'],
            'designation_id' => ['required', 'integer'],
            'grade_id' => ['nullable', 'integer'],
            'employment_type_id' => ['required', 'integer'],
            'reports_to_id' => ['nullable', 'integer'],
            'probation_end_date' => ['nullable', 'date'],
            'confirmed_on' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'kwsp_no' => ['nullable', 'regex:/^\d{1,20}$/'],
            'kwsp_exempt' => ['boolean'],
            'kwsp_exempt_reason' => ['nullable', 'string', 'max:255'],
            'kwsp_employee_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'perkeso_no' => ['nullable', 'string', 'max:20'],
            'perkeso_first_insured_on' => ['nullable', 'date'],
            'eis_exempt' => ['boolean'],
            'work_pass_type' => ['nullable', Rule::enum(WorkPassType::class)],
            'work_pass_no' => ['nullable', 'string', 'max:30'],
            'work_pass_expiry' => ['nullable', 'date'],
            'salary_payment_mode' => ['required', Rule::enum(SalaryPaymentMode::class)],
            'bank_id' => ['nullable', 'integer', 'exists:banks,id'],
            'bank_account_no' => ['nullable', 'regex:/^\d{5,20}$/'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'tax' => ['array'],
            'tax.tin' => ['nullable', 'string', 'max:14'],
            'tax.tax_residency' => ['nullable', Rule::enum(TaxResidency::class)],
            'tax.mtd_scheme' => ['nullable', Rule::enum(MtdScheme::class)],
            'tax.pcb_category' => ['nullable', 'integer', 'in:1,2,3'],
            'tax.pcb_category_override_reason' => ['nullable', 'string', 'max:255'],
            'tax.spouse_working' => ['nullable', 'boolean'],
            'tax.spouse_disabled' => ['boolean'],
            'tax.children_basic' => ['nullable', 'integer'],
            'tax.children_higher_ed' => ['nullable', 'integer'],
            'tax.children_disabled' => ['nullable', 'integer'],
            'tax.children_disabled_higher_ed' => ['nullable', 'integer'],
            'tax.claims_child_relief' => ['boolean'],
            'tax.zakat_via_payroll' => ['boolean'],
            'tax.zakat_monthly_amount' => ['nullable', 'numeric'],
            'tax.zakat_authority' => ['nullable', 'string', 'max:255'],
            'tax.zakat_ref_no' => ['nullable', 'string', 'max:255'],
            'approvers' => ['array'],
            'approvers.*' => ['nullable', 'integer'],
            'emergency_contacts' => ['array'],
        ];
    }
}
