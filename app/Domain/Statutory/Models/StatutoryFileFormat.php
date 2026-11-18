<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Statutory\Enums\FileScheme;
use App\Domain\Statutory\Enums\FormatStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Layout of a government file (spec-04 §2.11 statutory_file_formats, §6). The
 * layout JSON is our own schema: {fields: [{name, start, length, source, align,
 * pad, format}], header: {...}, options: {...}}.
 *
 * @property int $id
 * @property FileScheme $scheme
 * @property string $code
 * @property string $name
 * @property string $version
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $record_type
 * @property array<string, mixed> $layout
 * @property string|null $filename_pattern
 * @property string $line_ending
 * @property string $encoding
 * @property FormatStatus $status
 * @property string|null $source_url
 */
class StatutoryFileFormat extends Model
{
    use LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheme' => FileScheme::class,
            'status' => FormatStatus::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'layout' => 'array',
        ];
    }

    public function activityLogModule(): string
    {
        return 'statutory';
    }

    public static function effectiveFor(FileScheme $scheme, CarbonInterface $date): ?self
    {
        return self::query()->where('scheme', $scheme->value)
            ->whereDate('effective_from', '<=', $date->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->format('Y-m-d')))
            ->orderByDesc('effective_from')->first();
    }

    public function isVerified(): bool
    {
        return $this->status === FormatStatus::Verified;
    }
}
