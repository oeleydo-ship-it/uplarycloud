<?php

namespace App\Services\Marketing;

use App\Models\BlogPost;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BlogAiService
{
    public function generate(BlogPost $post): array
    {
        $settings = app(PlatformSettings::class)->group('blog_ai');
        $key = $settings['blog_ai_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Configure the blog AI API key in SEO & Analytics before generating content.');
        }

        $base = rtrim($settings['blog_ai_base_url'] ?? 'https://api.openai.com/v1', '/');
        $model = $settings['blog_ai_model'] ?? 'gpt-5-mini';
        $keywords = implode(', ', $post->keywords ?? []);
        $brief = $post->ai_prompt ?: 'Write a practical, authoritative article for teams deploying and operating cloud applications.';
        $prompt = "Create an SEO-optimized blog article. Focus keyword: {$post->focus_keyword}. Supporting keywords: {$keywords}. Brief: {$brief}. Return only valid JSON with keys title, excerpt, body_html, meta_title, meta_description, category, read_minutes. body_html must use semantic h2, h3, p, ul and li tags; do not include h1, scripts, styles, markdown fences, or unverifiable claims.";

        $response = Http::timeout(120)->retry(2, 1000)->withToken($key)->post($base.'/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert SaaS content strategist and technical editor. Return strict JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ])->throw();

        $content = $response->json('choices.0.message.content');
        $data = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('The AI provider returned an invalid article response.');
        }

        return [
            'title' => str((string) ($data['title'] ?? $post->title))->limit(255, '')->toString(),
            'excerpt' => str((string) ($data['excerpt'] ?? ''))->limit(1000, '')->toString(),
            'body_html' => strip_tags((string) ($data['body_html'] ?? ''), '<h2><h3><h4><p><ul><ol><li><strong><em><a><blockquote><code><pre>'),
            'meta_title' => str((string) ($data['meta_title'] ?? $data['title'] ?? $post->title))->limit(160, '')->toString(),
            'meta_description' => str((string) ($data['meta_description'] ?? $data['excerpt'] ?? ''))->limit(320, '')->toString(),
            'category' => str((string) ($data['category'] ?? $post->category ?? 'Operations'))->limit(100, '')->toString(),
            'read_minutes' => max(1, min(60, (int) ($data['read_minutes'] ?? 5))),
        ];
    }
}
