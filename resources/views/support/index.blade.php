<x-dashboard-layout title="Support">
    <div x-data="{ createOpen: {{ $errors->any() ? 'true' : 'false' }} }">
        <div class="page-heading">
            <div><p class="breadcrumb">Help / Support</p><h1>Support center</h1><p>Get help with deployments, infrastructure, billing, and workspace access.</p></div>
            <div class="heading-actions"><button class="button button--primary" @click="createOpen=true"><i data-lucide="plus"></i> New ticket</button></div>
        </div>

        <section class="stats-grid support-stats">
            <x-stat-card label="Open tickets" :value="$stats['open']" detail="Active conversations" icon="message-square" tone="purple" />
            <x-stat-card label="Urgent" :value="$stats['urgent']" detail="Needs immediate attention" icon="siren" tone="orange" />
            <x-stat-card label="Resolved" :value="$stats['resolved']" detail="Successfully completed" icon="circle-check-big" tone="green" />
            <x-stat-card label="All tickets" :value="$stats['total']" detail="Workspace history" icon="archive" tone="blue" />
        </section>

        <section class="support-layout">
            <article class="card support-ticket-card">
                <div class="table-toolbar">
                    <form class="search-filter-form" method="get">
                        <label class="table-search"><i data-lucide="search"></i><input name="search" value="{{ request('search') }}" placeholder="Search tickets..."></label>
                        <select name="status" onchange="this.form.submit()"><option value="">All statuses</option>@foreach(['open','waiting','resolved','closed'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select>
                        <button class="button button--secondary" type="submit">Filter</button>
                    </form>
                    <span class="table-count">{{ $tickets->total() }} tickets</span>
                </div>
                <div class="support-ticket-head"><span>Ticket</span><span>Category</span><span>Priority</span><span>Status</span><span>Updated</span><span></span></div>
                @forelse($tickets as $ticket)
                    <a href="{{ route('support.show', $ticket) }}" class="support-ticket-row">
                        <span><strong>{{ $ticket->subject }}</strong><small>{{ $ticket->number }} &middot; {{ $ticket->replies_count }} replies</small></span>
                        <span class="support-category"><i data-lucide="{{ match($ticket->category){'deployment'=>'rocket','server'=>'server','billing'=>'credit-card','domain'=>'globe-2','backup'=>'archive','account'=>'user-round',default=>'circle-help'} }}"></i>{{ ucfirst($ticket->category) }}</span>
                        <span class="priority-badge priority-badge--{{ $ticket->priority }}">{{ ucfirst($ticket->priority) }}</span>
                        <span class="ticket-status ticket-status--{{ $ticket->status }}"><i></i>{{ ucfirst($ticket->status) }}</span>
                        <time>{{ $ticket->updated_at->diffForHumans() }}</time>
                        <i data-lucide="chevron-right"></i>
                    </a>
                @empty
                    <div class="empty-state"><span><i data-lucide="messages-square"></i></span><h2>No support tickets</h2><p>Your workspace has no tickets matching these filters.</p><button class="button button--primary" @click="createOpen=true">Create ticket</button></div>
                @endforelse
                @if($tickets->hasPages())<div class="pagination-wrap">{{ $tickets->links() }}</div>@endif
            </article>

            <aside class="support-aside">
                <article class="card support-help-card"><span><i data-lucide="life-buoy"></i></span><h2>How can we help?</h2><p>Include the affected server or application and describe what you expected to happen.</p><button class="button button--primary button--full" @click="createOpen=true">Open a support ticket</button></article>
                <article class="card support-links-card"><div class="card-head"><div><h2>Helpful resources</h2><p>Resolve common issues faster.</p></div></div><a href="{{ route('system-health') }}"><i data-lucide="heart-pulse"></i><span><strong>System health</strong><small>Check platform readiness</small></span><i data-lucide="chevron-right"></i></a><a href="{{ route('activity.index') }}"><i data-lucide="history"></i><span><strong>Activity history</strong><small>Review workspace changes</small></span><i data-lucide="chevron-right"></i></a><a href="{{ route('logs.index') }}"><i data-lucide="scroll-text"></i><span><strong>Operational logs</strong><small>Search runtime events</small></span><i data-lucide="chevron-right"></i></a></article>
            </aside>
        </section>

        <div class="modal-backdrop" x-show="createOpen" x-cloak @click.self="createOpen=false">
            <form class="modal-card support-create-modal" method="post" action="{{ route('support.store') }}">@csrf
                <div class="modal-head"><div><h2>Create support ticket</h2><p>Give the support team enough context to investigate.</p></div><button type="button" class="icon-button" @click="createOpen=false"><i data-lucide="x"></i></button></div>
                <div class="form-grid form-grid--two">
                    <label class="field field--wide"><span>Subject</span><input name="subject" value="{{ old('subject') }}" required maxlength="180">@error('subject')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="field"><span>Category</span><select name="category" required>@foreach(['deployment','server','billing','domain','backup','account','other'] as $category)<option value="{{ $category }}" @selected(old('category')===$category)>{{ ucfirst($category) }}</option>@endforeach</select></label>
                    <label class="field"><span>Priority</span><select name="priority" required>@foreach(['low','normal','high','urgent'] as $priority)<option value="{{ $priority }}" @selected(old('priority','normal')===$priority)>{{ ucfirst($priority) }}</option>@endforeach</select></label>
                    <label class="field"><span>Affected server</span><select name="server_id"><option value="">None</option>@foreach($servers as $server)<option value="{{ $server->id }}" @selected((string)old('server_id')===(string)$server->id)>{{ $server->name }}</option>@endforeach</select></label>
                    <label class="field"><span>Affected application</span><select name="application_deployment_id"><option value="">None</option>@foreach($deployments as $deployment)<option value="{{ $deployment->id }}" @selected((string)old('application_deployment_id')===(string)$deployment->id)>{{ $deployment->name }}</option>@endforeach</select></label>
                    <label class="field field--wide"><span>Description</span><textarea name="description" rows="7" required>{{ old('description') }}</textarea>@error('description')<small class="field-error">{{ $message }}</small>@enderror</label>
                </div>
                <div class="modal-actions"><button type="button" class="button button--secondary" @click="createOpen=false">Cancel</button><button class="button button--primary"><i data-lucide="send"></i> Create ticket</button></div>
            </form>
        </div>
    </div>
</x-dashboard-layout>
