<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support\Enums;

/**
 * Backed enums whose labels come from translation files.
 * Each enum defines translationKey(): the dotted prefix, e.g. "core.enums.gender".
 */
trait TranslatableEnum
{
    abstract public static function translationKey(): string;

    public function label(): string
    {
        return (string) __(static::translationKey().'.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => (string) $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => (string) $case->value, self::cases());
    }
}
