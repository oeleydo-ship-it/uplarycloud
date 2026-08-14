<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\BlogPostController;
use App\Http\Controllers\Admin\CloudInfrastructureController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\MarketingPageController;
use App\Http\Controllers\Admin\SeoController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ApplicationDeploymentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DockerComposeController;
use App\Http\Controllers\DockerContainerController;
use App\Http\Controllers\DockerImageController;
use App\Http\Controllers\DockerNetworkController;
use App\Http\Controllers\DockerVolumeController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\GitWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\ManagedInfrastructureController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OperationalLogController;
use App\Http\Controllers\PlatformManagedInfrastructureController;
use App\Http\Controllers\ServerConnectionController;
use App\Http\Controllers\ServerConnectionValidationController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerProvisioningController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WebApplicationController;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', [MarketingController::class, 'home'])->name('home');
Route::get('/features', [MarketingController::class, 'features'])->name('marketing.features');
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
Route::get('/use-cases', [MarketingController::class, 'useCases'])->name('marketing.use-cases');
Route::get('/about', [MarketingController::class, 'about'])->name('marketing.about');
Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
Route::post('/contact', [MarketingController::class, 'storeContact'])->middleware('throttle:contact')->name('marketing.contact.store');
Route::get('/blog', [MarketingController::class, 'blog'])->name('marketing.blog');
Route::get('/blog/{slug}', [MarketingController::class, 'blogShow'])->name('marketing.blog.show');
Route::get('/pages/{page:slug}', [MarketingController::class, 'page'])->name('marketing.page');
Route::get('/robots.txt', [MarketingController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('sitemap');
Route::prefix('health')->middleware('throttle:120,1')->group(function (): void {
    Route::get('/live', [HealthController::class, 'live'])->name('health.live');
    Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
});
Route::post('/hooks/git/{deployment}', GitWebhookController::class)->middleware('throttle:30,1')->name('hooks.git');
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware('guest')->group(function (): void {
    Route::get('/install', [InstallController::class, 'create'])->name('install');
    Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:10,1')->name('install.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');
    Route::post('/forgot-password', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::ResetLinkSent ? back()->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    })->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token, 'email' => request('email')]))->name('password.reset');
    Route::post('/reset-password', function (Request $request) {
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', 'min:8']]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset ? redirect()->route('login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    })->name('password.update');
});

Route::post('/impersonation/leave', [ImpersonationController::class, 'stop'])->middleware('auth')->name('impersonation.leave');

