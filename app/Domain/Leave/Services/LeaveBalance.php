<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Balance on a date (spec-02 §3.3). Debits count against their lot regardless
 * of the leave date, so future booked leave already counts; pending applications
 * reserve; aggregate caps limit what is available.
 */
final class LeaveBalance
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly AggregateCaps $caps,
    ) {}

    /**
     * @return array{leave_type_id: int, date: string, has_balance: bool, credited: string, taken: string, encashed: string, reduced: string, carried_out: string, expired: string, reserved: string, remaining: string, available: string, cap_limited: bool, caps: list<array<string, mixed>>, expiring: list<array{lot_id: int, kind: string, remaining: string, date_to: string}>}
     */
    public function on(Employee $employee, LeaveType $type, CarbonInterface|string $date, ?int $excludeApplicationId = null): array
    {
        $day = DateRange::day($date);
        $buckets = ['credited' => '0', 'taken' => '0', 'encashed' => '0', 'reduced' => '0', 'carried_out' => '0', 'expired' => '0'];
        $expiring = [];
        $remainingTotal = '0';

        if ($type->hasBalance()) {
            $lots = LeaveMovement::query()->lots()
                ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->whereDate('date_from', '<=', $day->toDateString())->whereDate('date_to', '>=', $day->toDateString())
                ->whereHas('allocation', fn ($q) => $q->where('status', DocumentStatus::Submitted->value))
                ->get();
            $ids = $lots->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($lots as $lot) {
                $buckets['credited'] = Dec::add($buckets['credited'], $lot->days);
            }

            if ($ids !== []) {
                $rows = LeaveMovement::query()->whereIn('lot_id', $ids)->with('reversed:id,kind,lot_id')->get();
                foreach ($rows as $row) {
                    $kind = $row->kind === MovementKind::Reversal ? $row->reversed?->kind : $row->kind;
                    $isCreditReversal = $row->kind === MovementKind::Reversal && $row->reversed !== null && $row->reversed->lot_id === null;
                    $bucket = $isCreditReversal ? 'credited' : $this->bucketFor($kind);
                    // debits are negative; buckets other than "credited" are shown as positive amounts
                    $buckets[$bucket] = $bucket === 'credited'
                        ? Dec::add($buckets[$bucket], $row->days)
                        : Dec::sub($buckets[$bucket], $row->days);
                }
            }

            $remaining = $this->ledger->remainingMany($ids);
            foreach ($lots as $lot) {
                $r = $remaining[$lot->id] ?? '0';
                $remainingTotal = Dec::add($remainingTotal, $r);
                if (Dec::isPositive($r)) {
                    $expiring[] = ['lot_id' => $lot->id, 'kind' => $lot->kind->value, 'remaining' => $r, 'date_to' => $lot->date_to->toDateString()];
                }
            }
            usort($expiring, fn ($a, $b) => strcmp($a['date_to'], $b['date_to']));
        }

        $reserved = $this->reserved($employee, $type, $day, $excludeApplicationId);
        $available = Dec::sub($remainingTotal, $reserved);
        $capRows = $this->caps->roomOn($employee->id, $type->id, $day, $excludeApplicationId);
        $capLimited = false;
        foreach ($capRows as $cap) {
            if ($type->hasBalance() && Dec::cmp($cap['room'], $available) < 0) {
                $available = $cap['room'];
                $capLimited = true;
            }
        }

        return [
            'leave_type_id' => $type->id,
            'date' => $day->toDateString(),
            'has_balance' => $type->hasBalance(),
            ...$buckets,
            'reserved' => $reserved,
            'remaining' => $remainingTotal,
            'available' => $available,
            'cap_limited' => $capLimited,
            'caps' => $capRows,
            'expiring' => $expiring,
        ];
    }

    /**
     * Σ portion of pending applications of this type dated inside the allocation covering $date.
     */
    public function reserved(Employee $employee, LeaveType $type, CarbonInterface $date, ?int $excludeApplicationId = null): string
    {
        $allocation = LeaveAllocation::query()->live()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereDate('from_date', '<=', $date->toDateString())->whereDate('to_date', '>=', $date->toDateString())
            ->first();

        $query = DB::table('leave_application_days as d')
            ->join('leave_applications as a', 'a.id', '=', 'd.leave_application_id')
            ->where('a.employee_id', $employee->id)
            ->where('a.leave_type_id', $type->id)
            ->where('a.status', ApprovalStatus::Pending->value)
            ->when($excludeApplicationId !== null, fn ($q) => $q->where('a.id', '!=', $excludeApplicationId));

        if ($allocation !== null) {
            $query->whereBetween('d.date', [$allocation->from_date->toDateString(), $allocation->to_date->toDateString()]);
        } elseif ($type->hasBalance()) {
            return '0';
        }

        return Dec::norm((string) $query->sum('d.portion'));
    }

    /**
     * Balances of every active balance type, for the Slip Gaji (spec-02 §5.2, optional).
     *
     * @return list<array{code: string, name: string, available: string}>
     */
    public function summaryForSlip(Employee $employee, CarbonInterface $date): array
    {
        $out = [];
        foreach (LeaveType::query()->where('is_active', true)->orderBy('sort_order')->get() as $type) {
            if (! $type->hasBalance()) {
                continue;
            }
            $b = $this->on($employee, $type, $date);
            $out[] = ['code' => $type->code, 'name' => $type->displayName(), 'available' => $b['available']];
        }

        return $out;
    }

    private function bucketFor(?MovementKind $kind): string
    {
        return match ($kind) {
            MovementKind::Application => 'taken',
            MovementKind::Encashment => 'encashed',
            MovementKind::AdjustmentReduce, MovementKind::SeparationProrate => 'reduced',
            MovementKind::CarryOut => 'carried_out',
            MovementKind::Expiry => 'expired',
            default => 'credited', // negative policy_change rows and other credit-side corrections
        };
    }
}
