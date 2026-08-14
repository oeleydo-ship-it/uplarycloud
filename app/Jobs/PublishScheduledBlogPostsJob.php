<?php

namespace App\Jobs;

use App\Models\BlogPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishScheduledBlogPostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        BlogPost::where('status', 'scheduled')->whereNotNull('publish_at')->where('publish_at', '<=', now())
            ->each(fn (BlogPost $post) => $post->update(['status' => 'published', 'published_at' => $post->publish_at]));
    }
}
