<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\QualificationLevel;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kelayakan akademik (B1.12).
 *
 * @property int $id
 * @property int $employee_id
 * @property QualificationLevel $level
 * @property string $institution
 */
class EmployeeQualification extends Model
{
    use LogsBusinessActivity;

    protected $fillable = ['employee_id', 'level', 'institution', 'field', 'year_completed', 'grade_result'];

    protected function casts(): array
    {
        return ['level' => QualificationLevel::class, 'year_completed' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
