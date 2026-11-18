<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Cipta Akaun Pengguna": create (or reuse by email) a login for a Kakitangan,
 * grant the kakitangan role in this Organisasi and link the record.
 */
final class CreateEmployeeUserAccount
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * @param  array{email: string, password?: string|null, locale?: string|null}  $data
     */
    public function handle(Employee $employee, array $data): User
    {
        if ($employee->user_id !== null) {
            throw DomainValidation::withMessages(['email' => __('core.employee.already_has_user')]);
        }

        return DB::transaction(function () use ($employee, $data): User {
            $user = User::query()->where('email', strtolower($data['email']))->first();

            if ($user === null) {
                if (blank($data['password'] ?? null)) {
                    throw DomainValidation::withMessages(['password' => __('validation.required', ['attribute' => __('validation.attributes.password')])]);
                }
                $user = User::query()->create([
                    'name' => $employee->full_name,
                    'email' => strtolower($data['email']),
                    'password' => $data['password'],
                    'locale' => $data['locale'] ?? 'ms',
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif (Employee::query()->where('user_id', $user->id)->exists()) {
                throw DomainValidation::withMessages(['email' => __('core.validation.user_already_linked')]);
            }

            $employee->update(['user_id' => $user->id]);
            $this->roles->assign($user, $employee->organisation_id, PermissionMatrix::KAKITANGAN);

            return $user;
        });
    }
}
