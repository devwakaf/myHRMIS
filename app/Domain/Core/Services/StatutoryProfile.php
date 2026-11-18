<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Lindung24Election;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Statutory facts about an employee on a date (B6), consumed by Payroll (M4).
 */
final class StatutoryProfile
{
    /**
     * @param  array<string, mixed>  $taxProfile
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly CitizenshipStatus $citizenship,
        public readonly int $ageOnDate,
        public readonly ?int $firstInsuredAge,
        public readonly bool $kwspExempt,
        public readonly bool $eisExempt,
        public readonly ?string $kwspEmployeeRateOverride,
        public readonly Lindung24Status $lindung24,
        public readonly ?CarbonImmutable $workPassExpiry,
        public readonly array $taxProfile,
    ) {}

    public static function for(Employee $employee, CarbonInterface|string $date): self
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date)->startOfDay();

        $election = Lindung24Election::query()
            ->where('employee_id', $employee->id)
            ->effectiveOn($day->startOfMonth())
            ->orderByDesc('effective_from')
            ->first();

        $tax = $employee->taxProfile;
        $taxSnapshot = $tax === null ? [] : array_merge($tax->only([
            'id', 'tin', 'pcb_category', 'spouse_working', 'spouse_disabled', 'children_basic', 'children_higher_ed',
            'children_disabled', 'children_disabled_higher_ed', 'claims_child_relief', 'zakat_via_payroll',
        ]), [
            'tax_residency' => $tax->tax_residency->value,
            'mtd_scheme' => $tax->mtd_scheme->value,
            'zakat_monthly_amount' => $tax->zakat_monthly_amount?->toDecimalString(),
        ]);

        return new self(
            employeeId: $employee->id,
            citizenship: $employee->citizenship_status,
            ageOnDate: (int) $employee->date_of_birth->diffInYears($day),
            firstInsuredAge: $employee->perkeso_first_insured_on === null
                ? null
                : (int) $employee->date_of_birth->diffInYears($employee->perkeso_first_insured_on),
            kwspExempt: $employee->kwsp_exempt,
            eisExempt: $employee->eis_exempt,
            kwspEmployeeRateOverride: $employee->getAttribute('kwsp_employee_rate_override'),
            lindung24: $election->status ?? Lindung24Status::OptedIn,
            workPassExpiry: $employee->work_pass_expiry,
            taxProfile: $taxSnapshot,
        );
    }
}
