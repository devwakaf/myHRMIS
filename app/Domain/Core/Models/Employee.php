<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\BloodType;
use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\IdentityType;
use App\Domain\Core\Enums\MaritalStatus;
use App\Domain\Core\Enums\Race;
use App\Domain\Core\Enums\Religion;
use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Enums\SeparationReason;
use App\Domain\Core\Enums\WorkPassType;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Support\Enums\Gender;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Core\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kakitangan (B1.7).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $employee_no
 * @property string $full_name
 * @property string|null $preferred_name
 * @property IdentityType $identity_type
 * @property string|null $ic_no
 * @property string|null $old_ic_no
 * @property string|null $passport_no
 * @property string|null $passport_country
 * @property CarbonImmutable|null $passport_expiry
 * @property CarbonImmutable $date_of_birth
 * @property Gender $gender
 * @property CitizenshipStatus $citizenship_status
 * @property string $nationality
 * @property Race|null $race
 * @property Religion|null $religion
 * @property MaritalStatus $marital_status
 * @property BloodType|null $blood_type
 * @property bool $is_disabled
 * @property string $mobile_phone
 * @property string|null $personal_email
 * @property string|null $work_email
 * @property MalaysianState|null $state
 * @property EmployeeStatus $status
 * @property CarbonImmutable $date_of_joining
 * @property CarbonImmutable|null $continuous_service_from
 * @property int|null $branch_id
 * @property int $department_id
 * @property int $designation_id
 * @property int|null $grade_id
 * @property int $employment_type_id
 * @property int|null $reports_to_id
 * @property CarbonImmutable|null $probation_end_date
 * @property CarbonImmutable|null $confirmed_on
 * @property CarbonImmutable|null $contract_end_date
 * @property CarbonImmutable|null $retirement_date
 * @property int|null $user_id
 * @property int|null $previous_employee_id
 * @property CarbonImmutable|null $resignation_date
 * @property CarbonImmutable|null $relieving_date
 * @property SeparationReason|null $separation_reason
 * @property bool $kwsp_exempt
 * @property bool $eis_exempt
 * @property WorkPassType|null $work_pass_type
 * @property CarbonImmutable|null $work_pass_expiry
 * @property CarbonImmutable|null $perkeso_first_insured_on
 * @property SalaryPaymentMode $salary_payment_mode
 * @property-read Branch|null $branch
 * @property-read Department $department
 * @property-read Designation $designation
 * @property-read Grade|null $grade
 * @property-read EmploymentType $employmentType
 * @property-read Employee|null $reportsTo
 * @property-read User|null $user
 * @property-read EmployeeTaxProfile|null $taxProfile
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToOrganisation, HasFactory, LogsBusinessActivity, TracksAuthor;

    /** Columns readable only with core.employee.view-sensitive (or on one's own record). */
    public const SENSITIVE = [
        'ic_no', 'old_ic_no', 'passport_no', 'passport_country', 'passport_expiry', 'other_id_no',
        'race', 'religion', 'is_disabled', 'blood_type',
        'kwsp_no', 'kwsp_exempt', 'kwsp_exempt_reason', 'kwsp_employee_rate_override', 'perkeso_no',
        'perkeso_first_insured_on', 'eis_exempt', 'work_pass_type', 'work_pass_no', 'work_pass_expiry',
        'salary_payment_mode', 'bank_id', 'bank_account_no', 'bank_account_name',
    ];

    /** Organisation-assignment fields changed only through movements or a correction (B3.6). */
    public const ASSIGNMENT_FIELDS = [
        'branch_id', 'department_id', 'designation_id', 'grade_id', 'employment_type_id', 'reports_to_id',
    ];

    /** Columns editable through the HR employee form (status/separation/user link have their own actions). */
    public const FORM_FIELDS = [
        'employee_no', 'full_name', 'preferred_name', 'identity_type', 'ic_no', 'old_ic_no', 'passport_no',
        'passport_country', 'passport_expiry', 'other_id_no', 'date_of_birth', 'gender', 'citizenship_status',
        'nationality', 'race', 'religion', 'marital_status', 'blood_type', 'is_disabled',
        'mobile_phone', 'personal_email', 'work_email', 'address_line1', 'address_line2', 'address_line3',
        'postcode', 'city', 'state', 'country', 'permanent_same_as_current', 'permanent_address_line1',
        'permanent_address_line2', 'permanent_address_line3', 'permanent_postcode', 'permanent_city',
        'permanent_state', 'permanent_country',
        'date_of_joining', 'continuous_service_from', 'branch_id', 'department_id', 'designation_id', 'grade_id',
        'employment_type_id', 'reports_to_id', 'probation_end_date', 'confirmed_on', 'contract_end_date',
        'notice_period_days',
        'kwsp_no', 'kwsp_exempt', 'kwsp_exempt_reason', 'kwsp_employee_rate_override', 'perkeso_no',
        'perkeso_first_insured_on', 'eis_exempt', 'work_pass_type', 'work_pass_no', 'work_pass_expiry',
        'salary_payment_mode', 'bank_id', 'bank_account_no', 'bank_account_name',
    ];

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'identity_type' => IdentityType::class,
            'gender' => Gender::class,
            'citizenship_status' => CitizenshipStatus::class,
            'race' => Race::class,
            'religion' => Religion::class,
            'marital_status' => MaritalStatus::class,
            'blood_type' => BloodType::class,
            'state' => MalaysianState::class,
            'permanent_state' => MalaysianState::class,
            'status' => EmployeeStatus::class,
            'separation_reason' => SeparationReason::class,
            'work_pass_type' => WorkPassType::class,
            'salary_payment_mode' => SalaryPaymentMode::class,
            'passport_expiry' => 'immutable_date',
            'date_of_birth' => 'immutable_date',
            'date_of_joining' => 'immutable_date',
            'continuous_service_from' => 'immutable_date',
            'probation_end_date' => 'immutable_date',
            'confirmed_on' => 'immutable_date',
            'contract_end_date' => 'immutable_date',
            'retirement_date' => 'immutable_date',
            'resignation_date' => 'immutable_date',
            'relieving_date' => 'immutable_date',
            'perkeso_first_insured_on' => 'immutable_date',
            'work_pass_expiry' => 'immutable_date',
            'is_disabled' => 'boolean',
            'permanent_same_as_current' => 'boolean',
            'kwsp_exempt' => 'boolean',
            'eis_exempt' => 'boolean',
            'eligible_for_rehire' => 'boolean',
            'kwsp_employee_rate_override' => 'decimal:4',
        ];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['ic_no', 'old_ic_no', 'passport_no', 'other_id_no', 'bank_account_no', 'bank_account_name',
            'kwsp_no', 'perkeso_no', 'work_pass_no', 'kwsp_employee_rate_override'];
    }

    public function displayName(): string
    {
        return $this->preferred_name ?: $this->full_name;
    }

    /** Start of continuous service (B1.7): continuous_service_from, else date_of_joining. */
    public function serviceStart(): CarbonImmutable
    {
        return $this->continuous_service_from ?? $this->date_of_joining;
    }

    public function ageOn(CarbonInterface $date): int
    {
        return (int) $this->date_of_birth->diffInYears($date);
    }

    public function isLeft(): bool
    {
        return $this->status === EmployeeStatus::Left;
    }

    public function isForeign(): bool
    {
        return in_array($this->citizenship_status, [CitizenshipStatus::Foreign, CitizenshipStatus::ForeignPre1998], true);
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeNotLeft(Builder $query): Builder
    {
        return $query->where('status', '!=', EmployeeStatus::Left->value);
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Active->value);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * @return BelongsTo<EmploymentType, $this>
     */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function previousEmployee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_employee_id')->withoutGlobalScopes();
    }

    /**
     * @return HasOne<EmployeeTaxProfile, $this>
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(EmployeeTaxProfile::class);
    }

    /**
     * @return HasMany<EmployeeEmergencyContact, $this>
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * @return HasMany<EmployeeWorkHistory, $this>
     */
    public function workHistory(): HasMany
    {
        return $this->hasMany(EmployeeWorkHistory::class)->orderBy('from_date');
    }

    /**
     * @return HasMany<EmployeePreviousEmployment, $this>
     */
    public function previousEmployments(): HasMany
    {
        return $this->hasMany(EmployeePreviousEmployment::class)->orderByDesc('from_date');
    }

    /**
     * @return HasMany<EmployeeQualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class)->orderByDesc('year_completed');
    }

    /**
     * @return HasMany<EmployeeApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(EmployeeApprover::class);
    }

    /**
     * @return HasMany<EmployeeMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(EmployeeMovement::class)->orderByDesc('effective_date');
    }

    /**
     * @return HasMany<Lindung24Election, $this>
     */
    public function lindung24Elections(): HasMany
    {
        return $this->hasMany(Lindung24Election::class)->orderBy('effective_from');
    }
}
