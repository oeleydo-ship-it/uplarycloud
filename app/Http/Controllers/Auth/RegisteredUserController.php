<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $requireVerification = $settings->emailVerificationRequired();

        [$user, $tenant] = DB::transaction(function () use ($request, $requireVerification): array {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            if (! $requireVerification) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $tenant = Tenant::create(['name' => $request->string('workspace_name')]);
            $tenant->users()->attach($user, ['role' => MembershipRole::Owner->value]);
            ActivityLog::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'action' => 'workspace.created', 'description' => 'Workspace created', 'ip_address' => $request->ip()]);

            return [$user, $tenant];
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->put('tenant_id', $tenant->id);

        return $requireVerification
            ? redirect()->route('verification.notice')
            : redirect()->route('dashboard');
    }
}
