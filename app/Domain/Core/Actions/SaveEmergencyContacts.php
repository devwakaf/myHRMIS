<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmergencyRelationship;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Facades\DB;

/**
 * Replace an employee's emergency contacts (B1.11): exactly one primary when any exist.
 */
final class SaveEmergencyContacts
{
    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function handle(Employee $employee, array $contacts): void
    {
        $errors = [];
        $primaries = 0;

        foreach (array_values($contacts) as $i => $contact) {
            if (blank($contact['name'] ?? null)) {
                $errors["emergency_contacts.$i.name"] = __('validation.required', ['attribute' => __('validation.attributes.name')]);
            }
            if (blank($contact['phone'] ?? null)) {
                $errors["emergency_contacts.$i.phone"] = __('validation.required', ['attribute' => __('validation.attributes.phone')]);
            }
            if (EmergencyRelationship::tryFrom((string) ($contact['relationship'] ?? '')) === null) {
                $errors["emergency_contacts.$i.relationship"] = __('validation.in', ['attribute' => __('validation.attributes.relationship')]);
            }
            if (filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $primaries++;
            }
        }

        if ($contacts !== [] && $primaries !== 1) {
            $errors['emergency_contacts'] = __('core.validation.one_primary_contact');
        }

        DomainValidation::throwIf($errors);

        DB::transaction(function () use ($employee, $contacts): void {
            $employee->emergencyContacts()->delete();
            foreach (array_values($contacts) as $i => $contact) {
                $employee->emergencyContacts()->create([
                    'name' => $contact['name'],
                    'relationship' => $contact['relationship'],
                    'phone' => $contact['phone'],
                    'alt_phone' => $contact['alt_phone'] ?? null,
                    'address' => $contact['address'] ?? null,
                    'is_primary' => filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'sort_order' => $i,
                ]);
            }
        });
    }
}
