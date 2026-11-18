<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Enums\RegistrationType;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Support\Enums\MalaysianState;
use Database\Factories\Core\OrganisationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Organisasi (B1.1). Global table: not org-scoped.
 *
 * @property int $id
 * @property string $name
 * @property string $short_code
 * @property RegistrationType $registration_type
 * @property MalaysianState $state
 * @property DailyRateBasis $daily_rate_basis
 * @property string $daily_rate_divisor
 * @property bool $is_active
 * @property bool $hrd_corp_registered
 */
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'name', 'short_code', 'registration_type', 'ssm_no', 'ssm_old_no', 'kwsp_employer_no',
        'perkeso_employer_code', 'lhdn_employer_no', 'lhdn_hq_employer_no', 'tax_identification_no',
        'hrd_corp_registered', 'hrd_corp_levy_rate', 'address_line1', 'address_line2', 'address_line3',
        'postcode', 'city', 'state', 'phone', 'email', 'website', 'logo_path', 'daily_rate_basis',
        'daily_rate_divisor', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'registration_type' => RegistrationType::class,
            'state' => MalaysianState::class,
            'daily_rate_basis' => DailyRateBasis::class,
            'hrd_corp_registered' => 'boolean',
            'is_active' => 'boolean',
            'hrd_corp_levy_rate' => 'decimal:4',
            'daily_rate_divisor' => 'decimal:2',
        ];
    }

    protected static function newFactory(): OrganisationFactory
    {
        return OrganisationFactory::new();
    }

    public function activityLogModule(): string
    {
        return 'system';
    }

    /**
     * @return HasOne<HrSettings, $this>
     */
    public function hrSettings(): HasOne
    {
        return $this->hasOne(HrSettings::class)->withoutGlobalScopes();
    }

    public function hasEmployees(): bool
    {
        return Employee::acrossOrganisations()->where('organisation_id', $this->id)->exists();
    }
}
