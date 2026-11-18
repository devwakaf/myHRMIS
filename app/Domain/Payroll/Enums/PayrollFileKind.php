<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PayrollFileKind: string
{
    use TranslatableEnum;

    case Bank = 'bank';
    case BankSupplementary = 'bank_supplementary';
    case CashChequeList = 'cash_cheque_list';
    case SummaryCsv = 'summary_csv';
    case Kwsp = 'kwsp';
    case Perkeso = 'perkeso';
    case Cp39 = 'cp39';
    case Pcb2ii = 'pcb2ii';
    case HrdcListing = 'hrdc_listing';
    case ZakatListing = 'zakat_listing';
    case Register = 'register';
    case EaBatch = 'ea_batch';

    public static function translationKey(): string
    {
        return 'payroll.enums.file_kind';
    }
}
