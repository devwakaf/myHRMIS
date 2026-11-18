<?php

namespace App\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Organisation\OrganisationContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property bool $is_super_admin
 * @property bool $is_active
 * @property int|null $current_organisation_id
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsBusinessActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'is_active',
        'current_organisation_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'ms',
        'is_active' => true,
        'is_super_admin' => false,
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'system';
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['two_factor_secret', 'two_factor_recovery_codes'];
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, config('myhrmis.locales', ['ms', 'en']), true) ? $this->locale : 'ms';
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function currentOrganisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'current_organisation_id');
    }

    /**
     * The employee record linked to this user in the active (or given) organisation.
     */
    public function employee(?int $organisationId = null): ?Employee
    {
        $organisationId ??= OrganisationContext::idOrNull();
        if ($organisationId === null) {
            return null;
        }

        return Employee::acrossOrganisations()
            ->where('organisation_id', $organisationId)
            ->where('user_id', $this->id)
            ->first();
    }

    /**
     * Drop cached spatie relations (after switching team context).
     */
    public function forgetCachedRoles(): static
    {
        $this->unsetRelation('roles');
        $this->unsetRelation('permissions');

        return $this;
    }
}
