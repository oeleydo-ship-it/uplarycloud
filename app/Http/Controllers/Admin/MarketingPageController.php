<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingPage;
use App\Support\MarketingPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function index(MarketingPages $pages): View
    {
        foreach (array_keys(MarketingPages::defaults()) as $slug) {
            $pages->editable($slug);
        }

        return view('admin.marketing-pages.index', ['pages' => MarketingPage::orderBy('position')->get()]);
    }

    public function create(): View
    {
        return view('admin.marketing-pages.edit', ['page' => new MarketingPage(['published' => true, 'show_in_nav' => true, 'robots_index' => true, 'robots_follow' => true, 'position' => 100])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $page = MarketingPage::create($this->data($request));

        return redirect()->route('admin.marketing-pages.edit', $page)->with('success', 'Public page created.');
    }

    public function edit(MarketingPage $page): View
    {
        return view('admin.marketing-pages.edit', compact('page'));
    }

    public function update(Request $request, MarketingPage $page): RedirectResponse
    {
        $data = $this->data($request, $page);
        if ($page->isCore()) {
            $data['slug'] = $page->slug;
        }
        $page->update($data);

        return back()->with('success', 'Public page updated.');
    }

    public function destroy(MarketingPage $page): RedirectResponse
    {
        abort_if($page->isCore(), 422, 'Core marketing pages cannot be deleted. Unpublish them instead.');
        $page->delete();

        return redirect()->route('admin.marketing-pages.index')->with('success', 'Public page deleted.');
    }

    private function data(Request $request, ?MarketingPage $page = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('marketing_pages')->ignore($page)],
            'title' => ['required', 'string', 'max:160'],
            'nav_label' => ['nullable', 'string', 'max:60'],
            'hero_kicker' => ['nullable', 'string', 'max:100'],
            'hero_title' => ['nullable', 'string', 'max:200'],
            'hero_description' => ['nullable', 'string', 'max:1000'],
            'body_html' => ['nullable', 'string', 'max:250000'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'url', 'max:1000'],
            'og_image' => ['nullable', 'url', 'max:1000'],
            'position' => ['required', 'integer', 'min:0', 'max:65000'],
            'robots_index' => ['nullable', 'boolean'],
            'robots_follow' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
            'show_in_nav' => ['nullable', 'boolean'],
        ]);

        foreach (['robots_index', 'robots_follow', 'published', 'show_in_nav'] as $key) {
            $data[$key] = $request->boolean($key);
        }

        return $data;
    }
}
