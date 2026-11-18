<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support\Enums;

enum MalaysianState: string
{
    use TranslatableEnum;

    case Johor = 'JHR';
    case Kedah = 'KDH';
    case Kelantan = 'KTN';
    case Melaka = 'MLK';
    case NegeriSembilan = 'NSN';
    case Pahang = 'PHG';
    case PulauPinang = 'PNG';
    case Perak = 'PRK';
    case Perlis = 'PLS';
    case Sabah = 'SBH';
    case Sarawak = 'SWK';
    case Selangor = 'SGR';
    case Terengganu = 'TRG';
    case KualaLumpur = 'KUL';
    case Labuan = 'LBN';
    case Putrajaya = 'PJY';

    public static function translationKey(): string
    {
        return 'common.states';
    }
}
