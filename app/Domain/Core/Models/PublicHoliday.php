<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Core\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Global public holiday catalogue (Super Admin). scope = 'federal' or a state code.
 * Dates are populated from the official notice each year (UNVERIFIED-STAT).
 *
 * @property int $id
 * @property int $year
 * @property CarbonImmutable $date
 * @property string $name_ms
 * @property string|null $name_en
 * @property string $scope
 * @property bool $is_mandatory
 */
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    protected $fillable = ['year', 'date', 'name_ms', 'name_en', 'scope', 'is_mandatory', 'source_ref'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'is_mandatory' => 'boolean', 'year' => 'integer'];
    }

    public function displayName(): string
    {
        return App::getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ms;
    }
}
