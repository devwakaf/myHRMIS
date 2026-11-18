<?php

// spec-02 §2.6: the ledger is append-only — no code updates or deletes LeaveMovement rows.

it('never updates or deletes Ledger Cuti rows in application code', function () {
    $offenders = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if (preg_match('/LeaveMovement::(query\(\)|where|whereIn|whereKey)[^;]*->(update|delete|forceDelete|increment|decrement)\(/s', $code) === 1) {
            $offenders[] = $file->getPathname();
        }
        if (preg_match('/leave_movements[\'"]\)[^;]*->(update|delete)\(/s', $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

arch('leave models do not depend on HTTP')
    ->expect('App\Domain\Leave\Models')
    ->not->toUse(['Illuminate\Http', 'App\Http']);
