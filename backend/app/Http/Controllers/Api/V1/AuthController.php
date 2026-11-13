<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MOBILE_TOKEN_LIFETIME_DAYS = 90;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:120']]);
        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['As credenciais informadas são inválidas.']]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Esta conta está inativa.'], 403);
        }

        $token = $user->createToken(
            $data['device_name'],
            ['*'],
            now()->addDays(self::MOBILE_TOKEN_LIFETIME_DAYS),
        )->plainTextToken;

        return response()->json(['data' => ['user' => new UserResource($user), 'token' => $token, 'token_type' => 'Bearer']], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
