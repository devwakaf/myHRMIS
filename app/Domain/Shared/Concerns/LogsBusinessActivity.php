<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Activity log on business models (A5.7): only dirty attributes, log name = module.
 * Attributes listed in sensitiveAttributes() are recorded as "changed" without values.
 *
 * @mixin Model
 */
trait LogsBusinessActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogModule())
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'created_by', 'updated_by', 'remember_token', 'password'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function activityLogModule(): string
    {
        return 'core';
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return [];
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $sensitive = $this->sensitiveAttributes();
        if ($sensitive === []) {
            return;
        }

        $properties = $activity->properties->toArray();
        foreach (['attributes', 'old'] as $bucket) {
            if (! isset($properties[$bucket]) || ! is_array($properties[$bucket])) {
                continue;
            }
            foreach ($sensitive as $attribute) {
                if (array_key_exists($attribute, $properties[$bucket])) {
                    $properties[$bucket][$attribute] = 'changed';
                }
            }
        }

        $activity->properties = collect($properties);
    }
}
