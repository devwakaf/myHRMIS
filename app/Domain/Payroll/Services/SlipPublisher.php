<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Notifications\SalarySlipIssued;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Visibility and delivery of Slip Gaji (spec-04 §1.4): drafts are never visible
 * to the employee; submitted slips publish on submit or on paid per setting.
 */
final class SlipPublisher
{
    public function isPublished(SalarySlip $slip): bool
    {
        if ($slip->status !== DocumentStatus::Submitted) {
            return false;
        }

        $settings = PayrollSettings::current($slip->organisation_id);
        if ($settings->publish_slips_on === 'paid') {
            $runStatus = PayrollRun::query()->whereKey($slip->payroll_run_id)->value('status');

            return $slip->payment_status === PaymentStatus::Paid || $slip->payment_status === PaymentStatus::Withheld
                || $runStatus === PayrollRunStatus::Paid->value;
        }

        return true;
    }

    /**
     * Queue SalarySlipIssued to each employee with a login.
     *
     * @param  Collection<int, SalarySlip>  $slips
     */
    public function notify(Collection $slips): void
    {
        foreach ($slips as $slip) {
            $userId = $slip->employee()->value('user_id');
            $user = $userId === null ? null : User::query()->find($userId);
            if ($user === null) {
                continue;
            }
            $organisation = Organisation::query()->find($slip->organisation_id);
            $user->notify(new SalarySlipIssued($slip->id, $slip->period->locale('ms')->translatedFormat('F Y'), (string) $organisation?->name));
            $slip->forceFill(['emailed_at' => now()])->save();
        }
    }

    /** PDF password per setting (spec-04 §1.4; weak protection — a convenience only). */
    public function pdfPassword(SalarySlip $slip): ?string
    {
        return match (PayrollSettings::current($slip->organisation_id)->pdf_password_mode) {
            'ic_no' => $slip->ic_no ?? $slip->passport_no,
            'dob' => $slip->date_of_birth->format('dmY'),
            default => null,
        };
    }
}
