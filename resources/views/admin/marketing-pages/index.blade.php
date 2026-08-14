<x-admin-layout title="Public Pages">
    <div class="admin-heading"><div><p>SUPERADMIN / WEBSITE</p><h1>Public Pages</h1><span>Edit landing-page copy, navigation, publishing, and page-specific search metadata.</span></div><a class="button button--primary" href="{{ route('admin.marketing-pages.create') }}"><i data-lucide="plus"></i>New page</a></div>
    <article class="card admin-table-card">
        <table><thead><tr><th>Page</th><th>Navigation</th><th>Search visibility</th><th>Status</th><th>Updated</th><th></th></tr></thead>
            <tbody>@foreach($pages as $page)<tr>
                <td><strong>{{ $page->title }}</strong><small>/{{ $page->slug === 'home' ? '' : ($page->isCore() ? $page->slug : 'pages/'.$page->slug) }}</small></td>
                <td>{{ $page->show_in_nav ? ($page->nav_label ?: $page->title) : 'Hidden' }}</td>
                <td><span class="admin-pill {{ $page->robots_index ? '' : 'admin-pill--purple' }}">{{ $page->robots_index ? 'Index' : 'Noindex' }}</span></td>
                <td>{{ $page->published ? 'Published' : 'Draft' }}</td><td>{{ $page->updated_at?->diffForHumans() }}</td>
                <td><a class="admin-edit" href="{{ route('admin.marketing-pages.edit', $page) }}">Edit</a></td>
            </tr>@endforeach</tbody>
        </table>
    </article>
</x-admin-layout>
