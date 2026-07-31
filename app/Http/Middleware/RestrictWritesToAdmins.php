<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App-wide read-only tier. A normal operator may *view* the whole console but
 * may not change anything. The app first shipped a single tier where every logged-in operator
 * could edit, and only the user-management endpoints were gated. This closes the rest:
 * any state-changing request (non-safe HTTP method) from a non-admin is refused with 403,
 * so a viewer cannot delete a device, drop a link, run a scan, trigger an upgrade, etc.,
 * regardless of whether the UI still shows the control.
 *
 * Runs after `auth:sanctum`, so `user()` is present. Admins bypass entirely. The only
 * writes a non-admin keeps are changing *their own* password (a self-service action, not
 * a change to the monitored fleet) and the OPERATOR_ACTION_ROUTES below (harmless writes
 * that never touch the fleet's config either).
 */
class RestrictWritesToAdmins
{
    /** Safe (read-only) HTTP methods - never gated. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Route names a non-admin may still POST/PUT to (self-service only). */
    private const SELF_SERVICE_ROUTES = ['account.password.update'];

    /**
     * Route names a non-admin may still POST/DELETE to because the action is harmless:
     * it can only affect the requesting operator's own view, never the monitored fleet's
     * config. Live trace start/stop is the first example - the target is locked to the
     * device's own mgmt IP, so there's nothing here for a viewer to break.
     *
     * Notes + Sonar ticket links are the same shape: a NOC log and read-only ticket
     * pointers, neither of which changes the monitored fleet's config, so every
     * operator (not just admins) can create/edit/delete their own.
     */
    private const OPERATOR_ACTION_ROUTES = [
        'devices.trace.start', 'devices.trace.stop',
        'devices.notes.store', 'sites.notes.store', 'links.notes.store',
        'notes.update', 'notes.destroy',
        'devices.sonar-tickets.store', 'sites.sonar-tickets.store', 'links.sonar-tickets.store',
        'sonar-tickets.destroy', 'sonar-tickets.refresh',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $isWrite = ! in_array($request->getMethod(), self::SAFE_METHODS, true);

        if ($isWrite
            && ! $request->user()?->isAdmin()
            && ! $request->routeIs(self::SELF_SERVICE_ROUTES)
            && ! $request->routeIs(self::OPERATOR_ACTION_ROUTES)
        ) {
            abort(403, 'Read-only operator - an administrator must make this change.');
        }

        return $next($request);
    }
}
