<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function read(Request $request, string $notification, TenantContext $context): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        abort_unless((int) ($item->data['tenant_id'] ?? 0) === $context->id(), 404);
        $item->markAsRead();

        $url = (string) ($item->data['url'] ?? '');

        return str_starts_with($url, '/') ? redirect($url) : back();
    }

    public function readAll(Request $request, TenantContext $context): RedirectResponse
    {
        $request->user()->unreadNotifications()
            ->where('data->tenant_id', $context->id())
            ->update(['read_at' => now()]);

        return back()->with('success', 'Notifications marked as read.');
    }
}
