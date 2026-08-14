<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\BillingInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanCatalog;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'stats' => ['users' => User::count(), 'tenants' => Tenant::count(), 'subscriptions' => Subscription::whereIn('status', ['active', 'trialing'])->count(), 'revenue' => BillingInvoice::where('status', 'paid')->sum('total')],
            'recentUsers' => User::latest()->limit(6)->get(), 'recentTenants' => Tenant::withCount(['users', 'servers'])->latest()->limit(6)->get(),
        ]);
    }

    public function settings(PlatformSettings $settings): View
    {
        return view('admin.settings', ['settings' => $settings->group('general')]);
    }

    public function updateSettings(UpdatePlatformSettingsRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $settings->put('general', $request->platformSettings());

        return back()->with('success', 'Platform settings updated.');
    }

    public function users(Request $request): View
    {
        $users = User::query()->with(['tenants.latestSubscription.plan'])->withCount('tenants')->when($request->search, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")))->latest()->paginate(15)->withQueryString();

        return view('admin.users', compact('users'));
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'is_super_admin' => 'nullable|boolean']);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_super_admin' => $request->boolean('is_super_admin')]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', Rule::unique('users')->ignore($user)], 'is_super_admin' => 'nullable|boolean', 'email_verified' => 'nullable|boolean']);
        abort_if($user->is($request->user()) && ! $request->boolean('is_super_admin'), 422, 'You cannot remove your own superadmin access.');
        $user->update(['name' => $data['name'], 'email' => $data['email'], 'is_super_admin' => $request->boolean('is_super_admin'), 'email_verified_at' => $request->boolean('email_verified') ? ($user->email_verified_at ?? now()) : null]);

        return back()->with('success', 'User updated.');
    }

    public function tenants(Request $request): View
    {
        $tenants = Tenant::with(['latestSubscription.plan'])->withCount(['users', 'servers', 'deployments'])->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%$v%"))->latest()->paginate(15)->withQueryString();

        return view('admin.tenants', ['tenants' => $tenants, 'plans' => Plan::orderBy('position')->get()]);
    }

    public function updateTenantSubscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::in(['active', 'trialing', 'past_due', 'canceled'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        DB::transaction(function () use ($tenant, $data, $request): void {
            $now = now();
            $tenant->subscriptions()->whereIn('status', ['active', 'trialing', 'past_due'])->update([
                'status' => 'canceled',
                'ended_at' => $now,
            ]);

            $periodEnd = $data['billing_cycle'] === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();
            $tenant->subscriptions()->create([
                'plan_id' => $data['plan_id'],
                'status' => $data['status'],
                'billing_cycle' => $data['billing_cycle'],
                'trial_ends_at' => $data['status'] === 'trialing' ? $now->copy()->addDays(14) : null,
                'current_period_starts_at' => $now,
                'current_period_ends_at' => in_array($data['status'], ['active', 'trialing'], true) ? $periodEnd : null,
                'ended_at' => $data['status'] === 'canceled' ? $now : null,
                'metadata' => ['source' => 'superadmin', 'assigned_by' => $request->user()->id],
            ]);
        });

        return back()->with('success', 'Workspace subscription updated. Quotas and feature gates are effective immediately.');
    }

    public function plans(): View
    {
        return view('admin.plans', ['plans' => Plan::withCount('subscriptions')->orderBy('position')->get()]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $data = $this->planData($request);
        Plan::create($data);

        return back()->with('success', 'Plan created.');
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->planData($request, $plan));

        return back()->with('success', 'Plan updated.');
    }

    private function planData(Request $request, ?Plan $plan = null): array
    {
        $quotaRules = collect(PlanCatalog::quotaKeys())->mapWithKeys(fn (string $key) => [$key => 'nullable|integer|min:0'])->all();
        $data = $request->validate(array_merge([
            'name' => 'required|string|max:80',
            'slug' => ['required', 'alpha_dash', Rule::unique('plans')->ignore($plan)],
            'description' => 'nullable|string|max:255',
            'monthly_price' => 'required|numeric|min:0',
            'yearly_price' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'features' => 'nullable|string',
            'stripe_monthly_price_id' => 'nullable|string|max:120',
            'stripe_yearly_price_id' => 'nullable|string|max:120',
            'featured' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ], $quotaRules));

        $limits = [];
        foreach (PlanCatalog::quotaKeys() as $key) {
            $value = $request->input($key);
            $limits[$key] = ($value === null || $value === '') ? 'unlimited' : (int) $value;
            unset($data[$key]);
        }

        $gates = [];
        foreach (PlanCatalog::gateKeys() as $key) {
            $gates[$key] = $request->boolean('gate_'.$key);
        }

        $features = collect(preg_split('/\r\n|\r|\n/', $data['features'] ?? ''))->filter()->values()->all();

        return array_merge($data, [
            'limits' => $limits,
            'gates' => $gates,
            'features' => $features,
            'featured' => $request->boolean('featured'),
            'active' => $request->boolean('active'),
            'monthly_price' => (int) round($data['monthly_price'] * 100),
            'yearly_price' => (int) round($data['yearly_price'] * 100),
            'position' => $plan?->position ?? (Plan::max('position') + 1),
        ]);
    }

    public function mail(PlatformSettings $settings): View
    {
        return view('admin.mail', ['settings' => $settings->group('mail')]);
    }

    public function updateMail(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate(['mail_mailer' => 'required|in:smtp,log', 'smtp_host' => 'nullable|string|max:255', 'smtp_port' => 'nullable|integer|min:1|max:65535', 'smtp_username' => 'nullable|string|max:255', 'smtp_password' => 'nullable|string|max:255', 'smtp_encryption' => 'nullable|in:tls,ssl,none', 'from_address' => 'required|email', 'from_name' => 'required|string|max:100']);
        $settings->put('mail', $data);

        return back()->with('success', 'Email settings updated.');
    }

    public function payments(PlatformSettings $settings): View
    {
        return view('admin.payments', ['settings' => $settings->group('payments')]);
    }

    public function updatePayments(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate(['billing_driver' => 'required|in:fake,stripe,paypal', 'stripe_public_key' => 'nullable|string|max:255', 'stripe_secret' => 'nullable|string|max:255', 'stripe_webhook_secret' => 'nullable|string|max:255', 'paypal_client_id' => 'nullable|string|max:255', 'paypal_client_secret' => 'nullable|string|max:255', 'paypal_mode' => 'nullable|in:sandbox,live', 'tax_percentage' => 'nullable|numeric|min:0|max:100']);
        $settings->put('payments', $data);

        return back()->with('success', 'Payment gateway settings updated.');
    }

    public function security(PlatformSettings $settings): View
    {
        return view('admin.security', ['settings' => $settings->group('security')]);
    }

    public function updateSecurity(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate(['session_lifetime' => 'required|integer|min:5|max:10080', 'password_min_length' => 'required|integer|min:8|max:128', 'login_attempts' => 'required|integer|min:1|max:20', 'two_factor_required' => 'nullable|boolean', 'secure_cookies' => 'nullable|boolean', 'allow_api_tokens' => 'nullable|boolean']);
        foreach (['two_factor_required', 'secure_cookies', 'allow_api_tokens'] as $key) {
            $data[$key] = $request->boolean($key);
        } $settings->put('security', $data);

        return back()->with('success','Security policies updated.');
    }
}
