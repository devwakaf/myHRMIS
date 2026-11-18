<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lifecycle documents (A5): status transitions, stamping, immutability once
 * not draft, and the single documented lock bypass.
 *
 * The using model must cast `status` to a LifecycleStatus enum.
 *
 * @mixin Model
 */
trait HasLifecycle
{
    /** @var array<class-string, list<string>> */
    private static array $activeLockBypasses = [];

    /**
     * Columns that may always change, whatever the status.
     *
     * @return list<string>
     */
    public static function lifecycleColumns(): array
    {
        return [
            'status', 'submitted_at', 'submitted_by', 'cancelled_at', 'cancelled_by',
            'cancel_reason', 'updated_at', 'updated_by',
        ];
    }

    /**
     * Module-owned system columns that may change after submit (e.g. applied_at).
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return [];
    }

    /**
     * Named bypasses this model accepts and the attributes each one may touch.
     *
     * @return array<string, list<string>>
     */
    public function lockBypassFields(): array
    {
        return [];
    }

    public static function bootHasLifecycle(): void
    {
        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            $model->guardLockedChanges();
        });

        static::deleting(function (Model $model): void {
            /** @var Model&self $model */
            $status = $model->getOriginal('status') ?? $model->getAttribute('status');
            if ($status instanceof LifecycleStatus && ! $status->isDraft()) {
                throw DocumentLocked::notDeletable();
            }
        });
    }

    /**
     * Run a callback during which the named bypass is open for this model class.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withLockBypass(string $name, callable $callback): mixed
    {
        $previous = self::$activeLockBypasses[static::class] ?? null;
        self::$activeLockBypasses[static::class] = array_values(array_unique(array_merge($previous ?? [], [$name])));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset(self::$activeLockBypasses[static::class]);
            } else {
                self::$activeLockBypasses[static::class] = $previous;
            }
        }
    }

    public function lifecycleStatus(): LifecycleStatus
    {
        $status = $this->getAttribute('status');

        if (! $status instanceof LifecycleStatus) {
            throw new \LogicException(static::class.' must cast status to a LifecycleStatus enum.');
        }

        return $status;
    }

    public function isDraft(): bool
    {
        return $this->lifecycleStatus()->isDraft();
    }

    protected function guardLockedChanges(): void
    {
        if (! $this->exists) {
            return;
        }

        $original = $this->getOriginal('status');
        if (! $original instanceof LifecycleStatus || $original->isDraft()) {
            return;
        }

        $allowed = array_merge(static::lifecycleColumns(), $this->lifecycleMutableAttributes());
        $dirty = array_keys($this->getDirty());
        $blocked = array_values(array_diff($dirty, $allowed));

        if ($blocked === []) {
            return;
        }

        $bypassFields = [];
        $openBypasses = self::$activeLockBypasses[static::class] ?? [];
        foreach ($openBypasses as $name) {
            $bypassFields = array_merge($bypassFields, $this->lockBypassFields()[$name] ?? []);
        }

        $stillBlocked = array_values(array_diff($blocked, $bypassFields));
        if ($stillBlocked !== []) {
            throw DocumentLocked::forAttributes($stillBlocked);
        }

        $changes = [];
        foreach ($blocked as $attribute) {
            $changes[$attribute] = ['old' => $this->getRawOriginal($attribute), 'new' => $this->getAttributes()[$attribute] ?? null];
        }

        activity('lifecycle')
            ->performedOn($this)
            ->event('lock_bypass')
            ->withProperties(['bypass' => $openBypasses, 'changes' => $changes])
            ->log('lock_bypass');
    }

    /**
     * Move the document to another status, stamping actor columns and logging it.
     * Must be called inside the module Action's transaction.
     */
    public function transitionTo(LifecycleStatus $to, ?User $actor, ?string $reason = null): static
    {
        $from = $this->lifecycleStatus();

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw InvalidTransition::between($from, $to);
        }

        $isCancel = $to->value === 'cancelled';
        if ($isCancel && trim((string) $reason) === '') {
            throw DomainValidation::withMessages(['cancel_reason' => __('common.errors.cancel_reason_required')]);
        }

        $this->setAttribute('status', $to);
        $now = now();

        if (in_array($to->value, ['submitted', 'pending'], true)) {
            $this->setAttribute('submitted_at', $now);
            $this->setAttribute('submitted_by', $actor?->getKey());
        }

        if ($isCancel) {
            $this->setAttribute('cancelled_at', $now);
            $this->setAttribute('cancelled_by', $actor?->getKey());
            $this->setAttribute('cancel_reason', $reason);
        }

        $this->save();

        activity(static::activityLogName())
            ->performedOn($this)
            ->causedBy($actor)
            ->event('transition')
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason])
            ->log('transition');

        DocumentTransitioned::dispatch($this, $from, $to, $actor?->getKey(), $reason);

        return $this;
    }

    public static function activityLogName(): string
    {
        return 'default';
    }

    /**
     * "Batal & Salin" helper: a new unsaved draft prefilled from this document.
     */
    public function duplicateAsDraft(): static
    {
        $except = array_merge(
            static::lifecycleColumns(),
            $this->lifecycleMutableAttributes(),
            ['number', 'created_at', 'created_by', 'amended_from_id'],
        );

        /** @var static $copy */
        $copy = $this->replicate($except);
        $copy->setAttribute('status', $this->lifecycleStatus()::from('draft'));
        $copy->setAttribute('amended_from_id', $this->getKey());

        return $copy;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
