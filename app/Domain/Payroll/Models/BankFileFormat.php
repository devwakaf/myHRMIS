<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;

/**
 * Bank file layout (global, our own JSON schema — spec-04 §5.6). Only "CSV
 * Generik" is seeded; bank templates are user-supplied configuration (open Q10).
 *
 * @property int $id
 * @property int|null $bank_id
 * @property string $code
 * @property string $name
 * @property string $version
 * @property string $file_type
 * @property array<string, mixed> $layout
 * @property string $status
 * @property string|null $source_ref
 */
class BankFileFormat extends Model
{
    use LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }
}
