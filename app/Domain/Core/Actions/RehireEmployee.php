<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Rehire (B2, user-confirmed): a new Kakitangan record linked through
 * previous_employee_id. Personal, statutory, tax and bank data are copied; service
 * restarts (continuous_service_from null) unless HR sets it. For a same-org,
 * same-calendar-year rehire HR must review the YTD tax opening balances that
 * Payroll (M4) pre-fills — flagged here as `needs_ytd_review`.
 */
final class RehireEmployee
{
    public function __construct(
        private readonly CreateEmployee $create,
        private readonly UpdateEmployee $update,
        private readonly RoleService $roles,
    ) {}

    /**
     * @param  array<string, mixed>  $data  new employment fields (date_of_joining, department_id, …)
     * @return array{employee: Employee, warnings: list<string>, needs_ytd_review: bool}
     */
    public function handle(Employee $previous, array $data): array
    {
        if ($previous->status !== EmployeeStatus::Left) {
            throw DomainValidation::withMessages(['employee' => __('core.rehire.not_left')]);
        }

        return DB::transaction(function () use ($previous, $data): array {
            $base = $this->update->currentValues($previous);
            unset($base['employee_no'], $base['user_id'], $base['resignation_date'], $base['relieving_date'],
                $base['confirmed_on'], $base['probation_end_date'], $base['continuous_service_from']);

            $payload = array_merge($base, $data);
            $tax = $previous->taxProfile?->attributesToArray() ?? [];
            $tax['zakat_monthly_amount'] = $previous->taxProfile?->zakat_monthly_amount?->toDecimalString();
            $payload['tax'] = array_merge(Arr::only($tax, CreateEmployee::taxFields()), $data['tax'] ?? []);
            $payload['emergency_contacts'] = $previous->emergencyContacts->map(fn ($c) => [
                'name' => $c->name, 'relationship' => $c->relationship->value, 'phone' => $c->phone,
                'alt_phone' => $c->alt_phone, 'address' => $c->address, 'is_primary' => $c->is_primary,
            ])->all();

            $userId = $previous->user_id;
            if ($userId !== null) {
                $previous->update(['user_id' => null]);
            }

            $result = $this->create->handle(
                $payload,
                ['previous_employee_id' => $previous->id, 'user_id' => $userId],
                WorkHistorySource::Rehire,
            );

            /** @var Employee $employee */
            $employee = $result['employee'];
            if ($userId !== null) {
                $user = User::query()->find($userId);
                if ($user !== null) {
                    $user->update(['is_active' => true]);
                    $this->roles->assign($user, $employee->organisation_id, PermissionMatrix::KAKITANGAN);
                }
            }

            $sameYear = $previous->relieving_date !== null
                && $previous->relieving_date->year === CarbonImmutable::parse($employee->date_of_joining)->year;

            return [
                'employee' => $employee,
                'warnings' => $result['warnings'],
                'needs_ytd_review' => $sameYear && $previous->organisation_id === $employee->organisation_id,
            ];
        });
    }
}
