<?php

declare(strict_types=1);

namespace App\Domain\Leave\Contracts;

use App\Domain\Leave\Models\LeaveEncashment;

/**
 * Encashment → Additional Salary (spec-02 §5.3). Implemented by Payroll (M4).
 * Deviation: createForEncashment returns ?int so the M2 Null binding can leave
 * the encashment `awaiting_payroll` without an Additional Salary id.
 */
interface AdditionalPayPort
{
    public function createForEncashment(LeaveEncashment $encashment): ?int;

    /** Refuses (throws) once the Additional Salary was consumed by a locked run. */
    public function cancelForEncashment(LeaveEncashment $encashment): void;
}
