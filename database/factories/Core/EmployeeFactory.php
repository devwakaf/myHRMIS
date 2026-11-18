<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeTaxProfile;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Support\Enums\Gender;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Citizen Kakitangan in the active organisation with a fictional, format-valid
 * MyKad. Masters are reused from the organisation when present. After creating,
 * a tax profile and the hire work-history row are added (like CreateEmployee).
 *
 * States: left(), foreigner(), onProbation(), withUser().
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        $gender = fake()->randomElement([Gender::Male, Gender::Female]);
        $dob = CarbonImmutable::instance(fake()->dateTimeBetween('-55 years', '-22 years'))->startOfDay();
        $joining = CarbonImmutable::instance(fake()->dateTimeBetween('-8 years', '-3 months'))->startOfDay();

        return [
            'employee_no' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => ($gender === Gender::Male ? fake()->firstNameMale() : fake()->firstNameFemale()).' '.fake()->lastName(),
            'identity_type' => 'mykad',
            'ic_no' => FakeMyKad::make($dob, $gender, fake()->unique()->numberBetween(1, 99999)),
            'date_of_birth' => $dob->toDateString(),
            'gender' => $gender->value,
            'citizenship_status' => 'citizen',
            'nationality' => 'MY',
            'marital_status' => 'single',
            'mobile_phone' => fake()->numerify('01#-#######'),
            'personal_email' => fake()->unique()->safeEmail(),
            'state' => 'PHG',
            'country' => 'MY',
            'status' => 'active',
            'date_of_joining' => $joining->toDateString(),
            'department_id' => fn () => Department::query()->whereNotNull('parent_id')->value('id') ?? Department::factory()->create()->id,
            'designation_id' => fn () => Designation::query()->value('id') ?? Designation::factory()->create()->id,
            'employment_type_id' => fn () => EmploymentType::query()->where('requires_end_date', false)->value('id') ?? EmploymentType::factory()->create()->id,
            'retirement_date' => $dob->addYears(60)->toDateString(),
            'salary_payment_mode' => 'bank_transfer',
            'bank_id' => fn () => Bank::query()->value('id') ?? Bank::factory()->create()->id,
            'bank_account_no' => fake()->numerify('############'),
            'bank_account_name' => fake()->name(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Employee $employee): void {
            if (! EmployeeTaxProfile::query()->where('employee_id', $employee->id)->exists()) {
                EmployeeTaxProfile::query()->create(['employee_id' => $employee->id]);
            }
            if (! $employee->workHistory()->exists()) {
                app(WorkHistoryRecorder::class)->open($employee, $employee->date_of_joining, WorkHistorySource::Hire);
            }
        });
    }

    public function left(?string $relievingDate = null): static
    {
        return $this->state(fn () => [
            'status' => 'left',
            'relieving_date' => $relievingDate ?? CarbonImmutable::today()->subMonth()->toDateString(),
            'separation_reason' => 'resignation',
        ]);
    }

    public function foreigner(): static
    {
        return $this->state(fn () => [
            'identity_type' => 'passport',
            'ic_no' => null,
            'citizenship_status' => 'foreign',
            'nationality' => 'ID',
            'passport_no' => strtoupper(fake()->unique()->bothify('?#######')),
            'passport_country' => 'ID',
            'passport_expiry' => CarbonImmutable::today()->addYears(3)->toDateString(),
            'work_pass_type' => 'temporary_employment_pass',
            'work_pass_no' => strtoupper(fake()->bothify('PLKS#######')),
            'work_pass_expiry' => CarbonImmutable::today()->addYear()->toDateString(),
        ]);
    }

    public function onProbation(): static
    {
        return $this->state(fn () => [
            'date_of_joining' => CarbonImmutable::today()->subMonths(2)->toDateString(),
            'probation_end_date' => CarbonImmutable::today()->addMonth()->toDateString(),
            'confirmed_on' => null,
        ]);
    }
}
