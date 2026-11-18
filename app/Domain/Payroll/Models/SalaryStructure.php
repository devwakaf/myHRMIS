<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Struktur Gaji (spec-04 §2.4). Section of a row is derived from its component kind.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name_ms
 * @property string|null $name_en
 * @property string|null $description
 * @property int $revision
 * @property bool $is_active
 * @property CarbonImmutable|null $last_validated_at
 * @property-read Collection<int, SalaryStructureRow> $rows
 */
class SalaryStructure extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['revision' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'is_active' => 'boolean',
            'last_validated_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && ($this->name_en ?? '') !== '' ? (string) $this->name_en : $this->name_ms;
    }

    /**
     * @return HasMany<SalaryStructureRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(SalaryStructureRow::class)->orderBy('sort_order')->orderBy('id');
    }
}
