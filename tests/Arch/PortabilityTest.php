<?php

// B8 #69 and A10 arch rules.

function phpFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('matches every Inertia::render component to a .vue file with exact case', function () {
    $pages = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/pages'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'vue') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('js/pages')) + 1));
            $pages[] = substr($relative, 0, -4);
        }
    }

    $rendered = [];
    foreach (array_merge(phpFiles('app'), phpFiles('routes')) as $file) {
        preg_match_all("/(?:Inertia::render|Route::inertia)\(\s*(?:'[^']*',\s*)?'([^']+)'/", file_get_contents($file), $m);
        $rendered = array_merge($rendered, $m[1]);
    }

    expect($rendered)->not->toBeEmpty();
    foreach (array_unique($rendered) as $component) {
        expect(in_array($component, $pages, true))->toBeTrue("Missing page (case-sensitive): {$component}");
    }
});

it('has no backslash path literals, env() calls or debug helpers in app/', function () {
    foreach (phpFiles('app') as $file) {
        $code = file_get_contents($file);
        expect(preg_match("/storage_path\('[^']*\\\\\\\\|base_path\('[^']*\\\\\\\\/", $code))->toBe(0, $file)
            ->and(preg_match('/\benv\(/', $code))->toBe(0, $file)
            ->and(preg_match('/\b(dd|dump|ray)\(/', $code))->toBe(0, $file);
    }
});

arch('domain models do not depend on HTTP')
    ->expect('App\Domain\Core\Models')
    ->not->toUse(['Illuminate\Http', 'App\Http']);

arch('shared approval models do not depend on HTTP')
    ->expect('App\Domain\Shared\Approval\Models')
    ->not->toUse(['Illuminate\Http', 'App\Http']);

it('never types money amounts as float', function () {
    foreach (array_merge(phpFiles('app/Domain')) as $file) {
        expect(preg_match('/float\s+\$\w*(amount|salary)\w*/i', file_get_contents($file)))->toBe(0, $file);
    }
});
