<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_free_freemium_and_paid_supported_apps(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(50, Application::count());
        $this->assertGreaterThanOrEqual(40, Application::where('pricing_model', 'free')->count());
        $this->assertGreaterThanOrEqual(17, Application::whereHas('category', fn ($q) => $q->where('slug', 'cms'))->count());

        $this->assertDatabaseHas('applications', [
            'slug' => 'drupal',
            'pricing_model' => 'free',
            'license_type' => 'open_source',
            'active' => true,
        ]);
        $this->assertDatabaseHas('applications', [
            'slug' => 'joomla',
            'pricing_model' => 'free',
            'active' => true,
        ]);
        $this->assertDatabaseHas('applications', [
            'slug' => 'gitlab-ee',
            'pricing_model' => 'freemium',
            'license_type' => 'source_available',
        ]);
        $this->assertDatabaseHas('applications', [
            'slug' => 'portainer-business',
            'pricing_model' => 'paid',
            'requires_license' => true,
            'active' => true,
        ]);
        $this->assertDatabaseHas('applications', [
            'slug' => 'craft-cms',
            'pricing_model' => 'paid',
            'requires_license' => true,
            'active' => true,
        ]);
        $this->assertTrue(Application::where('slug', 'onlyoffice-enterprise')->firstOrFail()->template()->exists());
        $this->assertTrue(Application::where('slug', 'directus')->firstOrFail()->template()->exists());
    }

    public function test_paid_catalog_apps_remain_installable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'demo-workspace')->firstOrFail();

        foreach (['portainer-business', 'onlyoffice-enterprise', 'craft-cms'] as $slug) {
            $app = Application::where('slug', $slug)->firstOrFail();
            $this->assertTrue($app->active);
            $this->assertTrue($app->template()->exists());

            $this->actingAs($user)
                ->withSession(['tenant_id' => $tenant->id])
                ->get(route('applications.install', $app))
                ->assertOk()
                ->assertSee('Install', false)
                ->assertSee($app->name, false);
        }
    }
}
