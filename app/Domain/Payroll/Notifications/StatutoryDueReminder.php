<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Notifications;

use Carbon\CarbonImmutable;

/** To Payroll Officer and Finance: last month's statutory payments are not recorded and fall due on the 15th. */
final class StatutoryDueReminder extends PayrollNotice
{
    /**
     * @param  list<string>  $schemes  RemittanceScheme values still without a recorded payment
     */
    public function __construct(
        public readonly string $period,
        public readonly array $schemes,
        public readonly bool $dueToday,
    ) {}

    public function kind(): string
    {
        return $this->dueToday ? 'statutory_due_today' : 'statutory_due';
    }

    public function params(): array
    {
        [$year, $month] = explode('-', $this->period);

        return [
            'period' => __('common.months.'.(int) $month).' '.$year,
            'schemes' => implode(', ', array_map(fn (string $s) => (string) __('payroll.enums.remittance_scheme.'.$s), $this->schemes)),
            'due' => '15/'.CarbonImmutable::parse($this->period.'-01')->addMonth()->format('m/Y'),
        ];
    }

    public function link(): string
    {
        return '/statutory/remittances';
    }
}
