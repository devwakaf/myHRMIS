<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

use App\Domain\Shared\Approval\Enums\AttributeType;

/**
 * One registered request type (A6.2).
 */
final class ApprovalTypeDefinition
{
    /**
     * @param  class-string|null  $modelClass  Approvable model; null until the owning module is built
     * @param  array<string, AttributeType>  $attributes  condition catalogue (without the common applicant.* keys)
     * @param  array<string, list<string>>  $enumOptions  allowed values for enum attributes
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $modelClass,
        public readonly string $labelKey,
        public readonly string $approveAnyPermission,
        public readonly string $viewAllPermission,
        public readonly array $attributes = [],
        public readonly array $enumOptions = [],
    ) {}

    /**
     * @param  class-string  $modelClass
     */
    public function withModel(string $modelClass): self
    {
        return new self($this->key, $modelClass, $this->labelKey, $this->approveAnyPermission,
            $this->viewAllPermission, $this->attributes, $this->enumOptions);
    }

    public function label(): string
    {
        return (string) __($this->labelKey);
    }
}
