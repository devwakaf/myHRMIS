<?php

// spec-04 §8.2 — formula engine (cases 15–22) and §3.7 BM error catalogue.

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\LineSource;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Enums\RoundingMode;
use App\Domain\Payroll\Formula\ArrayScope;
use App\Domain\Payroll\Formula\Ast\Node;
use App\Domain\Payroll\Formula\DependencyGraph;
use App\Domain\Payroll\Formula\Evaluator;
use App\Domain\Payroll\Formula\FormulaError;
use App\Domain\Payroll\Formula\Parser;
use App\Domain\Payroll\Services\SlipCalculator;
use App\Domain\Payroll\Support\ComponentSpec;
use App\Domain\Payroll\Support\PaymentDays;
use App\Domain\Payroll\Support\SlipInput;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Payroll\Support\StatutoryInputs;
use App\Domain\Payroll\Support\TaxYearTotals;
use App\Domain\Statutory\Support\StatutoryPerson;
use BcMath\Number;
use Carbon\CarbonImmutable;

beforeEach(fn () => app()->setLocale('ms'));

function evaluateFormula(string $formula, array $values = [], array $full = []): Number|bool|string
{
    return (new Evaluator('UJIAN'))->evaluate((new Parser)->parse($formula), new ArrayScope($values, $full));
}

function formulaMessage(callable $fn): string
{
    try {
        $fn();
    } catch (FormulaError $e) {
        return $e->userMessage();
    }

    return 'tiada ralat';
}

function earning(string $code, ?string $formula = null, ?string $amount = null, bool $prorate = false, ?string $condition = null, int $order = 1, ComponentKind $kind = ComponentKind::Earning): ComponentSpec
{
    $parser = new Parser;

    return new ComponentSpec(
        $order, $code, $code, $code, $kind, $formula === null ? CalculationMethod::Fixed : CalculationMethod::Formula, null, $amount,
        $formula === null ? null : $parser->parse($formula), $condition === null ? null : $parser->parse($condition), $prorate,
        ['include_in_gross' => $kind === ComponentKind::Earning, 'show_on_slip' => true], PcbRemunerationType::Normal, EaBox::None,
        RoundingMode::HalfUp2dp, $order, false, null, false, LineSource::Structure, true,
    );
}

/** Calculate a statutory-free structure in a full month (÷26). */
function calculateSpecs(array $specs, string $base = '3000.00', ?PaymentDays $days = null): SlipResult
{
    $start = CarbonImmutable::parse('2026-03-01');
    $input = new SlipInput(
        $start, $start->endOfMonth()->startOfDay(), $days ?? PaymentDays::fullMonth('31', '22', 'fixed_divisor', '26.00'), $base, $specs, [],
        new StatutoryInputs(new StatutoryPerson(CitizenshipStatus::Citizen, CarbonImmutable::parse('1990-01-01'), $start, $start->endOfMonth()), null, null, null, null, null, null, new TaxYearTotals(2026)),
        ['GRADE' => 'G1'],
    );

    return app(SlipCalculator::class)->calculate($input);
}

// #15
it('evaluates percentages and IN lists', function () {
    expect((string) evaluateFormula('GAJI_POKOK * 10%', ['GAJI_POKOK' => new Number('3000')]))->toStartWith('300')
        ->and((string) evaluateFormula("IF(GRADE IN ['G1','G2'], 200, 0)", ['GRADE' => 'G1']))->toBe('200')
        ->and((string) evaluateFormula("IF(GRADE NOT IN ['G1','G2'], 200, 0)", ['GRADE' => 'G3']))->toBe('200')
        ->and(evaluateFormula('BASE >= 3000 DAN BUKAN (BASE > 5000)', ['BASE' => new Number('3000')]))->toBeTrue();

    $result = calculateSpecs([earning('GAJI_POKOK', 'BASE', null, true, null, 1), earning('ELAUN', 'FULL(GAJI_POKOK) * 10%', null, false, null, 2)]);
    expect($result->amount('ELAUN'))->toBe('300.00');
});

// #16
it('reports the exact BM messages of the error catalogue', function () {
    expect(formulaMessage(fn () => evaluateFormula('TIADA * 2')))->toBe("Kod komponen atau pemboleh ubah 'TIADA' tidak dikenali.")
        ->and(formulaMessage(fn () => (new Parser)->parse('SQRT(4)')))->toBe("Fungsi 'SQRT' tidak dibenarkan dalam formula.")
        ->and(formulaMessage(fn () => (new Parser)->parse('ABS(1, 2)')))->toBe('Fungsi ABS memerlukan 1 argumen, diberi 2.')
        ->and(formulaMessage(fn () => evaluateFormula("'abc' + 1")))->toBe("Operasi '+' tidak boleh digunakan pada nilai jenis teks.")
        ->and(formulaMessage(fn () => (new Parser)->parse('1 +')))->toBe('Ralat sintaks pada aksara 4: dijangka nilai atau ungkapan.');
});

