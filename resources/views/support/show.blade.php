<x-dashboard-layout :title="$ticket->number">
    <div class="page-heading support-detail-heading">
        <div><p class="breadcrumb"><a href="{{ route('support.index') }}">Support</a> / {{ $ticket->number }}</p><h1>{{ $ticket->subject }}</h1><p>Opened by {{ $ticket->creator?->name ?? 'Former member' }} &middot; {{ $ticket->created_at->diffForHumans() }}</p></div>
        <div class="heading-actions"><span class="priority-badge priority-badge--{{ $ticket->priority }}">{{ ucfirst($ticket->priority) }} priority</span><span class="ticket-status ticket-status--{{ $ticket->status }}"><i></i>{{ ucfirst($ticket->status) }}</span></div>
    </div>

    <section class="support-detail-layout">
        <div class="support-conversation">
            <article class="card support-message support-message--original">
                <div class="support-message-head"><span class="avatar">{{ str($ticket->creator?->name ?? 'User')->substr(0,2)->upper() }}</span><span><strong>{{ $ticket->creator?->name ?? 'Former member' }}</strong><small>Ticket opened &middot; {{ $ticket->created_at->format('M j, Y g:i A') }}</small></span></div>
                <div class="support-message-body">{!! nl2br(e($ticket->description)) !!}</div>
            </article>

            @foreach($ticket->replies as $reply)
                <article class="card support-message {{ $reply->staff_reply ? 'support-message--staff' : '' }}">
                    <div class="support-message-head"><span class="avatar">{{ str($reply->user?->name ?? ($reply->staff_reply ? 'Support' : 'User'))->substr(0,2)->upper() }}</span><span><strong>{{ $reply->user?->name ?? ($reply->staff_reply ? 'Uplary Support' : 'Former member') }}</strong><small>{{ $reply->staff_reply ? 'Support team' : 'Workspace member' }} &middot; {{ $reply->created_at->format('M j, Y g:i A') }}</small></span>@if($reply->staff_reply)<em>STAFF</em>@endif</div>
                    <div class="support-message-body">{!! nl2br(e($reply->message)) !!}</div>
                </article>
            @endforeach

            @if(!in_array($ticket->status,['resolved','closed'],true))
                <form class="card support-reply-form" method="post" action="{{ route('support.replies.store',$ticket) }}">@csrf
                    <div class="card-head"><div><h2>Add reply</h2><p>Share new findings or answer a support question.</p></div></div>
                    <div><label class="field"><span>Message</span><textarea name="message" rows="6" required placeholder="Add details to this ticket...">{{ old('message') }}</textarea>@error('message')<small class="field-error">{{ $message }}</small>@enderror</label><div class="support-reply-actions"><small>Please do not include passwords, private keys, or API tokens.</small><button class="button button--primary"><i data-lucide="send"></i> Send reply</button></div></div>
                </form>
            @else
                <div class="info-banner"><i data-lucide="circle-check-big"></i><div><strong>This ticket is {{ $ticket->status }}.</strong><p>Reopen it if you need to continue the conversation.</p></div></div>
            @endif
        </div>

        <aside class="support-detail-aside">
            <article class="card ticket-details-card"><div class="card-head"><div><h2>Ticket details</h2><p>Context attached to this request.</p></div></div><dl><div><dt>Ticket</dt><dd>{{ $ticket->number }}</dd></div><div><dt>Category</dt><dd>{{ ucfirst($ticket->category) }}</dd></div><div><dt>Priority</dt><dd><span class="priority-badge priority-badge--{{ $ticket->priority }}">{{ ucfirst($ticket->priority) }}</span></dd></div><div><dt>Server</dt><dd>{{ $ticket->server?->name ?? 'Not specified' }}</dd></div><div><dt>Application</dt><dd>{{ $ticket->deployment?->name ?? 'Not specified' }}</dd></div><div><dt>Last reply</dt><dd>{{ $ticket->last_replied_at?->diffForHumans() ?? 'No replies yet' }}</dd></div></dl></article>
            <article class="card ticket-status-card"><div class="card-head"><div><h2>Update status</h2><p>Manage the support lifecycle.</p></div></div><form method="post" action="{{ route('support.status',$ticket) }}">@csrf @method('PUT')<label class="field"><span>Status</span><select name="status">@foreach(['open','waiting','resolved','closed'] as $status)<option value="{{ $status }}" @selected($ticket->status===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label><button class="button button--secondary button--full">Update ticket</button></form></article>
            <article class="card support-context-card"><span><i data-lucide="shield-check"></i></span><h3>Secure support</h3><p>Platform diagnostics are checked separately. Never paste credentials or secret environment variables into a ticket.</p><a href="{{ route('system-health') }}">View system health <i data-lucide="arrow-right"></i></a></article>
        </aside>
    </section>
</x-dashboard-layout>
