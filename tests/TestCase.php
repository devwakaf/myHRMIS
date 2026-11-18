<?php

namespace Tests;

use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OrganisationContext::clear();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        OrganisationContext::clear();

        parent::tearDown();
    }

    /**
     * Include the test-only migrations (FakeApprovable table) in RefreshDatabase.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $app->afterResolving('migrator', fn ($migrator) => $migrator->path(base_path('tests/Support/migrations')));

        return $app;
    }

    protected function skipUnlessFortifyFeature(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
