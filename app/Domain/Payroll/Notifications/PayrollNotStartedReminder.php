<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Notifications;

/** To Payroll Officer: no Proses Gaji exists yet for the current month. */
final class PayrollNotStartedReminder extends PayrollNotice
{
    public function __construct(public readonly string $period) {}

    public function kind(): string
    {
        return 'payroll_not_started';
    }

    public function params(): array
    {
        [$year, $month] = explode('-', $this->period);

        return ['period' => __('common.months.'.(int) $month).' '.$year];
    }

    public function link(): string
    {
        return '/payroll/runs';
    }
}
