<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmploymentCategory;
use App\Domain\Shared\Approval\Enums\AttributeType;
use App\Domain\Shared\Approval\Exceptions\UnknownApprovalType;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Registry of approval request types (A6.2). Modules register their types from
 * their service providers; the morph alias of an Approvable model is its key.
 */
final class ApprovalTypeRegistry
{
    /** @var array<string, ApprovalTypeDefinition> */
    private array $types = [];

    public function register(ApprovalTypeDefinition $definition): void
    {
        $this->types[$definition->key] = $definition;

        if ($definition->modelClass !== null) {
            Relation::morphMap([$definition->key => $definition->modelClass]);
        }
    }

    /**
     * Attach the Approvable model once the owning module exists (M2/M3).
     *
     * @param  class-string  $modelClass
     */
    public function bindModel(string $key, string $modelClass): void
    {
        $this->register($this->get($key)->withModel($modelClass));
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): ApprovalTypeDefinition
    {
        return $this->types[$key] ?? throw new UnknownApprovalType('approval.errors.unknown_type', ['type' => $key]);
    }

    /**
     * @return array<string, ApprovalTypeDefinition>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Types that appear in configuration screens (all registered, sorted by key order of registration).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    /**
     * Attributes every type gets (A6.2).
     *
     * @return array<string, AttributeType>
     */
    public static function commonAttributes(): array
    {
        return [
            'applicant.department_id' => AttributeType::Number,
            'applicant.branch_id' => AttributeType::Number,
            'applicant.grade_rank' => AttributeType::Number,
            'applicant.employment_type_category' => AttributeType::Enum,
            'applicant.citizenship_status' => AttributeType::Enum,
        ];
    }

    /**
     * Full condition catalogue for a type: its own attributes plus the common ones.
     *
     * @return array<string, AttributeType>
     */
    public function catalogue(string $key): array
    {
        return array_merge($this->get($key)->attributes, self::commonAttributes());
    }

    /**
     * @return array<string, list<string>>
     */
    public function enumOptions(string $key): array
    {
        return array_merge($this->get($key)->enumOptions, [
            'applicant.employment_type_category' => EmploymentCategory::values(),
            'applicant.citizenship_status' => CitizenshipStatus::values(),
        ]);
    }
}
