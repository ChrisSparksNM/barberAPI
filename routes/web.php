<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;

// Redirect root based on authentication
Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->isBarber() || $user->isAdmin()) {
            return redirect('/dashboard');
        }
    }
    return redirect('/login');
});

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Dashboard routes (protected)
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/appointments/{appointment}/mark-no-show', [DashboardController::class, 'markNoShow'])->name('appointments.mark-no-show');
    Route::post('/appointments/{appointment}/mark-completed', [DashboardController::class, 'markCompleted'])->name('appointments.mark-completed');
    Route::post('/appointments/{appointment}/charge-no-show', [DashboardController::class, 'chargeNoShow'])->name('appointments.charge-no-show');
    Route::post('/appointments/{appointment}/charge-remaining', [DashboardController::class, 'chargeRemainingBalance'])->name('appointments.charge-remaining');
    Route::post('/appointments/process-no-shows', [DashboardController::class, 'processAllNoShows'])->name('appointments.process-no-shows');
    Route::get('/dashboard/day/{date}', [DashboardController::class, 'getDayDetails'])->name('dashboard.day-details');
    
    // Notification routes
    Route::post('/appointments/{appointment}/send-reminder', [\App\Http\Controllers\NotificationController::class, 'sendAppointmentReminder'])->name('appointments.send-reminder');
    Route::post('/send-all-reminders', [DashboardController::class, 'sendAllReminders'])->name('send-all-reminders');
});

require __DIR__.'/auth.php';
