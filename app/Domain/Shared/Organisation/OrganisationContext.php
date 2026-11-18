<?php

declare(strict_types=1);

namespace App\Domain\Shared\Organisation;

use App\Domain\Core\Models\Organisation;

/**
 * Holds the active Organisasi for the current request, job or command.
 *
 * Fail-closed: org-scoped queries throw MissingOrganisationContext when no
 * context is set, unless the caller deliberately opens a bypass.
 */
final class OrganisationContext
{
    private static ?int $id = null;

    private static ?Organisation $organisation = null;

    private static int $bypassDepth = 0;

    public static function set(Organisation|int|null $organisation): void
    {
        if ($organisation === null) {
            self::clear();

            return;
        }

        if ($organisation instanceof Organisation) {
            self::$organisation = $organisation;
            self::$id = (int) $organisation->getKey();
        } else {
            self::$organisation = null;
            self::$id = $organisation;
        }

        setPermissionsTeamId(self::$id);
    }

    public static function clear(): void
    {
        self::$id = null;
        self::$organisation = null;
        setPermissionsTeamId(null);
    }

    public static function has(): bool
    {
        return self::$id !== null;
    }

    public static function id(): int
    {
        if (self::$id === null) {
            throw new MissingOrganisationContext;
        }

        return self::$id;
    }

    public static function idOrNull(): ?int
    {
        return self::$id;
    }

    public static function current(): Organisation
    {
        $id = self::id();

        if (self::$organisation === null || (int) self::$organisation->getKey() !== $id) {
            self::$organisation = Organisation::query()->findOrFail($id);
        }

        return self::$organisation;
    }

    public static function isBypassed(): bool
    {
        return self::$bypassDepth > 0;
    }

    /**
     * Run a callback with the organisation scope disabled (migrations, seeders,
     * deliberate cross-organisation actions).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        self::$bypassDepth++;

        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    /**
     * Run a callback inside a specific organisation and restore the previous context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Organisation|int $organisation, callable $callback): mixed
    {
        $previousId = self::$id;
        $previousOrg = self::$organisation;
        $previousBypass = self::$bypassDepth;
        self::$bypassDepth = 0;

        self::set($organisation);

        try {
            return $callback();
        } finally {
            self::$bypassDepth = $previousBypass;
            if ($previousId === null) {
                self::clear();
            } else {
                self::set($previousOrg ?? $previousId);
            }
        }
    }
}
