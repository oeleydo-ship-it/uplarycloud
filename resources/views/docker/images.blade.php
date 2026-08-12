<x-dashboard-layout title="Docker images">
    <div class="images-page" x-data="{ pullOpen: false }">
        <div class="page-heading images-reference-heading">
            <div>
                <p class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a><i data-lucide="chevron-right"></i> Images</p>
                <h1>Images <i data-lucide="info"></i></h1>
                <p>Manage Docker images across your servers, applications, containers, and volumes.</p>
            </div>
            <div class="heading-actions">
                <form method="POST" action="{{ route('images.cleanup') }}" onsubmit="return confirm('Remove every image that is not used by a container?')">@csrf
                    <button class="button button--secondary" @disabled(!$counts['unused'])><i data-lucide="trash-2"></i> Cleanup Unused</button>
                </form>
                <button type="button" class="button button--primary" @click="pullOpen=true"><i data-lucide="download"></i> Pull Image</button>
            </div>
        </div>

        <section class="stats-grid images-reference-stats">
            <x-stat-card label="Total Images" :value="$counts['all']" detail="Cached across servers" icon="package" tone="purple" :href="route('images.index', ['show_unused'=>1])" />
            <x-stat-card label="Total Size" :value="number_format($counts['size']/1073741824,2).' GB'" detail="Docker image storage" icon="database" tone="green" :href="route('images.index', ['show_unused'=>1])" />
            <x-stat-card label="Unused Images" :value="$counts['unused']" :detail="number_format($counts['unused_size']/1073741824,2).' GB reclaimable'" icon="layers" tone="blue" :href="route('images.index', ['status'=>'unused', 'show_unused'=>1])" />
            <x-stat-card label="Outdated Images" :value="$counts['updates']" detail="Updates available" icon="refresh-cw" tone="orange" :href="route('images.index', ['status'=>'outdated', 'show_unused'=>1])" />
        </section>

        <form class="images-reference-filters" method="get">
            <label class="table-search"><i data-lucide="search"></i><input name="search" value="{{ request('search') }}" placeholder="Search images..."></label>
            <label class="filter-select"><select name="server" onchange="this.form.submit()"><option value="">All Servers</option>@foreach($servers as $server)<option value="{{ $server->id }}" @selected((string)request('server')===(string)$server->id)>{{ $server->name }}</option>@endforeach</select><i data-lucide="chevron-down"></i></label>
            <label class="filter-select"><select name="repository" onchange="this.form.submit()"><option value="">All Repositories</option>@foreach($repositories as $repository)<option value="{{ $repository }}" @selected(request('repository')===$repository)>{{ $repository }}</option>@endforeach</select><i data-lucide="chevron-down"></i></label>
            <label class="filter-select"><select name="tag" onchange="this.form.submit()"><option value="">All Tags</option>@foreach($tags as $tag)<option value="{{ $tag }}" @selected(request('tag')===$tag)>{{ $tag }}</option>@endforeach</select><i data-lucide="chevron-down"></i></label>
            <label class="filter-select"><select name="status" onchange="this.form.submit()"><option value="">All Status</option><option value="in_use" @selected(request('status')==='in_use')>In Use</option><option value="unused" @selected(request('status')==='unused')>Unused</option><option value="outdated" @selected(request('status')==='outdated')>Outdated</option><option value="current" @selected(request('status')==='current')>Current</option></select><i data-lucide="chevron-down"></i></label>
            <label class="images-unused-toggle"><span>Show Unused</span><input type="checkbox" name="show_unused" value="1" @checked(request()->boolean('show_unused')) onchange="this.form.submit()"><i></i></label>
            <button class="icon-button images-refresh" aria-label="Apply filters"><i data-lucide="refresh-cw"></i></button>
        </form>

        <div class="images-reference-layout">
            <article class="card images-table-card">
                @if($images->isEmpty())
                    <div class="empty-state"><span><i data-lucide="package-open"></i></span><h2>No images found</h2><p>Pull an image or change the filters to see cached images.</p><button type="button" class="button button--primary" @click="pullOpen=true">Pull image</button></div>
                @else
                    <div class="images-table-scroll"><div class="images-reference-table">
                        <div class="images-reference-head"><span>Image</span><span>Application</span><span>Tag</span><span>Size</span><span>Created</span><span>Server</span><span>Status</span><span>Actions</span></div>
                        @foreach($images as $image)
                            @php($application=$image->linkedApplications()->first())
                            <div class="images-reference-row {{ $selectedImage?->is($image) ? 'is-selected' : '' }}">
                                <a class="images-reference-primary" href="{{ request()->fullUrlWithQuery(['selected'=>$image->uuid]) }}"><span class="images-docker-icon"><i data-lucide="container"></i></span><span><strong>{{ $image->repository }}</strong><small class="mono">{{ str($image->docker_id ?: $image->digest ?: $image->uuid)->limit(14) }}</small></span></a>
                                <span class="images-application">@if($application)<x-application-icon :application="$application" size="sm"/><span><strong>{{ $application->name }}</strong><small>{{ $image->usageCount() }} container{{ $image->usageCount()===1?'':'s' }}</small></span>@else<span class="images-muted">—</span><small>No application</small>@endif</span>
                                <span><em class="images-tag">{{ $image->tag }}</em></span>
                                <span><strong>{{ $image->sizeLabel() }}</strong></span>
                                <span><strong>{{ ($image->pulled_at ?: $image->created_at)->diffForHumans() }}</strong></span>
                                <span><strong>{{ $image->server?->name ?? 'Server removed' }}</strong>@if($image->server)<small><i></i>{{ $image->server->status->label() }}</small>@endif</span>
                                <span>@if($image->update_available)<em class="images-status images-status--outdated">Outdated</em>@elseif($image->usageCount())<em class="images-status images-status--used">In Use</em>@else<em class="images-status images-status--unused">Unused</em>@endif</span>
                                <span class="images-actions"><form method="POST" action="{{ route('images.action',$image) }}">@csrf<input type="hidden" name="action" value="pull"><button class="icon-button" title="Pull latest"><i data-lucide="play"></i></button></form><details><summary class="icon-button"><i data-lucide="ellipsis"></i></summary><div>@if($image->update_available)<form method="POST" action="{{ route('images.action',$image) }}">@csrf<input type="hidden" name="action" value="backup_update"><button><i data-lucide="shield-check"></i> Backup & update</button></form>@endif<a href="{{ route('containers.index',['search'=>$image->reference()]) }}"><i data-lucide="box"></i> View containers</a>@if($image->server && ! $image->server->trashed())<a href="{{ route('servers.show',$image->server) }}"><i data-lucide="server"></i> View server</a>@endif @if(! $image->usageCount())<form method="POST" action="{{ route('images.action',$image) }}" onsubmit="return confirm('Remove this unused image?')">@csrf<input type="hidden" name="action" value="remove"><button class="is-danger"><i data-lucide="trash-2"></i> Remove image</button></form>@endif</div></details></span>
                            </div>
                        @endforeach
                    </div></div>
                    <div class="images-pagination"><span>Showing {{ $images->firstItem() }} to {{ $images->lastItem() }} of {{ $images->total() }} images</span>{{ $images->links() }}</div>
                @endif
            </article>

            <aside class="images-side-stack">
                <section class="card image-details-card"><div class="card-head"><div><h2>Image Details</h2><p>Application and runtime relationships</p></div></div>
                    @if($selectedImage)
                        <div class="image-details-body"><div class="image-details-title"><span class="images-docker-icon"><i data-lucide="container"></i></span><div><strong>{{ $selectedImage->reference() }}</strong><small class="mono">{{ $selectedImage->digest ?: $selectedImage->docker_id ?: 'Digest pending' }}</small></div></div><dl><div><dt>Containers</dt><dd>{{ $selectedImage->usageCount() }}</dd></div><div><dt>Applications</dt><dd>{{ $selectedImage->linkedApplications()->count() }}</dd></div><div><dt>Volumes</dt><dd>{{ $selectedImage->linkedVolumes()->count() }}</dd></div><div><dt>Server</dt><dd>{{ $selectedImage->server?->name ?? 'Server removed' }}</dd></div></dl>
                            @foreach($selectedImage->containers as $container)<article class="image-runtime-link"><x-application-icon :application="$container->resolvedApplication()" size="sm" fallback-icon="box"/><span><strong>{{ $container->applicationLabel() }}</strong><small>{{ $container->deployment?->name ? $container->deployment->name.' · ' : '' }}{{ $container->name }} · {{ $container->volumes->count() }} volume{{ $container->volumes->count()===1?'':'s' }}</small></span>@if($container->deployment)<a href="{{ route('deployments.show',$container->deployment) }}"><i data-lucide="external-link"></i></a>@endif</article>@endforeach
                            <div class="image-detail-links"><a href="{{ route('containers.index',['search'=>$selectedImage->reference()]) }}"><i data-lucide="box"></i> Containers</a>@if($selectedImage->linkedVolumes()->isNotEmpty())<a href="{{ route('volumes.index',['server'=>$selectedImage->server_id]) }}"><i data-lucide="database"></i> Volumes</a>@endif</div>
                        </div>
                    @else<div class="image-details-empty"><i data-lucide="package"></i><p>Select an image to inspect its applications, containers, and volumes.</p></div>@endif
                </section>
                <section class="card image-storage-card"><div class="card-head"><div><h2>Storage Usage</h2></div></div><div class="image-storage-body"><div class="image-storage-ring" style="--unused:{{ $counts['size'] ? round($counts['unused_size']/$counts['size']*100) : 0 }}"><strong>{{ number_format($counts['size']/1073741824,2) }} GB</strong><small>Total</small></div><dl><div><dt><i class="used"></i> In Use</dt><dd>{{ number_format(($counts['size']-$counts['unused_size'])/1073741824,2) }} GB</dd></div><div><dt><i class="unused"></i> Unused</dt><dd>{{ number_format($counts['unused_size']/1073741824,2) }} GB</dd></div></dl></div></section>
                <section class="card image-quick-card"><div class="card-head"><div><h2>Quick Actions</h2></div></div><button type="button" @click="pullOpen=true"><span><i data-lucide="download"></i></span><span><strong>Pull Image</strong><small>Download from registry</small></span></button><form method="POST" action="{{ route('images.check') }}">@csrf<button><span><i data-lucide="shield-check"></i></span><span><strong>Check Updates</strong><small>Scan upstream releases</small></span></button></form><form method="POST" action="{{ route('images.cleanup') }}">@csrf<button @disabled(!$counts['unused'])><span><i data-lucide="trash-2"></i></span><span><strong>Prune Unused Images</strong><small>Reclaim {{ number_format($counts['unused_size']/1073741824,2) }} GB</small></span></button></form></section>
            </aside>
        </div>

        <div class="modal-backdrop" x-show="pullOpen" x-cloak @keydown.escape.window="pullOpen=false"><section class="domain-modal" @click.outside="pullOpen=false"><div class="domain-modal-head"><div><span class="section-icon"><i data-lucide="download"></i></span><div><h2>Pull Docker image</h2><p>Download an image from a public or configured registry.</p></div></div><button type="button" @click="pullOpen=false"><i data-lucide="x"></i></button></div><form method="POST" action="{{ route('images.pull') }}">@csrf<div class="domain-modal-body"><label class="field"><span>Server</span><select name="server_id" required>@foreach($servers as $server)<option value="{{ $server->id }}">{{ $server->name }}</option>@endforeach</select></label><label class="field"><span>Repository</span><input name="repository" placeholder="nginx" required></label><label class="field"><span>Tag</span><input name="tag" value="latest" required></label></div><div class="domain-modal-actions"><button type="button" class="button button--secondary" @click="pullOpen=false">Cancel</button><button class="button button--primary"><i data-lucide="download"></i> Pull image</button></div></form></section></div>
    </div>
</x-dashboard-layout>
