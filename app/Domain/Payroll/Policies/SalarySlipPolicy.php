<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Policies;

use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Slip Gaji visibility (spec-04 §1.4): payroll.slip.view-all, or the employee's
 * own published slip (drafts never). Controllers answer 404 for "not yours".
 */
final class SalarySlipPolicy
{
    public function view(User $user, SalarySlip $slip): bool
    {
        if (! OrganisationContext::has() || $slip->organisation_id !== OrganisationContext::id()) {
            return false;
        }
        if ($user->can('payroll.slip.view-all')) {
            return true;
        }

        return $this->isOwn($user, $slip) && app(SlipPublisher::class)->isPublished($slip);
    }

    /** "Lihat Pengiraan" (calculation trace) is for PO / PA only. */
    public function viewTrace(User $user, SalarySlip $slip): bool
    {
        return $user->can('payroll.run.prepare') || $user->can('payroll.run.submit');
    }

    public function isOwn(User $user, SalarySlip $slip): bool
    {
        return (int) DB::table('employees')->where('id', $slip->employee_id)->value('user_id') === (int) $user->id;
    }
}
