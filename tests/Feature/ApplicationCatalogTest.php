<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_free_freemium_and_paid_supported_apps(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(30, Application::count());
        $this->assertGreaterThanOrEqual(25, Application::where('pricing_model', 'free')->count());
        $this->assertDatabaseHas('applications', [
            'slug' => 'gitlab-ee',
            'pricing_model' => 'freemium',
            'license_type' => 'source_available',
        ]);
        $this->assertDatabaseHas('applications', [
            'slug' => 'portainer-business',
            'pricing_model' => 'paid',
            'requires_license' => true,
        ]);
        $this->assertTrue(Application::where('slug', 'onlyoffice-enterprise')->firstOrFail()->template()->exists());
    }
}
