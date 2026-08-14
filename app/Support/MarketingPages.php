<?php

namespace App\Support;

use App\Models\MarketingPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketingPages
{
    public static function defaults(): array
    {
        return [
            'home' => ['title' => 'Home', 'nav_label' => 'Home', 'hero_kicker' => 'Docker operations, simplified', 'hero_title' => 'From server to production without the scramble.', 'hero_description' => 'Connect servers, deploy applications, automate domains and SSL, and keep production operations in one clear control plane.', 'meta_title' => 'Cloud application deployment and server management', 'meta_description' => 'Connect servers, deploy marketplace and Git applications, manage domains, SSL, backups, monitoring, and production operations from one console.', 'show_in_nav' => false, 'position' => 0],
            'features' => ['title' => 'Features', 'nav_label' => 'Features', 'hero_kicker' => 'Product', 'hero_title' => 'Everything your production stack needs, in one place.', 'hero_description' => 'Move from a connected host to a secure, observable application with repeatable workflows for every release.', 'meta_description' => 'Explore server management, application deployments, domains, SSL, backups, monitoring, logs, and team operations.', 'position' => 10],
            'pricing' => ['title' => 'Pricing', 'nav_label' => 'Pricing', 'hero_kicker' => 'Simple pricing', 'hero_title' => 'Start free. Grow when the stack does.', 'hero_description' => 'Choose clear limits and operational capabilities for personal projects, agencies, and production teams.', 'meta_description' => 'Compare Uplary Cloud plans, quotas, managed server access, backups, monitoring, and team features.', 'position' => 20],
            'use-cases' => ['title' => 'Use cases', 'nav_label' => 'Use cases', 'hero_kicker' => 'Built for your workflow', 'hero_title' => 'One control plane for every kind of team.', 'hero_description' => 'Run client stacks, product infrastructure, and independent projects with the same dependable operational model.', 'meta_description' => 'See how agencies, developers, and product teams use Uplary Cloud to deploy and operate applications.', 'position' => 30],
            'about' => ['title' => 'About', 'nav_label' => 'About', 'hero_kicker' => 'Our mission', 'hero_title' => 'Make production operations understandable.', 'hero_description' => 'A clear control plane that gives teams a shared, accurate picture of servers, applications, domains, releases, and operational health.', 'meta_description' => 'Learn why Uplary Cloud was built and how it simplifies production operations for modern teams.', 'position' => 40],
            'contact' => ['title' => 'Contact', 'nav_label' => 'Contact', 'hero_kicker' => 'Talk to us', 'hero_title' => 'Tell us what you are building.', 'hero_description' => 'Ask about plans, onboarding, partnerships, managed infrastructure, or moving an existing stack.', 'meta_description' => 'Contact Uplary Cloud for sales, onboarding, partnerships, and platform questions.', 'position' => 50],
        ];
    }

    public function find(string $slug, bool $includeDrafts = false): MarketingPage
    {
        $default = static::defaults()[$slug] ?? ['title' => str($slug)->headline()->toString(), 'nav_label' => str($slug)->headline()->toString(), 'position' => 100];
        if (! Schema::hasTable('marketing_pages')) {
            return new MarketingPage($default + ['slug' => $slug, 'published' => true, 'show_in_nav' => true, 'robots_index' => true, 'robots_follow' => true]);
        }

        $query = MarketingPage::query()->where('slug', $slug);
        if (! $includeDrafts) {
            $query->where('published', true);
        }

        return $query->first() ?? new MarketingPage($default + ['slug' => $slug, 'published' => true, 'show_in_nav' => true, 'robots_index' => true, 'robots_follow' => true]);
    }

    public function editable(string $slug): MarketingPage
    {
        $page = $this->find($slug, true);
        if ($page->exists) {
            return $page;
        }

        return MarketingPage::create($page->getAttributes());
    }

    public function navigation(): Collection
    {
        return $this->published()->where('show_in_nav', true)->sortBy('position')->values();
    }

    public function published(): Collection
    {
        $core = collect(array_keys(static::defaults()))->map(fn (string $slug) => $this->find($slug));
        if (! Schema::hasTable('marketing_pages')) {
            return $core;
        }

        return $core->concat(MarketingPage::query()->where('published', true)->whereNotIn('slug', MarketingPage::CORE_SLUGS)->orderBy('position')->get());
    }
}
