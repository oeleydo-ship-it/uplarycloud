<?php

namespace Tests\Feature;

use App\Jobs\GenerateBlogPostJob;
use App\Jobs\PublishScheduledBlogPostsJob;
use App\Models\BlogPost;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BlogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_and_schedule_an_seo_optimized_post(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.blog-posts.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.blog-posts.store'), [
            'title' => 'Managed Docker Hosting Guide',
            'slug' => 'managed-docker-hosting-guide',
            'category' => 'Guides',
            'excerpt' => 'A useful guide to managed Docker hosting.',
            'body_html' => '<h2>Choose the right workflow</h2><p>Start with repeatable deployments.</p>',
            'focus_keyword' => 'managed Docker hosting',
            'keywords_text' => 'Docker deployment, cloud operations',
            'meta_title' => 'Managed Docker Hosting Guide',
            'meta_description' => 'Learn how to choose and operate managed Docker hosting.',
            'status' => 'scheduled',
            'publish_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'read_minutes' => 6,
            'robots_index' => 1,
            'robots_follow' => 1,
        ])->assertSessionHasNoErrors();

        $post = BlogPost::firstOrFail();
        $this->get(route('marketing.blog.show', $post->slug))->assertNotFound();
        app(PublishScheduledBlogPostsJob::class)->handle();

        $this->get(route('marketing.blog.show', $post->slug))
            ->assertOk()->assertSee('Choose the right workflow')->assertSee('Managed Docker Hosting Guide | Uplary Cloud', false);
        $this->get(route('sitemap'))->assertSee(route('marketing.blog.show', $post->slug), false);
    }

    public function test_ai_generation_is_queued_and_writes_an_editable_seo_draft(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $post = BlogPost::create(['author_id' => $admin->id, 'title' => 'Draft', 'slug' => 'ai-draft', 'focus_keyword' => 'application deployment', 'keywords' => ['Docker hosting'], 'status' => 'draft', 'read_minutes' => 5]);

        $this->actingAs($admin)->post(route('admin.blog-posts.generate', $post), [
            'focus_keyword' => 'application deployment',
            'keywords_text' => 'Docker hosting, deployment automation',
            'ai_prompt' => 'Explain a safe production workflow.',
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(GenerateBlogPostJob::class, fn ($job) => $job->postId === $post->id);

        app(PlatformSettings::class)->put('blog_ai', ['blog_ai_api_key' => 'secret-key', 'blog_ai_base_url' => 'https://ai.example.test/v1', 'blog_ai_model' => 'writer-model']);
        Http::fake(['ai.example.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'title' => 'Application Deployment Without Guesswork', 'excerpt' => 'A practical deployment workflow.', 'body_html' => '<h2>Prepare production</h2><p>Use repeatable releases.</p>', 'meta_title' => 'Application Deployment Guide', 'meta_description' => 'Build a reliable application deployment workflow.', 'category' => 'Operations', 'read_minutes' => 7,
        ])]]]])]);
        app(GenerateBlogPostJob::class, ['postId' => $post->id])->handle(app(\App\Services\Marketing\BlogAiService::class));

        $this->assertSame('ready', $post->fresh()->ai_status);
        $this->assertStringContainsString('Prepare production', $post->fresh()->body_html);
        $this->assertSame('Application Deployment Guide', $post->fresh()->meta_title);
    }
}
