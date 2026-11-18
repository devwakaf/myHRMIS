<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

/**
 * Result of Approvable::checkApprovalCompletion() — the completion veto (A6.6).
 */
final class ApprovalCheck
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    private function __construct(
        public readonly bool $passed,
        public readonly ?string $translationKey = null,
        public readonly array $params = [],
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function fail(string $translationKey, array $params = []): self
    {
        return new self(false, $translationKey, $params);
    }

    public function message(): string
    {
        return $this->translationKey === null
            ? ''
            : (string) __($this->translationKey, array_map(fn ($v) => (string) $v, $this->params));
    }
}
