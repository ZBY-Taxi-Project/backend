<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userRole = $user->role instanceof UserRole ? $user->role->value : $user->role;

        // Admin always has access to all dispatcher and driver endpoints
        if ($userRole === UserRole::ADMIN->value) {
            return $next($request);
        }

        if (! in_array($userRole, $roles, true)) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'message' => 'You do not have permission to access this resource.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
