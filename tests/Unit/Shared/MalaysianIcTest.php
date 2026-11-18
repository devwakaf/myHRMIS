<?php

use App\Domain\Shared\Support\Enums\Gender;
use App\Domain\Shared\Support\MalaysianIc;
use Carbon\CarbonImmutable;

$today = CarbonImmutable::parse('2026-10-19');

// B8 #8
it('derives date of birth and gender from a MyKad number', function () use ($today) {
    $male = MalaysianIc::parse('900101-06-1235', 14, $today);
    $female = MalaysianIc::parse('900101061234', 14, $today);

    expect($male->dateOfBirth->toDateString())->toBe('1990-01-01')
        ->and($male->gender)->toBe(Gender::Male)
        ->and($female->gender)->toBe(Gender::Female)
        ->and($male->digits)->toBe('900101061235')
        ->and($male->placeCode)->toBe('06');
});

// B8 #9
it('uses 19YY when 20YY would make the person younger than the minimum age', function () use ($today) {
    expect(MalaysianIc::parse('130505101231', 14, $today)->dateOfBirth->toDateString())->toBe('1913-05-05')
        ->and(MalaysianIc::parse('100505101231', 14, $today)->dateOfBirth->toDateString())->toBe('2010-05-05')
        ->and(MalaysianIc::parse('251231101231', 14, $today)->dateOfBirth->toDateString())->toBe('1925-12-31');
});

// B8 #10
it('rejects invalid months and days and strips dashes and spaces', function () use ($today) {
    expect(fn () => MalaysianIc::parse('901301061234', 14, $today))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MalaysianIc::parse('900132061234', 14, $today))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MalaysianIc::parse('90010106123', 14, $today))->toThrow(InvalidArgumentException::class)
        ->and(MalaysianIc::normalise(' 900101-06 1234 '))->toBe('900101061234');
});

// B8 #11 (unit part)
it('flags unknown place-of-birth codes', function () use ($today) {
    expect(MalaysianIc::parse('900101061234', 14, $today)->isKnownPlaceCode())->toBeTrue()
        ->and(MalaysianIc::parse('900101171234', 14, $today)->isKnownPlaceCode())->toBeFalse();
});

it('masks all but the last four digits', function () {
    expect(MalaysianIc::mask('900101061234'))->toBe('********1234');
});
