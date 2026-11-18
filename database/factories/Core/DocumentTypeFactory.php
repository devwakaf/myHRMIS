<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('DOC###')),
            'name' => 'Dokumen '.fake()->unique()->numerify('####'),
            'tracks_expiry' => false,
            'is_active' => true,
        ];
    }
}
