<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! ($user->currentAccessToken() instanceof PersonalAccessToken)) {
            return response()->json(['message' => 'A API exige um token pessoal Sanctum.'], 401);
        }

        if (! $user || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['message' => 'Esta conta está inativa.'], 403);
        }

        return $next($request);
    }
}
