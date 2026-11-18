<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Shared\Approval\Enums\ActionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit of the approval engine (A6.5). actor_user_id null = system.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $approval_request_id
 * @property int|null $approval_request_step_id
 * @property int|null $actor_user_id
 * @property ActionType $action
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 */
class ApprovalAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organisation_id', 'approval_request_id', 'approval_request_step_id', 'actor_user_id', 'action', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return ['action' => ActionType::class, 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
