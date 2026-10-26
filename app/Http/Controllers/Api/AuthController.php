<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwilioVerifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\\+[1-9]\\d{7,14}$/', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:500'],
            'landmark' => ['required', 'string', 'max:200'],
            'state' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['seller_name'],
            // The starter users table requires a unique email. Phone-only sellers get a non-login placeholder.
            'email' => 'seller_' . preg_replace('/\D+/', '', $data['phone']) . '@phone.venter.local',
            'seller_name' => $data['seller_name'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'landmark' => $data['landmark'],
            'state' => $data['state'],
            'country' => $data['country'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('venter-app')->plainTextToken;

        return response()->json([
            'message' => 'Seller registration successful.',
            'user' => $this->safeSeller($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\\+[1-9]\\d{7,14}$/'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The supplied phone number or password is incorrect.'],
            ]);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->safeSeller($user),
            'token' => $user->createToken('venter-app')->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['message' => 'Logout successful.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->safeSeller($request->user())]);
    }

    public function forgotPassword(Request $request, TwilioVerifyService $twilio): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\\+[1-9]\\d{7,14}$/'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if ($user) {
            try {
                $twilio->sendCode($data['phone']);
            } catch (Throwable $e) {
                report($e);
                return response()->json([
                    'message' => 'Unable to send OTP at this time. Please try again later.',
                ], 503);
            }
        }

        return response()->json([
            'message' => 'If the phone number is registered, an OTP has been sent.',
        ]);
    }

    public function verifyOtp(Request $request, TwilioVerifyService $twilio): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\\+[1-9]\\d{7,14}$/'],
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user || ! $twilio->verifyCode($data['phone'], $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The OTP is invalid or expired.'],
            ]);
        }

        $plainToken = Str::random(64);
        DB::table('password_reset_otps')->updateOrInsert(
            ['phone' => $data['phone']],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'OTP verified.',
            'reset_token' => $plainToken,
            'expires_in_seconds' => 600,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\\+[1-9]\\d{7,14}$/'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $row = DB::table('password_reset_otps')->where('phone', $data['phone'])->first();

        if (! $row || ! Hash::check($data['reset_token'], $row->token)
            || ! $row->created_at || now()->diffInSeconds($row->created_at) > 600) {
            throw ValidationException::withMessages([
                'reset_token' => ['The reset token is invalid or expired. Verify your OTP again.'],
            ]);
        }

        $user = User::where('phone', $data['phone'])->firstOrFail();
        $user->password = Hash::make($data['password']);
        $user->save();

        DB::table('password_reset_otps')->where('phone', $data['phone'])->delete();

        // Revoke existing login tokens after password reset.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successful. Please log in again.']);
    }

    private function safeSeller(User $user): array
    {
        return [
            'id' => $user->id,
            'seller_name' => $user->seller_name,
            'phone' => $user->phone,
            'address' => $user->address,
            'landmark' => $user->landmark,
            'state' => $user->state,
            'country' => $user->country,
        ];
    }
}
