<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Notis Privasi Lokasi decision (spec-03 §3.14). The latest row per employee decides.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $notice_version
 * @property bool $accepted
 * @property CarbonImmutable $decided_at
 * @property string|null $user_agent
 * @property CarbonImmutable|null $withdrawn_at
 */
class LocationConsent extends Model
{
    use BelongsToOrganisation;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notice_version' => 'integer',
            'accepted' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    public static function latestFor(int $employeeId): ?self
    {
        return static::query()->where('employee_id', $employeeId)->orderByDesc('id')->first();
    }

    public function isValidFor(int $noticeVersion): bool
    {
        return $this->accepted && $this->withdrawn_at === null && $this->notice_version === $noticeVersion;
    }
}
