<?php

declare(strict_types=1);

namespace App\Domain\Core\Policies;

use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

/**
 * Documents (B1.10): HR manages; the owner may download (read only).
 */
final class EmployeeDocumentPolicy
{
    public function download(User $user, EmployeeDocument $document): bool
    {
        if (! OrganisationContext::has() || $document->organisation_id !== OrganisationContext::id()) {
            return false;
        }

        if ($user->can('core.employee.documents.manage') || $user->can('core.employee.view')) {
            return true;
        }

        return $document->employee?->user_id === $user->id;
    }

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return OrganisationContext::has()
            && $document->organisation_id === OrganisationContext::id()
            && $user->can('core.employee.documents.manage');
    }
}
