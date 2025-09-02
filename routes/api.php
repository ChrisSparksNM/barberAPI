<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\UserRegistrationController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\HealthController;

// Health check endpoint (no auth required)
Route::get('/health', [HealthController::class, 'check']);

// Public routes (no authentication required)
Route::get('/barbers', [UserRegistrationController::class, 'getBarbers']);
Route::get('/barbers/{barberName}', [UserRegistrationController::class, 'getBarberByName']);

Route::middleware("guest")->group(callback: function (): void{
    // Original registration (keep for backward compatibility)
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('guest')
        ->name('register');

    // New customer registration with email verification
    Route::post('/register/customer', [UserRegistrationController::class, 'registerCustomer'])
        ->name('register.customer');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('guest')
        ->name('login');
});


Route::middleware(['auth:sanctum'])->group( function(){
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Payment routes
    Route::post('/create-payment-intent', [App\Http\Controllers\PaymentController::class, 'createPaymentIntent']);
    Route::post('/confirm-payment', [App\Http\Controllers\PaymentController::class, 'confirmPayment']);
    Route::post('/pay-remaining-balance', [App\Http\Controllers\PaymentController::class, 'payRemainingBalance']);
    Route::post('/confirm-remaining-balance', [App\Http\Controllers\PaymentController::class, 'confirmRemainingBalance']);
    
    // Payment method management routes
    Route::post('/create-setup-intent', [App\Http\Controllers\PaymentController::class, 'createSetupIntent']);
    Route::post('/confirm-setup-intent', [App\Http\Controllers\PaymentController::class, 'confirmSetupIntent']);
    Route::post('/save-payment-method', [App\Http\Controllers\PaymentController::class, 'savePaymentMethod']);
    Route::post('/save-payment-method-from-intent', [App\Http\Controllers\PaymentController::class, 'savePaymentMethodFromIntent']);
    Route::get('/payment-methods', [App\Http\Controllers\PaymentController::class, 'getPaymentMethods']);
    Route::delete('/payment-methods/{paymentMethodId}', [App\Http\Controllers\PaymentController::class, 'deletePaymentMethod']);
    Route::post('/payment-methods/{paymentMethodId}/set-default', [App\Http\Controllers\PaymentController::class, 'setDefaultPaymentMethod']);
    
    // Appointment routes
    Route::get('/appointments/{barberName}/{date}', [App\Http\Controllers\AppointmentController::class, 'getAppointments']);
    Route::get('/my-appointments', [App\Http\Controllers\AppointmentController::class, 'getUserAppointments']);
    Route::post('/appointments/{appointmentId}/cancel', [App\Http\Controllers\AppointmentController::class, 'cancelAppointment']);
    Route::delete('/my-appointments/clear-past', [App\Http\Controllers\AppointmentController::class, 'clearPastAppointments']);
    
    // No-show management routes
    Route::post('/appointments/{appointmentId}/mark-no-show', [App\Http\Controllers\NoShowController::class, 'markNoShowAndCharge']);
    Route::get('/appointments/eligible-no-shows', [App\Http\Controllers\NoShowController::class, 'getEligibleNoShowAppointments']);
    Route::post('/appointments/process-no-shows', [App\Http\Controllers\NoShowController::class, 'processAllEligibleNoShows']);
    
    // SMS notification routes
    Route::post('/update-phone-number', [App\Http\Controllers\NotificationController::class, 'updatePhoneNumber']);
    Route::post('/test-sms', [App\Http\Controllers\NotificationController::class, 'testNotification']);
    
    // Tip payment routes
    Route::post('/pay-tip', [App\Http\Controllers\PaymentController::class, 'payTip']);
    Route::post('/confirm-tip-payment', [App\Http\Controllers\PaymentController::class, 'confirmTipPayment']);

    // Email verification routes
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'sendVerificationEmail'])
        ->name('verification.send');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('verification.verify');
    Route::get('/email/verification-status', [EmailVerificationController::class, 'checkVerificationStatus']);

    // User profile routes
    Route::get('/profile', function (Request $request) {
        $user = $request->user();
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
                'profile_image' => $user->profile_image,
                'bio' => $user->bio,
                'specialties' => $user->specialties,
                'working_hours' => $user->working_hours,
                'hourly_rate' => $user->hourly_rate,
            ]
        ]);
    });

    // Barber management routes (admin only)
    Route::middleware(['admin'])->group(function () {
        Route::post('/register/barber', [UserRegistrationController::class, 'registerBarber']);
        Route::put('/barbers/{barberId}', [UserRegistrationController::class, 'updateBarberProfile']);
        
        // User management routes
        Route::get('/admin/users', [UserManagementController::class, 'getAllUsers']);
        Route::get('/admin/users/{userId}', [UserManagementController::class, 'getUserDetails']);
        Route::put('/admin/users/{userId}', [UserManagementController::class, 'updateUser']);
        Route::delete('/admin/users/{userId}', [UserManagementController::class, 'deleteUser']);
        Route::post('/admin/users/{userId}/reset-password', [UserManagementController::class, 'resetUserPassword']);
        Route::get('/admin/statistics', [UserManagementController::class, 'getUserStatistics']);
    });

    // Barber profile routes (barber or admin)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::put('/barber/profile/{barberId}', [UserRegistrationController::class, 'updateBarberProfile']);
    });

});

