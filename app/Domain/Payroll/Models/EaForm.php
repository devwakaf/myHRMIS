<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\EaFormStatus;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borang EA with frozen figures (spec-04 §2.10, §6.5). Re-generation after
 * publish creates a superseding version.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $tax_year
 * @property string $serial_no
 * @property EaFormStatus $status
 * @property array<string, mixed> $data
 * @property string|null $pdf_path
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by
 * @property-read Employee $employee
 */
class EaForm extends Model
{
    use BelongsToOrganisation, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'status' => EaFormStatus::class,
            'data' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
