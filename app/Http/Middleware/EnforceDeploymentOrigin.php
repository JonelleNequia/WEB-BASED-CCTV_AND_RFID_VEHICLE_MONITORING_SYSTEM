<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FastCGI supplies the original scheme/address. No forwarded headers are trusted. */
class EnforceDeploymentOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('deployment.named_https')) {
            $integration = $request->is('api/v1/integration/*', 'api/latest-scan', 'api/check-latest-scan', 'api/guest-observation');
            $internal = $request->getHost() === '127.0.0.1'
                && $request->ip() === '127.0.0.1'
                && $request->getPort() === config('deployment.integration_port');
            if ($internal) {
                abort_unless($integration || $request->is('up', 'live/proxy-auth'), 404);
            } else {
                abort_unless($request->getHost() === config('deployment.host') && $request->isSecure() && $request->getPort() === 443, 400, 'Use the configured HTTPS address.');
                abort_if($integration, 404);
            }
        }

        return $next($request);
    }
}
