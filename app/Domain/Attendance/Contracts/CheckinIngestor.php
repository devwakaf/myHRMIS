<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Support\CheckinOutcome;
use App\Domain\Attendance\Support\PunchData;
use App\Domain\Core\Models\Employee;

/**
 * Entry point for punches (spec-03 §4.7). Phase 1: web/mobile (RecordWebCheckin).
 * Phase 2 (planned): device API and CSV import ingestors feeding the same processor.
 */
interface CheckinIngestor
{
    public function ingest(Employee $employee, PunchData $punch): CheckinOutcome;
}
