<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $role
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Authentication required.',
            ], 401);
        }

        // Check if user is suspended
        if ($user->hasRole('suspended')) {
            return response()->json([
                'success' => false,
                'error' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        // Check for required role
        if (!$user->hasRole($role)) {
            return response()->json([
                'success' => false,
                'error' => "Access denied. Required role: {$role}",
            ], 403);
        }

        return $next($request);
    }
}
