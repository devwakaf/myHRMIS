<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftType;

/**
 * Jenis OT for a processed day (spec-03 §4.3 step 8): assignment's → shift type's
 * (when overtime_enabled) → organisation default; null = no OT lines.
 */
final class OvertimeTypeResolver
{
    public function for(ShiftType $shift, ?ShiftAssignment $assignment): ?OvertimeType
    {
        $id = $assignment->overtime_type_id
            ?? ($shift->overtime_enabled ? $shift->overtime_type_id : null)
            ?? AttendanceSettings::current($shift->organisation_id)->default_overtime_type_id;

        return $id === null ? null : OvertimeType::query()->find($id);
    }
}