Route::middleware(['auth', 'tenant', 'platform.access'])->group(function (): void {
    Route::get('/verify-email', function (PlatformSettings $settings) {
        if (! $settings->emailVerificationRequired()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email');
    })->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('dashboard')->with('success', 'Email address verified.');
    })->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request, PlatformSettings $settings) {
        if (! $settings->emailVerificationRequired() || $request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard')->with('success', 'Email verification is not required.');
        }
        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'A new verification link has been sent.');
    })->middleware('throttle:6,1')->name('verification.send');
    Route::get('/dashboard', DashboardController::class)->middleware('verified')->name('dashboard');
    Route::get('/system-health', SystemHealthController::class)->name('system-health');
    Route::get('/support', [SupportController::class, 'index'])->middleware('platform.feature:support')->name('support.index');
    Route::post('/support', [SupportController::class, 'store'])->middleware('platform.feature:support')->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->middleware('platform.feature:support')->name('support.show');
    Route::post('/support/{ticket}/replies', [SupportController::class, 'reply'])->middleware('platform.feature:support')->name('support.replies.store');
    Route::put('/support/{ticket}/status', [SupportController::class, 'status'])->middleware('platform.feature:support')->name('support.status');
    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/managed-infrastructure', [PlatformManagedInfrastructureController::class, 'index'])->middleware(['plan.feature:managed_servers', 'paid.subscription'])->name('managed.index');
    Route::get('/cloud-api', [ManagedInfrastructureController::class, 'cloudApi'])->middleware('plan.feature:cloud_api')->name('cloud-api.index');
    Route::post('/cloud-api/connections', [ManagedInfrastructureController::class, 'connection'])->middleware('plan.feature:cloud_api')->name('managed.connections.store');
    Route::post('/cloud-api/connections/{connection}/verify', [ManagedInfrastructureController::class, 'verify'])->middleware('plan.feature:cloud_api')->name('managed.connections.verify');
    Route::get('/cloud-api/connections/{connection}/catalog', [ManagedInfrastructureController::class, 'catalog'])->middleware('plan.feature:cloud_api')->name('managed.connections.catalog');
    Route::delete('/cloud-api/connections/{connection}', [ManagedInfrastructureController::class, 'destroyConnection'])->middleware('plan.feature:cloud_api')->name('managed.connections.destroy');
    Route::post('/servers/cloud', [ManagedInfrastructureController::class, 'store'])->name('servers.cloud.store');
    Route::post('/managed-infrastructure/servers', [PlatformManagedInfrastructureController::class, 'store'])->middleware(['plan.feature:managed_servers', 'paid.subscription'])->name('managed.servers.store');
    Route::post('/managed-infrastructure/servers/{server}/action', [ManagedInfrastructureController::class, 'action'])->middleware(['plan.feature:managed_servers', 'paid.subscription'])->name('managed.servers.action');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::get('/servers/create/managed', [ServerController::class, 'createManaged'])->middleware(['plan.feature:managed_servers', 'paid.subscription'])->name('servers.create.managed');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::post('/servers/validate-connection', ServerConnectionValidationController::class)->name('servers.validate-connection');
    Route::get('/servers/{server}/provisioning', [ServerProvisioningController::class, 'show'])->name('servers.provisioning');
    Route::get('/servers/{server}/provisioning/status', [ServerProvisioningController::class, 'status'])->name('servers.provisioning.status');
    Route::get('/servers/{server}/success', [ServerProvisioningController::class, 'success'])->name('servers.success');
    Route::post('/servers/{server}/provisioning/retry', [ServerProvisioningController::class, 'retry'])->name('servers.provisioning.retry');
    Route::post('/servers/{server}/test-connection', ServerConnectionController::class)->name('servers.test-connection');
    Route::get('/servers/{server}/details', [ServerController::class, 'details'])->name('servers.details');
    Route::post('/servers/{server}/refresh', [ServerController::class, 'refresh'])->name('servers.refresh');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::get('/containers', [DockerContainerController::class, 'index'])->name('containers.index');
    Route::post('/containers/sync', [DockerContainerController::class, 'sync'])->name('containers.sync');
    Route::post('/containers/prune', [DockerContainerController::class, 'prune'])->name('containers.prune');
    Route::post('/containers/{container}/action', [DockerContainerController::class, 'action'])->name('containers.action');
    Route::get('/images', [DockerImageController::class, 'index'])->name('images.index');
    Route::post('/images/pull', [DockerImageController::class, 'pull'])->name('images.pull');
    Route::post('/images/cleanup', [DockerImageController::class, 'cleanup'])->name('images.cleanup');
    Route::post('/images/check-updates', [DockerImageController::class, 'check'])->name('images.check');
    Route::post('/images/{image}/action', [DockerImageController::class, 'action'])->name('images.action');
    Route::get('/volumes', [DockerVolumeController::class, 'index'])->name('volumes.index');
    Route::delete('/volumes/{volume}', [DockerVolumeController::class, 'destroy'])->name('volumes.destroy');
    Route::get('/networks', [DockerNetworkController::class, 'index'])->name('networks.index');
    Route::delete('/networks/{network}', [DockerNetworkController::class, 'destroy'])->name('networks.destroy');
    Route::get('/compose', [DockerComposeController::class, 'index'])->middleware(['platform.feature:custom_docker', 'plan.feature:custom_docker'])->name('compose.index');
    Route::post('/compose', [DockerComposeController::class, 'store'])->middleware(['platform.feature:custom_docker', 'plan.feature:custom_docker'])->name('compose.store');
    Route::get('/applications', [ApplicationDeploymentController::class, 'index'])->middleware('platform.feature:marketplace')->name('applications.index');
    Route::get('/applications/installed', [ApplicationDeploymentController::class, 'installed'])->name('applications.installed');
    Route::get('/applications/custom', [ApplicationDeploymentController::class, 'custom'])->middleware(['platform.feature:custom_docker', 'plan.feature:custom_docker'])->name('applications.custom');
    Route::get('/applications/{application}/install', [ApplicationDeploymentController::class, 'install'])->middleware(['platform.feature:marketplace', 'plan.feature:marketplace'])->name('applications.install');
    Route::get('/applications/web/new', [WebApplicationController::class, 'create'])->middleware(['platform.feature:git_deployments', 'plan.feature:git_deploy'])->name('applications.web.create');
    Route::post('/applications/web', [WebApplicationController::class, 'store'])->middleware('platform.feature:git_deployments')->name('applications.web.store');
    Route::post('/deployments', [ApplicationDeploymentController::class, 'store'])->name('deployments.store');
    Route::get('/deployments/{deployment}', [ApplicationDeploymentController::class, 'show'])->name('deployments.show');
    Route::get('/deployments/{deployment}/status', [ApplicationDeploymentController::class, 'status'])->name('deployments.status');
    Route::post('/deployments/{deployment}/verify', [ApplicationDeploymentController::class, 'verify'])->name('deployments.verify');
    Route::post('/deployments/{deployment}/redeploy', [ApplicationDeploymentController::class, 'redeploy'])->name('deployments.redeploy');
    Route::post('/deployments/{deployment}/rollback/{release}', [ApplicationDeploymentController::class, 'rollback'])->name('deployments.rollback');
    Route::delete('/deployments/{deployment}', [ApplicationDeploymentController::class, 'destroy'])->name('deployments.destroy');
    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('/domains/import', [DomainController::class, 'import'])->name('domains.import');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::put('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
    Route::post('/domains/{domain}/configure', [DomainController::class, 'configure'])->name('domains.configure');
    Route::post('/domains/{domain}/certificate', [DomainController::class, 'certificate'])->name('domains.certificate');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::get('/monitoring', [MonitoringController::class, 'index'])->middleware(['platform.feature:monitoring', 'plan.feature:monitoring'])->name('monitoring.index');
    Route::post('/monitoring/collect', [MonitoringController::class, 'collect'])->middleware(['platform.feature:monitoring', 'plan.feature:monitoring'])->name('monitoring.collect');
    Route::get('/alerts', [AlertController::class, 'index'])->middleware(['platform.feature:alerts', 'plan.feature:alerts'])->name('alerts.index');
    Route::post('/alerts', [AlertController::class, 'store'])->middleware(['platform.feature:alerts', 'plan.feature:alerts'])->name('alerts.store');
    Route::post('/alerts/{alert}/toggle', [AlertController::class, 'toggle'])->middleware(['platform.feature:alerts', 'plan.feature:alerts'])->name('alerts.toggle');
    Route::post('/incidents/{incident}/acknowledge', [AlertController::class, 'acknowledge'])->middleware(['platform.feature:alerts', 'plan.feature:alerts'])->name('incidents.acknowledge');
    Route::post('/incidents/{incident}/resolve', [AlertController::class, 'resolve'])->middleware(['platform.feature:alerts', 'plan.feature:alerts'])->name('incidents.resolve');
    Route::get('/backups', [BackupController::class, 'index'])->middleware(['platform.feature:backups', 'plan.feature:backups'])->name('backups.index');
    Route::post('/backups', [BackupController::class, 'store'])->middleware(['platform.feature:backups', 'plan.feature:backups'])->name('backups.store');
    Route::post('/backup-schedules', [BackupController::class, 'schedule'])->middleware(['platform.feature:backups', 'plan.feature:backups'])->name('backups.schedules.store');
    Route::post('/backup-schedules/{schedule}/toggle', [BackupController::class, 'toggleSchedule'])->middleware('platform.feature:backups')->name('backups.schedules.toggle');
    Route::delete('/backup-schedules/{schedule}', [BackupController::class, 'destroySchedule'])->middleware('platform.feature:backups')->name('backups.schedules.destroy');
    Route::post('/backup-destinations', [BackupController::class, 'destination'])->middleware(['platform.feature:backups', 'plan.feature:backups'])->name('backups.destinations.store');
    Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('platform.feature:backups')->name('backups.restore');
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])->middleware('platform.feature:backups')->name('backups.download');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->middleware('platform.feature:backups')->name('backups.destroy');
    Route::get('/logs', [OperationalLogController::class, 'index'])->name('logs.index');
    Route::get('/logs/download', [OperationalLogController::class, 'download'])->name('logs.download');
    Route::get('/activity', ActivityController::class)->name('activity.index');
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->middleware(['platform.feature:api_tokens', 'plan.feature:api_tokens'])->name('api-tokens.index');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->middleware(['platform.feature:api_tokens', 'plan.feature:api_tokens'])->name('api-tokens.store');
    Route::put('/api-tokens/{token}', [ApiTokenController::class, 'update'])->middleware('platform.feature:api_tokens')->name('api-tokens.update');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->middleware('platform.feature:api_tokens')->name('api-tokens.destroy');
    Route::get('/users', [TeamController::class, 'index'])->name('team.index');
    Route::post('/users/invitations', [TeamController::class, 'invite'])->name('team.invite');
    Route::put('/users/{member}', [TeamController::class, 'update'])->name('team.update');
    Route::post('/users/{member}/suspend', [TeamController::class, 'suspend'])->name('team.suspend');
    Route::delete('/users/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::delete('/billing/subscription', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::get('/settings', [GeneralSettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [GeneralSettingsController::class, 'update'])->name('settings.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'superadmin'])->group(function (): void {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
    Route::put('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
    Route::get('/branding', [BrandingController::class, 'edit'])->name('branding');
    Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::get('/public-pages', [MarketingPageController::class, 'index'])->name('marketing-pages.index');
    Route::get('/public-pages/create', [MarketingPageController::class, 'create'])->name('marketing-pages.create');
    Route::post('/public-pages', [MarketingPageController::class, 'store'])->name('marketing-pages.store');
    Route::get('/public-pages/{page}/edit', [MarketingPageController::class, 'edit'])->name('marketing-pages.edit');
    Route::put('/public-pages/{page}', [MarketingPageController::class, 'update'])->name('marketing-pages.update');
    Route::delete('/public-pages/{page}', [MarketingPageController::class, 'destroy'])->name('marketing-pages.destroy');
    Route::get('/seo', [SeoController::class, 'edit'])->name('seo.edit');
    Route::put('/seo', [SeoController::class, 'update'])->name('seo.update');
    Route::get('/blog-posts', [BlogPostController::class, 'index'])->name('blog-posts.index');
    Route::get('/blog-posts/create', [BlogPostController::class, 'create'])->name('blog-posts.create');
    Route::post('/blog-posts', [BlogPostController::class, 'store'])->name('blog-posts.store');
    Route::get('/blog-posts/{post}/edit', [BlogPostController::class, 'edit'])->name('blog-posts.edit');
    Route::put('/blog-posts/{post}', [BlogPostController::class, 'update'])->name('blog-posts.update');
    Route::post('/blog-posts/{post}/generate', [BlogPostController::class, 'generate'])->name('blog-posts.generate');
    Route::delete('/blog-posts/{post}', [BlogPostController::class, 'destroy'])->name('blog-posts.destroy');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
    Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->name('users.impersonate');
    Route::get('/tenants', [AdminController::class, 'tenants'])->name('tenants');
    Route::put('/tenants/{tenant}/subscription', [AdminController::class, 'updateTenantSubscription'])->name('tenants.subscription.update');
    Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
    Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
    Route::get('/mail', [AdminController::class, 'mail'])->name('mail');
    Route::put('/mail', [AdminController::class, 'updateMail'])->name('mail.update');
    Route::get('/payments', [AdminController::class, 'payments'])->name('payments');
    Route::put('/payments', [AdminController::class, 'updatePayments'])->name('payments.update');
    Route::get('/security', [AdminController::class, 'security'])->name('security');
    Route::put('/security', [AdminController::class, 'updateSecurity'])->name('security.update');
    Route::get('/cloud-infrastructure', [CloudInfrastructureController::class, 'index'])->name('cloud');
    Route::post('/cloud-infrastructure/connections', [CloudInfrastructureController::class, 'storeConnection'])->name('cloud.connections.store');
    Route::put('/cloud-infrastructure/connections/{c}', [CloudInfrastructureController::class, 'updateConnection'])->name('cloud.connections.update');
    Route::post('/cloud-infrastructure/connections/{c}/verify', [CloudInfrastructureController::class, 'verify'])->name('cloud.connections.verify');
    Route::post('/cloud-infrastructure/connections/{c}/sync', [CloudInfrastructureController::class, 'syncPlans'])->name('cloud.connections.sync');
    Route::post('/cloud-infrastructure/plans/sync', [CloudInfrastructureController::class, 'syncAllPlans'])->name('cloud.plans.sync');
    Route::post('/cloud-infrastructure/plans', [CloudInfrastructureController::class, 'storePlan'])->name('cloud.plans.store');
    Route::put('/cloud-infrastructure/plans/{p}', [CloudInfrastructureController::class, 'updatePlan'])->name('cloud.plans.update');
    Route::put('/cloud-infrastructure/markup', [CloudInfrastructureController::class, 'updateGlobalMarkup'])->name('cloud.markup.update');
});

Route::get('/invitations/{invitation}/{token}', [TeamController::class, 'accept'])->middleware('auth')->name('invitations.accept');
