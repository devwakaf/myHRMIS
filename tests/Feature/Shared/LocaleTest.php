<?php

use Illuminate\Support\Arr;

// B8 #64
it('has the same translation keys in lang/ms and lang/en', function () {
    $files = array_map('basename', glob(lang_path('ms').'/*.php'));
    expect(array_map('basename', glob(lang_path('en').'/*.php')))->toEqualCanonicalizing($files);

    foreach ($files as $file) {
        $ms = array_keys(Arr::dot(require lang_path("ms/{$file}")));
        $en = array_keys(Arr::dot(require lang_path("en/{$file}")));

        expect(array_values(array_diff($ms, $en)))->toBe([], "Missing in en/{$file}")
            ->and(array_values(array_diff($en, $ms)))->toBe([], "Missing in ms/{$file}");
    }
});

// B8 #65
it('persists the locale toggle on the user and uses the session for guests', function () {
    $org = makeOrganisation();
    $user = userIn($org, 'kakitangan');

    $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'ms'));
    $this->put('/locale', ['locale' => 'en'])->assertRedirect();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'en'));

    $this->flushSession();
    actingInOrg($user, $org)->put('/locale', ['locale' => 'en']);
    expect($user->fresh()->locale)->toBe('en');

    $this->flushSession();
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('renders the app shell in BM by default', function () {
    $org = makeOrganisation();
    $user = userIn($org, 'pengurus_hr');

    actingInOrg($user, $org)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('locale', 'ms')->where('organisation.id', $org->id));

    expect(__('common.nav.employees'))->toBe('Kakitangan');
});
