<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Support\DomainValidation;

final class UpdateOrganisation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Organisation $organisation, array $data): Organisation
    {
        if (array_key_exists('short_code', $data)
            && $data['short_code'] !== $organisation->short_code
            && $organisation->hasEmployees()) {
            throw DomainValidation::withMessages(['short_code' => __('core.organisation.short_code_locked')]);
        }

        $organisation->update($data);

        return $organisation;
    }
}
