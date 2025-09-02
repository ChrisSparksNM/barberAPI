<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserRegistrationController extends Controller
{
    /**
     * Register a new user (customer)
     */
    public function registerCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'phone_number' => ['required', 'string', 'max:20'],
                'password' => ['required', 'confirmed', Password::defaults()],
                'notifications_enabled' => ['boolean'],
                'sms_notifications_enabled' => ['boolean'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'password' => Hash::make($request->password),
                'role' => 'customer',
                'notifications_enabled' => $request->get('notifications_enabled', true),
                'sms_notifications_enabled' => $request->get('sms_notifications_enabled', true),
                'is_active' => true,
            ]);

            // Send email verification
            event(new Registered($user));

            // Create API token
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registration successful! Please check your email to verify your account.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                ],
                'token' => $token,
                'token_type' => 'Bearer'
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Customer registration failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Register a new barber (admin only)
     */
    public function registerBarber(Request $request): JsonResponse
    {
        try {
            // Check if user is admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can register barbers.'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'phone_number' => ['required', 'string', 'max:20'],
                'password' => ['required', 'confirmed', Password::defaults()],
                'barber_name' => ['required', 'string', 'max:255', 'unique:users'],
                'bio' => ['nullable', 'string', 'max:1000'],
                'specialties' => ['nullable', 'array'],
                'specialties.*' => ['string', 'max:100'],
                'working_hours' => ['nullable', 'array'],
                'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                'profile_image' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'password' => Hash::make($request->password),
                'role' => 'barber',
                'barber_name' => $request->barber_name,
                'bio' => $request->bio,
                'specialties' => $request->specialties,
                'working_hours' => $request->working_hours,
                'hourly_rate' => $request->hourly_rate,
                'profile_image' => $request->profile_image,
                'notifications_enabled' => true,
                'sms_notifications_enabled' => true,
                'is_active' => true,
            ]);

            // Send email verification
            event(new Registered($user));

            return response()->json([
                'success' => true,
                'message' => 'Barber registered successfully! Verification email sent.',
                'barber' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'barber_name' => $user->barber_name,
                    'bio' => $user->bio,
                    'specialties' => $user->specialties,
                    'working_hours' => $user->working_hours,
                    'hourly_rate' => $user->hourly_rate,
                    'profile_image' => $user->profile_image,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Barber registration failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Barber registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Get all barbers (public endpoint)
     */
    public function getBarbers(): JsonResponse
    {
        try {
            $barbers = User::where('role', 'barber')
                ->where('is_active', true)
                ->select([
                    'id',
                    'name',
                    'barber_name',
                    'bio',
                    'specialties',
                    'working_hours',
                    'hourly_rate',
                    'profile_image',
                    'email_verified_at'
                ])
                ->get();

            return response()->json([
                'success' => true,
                'barbers' => $barbers
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch barbers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch barbers.'
            ], 500);
        }
    }

    /**
     * Get barber details by barber name
     */
    public function getBarberByName(string $barberName): JsonResponse
    {
        try {
            $barber = User::where('role', 'barber')
                ->where('barber_name', $barberName)
                ->where('is_active', true)
                ->select([
                    'id',
                    'name',
                    'barber_name',
                    'bio',
                    'specialties',
                    'working_hours',
                    'hourly_rate',
                    'profile_image',
                    'email_verified_at'
                ])
                ->first();

            if (!$barber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barber not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'barber' => $barber
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch barber: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch barber details.'
            ], 500);
        }
    }

    /**
     * Update barber profile (barber or admin only)
     */
    public function updateBarberProfile(Request $request, int $barberId): JsonResponse
    {
        try {
            $user = $request->user();
            $barber = User::where('role', 'barber')->findOrFail($barberId);

            // Check permissions
            if (!$user->isAdmin() && $user->id !== $barber->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update your own profile.'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'string', 'max:255'],
                'phone_number' => ['sometimes', 'string', 'max:20'],
                'barber_name' => ['sometimes', 'string', 'max:255', 'unique:users,barber_name,' . $barberId],
                'bio' => ['nullable', 'string', 'max:1000'],
                'specialties' => ['nullable', 'array'],
                'specialties.*' => ['string', 'max:100'],
                'working_hours' => ['nullable', 'array'],
                'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                'profile_image' => ['nullable', 'string', 'max:255'],
                'is_active' => ['sometimes', 'boolean'], // Admin only
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $request->only([
                'name', 'phone_number', 'barber_name', 'bio', 
                'specialties', 'working_hours', 'hourly_rate', 'profile_image'
            ]);

            // Only admin can change is_active status
            if ($user->isAdmin() && $request->has('is_active')) {
                $updateData['is_active'] = $request->is_active;
            }

            $barber->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Barber profile updated successfully.',
                'barber' => [
                    'id' => $barber->id,
                    'name' => $barber->name,
                    'barber_name' => $barber->barber_name,
                    'bio' => $barber->bio,
                    'specialties' => $barber->specialties,
                    'working_hours' => $barber->working_hours,
                    'hourly_rate' => $barber->hourly_rate,
                    'profile_image' => $barber->profile_image,
                    'is_active' => $barber->is_active,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to update barber profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update barber profile.'
            ], 500);
        }
    }
}