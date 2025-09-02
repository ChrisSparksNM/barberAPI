<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    /**
     * Get all users (admin only)
     */
    public function getAllUsers(Request $request): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $query = User::query();

            // Filter by role
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by email verification
            if ($request->has('verified')) {
                if ($request->boolean('verified')) {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            }

            // Search by name or email
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('barber_name', 'like', "%{$search}%");
                });
            }

            $users = $query->select([
                'id', 'name', 'email', 'phone_number', 'role', 'barber_name',
                'email_verified_at', 'is_active', 'last_login_at', 'created_at',
                'profile_image', 'bio', 'specialties', 'working_hours', 'hourly_rate'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

            return response()->json([
                'success' => true,
                'users' => $users
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users.'
            ], 500);
        }
    }

    /**
     * Get user details (admin only)
     */
    public function getUserDetails(Request $request, int $userId): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::findOrFail($userId);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'barber_name' => $user->barber_name,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'profile_image' => $user->profile_image,
                    'bio' => $user->bio,
                    'specialties' => $user->specialties,
                    'working_hours' => $user->working_hours,
                    'hourly_rate' => $user->hourly_rate,
                    'notifications_enabled' => $user->notifications_enabled,
                    'sms_notifications_enabled' => $user->sms_notifications_enabled,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch user details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }
    }

    /**
     * Update user (admin only)
     */
    public function updateUser(Request $request, int $userId): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::findOrFail($userId);

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $userId],
                'phone_number' => ['sometimes', 'string', 'max:20'],
                'role' => ['sometimes', 'in:customer,barber,admin'],
                'barber_name' => ['sometimes', 'string', 'max:255', 'unique:users,barber_name,' . $userId],
                'is_active' => ['sometimes', 'boolean'],
                'bio' => ['nullable', 'string', 'max:1000'],
                'specialties' => ['nullable', 'array'],
                'specialties.*' => ['string', 'max:100'],
                'working_hours' => ['nullable', 'array'],
                'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                'profile_image' => ['nullable', 'string', 'max:255'],
                'notifications_enabled' => ['sometimes', 'boolean'],
                'sms_notifications_enabled' => ['sometimes', 'boolean'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $request->only([
                'name', 'email', 'phone_number', 'role', 'barber_name', 'is_active',
                'bio', 'specialties', 'working_hours', 'hourly_rate', 'profile_image',
                'notifications_enabled', 'sms_notifications_enabled'
            ]);

            $user->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'barber_name' => $user->barber_name,
                    'is_active' => $user->is_active,
                    'email_verified_at' => $user->email_verified_at,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to update user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user.'
            ], 500);
        }
    }

    /**
     * Delete user (admin only)
     */
    public function deleteUser(Request $request, int $userId): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::findOrFail($userId);

            // Prevent admin from deleting themselves
            if ($user->id === $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account.'
                ], 400);
            }

            // Check if user has appointments
            if ($user->role === 'barber' && $user->barberAppointments()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete barber with existing appointments. Please reassign or cancel appointments first.'
                ], 400);
            }

            $userName = $user->name;
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => "User '{$userName}' deleted successfully."
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to delete user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user.'
            ], 500);
        }
    }

    /**
     * Reset user password (admin only)
     */
    public function resetUserPassword(Request $request, int $userId): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::findOrFail($userId);
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to reset password: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password.'
            ], 500);
        }
    }

    /**
     * Get user statistics (admin only)
     */
    public function getUserStatistics(Request $request): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $stats = [
                'total_users' => User::count(),
                'customers' => User::where('role', 'customer')->count(),
                'barbers' => User::where('role', 'barber')->count(),
                'admins' => User::where('role', 'admin')->count(),
                'verified_users' => User::whereNotNull('email_verified_at')->count(),
                'unverified_users' => User::whereNull('email_verified_at')->count(),
                'active_users' => User::where('is_active', true)->count(),
                'inactive_users' => User::where('is_active', false)->count(),
                'recent_registrations' => User::where('created_at', '>=', now()->subDays(30))->count(),
            ];

            return response()->json([
                'success' => true,
                'statistics' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch user statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics.'
            ], 500);
        }
    }
}