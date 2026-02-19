<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 *
 * API endpoints for managing authentication
 */
class AuthController extends Controller {

	public function register(Request $request) {
		$request->validate([
			'name' => 'required|string|max:255',
			'email' => 'required|string|email|max:255|unique:users',
				'password' => 'required|string|min:8',
		]);

		$user = User::create([
			'name' => $request->name,
			'email' => $request->email,
			'password' => Hash::make($request->password),
			'admin' => false,
			'admin_level' => 0, // Normal kullanıcı
		]);

		$token = $user->createToken('auth_token')->plainTextToken;

		return response()->json([
			'access_token' => $token,
			'token_type' => 'Bearer',
			'user' => $user
		], 201);
	}

	public function login(Request $request) {
		$request->validate([
			'email' => 'required|email',
			'password' => 'required',
		]);

		if (!Auth::attempt($request->only('email', 'password'))) {
			throw ValidationException::withMessages([
				'email' => ['Girilen bilgiler hatalı.'],
			]);
		}

		$user = User::where('email', $request->email)->firstOrFail();
		$token = $user->createToken('auth_token')->plainTextToken;

		return response()->json([
			'access_token' => $token,
			'token_type' => 'Bearer',
			'user' => $user
		]);
	}
 

public function login2(Request $request)
{
    $credentials = $request->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    if (!\Illuminate\Support\Facades\Auth::attempt($credentials)) {
        return response()->json([
            'message' => 'E-posta veya şifre hatalı.'
        ], 401);
    }

    /** @var \App\Models\User $user */
    $user  = \App\Models\User::where('email', $request->email)->firstOrFail();

    // İsteğe bağlı: e-posta doğrulanmış mı?
    // if (is_null($user->email_verified_at)) {
    //     return response()->json(['message' => 'E-posta doğrulanmamış.'], 403);
    // }

    // İsteğe bağlı: sadece admin panele erişebilsin (örnek)
    // if (!$user->admin) {
    //     return response()->json(['message' => 'Yetkisiz.'], 403);
    // }

    // Token yetenekleri/cihaz adı (opsiyonel)
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return response()->json([
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => $user,
    ]);
}

	public function logout(Request $request) {
		$request->user()->currentAccessToken()->delete();

		return response()->json([
			'message' => 'Başarıyla çıkış yapıldı'
		]);
	}

	public function forgotPassword(Request $request) {
		// TODO: Şifre sıfırlama mantığı
	}

	public function resetPassword(Request $request) {
		// TODO: Yeni şifre belirleme mantığı
	}
}
