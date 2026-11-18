<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\IdentityType;
use App\Domain\Core\Enums\MaritalStatus;
use App\Domain\Core\Enums\Religion;
use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Support\ValidationResult;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainValidation;
use App\Domain\Shared\Support\MalaysianIc;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Business validation and derivations for Kakitangan (B3.1–B3.5).
 * Field types are checked by the FormRequest; this class checks the rules that
 * span fields, settings and other records, and returns non-blocking warnings.
 */
final class EmployeeValidator
{
    public function __construct(private readonly PcbCategorySuggester $pcb) {}

    /**
     * @param  array<string, mixed>  $data  merged employee attributes (existing + changes); tax profile under 'tax'
     */
    public function validate(array $data, ?Employee $existing = null): ValidationResult
    {
        $settings = HrSettings::current();
        $errors = [];
        $warnings = [];
        $today = CarbonImmutable::today();

        $citizenship = CitizenshipStatus::tryFrom((string) ($data['citizenship_status'] ?? ''));
        $identityType = IdentityType::tryFrom((string) ($data['identity_type'] ?? ''));

        // B3.1 MyKad
        if (isset($data['ic_no']) && $data['ic_no'] !== '') {
            $data['ic_no'] = MalaysianIc::normalise((string) $data['ic_no']);
        } else {
            $data['ic_no'] = null;
        }

        if ($identityType === IdentityType::MyKad) {
            if ($data['ic_no'] === null) {
                $errors['ic_no'] = __('validation.required', ['attribute' => __('validation.attributes.ic_no')]);
            } else {
                try {
                    $ic = MalaysianIc::parse((string) $data['ic_no'], $settings->min_employment_age, $today);
                    $data['date_of_birth'] = $ic->dateOfBirth->toDateString();
                    $data['gender'] = $ic->gender->value;
                    if (! $ic->isKnownPlaceCode()) {
                        $warnings[] = (string) __('core.warnings.ic_place_code', ['code' => $ic->placeCode]);
                    }
                } catch (InvalidArgumentException $e) {
                    $errors['ic_no'] = __($e->getMessage());
                }
            }
        }

        // B3.2 identity by citizenship
        $nationality = strtoupper((string) ($data['nationality'] ?? 'MY'));
        $data['nationality'] = $nationality;
        if ($citizenship === CitizenshipStatus::Citizen) {
            if ($identityType !== IdentityType::MyKad) {
                $errors['identity_type'] = __('core.validation.citizen_needs_mykad');
            }
            if ($nationality !== 'MY') {
                $errors['nationality'] = __('core.validation.citizen_nationality');
            }
        }
        if ($citizenship === CitizenshipStatus::PermanentResident && $identityType !== IdentityType::MyKad) {
            $errors['identity_type'] = __('core.validation.pr_needs_mykad');
        }
        $isForeign = in_array($citizenship, [CitizenshipStatus::Foreign, CitizenshipStatus::ForeignPre1998], true);
        if ($isForeign) {
            foreach (['passport_no', 'passport_country'] as $field) {
                if (blank($data[$field] ?? null)) {
                    $errors[$field] = __('validation.required', ['attribute' => __('validation.attributes.'.$field)]);
                }
            }
            if ($nationality === 'MY') {
                $errors['nationality'] = __('core.validation.foreign_nationality');
            }
            foreach (['work_pass_type', 'work_pass_no', 'work_pass_expiry'] as $field) {
                if (blank($data[$field] ?? null)) {
                    if ($citizenship === CitizenshipStatus::ForeignPre1998) {
                        $warnings[] = (string) __('core.warnings.work_pass_missing');
                    } else {
                        $errors[$field] = __('validation.required', ['attribute' => __('validation.attributes.'.$field)]);
                    }
                }
            }
        }
        if (! empty($data['passport_country'])) {
            $data['passport_country'] = strtoupper((string) $data['passport_country']);
        }

        $joining = $this->date($data['date_of_joining'] ?? null);
        $dob = $this->date($data['date_of_birth'] ?? null);

        foreach (['passport_expiry' => 'passport', 'work_pass_expiry' => 'work_pass'] as $field => $label) {
            $expiry = $this->date($data[$field] ?? null);
            if ($expiry === null) {
                continue;
            }
            if ($joining !== null && $expiry->lessThan($joining)) {
                $errors[$field] = __('core.validation.expiry_before_joining');
            } elseif ($expiry->lessThan($today)) {
                $warnings[] = (string) __('core.warnings.'.$label.'_expired');
            }
        }

        // B3.3 dates
        if ($dob === null) {
            $errors['date_of_birth'] = __('validation.required', ['attribute' => __('validation.attributes.date_of_birth')]);
        } elseif ($dob->greaterThan($today)) {
            $errors['date_of_birth'] = __('core.validation.dob_future');
        } elseif ($joining !== null) {
            $ageAtJoining = (int) $dob->diffInYears($joining);
            if ($ageAtJoining < $settings->min_employment_age) {
                $errors['date_of_joining'] = __('core.validation.below_min_age', ['age' => $settings->min_employment_age]);
            } elseif ($ageAtJoining < 18) {
                $warnings[] = (string) __('core.warnings.under_18');
            }
        }

        if ($joining !== null) {
            foreach (['probation_end_date', 'contract_end_date', 'relieving_date'] as $field) {
                $d = $this->date($data[$field] ?? null);
                if ($d !== null && $d->lessThan($joining)) {
                    $errors[$field] = __('core.validation.before_joining');
                }
            }
            $csf = $this->date($data['continuous_service_from'] ?? null);
            if ($csf !== null && $csf->greaterThan($joining)) {
                $errors['continuous_service_from'] = __('core.validation.service_after_joining');
            }
            if (empty($data['probation_end_date']) && $existing === null && $settings->default_probation_months > 0) {
                $data['probation_end_date'] = $joining->addMonthsNoOverflow($settings->default_probation_months)->toDateString();
            }
        }
        $relieving = $this->date($data['relieving_date'] ?? null);
        $resignation = $this->date($data['resignation_date'] ?? null);
        if ($relieving !== null && $resignation !== null && $relieving->lessThan($resignation)) {
            $errors['relieving_date'] = __('core.validation.relieving_before_resignation');
        }

        if ($dob !== null) {
            $data['retirement_date'] = $dob->addYears($settings->retirement_age)->toDateString();
        }

        // B3.5 organisation structure
        $errors = array_merge($errors, $this->validateStructure($data, $existing));

        $type = empty($data['employment_type_id']) ? null : EmploymentType::query()->find($data['employment_type_id']);
        if ($type !== null && $type->requires_end_date && blank($data['contract_end_date'] ?? null)) {
            $errors['contract_end_date'] = __('core.validation.contract_end_required');
        }

        if (! empty($data['work_email'])) {
            $taken = Employee::query()->where('work_email', $data['work_email'])
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($taken) {
                $errors['work_email'] = __('validation.unique', ['attribute' => __('validation.attributes.work_email')]);
            }
        }

        if (! empty($data['user_id'])) {
            $linked = Employee::query()->where('user_id', $data['user_id'])
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($linked) {
                $errors['user_id'] = __('core.validation.user_already_linked');
            }
        }

        // Uniqueness of identity among non-left employees (B3.1)
        [$idErrors, $idWarnings] = $this->identityUniqueness($data, $existing);
        $errors = array_merge($errors, $idErrors);
        $warnings = array_merge($warnings, $idWarnings);

        // Statutory & bank
        if (! empty($data['kwsp_exempt']) && blank($data['kwsp_exempt_reason'] ?? null)) {
            $errors['kwsp_exempt_reason'] = __('validation.required', ['attribute' => __('validation.attributes.kwsp_exempt_reason')]);
        }
        $mode = SalaryPaymentMode::tryFrom((string) ($data['salary_payment_mode'] ?? 'bank_transfer'));
        if ($mode === SalaryPaymentMode::BankTransfer) {
            foreach (['bank_id', 'bank_account_no', 'bank_account_name'] as $field) {
                if (blank($data[$field] ?? null)) {
                    $errors[$field] = __('validation.required', ['attribute' => __('validation.attributes.'.$field)]);
                }
            }
        }

        // B3.4 tax profile
        if (isset($data['tax']) && is_array($data['tax'])) {
            [$tax, $taxErrors, $taxWarnings] = $this->validateTax($data['tax'], $data);
            $data['tax'] = $tax;
            $errors = array_merge($errors, $taxErrors);
            $warnings = array_merge($warnings, $taxWarnings);
        }

        DomainValidation::throwIf($errors);

        return new ValidationResult($data, array_values(array_unique($warnings)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateStructure(array $data, ?Employee $existing): array
    {
        $errors = [];
        $checks = [
            'department_id' => [Department::class, true],
            'designation_id' => [Designation::class, true],
            'employment_type_id' => [EmploymentType::class, true],
            'grade_id' => [Grade::class, false],
            'branch_id' => [Branch::class, Branch::query()->where('is_active', true)->exists()],
        ];

        foreach ($checks as $field => [$class, $required]) {
            $id = $data[$field] ?? null;
            if ($id === null || $id === '') {
                if ($required) {
                    $errors[$field] = __('validation.required', ['attribute' => __('validation.attributes.'.$field)]);
                }

                continue;
            }
            $model = $class::query()->find($id);
            $unchanged = $existing !== null && (int) $existing->getAttribute($field) === (int) $id;
            if ($model === null || (! $model->getAttribute('is_active') && ! $unchanged)) {
                $errors[$field] = __('core.validation.inactive_or_foreign', ['attribute' => __('validation.attributes.'.$field)]);
            }
        }

        $reportsTo = $data['reports_to_id'] ?? null;
        if ($reportsTo !== null && $reportsTo !== '') {
            $error = $this->reportsToError((int) $reportsTo, $existing);
            if ($error !== null) {
                $errors['reports_to_id'] = $error;
            }
        }

        return $errors;
    }

    public function reportsToError(int $managerId, ?Employee $employee): ?string
    {
        if ($employee !== null && $managerId === $employee->id) {
            return (string) __('core.validation.reports_to_self');
        }

        $manager = Employee::query()->find($managerId);
        if ($manager === null || $manager->status !== EmployeeStatus::Active) {
            return (string) __('core.validation.reports_to_inactive');
        }

        if ($employee !== null) {
            $seen = [];
            $current = $manager;
            while ($current !== null && $current->reports_to_id !== null) {
                if ($current->reports_to_id === $employee->id) {
                    return (string) __('core.validation.reports_to_cycle');
                }
                if (isset($seen[$current->id])) {
                    break;
                }
                $seen[$current->id] = true;
                $current = Employee::query()->find($current->reports_to_id);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function identityUniqueness(array $data, ?Employee $existing): array
    {
        $errors = [];
        $warnings = [];
        $orgId = OrganisationContext::id();

        $pairs = [];
        if (! empty($data['ic_no'])) {
            $pairs['ic_no'] = fn ($q) => $q->where('ic_no', $data['ic_no']);
        }
        if (! empty($data['passport_no']) && ! empty($data['passport_country'])) {
            $pairs['passport_no'] = fn ($q) => $q->where('passport_no', $data['passport_no'])->where('passport_country', $data['passport_country']);
        }

        foreach ($pairs as $field => $filter) {
            $sameOrg = Employee::query()->notLeft()->tap($filter)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($sameOrg) {
                $errors[$field] = __('core.validation.duplicate_identity');

                continue;
            }

            $otherOrg = Employee::acrossOrganisations()->where('organisation_id', '!=', $orgId)
                ->where('status', '!=', EmployeeStatus::Left->value)->tap($filter)->exists();
            if ($otherOrg) {
                $warnings[] = (string) __('core.warnings.duplicate_identity_other_org');
            }
        }

        return [$errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $tax
     * @param  array<string, mixed>  $employee
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function validateTax(array $tax, array $employee): array
    {
        $errors = [];
        $warnings = [];
        $marital = MaritalStatus::tryFrom((string) ($employee['marital_status'] ?? 'single')) ?? MaritalStatus::Single;

        $spouseWorking = $tax['spouse_working'] ?? null;
        $spouseWorking = ($spouseWorking === null || $spouseWorking === '') ? null : filter_var($spouseWorking, FILTER_VALIDATE_BOOLEAN);
        $tax['spouse_working'] = $spouseWorking;

        if ($marital === MaritalStatus::Married && $spouseWorking === null) {
            $errors['tax.spouse_working'] = __('core.validation.spouse_working_required');
        }

        foreach (['children_basic', 'children_higher_ed', 'children_disabled', 'children_disabled_higher_ed'] as $field) {
            $value = $tax[$field] ?? 0;
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 30) {
                $errors['tax.'.$field] = __('core.validation.children_range');
            }
            $tax[$field] = (int) $value;
        }

        $suggested = $this->pcb->suggest($marital, $spouseWorking);
        $category = (int) ($tax['pcb_category'] ?? $suggested);
        $tax['pcb_category'] = $category;
        if ($category !== $suggested) {
            if (blank($tax['pcb_category_override_reason'] ?? null)) {
                $errors['tax.pcb_category_override_reason'] = __('core.validation.pcb_override_reason', ['suggested' => $suggested]);
            }
        } else {
            $tax['pcb_category_override_reason'] = null;
        }

        $zakat = $tax['zakat_monthly_amount'] ?? null;
        $tax['zakat_monthly_amount'] = is_numeric($zakat) ? (string) $zakat : null;

        if (! empty($tax['zakat_via_payroll'])) {
            $amount = $tax['zakat_monthly_amount'] ?? null;
            if (! is_numeric($amount) || bccomp((string) $amount, '0', 2) <= 0) {
                $errors['tax.zakat_monthly_amount'] = __('core.validation.zakat_amount');
            }
            if (($employee['religion'] ?? null) !== Religion::Islam->value) {
                $warnings[] = (string) __('core.warnings.zakat_religion');
            }
        }

        return [$tax, $errors, $warnings];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
