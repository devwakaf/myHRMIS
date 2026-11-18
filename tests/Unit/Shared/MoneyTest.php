<?php

use App\Domain\Shared\Money\Format;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\Rounding;
use Illuminate\Support\Facades\App;

// B8 #62
it('rounds up to five sen and to the next ringgit', function () {
    expect((string) Rounding::upToFiveSen('287.02'))->toBe('287.05')
        ->and((string) Rounding::upToFiveSen('287.00'))->toBe('287.00')
        ->and((string) Rounding::upToFiveSen('287.059'))->toBe('287.05')
        ->and((string) Rounding::ceilRinggit('780.01'))->toBe('781')
        ->and((string) Rounding::ceilRinggit('780.00'))->toBe('780');
});

it('rounds half away from zero and truncates', function () {
    expect((string) Rounding::halfUp('1.005'))->toBe('1.01')
        ->and((string) Rounding::halfUp('-1.005'))->toBe('-1.01')
        ->and((string) Rounding::truncate('2.999', 2))->toBe('2.99')
        ->and((string) Rounding::toStep('1.3', '0.5'))->toBe('1.500')
        ->and((string) Rounding::toStep('1.2', '0.5'))->toBe('1.000');
});

// B8 #63
it('formats money the same in both locales', function (string $locale) {
    App::setLocale($locale);

    expect(Money::of('1234.56')->format())->toBe('RM 1,234.56')
        ->and(Money::of('-1234.56')->format())->toBe('-RM 1,234.56')
        ->and(Money::of('0')->format())->toBe('RM 0.00')
        ->and(Format::money('1234567.5'))->toBe('RM 1,234,567.50');
})->with(['ms', 'en']);

it('keeps precision through arithmetic and rounds only on output', function () {
    $daily = Money::of('2600.00')->dividedBy('26');
    expect($daily->toDecimalString())->toBe('100.00')
        ->and(Money::of('1000')->percent('11')->toDecimalString())->toBe('110.00')
        ->and(Money::of('10')->dividedBy('3')->times('3')->toDecimalString())->toBe('10.00')
        ->and(Money::of('5')->plus('2.5')->minus(Money::of('0.5'))->toDecimalString())->toBe('7.00')
        ->and(Money::of('1.00')->jsonSerialize())->toBe(['amount' => '1.00', 'formatted' => 'RM 1.00']);
});
