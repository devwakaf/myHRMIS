<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AssignmentChangeAction;
use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;

/**
 * Reversal log for split / trim / cancel of assignments and rest days (spec-03 §3.5).
 *
 * @property int $id
 * @property int $organisation_id
 * @property AssignmentChangeCause $cause_type
 * @property int $cause_id
 * @property int|null $shift_assignment_id
 * @property int|null $employee_rest_day_id
 * @property AssignmentChangeAction $action
 * @property array<string, mixed>|null $before
 */
class ShiftAssignmentChange extends Model
{
    use BelongsToOrganisation;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cause_type' => AssignmentChangeCause::class,
            'action' => AssignmentChangeAction::class,
            'before' => 'array',
            'shift_assignment_id' => 'integer',
            'employee_rest_day_id' => 'integer',
        ];
    }
}
