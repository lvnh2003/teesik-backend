<?php
namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Đăng ký
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'user'
        ]);

        $token = $user->createToken('authToken')->accessToken;

        return $this->createdResponse(
            ['user' => $user, 'token' => $token, 'token_type' => 'Bearer'],
            'Registration successful'
        );
    }

    // Đăng nhập
    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $user = Auth::user();
        $token = $user->createToken('authToken')->accessToken;

        return $this->successResponse(
            ['user' => $user, 'token' => $token, 'token_type' => 'Bearer'],
            'Login successful'
        );
    }
}
