<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by / updated_by from the authenticated user.
 *
 * @mixin Model
 */
trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        static::creating(function (Model $model): void {
            $id = Auth::id();
            if ($id !== null && $model->getAttribute('created_by') === null) {
                $model->setAttribute('created_by', $id);
            }
            if ($id !== null && $model->getAttribute('updated_by') === null) {
                $model->setAttribute('updated_by', $id);
            }
        });

        static::updating(function (Model $model): void {
            $id = Auth::id();
            if ($id !== null) {
                $model->setAttribute('updated_by', $id);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
