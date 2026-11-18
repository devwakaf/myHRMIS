<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Resources;

use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Option lists for selects ({value, label}) built from org-scoped masters and enums.
 */
final class Lookup
{
    /**
     * @param  class-string<Model>  $class
     * @return list<array{value: int, label: string, is_active: bool}>
     */
    public static function masters(string $class, bool $onlyActive = false): array
    {
        /** @var Builder<Model> $query */
        $query = $class::query();
        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get()->map(fn ($m) => [
            'value' => (int) $m->getKey(),
            'label' => method_exists($m, 'displayName') ? $m->displayName() : (string) $m->getAttribute('name'),
            'is_active' => (bool) ($m->getAttribute('is_active') ?? true),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function employeeForm(): array
    {
        return [
            'branches' => self::masters(Branch::class),
            'departments' => Department::query()->orderBy('path')->get()->map(fn (Department $d) => [
                'value' => $d->id,
                'label' => str_repeat('— ', $d->depth).$d->displayName(),
                'is_active' => $d->is_active,
            ])->values()->all(),
            'designations' => self::masters(Designation::class),
            'grades' => self::masters(Grade::class),
            'employment_types' => EmploymentType::query()->orderBy('name')->get()->map(fn (EmploymentType $t) => [
                'value' => $t->id,
                'label' => $t->displayName(),
                'is_active' => $t->is_active,
                'requires_end_date' => $t->requires_end_date,
            ])->values()->all(),
            'banks' => Bank::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Bank $b) => ['value' => $b->id, 'label' => $b->name, 'is_active' => true])->values()->all(),
            'managers' => self::employees(),
            'users' => self::members(),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function employees(bool $activeOnly = true): array
    {
        return Employee::query()
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_no'])
            ->map(fn (Employee $e) => ['value' => $e->id, 'label' => $e->full_name.' ('.$e->employee_no.')'])
            ->values()->all();
    }

    /**
     * Active users who are members of the active organisation.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function members(): array
    {
        $ids = app(OrganisationMembership::class)->memberUserIds(OrganisationContext::id());

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name.' <'.$u->email.'>'])
            ->values()->all();
    }

    /**
     * @return list<array{value: int, label: string, is_active: bool}>
     */
    public static function holidayLists(): array
    {
        return self::masters(HolidayList::class);
    }

    /**
     * @return list<array{value: int, label: string, is_active: bool}>
     */
    public static function documentTypes(): array
    {
        return self::masters(DocumentType::class, true);
    }
}
