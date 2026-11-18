<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Leave\Enums\DayKind;
use App\Domain\Leave\Enums\DaySession;
use Carbon\CarbonImmutable;

/**
 * Day-by-day result of LeaveDayCalculator (spec-02 §3.1).
 */
final class DayBreakdown
{
    /**
     * @param  list<array{date: CarbonImmutable, day_kind: DayKind, session: DaySession, portion: string, unpaid_portion: string, holiday_list_id: int|null, holiday_name: string|null}>  $days
     */
    public function __construct(
        public readonly array $days,
        public readonly string $totalDays,
        public readonly string $unpaidDays,
        public readonly int $calendarDays,
    ) {}

    /**
     * Days that deduct something, as consumption input.
     *
     * @return list<array{date: CarbonImmutable, portion: string}>
     */
    public function deductible(): array
    {
        $out = [];
        foreach ($this->days as $day) {
            if (Dec::isPositive($day['portion'])) {
                $out[] = ['date' => $day['date'], 'portion' => $day['portion']];
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function countsByKind(): array
    {
        $counts = [];
        foreach (DayKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }
        foreach ($this->days as $day) {
            $counts[$day['day_kind']->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_days' => $this->totalDays,
            'unpaid_days' => $this->unpaidDays,
            'calendar_days' => $this->calendarDays,
            'counts' => $this->countsByKind(),
            'days' => array_map(fn (array $d) => [
                'date' => $d['date']->toDateString(),
                'day_kind' => $d['day_kind']->value,
                'day_kind_label' => $d['day_kind']->label(),
                'session' => $d['session']->value,
                'portion' => $d['portion'],
                'unpaid_portion' => $d['unpaid_portion'],
                'holiday_name' => $d['holiday_name'],
            ], $this->days),
        ];
    }
}
