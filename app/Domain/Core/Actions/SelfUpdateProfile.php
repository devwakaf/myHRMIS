<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Shared\Support\DomainValidation;

/**
 * Profil Saya: a Kakitangan edits only the fields HR settings allow (B1.15).
 */
final class SelfUpdateProfile
{
    public function __construct(
        private readonly UpdateEmployee $update,
        private readonly SaveEmergencyContacts $contacts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Employee $employee, array $data): void
    {
        $allowed = HrSettings::current()->selfServiceFields();
        $errors = [];

        foreach (array_keys($data) as $field) {
            if (! in_array($field, $allowed, true)) {
                $errors[$field] = __('core.validation.self_service_not_allowed');
            }
        }
        DomainValidation::throwIf($errors);

        $contacts = $data['emergency_contacts'] ?? null;
        unset($data['emergency_contacts'], $data['qualifications']);

        if ($data !== []) {
            $this->update->handle($employee, $data, includeTax: false);
        }

        if (is_array($contacts)) {
            $this->contacts->handle($employee, array_values($contacts));
        }
    }
}
