<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Support\MtdInput;
use App\Domain\Statutory\Support\MtdResult;
use App\Domain\Statutory\Support\MtdTable;

/**
 * PCB / MTD — LHDN computerised method (spec-04 §4.6 5e; stat §4). Our own
 * implementation of the published formula: normal pass, additional-remuneration
 * pass, RM10 minimums, zakat offset. Intermediate values stay unrounded (scale 10);
 * the final amounts are truncated to sen then rounded up to the next 5 sen.
 */
final class MtdCalculator
{
    public function calculate(MtdTable $table, MtdInput $in): MtdResult
    {
        if (! $in->resident) {
            $pcb = (string) Rounding::upToFiveSen(Num::percent(Num::add($in->y1, $in->yt), $table->nonResidentRate));

            return new MtdResult('non_resident', $pcb, '0.00', $pcb, $pcb, $table->rateSetId, [
                'Y1' => Num::trim($in->y1), 'Yt' => Num::trim($in->yt), 'rate' => $table->nonResidentRate,
            ]);
        }

        $n = $in->n();
        $cap = $table->epfReliefCap;
        $ke = Num::min($in->k, $cap);
        $k1e = Num::min($in->k1, Num::max(Num::sub($cap, $ke), '0'));
        // K2 × n is kept exact (min(K1e × n, cap room)) so P never carries a division remainder across a band boundary.
        $k2n = $this->k2TimesN($k1e, Num::sub(Num::sub($cap, $ke), $k1e), $n);
        $k2 = $n > 0 ? Num::div($k2n, (string) $n) : '0';
        $y2 = $in->y2 ?? $in->y1;

        $reliefs = $this->reliefs($table, $in);
        $lp1 = $this->lp1($table, $in);
        $deductions = Num::add($reliefs['total'], $in->lp, $lp1);

        $p = Num::sub(
            Num::add(Num::sub($in->y, $ke), Num::sub($in->y1, $k1e), Num::sub(Num::mul($y2, (string) $n), $k2n)),
            $deductions,
        );
        $tax = $this->tax($table, $p, $in->category);
        $raw = Num::max(Num::div(Num::sub($tax['t'], Num::add($in->z, $in->x)), (string) ($n + 1)), '0');
        $mtd = (string) Rounding::upToFiveSen($raw);
        if (Num::cmp($mtd, $table->pcbMinimum) < 0) {
            $mtd = '0.00';
        }

        $inputs = [
            'Y' => Num::trim($in->y), 'K' => Num::trim($in->k), 'Ke' => Num::trim($ke),
            'Y1' => Num::trim($in->y1), 'K1' => Num::trim($in->k1), 'K1e' => Num::trim($k1e),
            'Y2' => Num::trim($y2), 'K2' => Num::trim($k2), 'Yt' => Num::trim($in->yt), 'Kt' => Num::trim($in->kt),
            'n' => $n, 'D' => $reliefs['D'], 'S' => $reliefs['S'], 'DU' => $reliefs['DU'], 'SU' => $reliefs['SU'], 'QC' => $reliefs['QC'],
            'LP' => Num::trim($in->lp), 'LP1' => Num::trim($lp1), 'P' => Num::trim($p), 'M' => $tax['m'], 'R' => $tax['r'], 'B' => $tax['b'],
            'T' => Num::trim($tax['t']), 'Z' => Num::trim($in->z), 'X' => Num::trim($in->x), 'MTD_raw' => Num::trim($raw), 'MTD' => $mtd,
            'category' => $in->category,
        ];

        $additional = '0.00';
        if (Num::isPositive($in->yt)) {
            $kte = Num::min($in->kt, Num::max(Num::sub(Num::sub($cap, $ke), $k1e), '0'));
            $k2an = $this->k2TimesN($k1e, Num::sub(Num::sub(Num::sub($cap, $ke), $k1e), $kte), $n);
            $k2a = $n > 0 ? Num::div($k2an, (string) $n) : '0';
            $p2 = Num::sub(
                Num::add(Num::sub($in->y, $ke), Num::sub($in->y1, $k1e), Num::sub(Num::mul($y2, (string) $n), $k2an), Num::sub($in->yt, $kte)),
                $deductions,
            );
            $tax2 = $this->tax($table, $p2, $in->category);
            $annual = Num::add($in->x, Num::mul($mtd, (string) ($n + 1)));
            // [V-LHDN] open Q3: additional PCB = T' − (annual MTD + Z).
            $rawAdd = Num::max(Num::sub($tax2['t'], Num::add($annual, $in->z)), '0');
            $additional = (string) Rounding::upToFiveSen($rawAdd);
            if (Num::cmp($additional, $table->additionalPcbMinimum) < 0) {
                $additional = '0.00';
            }
            $inputs += [
                'Kte' => Num::trim($kte), 'K2_additional' => Num::trim($k2a), 'P_additional' => Num::trim($p2),
                'M_additional' => $tax2['m'], 'R_additional' => $tax2['r'], 'B_additional' => $tax2['b'],
                'T_additional' => Num::trim($tax2['t']), 'annual_mtd' => Num::trim($annual), 'additional_raw' => Num::trim($rawAdd),
            ];
        }

        $zakat = Num::add($in->zakatPayroll, $in->zakatExternal);
        $netNormal = self::netOfZakat($mtd, $zakat);
        $net = Num::money(Num::add($netNormal, $additional));

        $inputs += ['zakat_payroll' => Num::trim($in->zakatPayroll), 'zakat_external' => Num::trim($in->zakatExternal), 'pcb_additional' => $additional];

        return new MtdResult('normal', $mtd, $additional, Num::money(Num::add($mtd, $additional)), $net, $table->rateSetId, $inputs);
    }

