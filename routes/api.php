<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\HealthController;

// Health check endpoint (no auth required)
Route::get('/health', [HealthController::class, 'check']);

Route::middleware("guest")->group(callback: function (): void{
    Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

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

});

