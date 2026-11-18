<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\EmergencyRelationship;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kontak Kecemasan (B1.11).
 *
 * @property int $id
 * @property int $employee_id
 * @property string $name
 * @property EmergencyRelationship $relationship
 * @property string $phone
 * @property string|null $alt_phone
 * @property string|null $address
 * @property bool $is_primary
 * @property int $sort_order
 */
class EmployeeEmergencyContact extends Model
{
    use LogsBusinessActivity;

    protected $fillable = ['employee_id', 'name', 'relationship', 'phone', 'alt_phone', 'address', 'is_primary', 'sort_order'];

    protected function casts(): array
    {
        return ['relationship' => EmergencyRelationship::class, 'is_primary' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
