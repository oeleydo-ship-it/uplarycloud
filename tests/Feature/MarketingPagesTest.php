<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_view_marketing_pages(): void
    {
        User::factory()->create();

        $this->get(route('home'))->assertOk()->assertSee('From server to production', false);
        $this->get(route('marketing.features'))->assertOk()->assertSee('Servers', false)->assertSee('Marketplace', false);
        $this->get(route('marketing.pricing'))->assertOk()->assertSee('Free', false)->assertSee('Pro', false);
        $this->get(route('marketing.use-cases'))->assertOk()->assertSee('Agencies', false);
        $this->get(route('marketing.about'))->assertOk()->assertSee('control plane', false);
        $this->get(route('marketing.contact'))->assertOk()->assertSee('Send message', false);
        $this->get(route('marketing.blog'))->assertOk()->assertSee('Operating notes', false);
        $this->get(route('marketing.blog.show', 'from-server-to-production'))->assertOk()->assertSee('bare server', false);
    }

    public function test_authenticated_users_are_sent_from_home_to_the_dashboard(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_users_can_still_open_other_marketing_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('marketing.pricing'))->assertOk();
        $this->actingAs($user)->get(route('marketing.features'))->assertOk()->assertSee('Dashboard', false);
    }

    public function test_pricing_page_reads_live_plans_from_the_database(): void
    {
        User::factory()->create();
        $defaults = PlanCatalog::defaultsFor('pro');
        Plan::query()->create([
            'name' => 'Pro',
            'slug' => 'pro',
            'description' => 'For growing teams running production workloads.',
            'monthly_price' => 2900,
            'yearly_price' => 27840,
            'currency' => 'USD',
            'limits' => $defaults['limits'],
            'features' => ['Priority support', '30-day monitoring'],
            'gates' => $defaults['gates'],
            'featured' => true,
            'active' => true,
            'position' => 3,
        ]);

        $this->get(route('marketing.pricing'))
            ->assertOk()
            ->assertSee('Priority support', false)
            ->assertSee('30-day monitoring', false);
    }

    public function test_contact_form_validates_and_stores_an_inquiry(): void
    {
        User::factory()->create();
        Mail::fake();

        $this->post(route('marketing.contact.store'), [
            'name' => 'Ada',
            'email' => 'not-an-email',
            'topic' => 'sales',
            'subject' => 'Hello',
            'message' => 'Too short',
        ])->assertSessionHasErrors(['email', 'message']);

        $this->post(route('marketing.contact.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'company' => 'Analytical Engines',
            'topic' => 'sales',
            'subject' => 'Agency onboarding',
            'message' => 'We would like a walkthrough for three client workspaces.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('contact_inquiries', [
            'email' => 'ada@example.com',
            'topic' => 'sales',
            'subject' => 'Agency onboarding',
        ]);
    }

    public function test_unknown_blog_slug_is_not_found(): void
    {
        User::factory()->create();

        $this->get(route('marketing.blog.show', 'does-not-exist'))->assertNotFound();
    }

    public function test_login_register_and_dashboard_routes_still_work(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $tenant = Tenant::create(['name' => 'Workspace']);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk();
    }
}