    /**
     * Zakat paid this month offsets normal MTD (stat §4.5): a net below the RM10
     * minimum but above zero is still deducted; unused zakat is not carried here
     * (it reaches next month's Z through the tax year).
     */
    public static function netOfZakat(string $mtd, string $zakat): string
    {
        return Num::money(Num::max(Num::sub($mtd, $zakat), '0'));
    }

    /** Apply the minimum rule to an already rounded MTD (exposed for the spec's minimum-rule cases). */
    public static function applyMinimum(string $mtd, string $minimum): string
    {
        return Num::cmp($mtd, $minimum) < 0 ? '0.00' : Num::money($mtd);
    }

    /**
     * @return array{t: string, m: string, r: string, b: string}
     */
    private function tax(MtdTable $table, string $p, int $category): array
    {
        if (! Num::isPositive($p)) {
            return ['t' => '0', 'm' => '0', 'r' => '0', 'b' => '0'];
        }

        $band = $table->band($p) ?? throw new StatutoryRateException('statutory.errors.mtd_band_missing', ['p' => Num::money($p), 'code' => $table->code]);
        if ($band->noTax) {
            return ['t' => '0', 'm' => $band->m, 'r' => $band->r, 'b' => '0'];
        }

        $b = $band->b($category);
        $t = Num::add(Num::percent(Num::sub($p, $band->m), $band->r), $b);

        return ['t' => Num::max($t, '0'), 'm' => Num::trim($band->m), 'r' => Num::trim($band->r), 'b' => Num::trim($b)];
    }

    /**
     * D always; S when category 2; DU when disabled; SU when category 2 and the
     * spouse is disabled; QC only for categories 2 and 3 claiming child relief.
     *
     * @return array{total: string, D: string, S: string, DU: string, SU: string, QC: string}
     */
    private function reliefs(MtdTable $table, MtdInput $in): array
    {
        $d = $table->reliefIndividual;
        $s = $in->category === 2 ? $table->reliefSpouse : '0';
        $du = $in->disabledSelf ? $table->reliefDisabledSelf : '0';
        $su = $in->category === 2 && $in->disabledSpouse ? $table->reliefDisabledSpouse : '0';
        $qc = '0';
        if (in_array($in->category, [2, 3], true) && $in->claimsChildRelief) {
            $qc = Num::add(
                Num::mul((string) $in->childrenBasic, $table->childBasic),
                Num::mul((string) $in->childrenHigherEd, $table->childHigherEd),
                Num::mul((string) $in->childrenDisabled, $table->childDisabled),
                Num::mul((string) $in->childrenDisabledHigherEd, $table->childDisabledHigherEd),
            );
        }

        return [
            'total' => Num::add($d, $s, $du, $su, $qc),
            'D' => Num::trim($d), 'S' => Num::trim($s), 'DU' => Num::trim($du), 'SU' => Num::trim($su), 'QC' => Num::trim($qc),
        ];
    }

    /** K2 × n = min(K1e × n, max(0, room)); 0 when n = 0 (December). */
    private function k2TimesN(string $k1e, string $room, int $n): string
    {
        return $n > 0 ? Num::min(Num::mul($k1e, (string) $n), Num::max($room, '0')) : '0';
    }

    /** LP1: TP1 of this month, plus SOCSO/EIS employee shares when the set says so (open Q5). */
    private function lp1(MtdTable $table, MtdInput $in): string
    {
        if (! $table->autoSocsoEisRelief) {
            return $in->lp1;
        }

        $room = Num::max(Num::sub($table->socsoEisReliefCap, $in->socsoEisPrior), '0');

        return Num::add($in->lp1, Num::min($in->socsoEisEmployee, $room));
    }
}
