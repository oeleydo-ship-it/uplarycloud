<?php
namespace App\Http\Middleware;
use App\Models\PersonalAccessToken;use Closure;use Illuminate\Http\Request;use Symfony\Component\HttpFoundation\Response;
class EnsureApiTokenTenant{public function handle(Request $request,Closure $next):Response{$token=$request->user()?->currentAccessToken();abort_unless($token instanceof PersonalAccessToken&&$token->tenant_id&&!$token->revoked_at,401);$member=$request->user()->tenants()->whereKey($token->tenant_id)->wherePivot('is_active',true)->exists();abort_unless($member,403);$ips=$token->ip_restrictions??[];abort_if($ips&&!in_array($request->ip(),$ips,true),403,'This token is not allowed from your IP address.');$request->attributes->set('tenant_id',$token->tenant_id);return$next($request);}}
