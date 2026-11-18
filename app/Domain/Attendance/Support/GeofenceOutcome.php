<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\AttemptResult;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Models\ShiftLocation;

/**
 * Result of GeofenceEvaluator (spec-03 §4.6): accepted with a GeofenceResult, or
 * rejected with an AttemptResult. Distances are metres (float is fine for distance).
 */
final class GeofenceOutcome
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?GeofenceResult $result,
        public readonly ?AttemptResult $rejection,
        public readonly ?ShiftLocation $location,
        public readonly ?float $distance,
    ) {}

    public static function accept(GeofenceResult $result, ?ShiftLocation $location = null, ?float $distance = null): self
    {
        return new self(true, $result, null, $location, $distance);
    }

    public static function reject(AttemptResult $reason, ?ShiftLocation $nearest = null, ?float $distance = null): self
    {
        return new self(false, null, $reason, $nearest, $distance);
    }
}
