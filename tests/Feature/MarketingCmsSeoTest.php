<?php

namespace Tests\Feature;

use App\Models\MarketingPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingCmsSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_public_page_content_and_regular_users_cannot(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.marketing-pages.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.marketing-pages.index'))->assertOk()->assertSee('Public Pages');

        $page = MarketingPage::where('slug', 'features')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.marketing-pages.update', $page), [
            'slug' => 'features',
            'title' => 'Platform Features',
            'nav_label' => 'Features',
            'hero_kicker' => 'Modern operations',
            'hero_title' => 'A better production workflow',
            'hero_description' => 'Ship and operate services with confidence.',
            'body_html' => '<h2>Editable capability content</h2><p>Managed by the platform team.</p>',
            'meta_title' => 'Production platform features',
            'meta_description' => 'A focused description for search engines.',
            'position' => 10,
            'published' => 1,
            'show_in_nav' => 1,
            'robots_index' => 1,
            'robots_follow' => 1,
        ])->assertSessionHasNoErrors();

        $this->get(route('marketing.features'))
            ->assertOk()
            ->assertSee('A better production workflow')
            ->assertSee('Editable capability content')
            ->assertSee('Production platform features | Uplary Cloud', false)
            ->assertSee('A focused description for search engines.');
    }

    public function test_custom_pages_respect_publish_state_navigation_and_robots_settings(): void
    {
        User::factory()->create();
        $page = MarketingPage::create([
            'slug' => 'security',
            'title' => 'Security',
            'nav_label' => 'Security',
            'hero_title' => 'Security by design',
            'hero_description' => 'How the platform protects production workloads.',
            'body_html' => '<h2>Operational safeguards</h2>',
            'published' => false,
            'show_in_nav' => true,
            'robots_index' => false,
            'robots_follow' => true,
            'position' => 25,
        ]);

        $this->get(route('marketing.page', $page))->assertNotFound();
        $page->update(['published' => true]);

        $this->get(route('marketing.page', $page))
            ->assertOk()
            ->assertSee('Security by design')
            ->assertSee('Operational safeguards')
            ->assertSee('content="noindex,follow"', false);
        $this->get(route('marketing.features'))->assertSee('Security');
        $this->get(route('sitemap'))->assertOk()->assertSee(route('marketing.page', $page), false);
    }

    public function test_global_seo_verification_analytics_and_robots_are_rendered(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->put(route('admin.seo.update'), [
            'default_meta_title' => 'Uplary Cloud',
            'default_meta_description' => 'Deploy and operate modern applications.',
            'canonical_base_url' => 'https://cloud.example.com',
            'default_og_image' => 'https://cloud.example.com/social.png',
            'twitter_handle' => '@uplary',
            'google_site_verification' => 'google-token',
            'bing_site_verification' => 'bing-token',
            'google_analytics_id' => 'G-ABC123XYZ',
            'google_tag_manager_id' => 'GTM-ABC123',
            'robots_follow' => 1,
        ])->assertSessionHasNoErrors();

        $this->get(route('marketing.features'))
            ->assertOk()
            ->assertSee('google-site-verification', false)
            ->assertSee('google-token')
            ->assertSee('msvalidate.01', false)
            ->assertSee('G-ABC123XYZ')
            ->assertSee('GTM-ABC123')
            ->assertSee('content="noindex,follow"', false);

        $this->get(route('robots'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("Disallow: /", false)
            ->assertSee(route('sitemap'), false);
    }
}
