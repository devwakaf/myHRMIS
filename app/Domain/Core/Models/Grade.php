<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Core\GradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gred (B1.5). Higher rank = more senior.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property int $rank
 * @property Money|null $salary_min
 * @property Money|null $salary_max
 * @property bool $is_active
 */
class Grade extends Model
{
    /** @use HasFactory<GradeFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'code', 'name', 'name_en', 'rank', 'salary_min', 'salary_max', 'is_active'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'salary_min' => MoneyCast::class,
            'salary_max' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['salary_min', 'salary_max'];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
