<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Core\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis Dokumen (B1.10).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property bool $tracks_expiry
 * @property bool $is_active
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'code', 'name', 'name_en', 'tracks_expiry', 'is_active'];

    protected function casts(): array
    {
        return ['tracks_expiry' => 'boolean', 'is_active' => 'boolean'];
    }
}
