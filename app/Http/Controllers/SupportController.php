<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportTicketRequest;
use App\Models\ActivityLog;
use App\Models\SupportTicket;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $tenant = $context->current();
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());
        $tickets = $tenant->supportTickets()->with(['creator', 'server', 'deployment'])->withCount('replies')->latest('updated_at');
        if (in_array($status, ['open', 'waiting', 'resolved', 'closed'], true)) $tickets->where('status', $status);
        if ($search !== '') $tickets->where(fn ($query) => $query->where('subject', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%"));

        return view('support.index', [
            'tickets' => $tickets->paginate(10)->withQueryString(),
            'servers' => $tenant->servers()->orderBy('name')->get(),
            'deployments' => $tenant->deployments()->orderBy('name')->get(),
            'stats' => [
                'open' => $tenant->supportTickets()->whereIn('status', ['open', 'waiting'])->count(),
                'urgent' => $tenant->supportTickets()->where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
                'resolved' => $tenant->supportTickets()->where('status', 'resolved')->count(),
                'total' => $tenant->supportTickets()->count(),
            ],
        ]);
    }

    public function store(StoreSupportTicketRequest $request, TenantContext $context): RedirectResponse
    {
        $ticket = $context->current()->supportTickets()->create($request->safe()->merge(['created_by' => $request->user()->id])->all());
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'support.ticket.created', 'description' => $ticket->number.' created: '.$ticket->subject, 'subject_type' => SupportTicket::class, 'subject_id' => $ticket->id, 'status' => 'success']);

        return redirect()->route('support.show', $ticket)->with('success', 'Support ticket created.');
    }

    public function show(SupportTicket $ticket, TenantContext $context): View
    {
        $this->tenantTicket($ticket, $context);

        return view('support.show', ['ticket' => $ticket->load(['creator', 'server', 'deployment', 'replies.user'])]);
    }

    public function reply(Request $request, SupportTicket $ticket, TenantContext $context): RedirectResponse
    {
        $this->tenantTicket($ticket, $context);
        abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 422, 'Resolved tickets must be reopened before replying.');
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:10000']]);
        $ticket->replies()->create(['user_id' => $request->user()->id, 'message' => $data['message'], 'staff_reply' => false]);
        $ticket->update(['status' => 'waiting', 'last_replied_at' => now()]);
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'support.ticket.replied', 'description' => 'Reply added to '.$ticket->number, 'subject_type' => SupportTicket::class, 'subject_id' => $ticket->id, 'status' => 'success']);

        return back()->with('success', 'Reply added.');
    }

    public function status(Request $request, SupportTicket $ticket, TenantContext $context): RedirectResponse
    {
        $this->tenantTicket($ticket, $context);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'waiting', 'resolved', 'closed'])]]);
        $ticket->update(['status' => $data['status'], 'resolved_at' => $data['status'] === 'resolved' ? now() : null]);
        ActivityLog::create(['tenant_id' => $context->id(), 'user_id' => $request->user()->id, 'action' => 'support.ticket.status_updated', 'description' => $ticket->number.' marked '.$data['status'], 'subject_type' => SupportTicket::class, 'subject_id' => $ticket->id, 'status' => 'success']);

        return back()->with('success', 'Ticket status updated.');
    }

    private function tenantTicket(SupportTicket $ticket, TenantContext $context): void
    {
        abort_unless($ticket->tenant_id === $context->id(), 404);
    }
}
