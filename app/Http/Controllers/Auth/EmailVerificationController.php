<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Send email verification notification
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is already verified.'
                ], 400);
            }

            $user->sendEmailVerificationNotification();

            return response()->json([
                'success' => true,
                'message' => 'Verification email sent successfully.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send verification email: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email.'
            ], 500);
        }
    }

    /**
     * Verify email address
     */
    public function verifyEmail(EmailVerificationRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email is already verified.',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'email_verified_at' => $user->email_verified_at,
                        'is_active' => $user->is_active,
                    ]
                ]);
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully!',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Email verification failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Email verification failed.'
            ], 500);
        }
    }

    /**
     * Check email verification status
     */
    public function checkVerificationStatus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'is_verified' => $user->hasVerifiedEmail(),
                'email_verified_at' => $user->email_verified_at,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to check verification status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check verification status.'
            ], 500);
        }
    }
}