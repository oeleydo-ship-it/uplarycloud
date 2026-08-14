<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        abort_if($request->session()->has('impersonator_id'), 409, 'End the current support session first.');

        if ($user->is($admin) || $user->is_super_admin) {
            throw ValidationException::withMessages(['user' => 'Only customer accounts can be accessed through support impersonation.']);
        }

        $data = $request->validate(['tenant_id' => ['required', 'integer', 'exists:tenants,id']]);
        $tenant = $user->tenants()->wherePivot('is_active', true)->find($data['tenant_id']);

        if (! $tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'Select an active workspace that belongs to this user.']);
        }

        ActivityLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'action' => 'support.impersonation.started',
            'description' => $admin->email.' started a support session as '.$user->email,
            'ip_address' => $request->ip(),
            'status' => 'success',
            'metadata' => ['impersonated_user_id' => $user->id],
            'created_at' => now(),
        ]);

        $request->session()->put([
            'impersonator_id' => $admin->id,
            'impersonator_name' => $admin->name,
            'impersonator_email' => $admin->email,
            'impersonated_user_id' => $user->id,
            'impersonation_started_at' => now()->toIso8601String(),
            'tenant_id' => $tenant->id,
        ]);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Support session started for '.$user->name.'.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $admin = User::query()->whereKey($request->session()->get('impersonator_id'))->where('is_super_admin', true)->first();
        abort_unless($admin, 403, 'No valid support session is active.');

        $target = $request->user();
        $tenantId = $request->session()->get('tenant_id');
        if ($tenantId && Tenant::whereKey($tenantId)->exists()) {
            ActivityLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $admin->id,
                'action' => 'support.impersonation.ended',
                'description' => $admin->email.' ended the support session for '.$target->email,
                'ip_address' => $request->ip(),
                'status' => 'success',
                'metadata' => ['impersonated_user_id' => $target->id],
                'created_at' => now(),
            ]);
        }

        Auth::guard('web')->login($admin);
        $request->session()->forget(['impersonator_id', 'impersonator_name', 'impersonator_email', 'impersonated_user_id', 'impersonation_started_at', 'tenant_id']);
        $request->session()->regenerate();

        return redirect()->route('admin.users')->with('success', 'Support session ended. You are back in the Platform Console.');
    }
}
