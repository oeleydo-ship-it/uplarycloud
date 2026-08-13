<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Http\Requests\InstallRequest;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\InstallationState;
use App\Support\PlatformSettings;
use Database\Seeders\ApplicationCatalogSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstallController extends Controller
{
    public function create(InstallationState $installation): View|RedirectResponse
    {
        if ($installation->isInstalled()) {
            return redirect()->route('login');
        }

        return view('install.index');
    }

    public function store(InstallRequest $request, InstallationState $installation, PlatformSettings $settings): RedirectResponse
    {
        if ($installation->isInstalled()) {
            return redirect()->route('login');
        }

        [$user, $tenant] = DB::transaction(function () use ($request, $installation, $settings): array {
            if (User::query()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This platform is already installed.',
                ]);
            }

            $platformName = trim((string) $request->input('platform_name', '')) ?: config('app.name', 'Uplary Cloud');
            $workspaceName = trim((string) $request->input('workspace_name', '')) ?: $platformName;

            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'is_super_admin' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $tenant = Tenant::create(['name' => $workspaceName]);
            $tenant->users()->attach($user, ['role' => MembershipRole::Owner->value]);

            $settings->put('general', [
                'platform_name' => $platformName,
                'platform_url' => config('app.url'),
                'support_email' => $user->email,
                'default_timezone' => config('app.timezone', 'UTC'),
                'default_currency' => 'USD',
                'default_language' => config('app.locale', 'en'),
                'date_format' => 'M j, Y',
                'time_format' => 'g:i A',
                'registration_enabled' => true,
                'email_verification' => ! app()->environment('local'),
                'maintenance_mode' => false,
            ]);

            $settings->put('branding', [
                'name' => $platformName,
                'short_name' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $platformName) ?: 'UP', 0, 2)),
                'tagline' => 'Deploy confidently. Operate clearly.',
                'primary_color' => '#6C4CF5',
                'secondary_color' => '#17152B',
                'company_name' => $platformName,
                'support_email' => $user->email,
            ]);

            $installation->markInstalled();

            ActivityLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'action' => 'platform.installed',
                'description' => 'Platform installed and superadmin created',
                'ip_address' => $request->ip(),
            ]);

            return [$user, $tenant];
        });

        // Catalog is global (not tenant-scoped). Seed outside the install transaction
        // so a catalog failure does not roll back the superadmin/workspace.
        app(ApplicationCatalogSeeder::class)->run();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', 'Uplary Cloud is ready. Welcome aboard.');
    }
}
