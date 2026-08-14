<?php

namespace App\Jobs;

use App\Models\BlogPost;
use App\Services\Marketing\BlogAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateBlogPostJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $postId)
    {
        $this->onQueue('default');
    }

    public function handle(BlogAiService $ai): void
    {
        $post = BlogPost::findOrFail($this->postId);
        $post->update(['ai_status' => 'generating', 'ai_error' => null]);
        try {
            $post->update($ai->generate($post) + ['ai_status' => 'ready', 'ai_error' => null]);
        } catch (Throwable $exception) {
            $post->update(['ai_status' => 'failed', 'ai_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
