<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function create()
    {
        return view('auth.register', ['userTypes' => UserType::publicRegistrationCases()]);
    }

    public function store(RegisterUserRequest $request)
    {
        $data = $request->validated();
        $type = UserType::from($data['user_type']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'user_type' => $type,
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
            'password' => $data['password'],
        ]);

        // The role is derived from the allowlisted user type, never from client input.
        $user->assignRole($type->value);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
