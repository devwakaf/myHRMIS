<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

use App\Domain\Payroll\Formula\Ast\Call;
use App\Domain\Payroll\Formula\Ast\Identifier;
use App\Domain\Payroll\Formula\Ast\Node;

/**
 * Identifiers a formula reads: `direct` (plain references) and `full` (inside FULL()).
 */
final class References
{
    /**
     * @param  list<string>  $direct
     * @param  list<string>  $full
     */
    public function __construct(
        public readonly array $direct,
        public readonly array $full,
    ) {}

    public static function of(?Node $node): self
    {
        $direct = [];
        $full = [];
        if ($node !== null) {
            self::collect($node, $direct, $full);
        }

        return new self(array_values(array_unique($direct)), array_values(array_unique($full)));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([...$this->direct, ...$this->full]));
    }

    /**
     * @param  list<string>  $direct
     * @param  list<string>  $full
     */
    private static function collect(Node $node, array &$direct, array &$full): void
    {
        if ($node instanceof Call && $node->name === 'FULL' && ($node->args[0] ?? null) instanceof Identifier) {
            $full[] = $node->args[0]->name;

            return;
        }
        if ($node instanceof Identifier) {
            $direct[] = $node->name;

            return;
        }
        foreach ($node->children() as $child) {
            self::collect($child, $direct, $full);
        }
    }
}
