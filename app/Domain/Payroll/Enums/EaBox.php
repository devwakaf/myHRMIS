<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EaBox: string
{
    use TranslatableEnum;

    case B1aSalary = 'b1a_salary';
    case B1bFeesCommissionBonus = 'b1b_fees_commission_bonus';
    case B1cAllowancesPerquisites = 'b1c_allowances_perquisites';
    case B1dTaxBorne = 'b1d_tax_borne';
    case B1eEsos = 'b1e_esos';
    case B1fGratuity = 'b1f_gratuity';
    case B2BenefitInKind = 'b2_benefit_in_kind';
    case B3Accommodation = 'b3_accommodation';
    case B4UnapprovedFund = 'b4_unapproved_fund';
    case B5LossOfEmployment = 'b5_loss_of_employment';
    case None = 'none';

    public static function translationKey(): string
    {
        return 'payroll.enums.ea_box';
    }
}