// #17
it('rejects a cycle with its path', function () {
    $graph = new DependencyGraph;
    $graph->addNode('A', 'a');
    $graph->addNode('B', 'b');
    $graph->addEdge('A', 'B');
    $graph->addEdge('B', 'A');
    expect(formulaMessage(fn () => $graph->sort()))->toBe('Rujukan berkitar dikesan: A → B → A.')
        ->and(formulaMessage(fn () => calculateSpecs([earning('A', 'B + 1'), earning('B', 'A + 1', null, false, null, 2)])))
        ->toBe('Rujukan berkitar dikesan: A → B → A.');
});

// #18
it('resolves a forward reference by the dependency sort (no silent zero)', function () {
    $result = calculateSpecs([
        earning('ELAUN', 'GAJI_TETAP * 2', null, false, null, 1),
        earning('GAJI_TETAP', null, '150.00', false, null, 2),
    ]);
    expect($result->amount('ELAUN'))->toBe('300.00');
});

// #19 (unit half: FULL() avoids double pro-ration; the structure-save rejection is a feature test)
it('pro-rates once when a prorated component uses FULL()', function () {
    $days = new PaymentDays('31', '22', 'fixed_divisor', '26.00', '0', '2.000', '0', '0', false);
    $result = calculateSpecs([earning('GAJI_POKOK', 'BASE', null, true, null, 1), earning('ELAUN', 'FULL(GAJI_POKOK) * 10%', null, true, null, 2)], '3000.00', $days);
    expect($result->amount('GAJI_POKOK'))->toBe('2769.23')->and($result->amount('ELAUN'))->toBe('276.92');
});

// #20
it('guards division, SAFE_DIV and the size limits', function () {
    expect(formulaMessage(fn () => evaluateFormula('10 / 0')))->toBe('Pembahagian dengan sifar dalam formula komponen UJIAN.')
        ->and((string) evaluateFormula('SAFE_DIV(10, 0, 0)'))->toBe('0')
        ->and(formulaMessage(fn () => (new Parser)->parse(str_repeat('1', 250).'+'.str_repeat('1', 250))))->toBe('Formula melebihi had panjang (500 aksara).')
        ->and(formulaMessage(fn () => (new Parser)->parse(str_repeat('(', 33).'1'.str_repeat(')', 33))))->toBe('Formula melebihi had susunan (32 tahap).')
        ->and(formulaMessage(fn () => (new Parser)->parse(str_repeat('(', 32).'1'.str_repeat(')', 32))))->toBe('tiada ralat')
        ->and(formulaMessage(fn () => (new Parser)->parse('2 ^ 11')))->toContain('mesti nombor bulat');
});

// #21
it('skips a row whose condition is false and refuses a non-boolean condition', function () {
    $result = calculateSpecs([
        earning('ELAUN', null, '200.00', false, 'BASE > 5000', 1),
        earning('BONUS_ELAUN', 'ELAUN + 50', null, false, null, 2),
    ]);
    expect($result->line('ELAUN'))->toBeNull()->and($result->amount('BONUS_ELAUN'))->toBe('50.00')
        ->and(formulaMessage(fn () => calculateSpecs([earning('ELAUN', null, '200.00', false, 'BASE + 1')])))
        ->toBe('Syarat mesti menghasilkan Ya atau Tidak.');
});

it('round-trips the AST through JSON', function () {
    $ast = (new Parser)->parse("IF(GRADE IN ['G1', 'G2'] DAN BASE > 1000, ROUND(BASE * 1.5%, 2), MAX(1, 2, 3))");
    $copy = Node::fromArray(json_decode((string) json_encode($ast->toArray()), true));
    expect($copy->toArray())->toBe($ast->toArray())
        ->and((string) (new Evaluator)->evaluate($copy, new ArrayScope(['GRADE' => 'G1', 'BASE' => new Number('3000')])))->toBe('45.00');
});

it('rejects a negative result instead of clamping', function () {
    expect(formulaMessage(fn () => calculateSpecs([earning('POT', 'BASE - 5000')])))->toBe('Komponen POT menghasilkan nilai negatif (-2000.00).');
});

// #22
it('has no PHP evaluation path in the formula engine', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Domain/Payroll/Formula'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $forbidden = is_array($token)
                ? $token[0] === T_EVAL || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['create_function', 'call_user_func', 'call_user_func_array', 'assert'], true))
                : $token === '`';
            expect($forbidden)->toBeFalse($file->getPathname());
        }
    }
});
