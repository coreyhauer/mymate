<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusted-proxy SSO for the gigtool Voltron proxy.
 *
 * When MyMate is served behind Voltron, its nginx (auth_request against /voltron-api/authz)
 * injects the signed-in user's email as `X-Voltron-Email`, plus a shared secret
 * `X-Voltron-Proxy-Secret` that only the proxy knows. We honour the email ONLY when that secret
 * matches (constant-time), so a request that reaches MyMate directly - or forges the header -
 * is ignored and falls through to normal session/password auth.
 *
 * A first-seen user is auto-provisioned as a **view-only operator** (`is_admin` is not fillable
 * and defaults to false); this middleware NEVER elevates to admin - promotion is a manual,
 * deliberate act in the operator-management UI. Inert until `mymate.voltron.secret` is set.
 */
class AuthenticateViaVoltron
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('mymate.voltron.secret', '');

        if ($secret !== '') {
            $email = strtolower(trim((string) $request->header('X-Voltron-Email', '')));
            $provided = (string) $request->header('X-Voltron-Proxy-Secret', '');

            if ($email !== '' && str_contains($email, '@') && hash_equals($secret, $provided)) {
                $current = Auth::guard('web')->user();
                if ($current === null || strtolower((string) $current->email) !== $email) {
                    $user = User::firstOrCreate(
                        ['email' => $email],
                        [
                            'name' => trim((string) $request->header('X-Voltron-Name', '')) ?: $email,
                            'password' => bcrypt(Str::random(48)), // unusable - SSO only, never a password login
                        ],
                    );
                    Auth::guard('web')->login($user);
                }
            }
        }

        return $next($request);
    }
}
