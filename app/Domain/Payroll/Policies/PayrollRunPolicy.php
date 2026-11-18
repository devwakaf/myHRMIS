<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Policies;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

/**
 * Role-gated Payroll Run actions with segregation of duties (spec-00 A7,
 * spec-04 §1.4–§1.5). No approval engine involved.
 */
final class PayrollRunPolicy
{
    public function view(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && ($user->can('payroll.run.prepare') || $user->can('payroll.run.submit')
            || $user->can('payroll.run.mark-paid') || $user->can('payroll.slip.view-all'));
    }

    public function prepare(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && $user->can('payroll.run.prepare');
    }

    /** The preparer / last generator may never submit (A7). */
    public function submit(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && $user->can('payroll.run.submit')
            && ($user->is_super_admin || ! in_array($user->id, array_filter([$run->prepared_by, $run->generated_by]), true));
    }

    public function markPaid(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && $user->can('payroll.run.mark-paid');
    }

    public function bankFile(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && $user->can('payroll.run.bank-file');
    }

    public function cancel(User $user, PayrollRun $run): bool
    {
        return $this->sameOrg($run) && $user->can('payroll.run.cancel');
    }

    private function sameOrg(PayrollRun $run): bool
    {
        return OrganisationContext::has() && $run->organisation_id === OrganisationContext::id();
    }
}
