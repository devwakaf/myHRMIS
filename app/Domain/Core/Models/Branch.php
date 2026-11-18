<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Support\Enums\MalaysianState;
use Database\Factories\Core\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cawangan (B1.2).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property MalaysianState $state
 * @property bool $is_active
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'code', 'name', 'name_en', 'address_line1', 'address_line2', 'postcode',
        'city', 'state', 'phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['state' => MalaysianState::class, 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
