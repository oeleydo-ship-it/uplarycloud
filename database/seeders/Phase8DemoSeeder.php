<?php

namespace Database\Seeders;

use App\Models\BillingInvoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\UsageService;
use App\Support\PlanCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class Phase8DemoSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['Free', 'free', 'For personal experiments and a single server.', 0, 0, ['Core Docker management', 'Community support', '24-hour monitoring'], false],
            ['Starter', 'starter', 'For independent developers shipping real projects.', 1200, 11520, ['Automated backups', '7-day monitoring', 'Email alerts', 'API access'], false],
            ['Pro', 'pro', 'For growing teams running production workloads.', 2900, 27840, ['Everything in Starter', '30-day monitoring', 'Priority support', 'Advanced API scopes', 'S3 destinations'], true],
            ['Business', 'business', 'For organizations with demanding infrastructure.', 7900, 75840, ['Everything in Pro', '90-day monitoring', 'Audit exports', 'SLA support', 'Custom onboarding'], false],
        ];
        foreach ($plans as $position => [$name, $slug, $description, $monthly, $yearly, $features, $featured]) {
            $defaults = PlanCatalog::defaultsFor($slug);
            Plan::updateOrCreate(['slug' => $slug], compact('name', 'description', 'features', 'featured') + [
                'limits' => $defaults['limits'],
                'gates' => $defaults['gates'],
                'monthly_price' => $monthly,
                'yearly_price' => $yearly,
                'currency' => 'USD',
                'stripe_monthly_price_id' => config('billing.stripe.prices.'.$slug.'_monthly'),
                'stripe_yearly_price_id' => config('billing.stripe.prices.'.$slug.'_yearly'),
                'active' => true,
                'position' => $position + 1,
            ]);
        }
        $tenant = Tenant::first();
        if (! $tenant) {
            return;
        }$owner = $tenant->users()->wherePivot('role', 'owner')->first();
        if (! $owner) {
            return;
        }
        foreach ([['Maya Chen', 'maya@example.com', 'admin'], ['Omar Farooq', 'omar@example.com', 'developer'], ['Ava Brooks', 'ava@example.com', 'billing']] as [$name,$email,$role]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now(), 'last_active_at' => now()->subMinutes(rand(8, 180))]);
            $tenant->users()->syncWithoutDetaching([$user->id => ['role' => $role, 'is_active' => true]]);
        }
        $pro = Plan::where('slug', 'pro')->firstOrFail();
        $subscription = Subscription::firstOrCreate(['tenant_id' => $tenant->id, 'status' => 'active'], ['plan_id' => $pro->id, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now()->startOfMonth(), 'current_period_ends_at' => now()->addMonth()->startOfMonth(), 'metadata' => ['gateway' => 'fake']]);
        PaymentMethod::firstOrCreate(['tenant_id' => $tenant->id, 'is_default' => true], ['type' => 'card', 'brand' => 'Visa', 'last_four' => '4242', 'expiry_month' => 12, 'expiry_year' => now()->year + 3]);
        foreach (range(0, 2) as $i) {
            $date = now()->subMonths($i);
            BillingInvoice::firstOrCreate(['tenant_id' => $tenant->id, 'number' => 'INV-'.$date->format('Ym').'-DEMO'], ['subscription_id' => $subscription->id, 'status' => 'paid', 'currency' => 'USD', 'subtotal' => $pro->monthly_price, 'total' => $pro->monthly_price, 'paid_at' => $date->copy()->startOfMonth(), 'line_items' => [['description' => 'Pro plan (monthly)', 'amount' => $pro->monthly_price]], 'created_at' => $date->copy()->startOfMonth()]);
        }
        app(UsageService::class)->collect($tenant);
    }
}
