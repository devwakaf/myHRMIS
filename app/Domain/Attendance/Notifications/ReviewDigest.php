<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Notifications;

/** To HR, once a day: Semakan Kehadiran items older than 24 h (spec-03 §6). */
final class ReviewDigest extends AttendanceNotice
{
    /**
     * @param  array<string, int>  $counts  review reason => count
     */
    public function __construct(public readonly array $counts) {}

    public function kind(): string
    {
        return 'review_digest';
    }

    public function params(): array
    {
        $parts = [];
        foreach ($this->counts as $reason => $count) {
            $parts[] = __('attendance.enums.review_reason.'.$reason).': '.$count;
        }

        return ['total' => (string) array_sum($this->counts), 'details' => implode('; ', $parts)];
    }
}
