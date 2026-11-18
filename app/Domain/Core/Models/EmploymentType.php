<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\EmploymentCategory;
use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Core\EmploymentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis Perkhidmatan (B1.6).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property EmploymentCategory $category
 * @property bool $requires_end_date
 * @property bool $is_active
 */
class EmploymentType extends Model
{
    /** @use HasFactory<EmploymentTypeFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'code', 'name', 'name_en', 'category', 'requires_end_date', 'is_active'];

    protected function casts(): array
    {
        return [
            'category' => EmploymentCategory::class,
            'requires_end_date' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
