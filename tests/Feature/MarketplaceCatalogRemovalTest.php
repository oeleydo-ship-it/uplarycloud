<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCategory;
use Database\Seeders\ApplicationCatalogExpansionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceCatalogRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_nocodb_is_not_added_by_catalog_seeding(): void
    {
        $this->seed(ApplicationCatalogExpansionSeeder::class);

        $this->assertDatabaseMissing('applications', ['slug' => 'nocodb']);
    }

    public function test_existing_nocodb_listing_is_deactivated_without_deleting_it(): void
    {
        $category = ApplicationCategory::create([
            'name' => 'Databases',
            'slug' => 'databases',
            'icon' => 'database',
            'position' => 1,
        ]);
        $application = Application::create([
            'category_id' => $category->id,
            'name' => 'NocoDB',
            'slug' => 'nocodb',
            'description' => 'Existing installation record',
            'docker_image' => 'nocodb/nocodb',
            'active' => true,
            'featured' => true,
        ]);

        $migration = require database_path('migrations/2026_08_15_000000_remove_nocodb_from_marketplace.php');
        $migration->up();

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'slug' => 'nocodb',
            'active' => false,
            'featured' => false,
        ]);
    }
}
