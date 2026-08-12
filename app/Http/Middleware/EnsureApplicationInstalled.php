<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationInstalled
{
    public function __construct(private InstallationState $installation)
    {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $installed = $this->installation->isInstalled();
        $onInstall = $request->is('install');

        if (! $installed && ! $onInstall) {
            return redirect()->route('install');
        }

        if ($installed && $onInstall) {
            return redirect()->route('login');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->is('up', 'health', 'health/*', 'hooks/git/*', 'stripe/webhook');
    }
}
