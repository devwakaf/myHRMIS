<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

/**
 * Kinds of Ledger Cuti rows (spec-02 §2.6). Credit rows are lots; every other
 * row points at the lot it acts on.
 */
enum MovementKind: string
{
    use TranslatableEnum;

    // credits (lots)
    case Allocation = 'allocation';
    case CarryIn = 'carry_in';
    case Accrual = 'accrual';
    case TierChange = 'tier_change';
    case PolicyChange = 'policy_change';
    case AdjustmentAdd = 'adjustment_add';
    case Compensatory = 'compensatory';

    // debits
    case Application = 'application';
    case Encashment = 'encashment';
    case AdjustmentReduce = 'adjustment_reduce';
    case CarryOut = 'carry_out';
    case Expiry = 'expiry';
    case SeparationProrate = 'separation_prorate';

    case Reversal = 'reversal';

    public static function translationKey(): string
    {
        return 'leave.enums.movement_kind';
    }

    public function isCredit(): bool
    {
        return in_array($this, self::credits(), true);
    }

    /**
     * @return list<self>
     */
    public static function credits(): array
    {
        return [self::Allocation, self::CarryIn, self::Accrual, self::TierChange, self::PolicyChange, self::AdjustmentAdd, self::Compensatory];
    }

    /**
     * Credits that make up the entitlement of an allocation (not carried, adjusted or granted).
     *
     * @return list<self>
     */
    public static function entitlementCredits(): array
    {
        return [self::Allocation, self::Accrual, self::TierChange, self::PolicyChange];
    }

    /**
     * @return list<string>
     */
    public static function creditValues(): array
    {
        return array_map(fn (self $k) => $k->value, self::credits());
    }
}
