<?php

declare(strict_types=1);

namespace App\Domain\Leave\Events;

use App\Domain\Leave\Enums\PayoutStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Payroll reports an encashment's payout progress (spec-02 §5.3, spec-04 §4.2):
 * in_payroll on run submit, paid on mark-paid, awaiting_payroll on run cancel.
 * Owned by Leave (the encashment's module); dispatched by Payroll.
 */
final class LeaveEncashmentPayoutChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $encashmentId,
        public readonly PayoutStatus $status,
    ) {}
}
