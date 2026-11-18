<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\EmployeeNoMode;
use App\Domain\Core\Enums\HolidayNoticeFrequency;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tetapan HR (B1.15), one per Organisasi.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $employee_no_format
 * @property EmployeeNoMode $employee_no_mode
 * @property int $retirement_age
 * @property int $default_probation_months
 * @property string $standard_daily_hours
 * @property string $standard_weekly_hours
 * @property int $min_employment_age
 * @property bool $birthday_digest_to_hr
 * @property bool $birthday_digest_to_colleagues
 * @property bool $anniversary_digest
 * @property int $remind_probation_days
 * @property int $remind_contract_days
 * @property int $remind_work_pass_days
 * @property int $remind_document_days
 * @property int $remind_retirement_days
 * @property HolidayNoticeFrequency $holiday_notice
 * @property list<string>|null $self_service_editable
 */
class HrSettings extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $table = 'hr_settings';

    /** Fields Kakitangan may edit on their own profile by default. */
    public const DEFAULT_SELF_SERVICE = [
        'mobile_phone', 'personal_email', 'address_line1', 'address_line2', 'address_line3',
        'postcode', 'city', 'state', 'emergency_contacts',
    ];

    /** Every field self-service may ever be allowed to edit (tax fields excluded, rev-1 Q14). */
    public const SELF_SERVICE_CANDIDATES = [
        'preferred_name', 'mobile_phone', 'personal_email', 'address_line1', 'address_line2', 'address_line3',
        'postcode', 'city', 'state', 'country', 'permanent_same_as_current', 'permanent_address_line1',
        'permanent_address_line2', 'permanent_address_line3', 'permanent_postcode', 'permanent_city',
        'permanent_state', 'permanent_country', 'blood_type', 'emergency_contacts', 'qualifications',
    ];

    protected $fillable = [
        'organisation_id', 'employee_no_format', 'employee_no_mode', 'retirement_age', 'default_probation_months',
        'standard_daily_hours', 'standard_weekly_hours', 'min_employment_age', 'birthday_digest_to_hr',
        'birthday_digest_to_colleagues', 'anniversary_digest', 'remind_probation_days', 'remind_contract_days',
        'remind_work_pass_days', 'remind_document_days', 'remind_retirement_days', 'holiday_notice',
        'self_service_editable',
    ];

    protected $attributes = [
        'employee_no_format' => '{CODE}-{#####}',
        'employee_no_mode' => 'auto',
        'retirement_age' => 60,
        'default_probation_months' => 3,
        'standard_daily_hours' => '8.00',
        'standard_weekly_hours' => '45.00',
        'min_employment_age' => 14,
        'birthday_digest_to_hr' => true,
        'birthday_digest_to_colleagues' => false,
        'anniversary_digest' => true,
        'remind_probation_days' => 14,
        'remind_contract_days' => 30,
        'remind_work_pass_days' => 90,
        'remind_document_days' => 30,
        'remind_retirement_days' => 180,
        'holiday_notice' => 'off',
    ];

    protected function casts(): array
    {
        return [
            'employee_no_mode' => EmployeeNoMode::class,
            'holiday_notice' => HolidayNoticeFrequency::class,
            'retirement_age' => 'integer',
            'default_probation_months' => 'integer',
            'min_employment_age' => 'integer',
            'standard_daily_hours' => 'decimal:2',
            'standard_weekly_hours' => 'decimal:2',
            'birthday_digest_to_hr' => 'boolean',
            'birthday_digest_to_colleagues' => 'boolean',
            'anniversary_digest' => 'boolean',
            'remind_probation_days' => 'integer',
            'remind_contract_days' => 'integer',
            'remind_work_pass_days' => 'integer',
            'remind_document_days' => 'integer',
            'remind_retirement_days' => 'integer',
            'self_service_editable' => 'array',
        ];
    }

    /**
     * Settings of the active organisation (created on demand with defaults).
     */
    public static function current(): self
    {
        return static::forOrganisation(OrganisationContext::id());
    }

    public static function forOrganisation(int $organisationId): self
    {
        return OrganisationContext::bypass(fn () => static::query()->firstOrCreate(
            ['organisation_id' => $organisationId],
            ['self_service_editable' => self::DEFAULT_SELF_SERVICE],
        ));
    }

    /**
     * @return list<string>
     */
    public function selfServiceFields(): array
    {
        return $this->self_service_editable ?? self::DEFAULT_SELF_SERVICE;
    }
}
