<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Masters with `name` (BM, required) and `name_en` (optional): show name_en
 * when the locale is English and it is filled, else name (A3).
 *
 * @mixin Model
 */
trait HasLocalisedName
{
    public function displayName(?string $locale = null): string
    {
        $locale ??= App::getLocale();
        $en = $this->getAttribute('name_en');

        if ($locale === 'en' && is_string($en) && trim($en) !== '') {
            return $en;
        }

        return (string) $this->getAttribute('name');
    }
}
