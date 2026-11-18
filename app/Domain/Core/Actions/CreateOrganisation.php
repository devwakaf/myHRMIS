<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmploymentCategory;
use App\Domain\Core\Events\OrganisationCreated;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Approval\Actions\SeedDefaultWorkflows;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Support\Facades\DB;

/**
 * B1.1 "On create": roles, hr_settings, default approval workflows, document
 * types, employment types and the org-level root department.
 */
final class CreateOrganisation
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly SeedDefaultWorkflows $workflows,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Organisation
    {
        return DB::transaction(function () use ($data): Organisation {
            $organisation = Organisation::query()->create($data);

            $this->roles->createRolesFor($organisation->id);

            OrganisationContext::run($organisation, function () use ($organisation): void {
                HrSettings::forOrganisation($organisation->id);
                $this->seedEmploymentTypes();
                $this->seedDocumentTypes();

                Department::query()->create([
                    'code' => 'ROOT',
                    'name' => $organisation->name,
                    'parent_id' => null,
                ]);

                $this->workflows->handle($organisation->id);

                // Modules seed their own defaults synchronously inside this transaction.
                OrganisationCreated::dispatch($organisation);
            });

            return $organisation;
        });
    }

    private function seedEmploymentTypes(): void
    {
        $types = [
            ['code' => 'TETAP', 'name' => 'Tetap', 'name_en' => 'Permanent', 'category' => EmploymentCategory::Permanent, 'requires_end_date' => false],
            ['code' => 'KONTRAK', 'name' => 'Kontrak', 'name_en' => 'Contract', 'category' => EmploymentCategory::Contract, 'requires_end_date' => true],
            ['code' => 'SAMBILAN', 'name' => 'Sambilan', 'name_en' => 'Part-time', 'category' => EmploymentCategory::PartTime, 'requires_end_date' => false],
            ['code' => 'PELATIH', 'name' => 'Pelatih', 'name_en' => 'Intern', 'category' => EmploymentCategory::Intern, 'requires_end_date' => true],
        ];

        foreach ($types as $type) {
            EmploymentType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }

    private function seedDocumentTypes(): void
    {
        $types = [
            ['code' => 'MYKAD', 'name' => 'Salinan MyKad', 'name_en' => 'MyKad copy', 'tracks_expiry' => false],
            ['code' => 'PASPORT', 'name' => 'Pasport', 'name_en' => 'Passport', 'tracks_expiry' => true],
            ['code' => 'PAS_KERJA', 'name' => 'Pas Kerja', 'name_en' => 'Work pass', 'tracks_expiry' => true],
            ['code' => 'SURAT_TAWARAN', 'name' => 'Surat Tawaran', 'name_en' => 'Offer letter', 'tracks_expiry' => false],
            ['code' => 'KONTRAK', 'name' => 'Kontrak Perkhidmatan', 'name_en' => 'Service contract', 'tracks_expiry' => true],
            ['code' => 'SIJIL_AKADEMIK', 'name' => 'Sijil Akademik', 'name_en' => 'Academic certificate', 'tracks_expiry' => false],
            ['code' => 'SIJIL_PERUBATAN', 'name' => 'Sijil Perubatan', 'name_en' => 'Medical certificate', 'tracks_expiry' => false],
            ['code' => 'LAIN', 'name' => 'Lain-lain', 'name_en' => 'Others', 'tracks_expiry' => false],
        ];

        foreach ($types as $type) {
            DocumentType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
