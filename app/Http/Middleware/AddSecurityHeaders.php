<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $policy = config('security.content_security_policy');
        if ($policy) {
            $header = config('security.content_security_policy_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $response->headers->set($header, $policy);
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('security.hsts_max_age').'; includeSubDomains');
        }

        return $response;
    }
}
