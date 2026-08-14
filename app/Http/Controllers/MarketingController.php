<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactInquiryRequest;
use App\Models\Application;
use App\Models\ContactInquiry;
use App\Models\MarketingPage;
use App\Support\Branding;
use App\Support\MarketingPosts;
use App\Support\MarketingPages;
use App\Support\PlatformSettings;
use App\Support\PublicPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Illuminate\Http\Response;
use Throwable;

class MarketingController extends Controller
{
    public function __construct(private readonly MarketingPages $pages) {}

    public function home(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('marketing.home', [
            'applications' => $this->featuredApplications(),
            'page' => $this->pages->find('home'),
        ]);
    }

    public function features(): View
    {
        return view('marketing.features', ['page' => $this->pages->find('features')]);
    }

    public function pricing(): View
    {
        return view('marketing.pricing', [
            'plans' => PublicPlans::all(),
            'page' => $this->pages->find('pricing'),
        ]);
    }

    public function useCases(): View
    {
        return view('marketing.use-cases', ['page' => $this->pages->find('use-cases')]);
    }

    public function about(): View
    {
        return view('marketing.about', ['page' => $this->pages->find('about')]);
    }

    public function contact(): View
    {
        return view('marketing.contact', ['page' => $this->pages->find('contact')]);
    }

    public function page(MarketingPage $page): View
    {
        abort_unless($page->published && ! $page->isCore(), 404);

        return view('marketing.page', compact('page'));
    }

    public function robots(PlatformSettings $settings): Response
    {
        $allow = (bool) ((int) $settings->get('seo', 'robots_index', 1));
        $body = $allow ? "User-agent: *\nAllow: /\n" : "User-agent: *\nDisallow: /\n";
        $body .= 'Sitemap: '.route('sitemap')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        return response()->view('marketing.sitemap', ['pages' => $this->pages->published(), 'posts' => MarketingPosts::all()], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function storeContact(StoreContactInquiryRequest $request, Branding $branding, PlatformSettings $settings): RedirectResponse
    {
        $inquiry = ContactInquiry::create($request->safe()->all() + [
            'ip_address' => $request->ip(),
        ]);

        $this->notifySupport($inquiry, $branding, $settings);

        return back()->with('success', 'Thanks — we received your message and will reply by email.');
    }

    public function blog(): View
    {
        return view('marketing.blog.index', [
            'posts' => MarketingPosts::all(),
        ]);
    }

    public function blogShow(string $slug): View
    {
        $post = MarketingPosts::find($slug);
        abort_unless($post, 404);

        return view('marketing.blog.show', [
            'post' => $post,
            'posts' => MarketingPosts::all()->reject(fn (object $item) => $item->slug === $slug)->values(),
        ]);
    }

    /**
     * @return Collection<int, mixed>
     */
    private function featuredApplications()
    {
        try {
            $apps = Application::query()
                ->where('active', true)
                ->orderByDesc('featured')
                ->orderByDesc('install_count')
                ->orderBy('name')
                ->limit(8)
                ->get();

            if ($apps->isNotEmpty()) {
                return $apps;
            }
        } catch (Throwable) {
            // Catalog may be empty immediately after install.
        }

        return collect([
            ['name' => 'WordPress', 'slug' => 'wordpress', 'icon' => 'panels-top-left', 'accent' => '#287eb3'],
            ['name' => 'n8n', 'slug' => 'n8n', 'icon' => 'workflow', 'accent' => '#ea4b71'],
            ['name' => 'Nextcloud', 'slug' => 'nextcloud', 'icon' => 'cloud', 'accent' => '#1689d4'],
            ['name' => 'Ghost', 'slug' => 'ghost', 'icon' => 'circle', 'accent' => '#202129'],
            ['name' => 'Gitea', 'slug' => 'gitea', 'icon' => 'git-branch', 'accent' => '#62a944'],
            ['name' => 'Uptime Kuma', 'slug' => 'uptime-kuma', 'icon' => 'heart-pulse', 'accent' => '#34b27b'],
        ])->map(fn (array $app) => (object) $app);
    }

    private function notifySupport(ContactInquiry $inquiry, Branding $branding, PlatformSettings $settings): void
    {
        $to = $branding->platform()['support_email'] ?: $settings->get('general', 'support_email');

        if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::raw(
                "New contact inquiry from {$inquiry->name} <{$inquiry->email}>\n"
                .'Company: '.($inquiry->company ?: '—')."\n"
                ."Topic: {$inquiry->topic}\n"
                ."Subject: {$inquiry->subject}\n\n"
                .$inquiry->message,
                function ($message) use ($to, $inquiry): void {
                    $message->to($to)
                        ->replyTo($inquiry->email, $inquiry->name)
                        ->subject('Contact: '.$inquiry->subject);
                },
            );
        } catch (Throwable) {
            // Storage is the source of truth; mail is best-effort.
        }
    }
}
