<?php

namespace Acelle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrustProxies
{
    /**
     * Handle an incoming request.
     * Trust the local nginx reverse proxy for X-Forwarded headers.
     */
    public function handle($request, Closure $next)
    {
        // Trust the immediate upstream proxy (container nginx after realip processing).
        // nginx realip module rewrites REMOTE_ADDR to the real client IP,
        // so we must trust whatever REMOTE_ADDR is to honor X-Forwarded-Proto.
        $request->setTrustedProxies(
            [$request->server->get('REMOTE_ADDR', '127.0.0.1')],
            Request::HEADER_X_FORWARDED_ALL
        );

        return $next($request);
    }
}
