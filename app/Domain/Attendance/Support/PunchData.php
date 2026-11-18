<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\CheckinSource;
use Carbon\CarbonImmutable;

/**
 * Input of a CheckinIngestor (spec-03 §4.7). For web/mobile the event time is the
 * server receive time; `clientReportedAt` is audit only.
 */
final class PunchData
{
    public function __construct(
        public readonly CheckinSource $source,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $accuracy = null,
        public readonly ?CarbonImmutable $clientReportedAt = null,
        public readonly ?string $clientRequestId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?CarbonImmutable $deviceTime = null,
        public readonly ?string $deviceCode = null,
    ) {}
}
