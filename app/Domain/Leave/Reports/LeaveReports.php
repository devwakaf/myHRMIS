<?php

declare(strict_types=1);

namespace App\Domain\Leave\Reports;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\AggregateCaps;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Data behind the Cuti reports (spec-02 §6.2). Screens and XLSX/CSV export are
 * the UI milestone's job; every number here comes from the ledger, so
 * closing = opening + credited − taken − encashed − reduced − carried out − expired
 * holds without boundary special cases. Attachments never appear in any row.
 */
final class LeaveReports
{
    private const BUCKETS = ['credited', 'taken', 'encashed', 'reduced', 'carried_out', 'expired'];

    public function __construct(
        private readonly LeaveBalance $balance,
        private readonly AggregateCaps $caps,
    ) {}

    /**
     * 1. Baki Cuti Kakitangan — per employee × balance type for [from, to] by posted_on.
     *
     * @param  list<int>|null  $employeeIds
     * @param  list<int>|null  $typeIds
     * @return list<array<string, mixed>>
     */
    public function balanceSummary(CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds = null, ?array $typeIds = null): array
    {
        $rows = DB::table('leave_movements as m')
            ->leftJoin('leave_movements as o', 'o.id', '=', 'm.reverses_movement_id')
            ->leftJoin('leave_allocations as a', 'a.id', '=', 'm.leave_allocation_id')
            ->whereNotNull('m.leave_allocation_id')
            ->where('a.organisation_id', OrganisationContext::id())
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('m.employee_id', $employeeIds))
            ->when($typeIds !== null, fn ($q) => $q->whereIn('m.leave_type_id', $typeIds))
            ->whereDate('m.posted_on', '<=', $to->toDateString())
            ->selectRaw('m.employee_id, m.leave_type_id, m.kind, m.lot_id, o.kind as reversed_kind, o.lot_id as reversed_lot_id, m.reverses_movement_id, m.posted_on < ? as before_range, SUM(m.days) as days', [$from->toDateString()])
            ->groupBy('m.employee_id', 'm.leave_type_id', 'm.kind', 'm.lot_id', 'o.kind', 'o.lot_id', 'm.reverses_movement_id', 'before_range')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $row->employee_id.':'.$row->leave_type_id;
            $out[$key] ??= ['employee_id' => (int) $row->employee_id, 'leave_type_id' => (int) $row->leave_type_id, 'opening' => '0']
                + array_fill_keys(self::BUCKETS, '0');
            $days = (string) $row->days;

            if ((int) $row->before_range === 1) {
                $out[$key]['opening'] = Dec::add($out[$key]['opening'], $days);

                continue;
            }

            $bucket = $this->bucket((string) $row->kind, $row->lot_id, $row->reversed_kind, $row->reversed_lot_id, $row->reverses_movement_id);
            // credits are shown signed; consumption buckets as positive amounts
            $out[$key][$bucket] = $bucket === 'credited' ? Dec::add($out[$key][$bucket], $days) : Dec::sub($out[$key][$bucket], $days);
        }

        $employees = Employee::query()->whereIn('id', array_unique(array_column($out, 'employee_id')))->get()->keyBy('id');
        $types = LeaveType::query()->get()->keyBy('id');

        foreach ($out as $key => $row) {
            $closing = Dec::sub(Dec::add($row['opening'], $row['credited']), Dec::add($row['taken'], $row['encashed'], $row['reduced'], $row['carried_out'], $row['expired']));
            $employee = $employees->get($row['employee_id']);
            $type = $types->get($row['leave_type_id']);
            $balance = ($employee !== null && $type !== null) ? $this->balance->on($employee, $type, $to) : null;
            $out[$key] += [
                'employee' => $employee?->full_name,
                'employee_no' => $employee?->employee_no,
                'leave_type' => $type?->displayName(),
                'closing' => $closing,
                'reserved' => $balance['reserved'] ?? '0',
                'available' => $balance['available'] ?? '0',
            ];
        }

        return array_values($out);
    }

    /**
     * 7a. Baki Negatif — employees whose available balance is below zero on a date.
     *
     * @return list<array<string, mixed>>
     */
    public function negativeBalances(CarbonImmutable $date): array
    {
        $out = [];
        $types = LeaveType::query()->get()->filter(fn (LeaveType $t) => $t->hasBalance());
        $pairs = DB::table('leave_allocations')->where('organisation_id', OrganisationContext::id())
            ->where('status', 'submitted')->whereDate('from_date', '<=', $date->toDateString())->whereDate('to_date', '>=', $date->toDateString())
            ->select('employee_id', 'leave_type_id')->distinct()->get();

        foreach ($pairs as $pair) {
            $type = $types->get($pair->leave_type_id);
            $employee = Employee::query()->find($pair->employee_id);
            if ($type === null || $employee === null) {
                continue;
            }
            $balance = $this->balance->on($employee, $type, $date);
            if (Dec::isNegative($balance['remaining'])) {
                $out[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'leave_type' => $type->displayName(), 'balance' => $balance['remaining']];
            }
        }

        return $out;
    }

    /**
     * 6. Penggunaan Had Gabungan — usage vs cap per employee for the period containing $date.
     *
     * @return list<array<string, mixed>>
     */
    public function capUsage(CarbonImmutable $date): array
    {
        $period = LeavePeriod::containing($date);
        if ($period === null) {
            return [];
        }

        $out = [];
        foreach (LeaveAggregateCap::query()->where('is_active', true)->with('types')->get() as $cap) {
            $employeeIds = DB::table('leave_applications')->whereIn('leave_type_id', $cap->types->pluck('id'))
                ->whereBetween('from_date', [$period->from_date->toDateString(), $period->to_date->toDateString()])
                ->distinct()->pluck('employee_id');
            foreach ($employeeIds as $employeeId) {
                $used = $this->caps->usage((int) $employeeId, $cap, $period);
                $out[] = ['cap' => $cap->name, 'employee_id' => (int) $employeeId, 'cap_days' => (string) $cap->cap_days, 'used' => $used, 'room' => Dec::max(Dec::sub((string) $cap->cap_days, $used), '0')];
            }
        }

        return $out;
    }

    private function bucket(string $kind, mixed $lotId, mixed $reversedKind, mixed $reversedLotId, mixed $reversesId): string
    {
        if ($reversesId !== null) {
            if ($reversedLotId === null) {
                return 'credited'; // reversal of a credit
            }
            $kind = (string) $reversedKind;
        } elseif ($lotId === null) {
            return 'credited';
        }

        return match (MovementKind::from($kind)) {
            MovementKind::Application => 'taken',
            MovementKind::Encashment => 'encashed',
            MovementKind::AdjustmentReduce, MovementKind::SeparationProrate => 'reduced',
            MovementKind::CarryOut => 'carried_out',
            MovementKind::Expiry => 'expired',
            default => 'credited',
        };
    }
}
