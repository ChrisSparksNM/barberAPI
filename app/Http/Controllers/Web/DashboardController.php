<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Http\Controllers\NoShowController;
use Carbon\Carbon;
use Exception;

class DashboardController extends Controller
{
    /**
     * Safely format appointment time
     */
    private function formatAppointmentTime($appointmentTime)
    {
        try {
            $timeString = is_string($appointmentTime) 
                ? $appointmentTime 
                : $appointmentTime->format('H:i');
            
            // Handle different time formats - remove seconds if present
            if (strlen($timeString) > 5) {
                $timeString = substr($timeString, 0, 5);
            }
            
            return \Carbon\Carbon::createFromFormat('H:i', $timeString)->format('g:i A');
        } catch (Exception $e) {
            return $appointmentTime ?? 'Invalid Time';
        }
    }
    public function index(Request $request)
    {
        $user = auth()->user();
        $selectedDate = $request->get('date', now()->format('Y-m-d'));
        $selectedBarber = $request->get('barber', 'all');
        $viewMode = $request->get('view', 'list'); // 'calendar' or 'list'
        
        // Get current month data for calendar
        $currentMonth = $request->get('month', now()->format('Y-m'));
        $monthStart = Carbon::parse($currentMonth . '-01');
        $monthEnd = $monthStart->copy()->endOfMonth();
        
        // Get all appointments for the month
        $monthlyAppointmentsQuery = Appointment::with('user')
            ->whereBetween('appointment_date', [$monthStart, $monthEnd])
            ->orderBy('appointment_date', 'asc')
            ->orderBy('appointment_time', 'asc');
            
        // If user is a barber, only show their appointments
        if ($user && $user->isBarber()) {
            $monthlyAppointmentsQuery->where('barber_name', $user->barber_name);
            $selectedBarber = $user->barber_name;
        } elseif ($selectedBarber !== 'all') {
            $monthlyAppointmentsQuery->where('barber_name', $selectedBarber);
        }
        
        $monthlyAppointments = $monthlyAppointmentsQuery->get()->groupBy(function($appointment) {
            return $appointment->appointment_date->format('Y-m-d');
        });
        
        // Get appointments for the selected date (for list view or day modal)
        $appointmentsQuery = Appointment::with('user')
            ->whereDate('appointment_date', $selectedDate)
            ->orderBy('appointment_time', 'asc');
        
        if ($user && $user->isBarber()) {
            $appointmentsQuery->where('barber_name', $user->barber_name);
        } elseif ($selectedBarber !== 'all') {
            $appointmentsQuery->where('barber_name', $selectedBarber);
        }
        
        $appointments = $appointmentsQuery->get();
        
        // Get summary statistics for the selected period
        $statsQuery = Appointment::with('user');
        
        if ($viewMode === 'calendar') {
            // For calendar view, show stats for the entire month
            $statsQuery->whereBetween('appointment_date', [$monthStart, $monthEnd]);
        } else {
            // For list view, show stats for the selected date
            $statsQuery->whereDate('appointment_date', $selectedDate);
        }
        
        // Apply barber filter to stats
        if ($user && $user->isBarber()) {
            $statsQuery->where('barber_name', $user->barber_name);
        } elseif ($selectedBarber !== 'all') {
            $statsQuery->where('barber_name', $selectedBarber);
        }
        
        $statsAppointments = $statsQuery->get();
        
        $stats = [
            'total_appointments' => $statsAppointments->count(),
            'scheduled' => $statsAppointments->where('appointment_status', 'scheduled')->count(),
            'completed' => $statsAppointments->where('appointment_status', 'completed')->count(),
            'no_shows' => $statsAppointments->where('is_no_show', true)->count(),
            'cancelled' => $statsAppointments->where('appointment_status', 'cancelled')->count(),
            'total_revenue' => $statsAppointments->where('appointment_status', 'completed')->sum('total_amount'),
            'total_tips' => $statsAppointments->where('appointment_status', 'completed')->sum('tip_amount'),
            'no_show_charges' => $statsAppointments->where('is_no_show', true)->sum('no_show_charge_amount'),
        ];
        
        // Get eligible no-show appointments
        $eligibleNoShows = $appointments->filter(function ($appointment) {
            return $appointment->isEligibleForNoShowCharge();
        });
        
        $barbers = ['David', 'Jesse', 'Marissa'];
        
        return view('dashboard.index', compact(
            'appointments', 
            'stats', 
            'eligibleNoShows', 
            'selectedDate', 
            'selectedBarber', 
            'barbers',
            'user',
            'viewMode',
            'currentMonth',
            'monthStart',
            'monthEnd',
            'monthlyAppointments'
        ));
    }
    
    public function markNoShow(Request $request, $appointmentId)
    {
        $user = auth()->user();
        
        // Only barbers and admins can mark no-shows
        if (!$user->isStaff()) {
            return back()->with('error', 'You do not have permission to mark appointments as no-show.');
        }
        
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);
        
        $appointment = Appointment::findOrFail($appointmentId);
        
        // If user is a barber, they can only mark their own appointments
        if ($user->isBarber() && $appointment->barber_name !== $user->barber_name) {
            return back()->with('error', 'You can only mark your own appointments as no-show.');
        }
        
        if (!$appointment->isEligibleForNoShowCharge()) {
            return back()->with('error', 'This appointment is not eligible for no-show processing.');
        }
        
        $noShowController = new NoShowController();
        $response = $noShowController->markNoShowAndCharge($request, $appointmentId);
        $data = $response->getData(true);
        
        // Check if the response has a success key and it's true
        if (isset($data['success']) && $data['success']) {
            $message = $data['charged'] 
                ? "No-show marked and charged $" . number_format($data['charge_amount'], 2)
                : 'No-show marked (no charge applied)';
            return back()->with('success', $message);
        } else {
            // Handle error responses
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Failed to process no-show';
            return back()->with('error', $errorMessage);
        }
    }
    
    public function markCompleted(Request $request, $appointmentId)
    {
        $user = auth()->user();
        
        // Only barbers and admins can mark appointments as completed
        if (!$user->isStaff()) {
            return back()->with('error', 'You do not have permission to mark appointments as completed.');
        }
        
        $appointment = Appointment::findOrFail($appointmentId);
        
        // If user is a barber, they can only mark their own appointments
        if ($user->isBarber() && $appointment->barber_name !== $user->barber_name) {
            return back()->with('error', 'You can only mark your own appointments as completed.');
        }
        
        if ($appointment->appointment_status !== 'scheduled') {
            return back()->with('error', 'Only scheduled appointments can be marked as completed.');
        }
        
        // Check if there's a remaining balance to charge
        if ($appointment->hasRemainingBalance()) {
            // Charge the remaining balance
            $paymentController = new \App\Http\Controllers\PaymentController();
            $chargeRequest = new Request([
                'appointment_id' => $appointment->id,
                'charge_remaining' => true
            ]);
            
            $response = $paymentController->chargeRemainingBalance($chargeRequest);
            $data = $response->getData(true);
            
            if (isset($data['success']) && $data['success']) {
                // Mark as completed after successful charge
                $appointment->update([
                    'appointment_status' => 'completed',
                    'full_payment_completed' => true
                ]);
                
                $remainingAmount = number_format($appointment->remaining_amount, 2);
                return back()->with('success', "Appointment completed and remaining balance of $$remainingAmount charged successfully.");
            } else {
                // Mark as completed even if charge fails, but note the issue
                $appointment->update([
                    'appointment_status' => 'completed'
                ]);
                
                $errorMessage = $data['error'] ?? $data['message'] ?? 'Failed to charge remaining balance';
                return back()->with('warning', "Appointment marked as completed, but failed to charge remaining balance: $errorMessage");
            }
        } else {
            // No remaining balance, just mark as completed
            $appointment->update([
                'appointment_status' => 'completed'
            ]);
            
            return back()->with('success', 'Appointment marked as completed.');
        }
    }
    
    public function processAllNoShows(Request $request)
    {
        $selectedDate = $request->get('date', now()->format('Y-m-d'));
        
        $noShowController = new NoShowController();
        $response = $noShowController->processAllEligibleNoShows($request);
        $data = $response->getData(true);
        
        // Check if the response has a success key and it's true
        if (isset($data['success']) && $data['success']) {
            $results = $data['results'];
            $message = "Processed {$results['processed']} appointments. " .
                      "Charged: {$results['charged']}, " .
                      "No payment method: {$results['no_payment_method']}, " .
                      "Failed: {$results['failed']}";
            return back()->with('success', $message);
        } else {
            // Handle error responses
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Failed to process no-shows';
            return back()->with('error', $errorMessage);
        }
    }
    
    public function chargeNoShow(Request $request, $appointmentId)
    {
        $user = auth()->user();
        
        // Only barbers and admins can charge no-shows
        if (!$user->isStaff()) {
            return back()->with('error', 'You do not have permission to charge no-shows.');
        }
        
        $appointment = Appointment::findOrFail($appointmentId);
        
        // If user is a barber, they can only charge their own appointments
        if ($user->isBarber() && $appointment->barber_name !== $user->barber_name) {
            return back()->with('error', 'You can only charge your own appointments.');
        }
        
        if (!$appointment->is_no_show) {
            return back()->with('error', 'This appointment is not marked as a no-show.');
        }
        
        if ($appointment->no_show_charge_amount) {
            return back()->with('error', 'This no-show has already been charged.');
        }
        
        if (!$appointment->user->defaultPaymentMethod) {
            return back()->with('error', 'Customer has no saved payment method to charge.');
        }
        
        $noShowController = new NoShowController();
        $response = $noShowController->chargeExistingNoShow($request, $appointmentId);
        $data = $response->getData(true);
        
        // Check if the response has a success key and it's true
        if (isset($data['success']) && $data['success']) {
            $message = $data['charged'] 
                ? "No-show charge of $" . number_format($data['charge_amount'], 2) . " applied successfully"
                : 'No-show charge could not be applied';
            return back()->with('success', $message);
        } else {
            // Handle error responses
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Failed to charge no-show';
            return back()->with('error', $errorMessage);
        }
    }
    
    public function getDayDetails(Request $request, $date)
    {
        $user = auth()->user();
        $selectedBarber = $request->get('barber', 'all');
        
        // Get appointments for the selected date
        $appointmentsQuery = Appointment::with('user')
            ->whereDate('appointment_date', $date)
            ->orderBy('appointment_time', 'asc');
        
        // If user is a barber, only show their appointments
        if ($user && $user->isBarber()) {
            $appointmentsQuery->where('barber_name', $user->barber_name);
        } elseif ($selectedBarber !== 'all') {
            $appointmentsQuery->where('barber_name', $selectedBarber);
        }
        
        $appointments = $appointmentsQuery->get();
        
        return response()->json([
            'date' => Carbon::parse($date)->format('l, F j, Y'),
            'appointments' => $appointments->map(function($appointment) {
                return [
                    'id' => $appointment->id,
                    'time' => $this->formatAppointmentTime($appointment->appointment_time),
                    'customer_name' => $appointment->user->name,
                    'customer_email' => $appointment->user->email,
                    'barber_name' => $appointment->barber_name,
                    'services' => $appointment->services,
                    'total_amount' => $appointment->total_amount,
                    'tip_amount' => $appointment->tip_amount,
                    'remaining_amount' => $appointment->remaining_amount,
                    'status' => $appointment->appointment_status,
                    'is_no_show' => $appointment->is_no_show,
                    'no_show_charge_amount' => $appointment->no_show_charge_amount,
                    'can_mark_completed' => $appointment->appointment_status === 'scheduled' && !$appointment->is_no_show,
                    'can_mark_no_show' => $appointment->isEligibleForNoShowCharge(),
                    'has_remaining_balance' => $appointment->hasRemainingBalance(),
                    'can_receive_tip' => $appointment->canReceiveTip(),
                ];
            })
        ]);
    }

    public function chargeRemainingBalance(Request $request, $appointmentId)
    {
        $user = auth()->user();
        
        // Only barbers and admins can charge remaining balance
        if (!$user->isStaff()) {
            return back()->with('error', 'You do not have permission to charge remaining balance.');
        }
        
        $appointment = Appointment::findOrFail($appointmentId);
        
        // If user is a barber, they can only charge their own appointments
        if ($user->isBarber() && $appointment->barber_name !== $user->barber_name) {
            return back()->with('error', 'You can only charge your own appointments.');
        }
        
        if (!$appointment->hasRemainingBalance()) {
            return back()->with('error', 'This appointment has no remaining balance to charge.');
        }
        
        if (!$appointment->user->defaultPaymentMethod) {
            return back()->with('error', 'Customer has no saved payment method to charge.');
        }
        
        $paymentController = new \App\Http\Controllers\PaymentController();
        $chargeRequest = new Request([
            'appointment_id' => $appointment->id
        ]);
        
        $response = $paymentController->chargeRemainingBalance($chargeRequest);
        $data = $response->getData(true);
        
        // Check if the response has a success key and it's true
        if (isset($data['success']) && $data['success']) {
            $message = "Remaining balance of $" . number_format($data['charge_amount'], 2) . " charged successfully";
            return back()->with('success', $message);
        } else {
            // Handle error responses
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Failed to charge remaining balance';
            return back()->with('error', $errorMessage);
        }
    }

    public function sendAllReminders(Request $request)
    {
        $user = auth()->user();
        
        // Only admins can send all reminders
        if (!$user->isAdmin()) {
            return back()->with('error', 'You do not have permission to send all reminders.');
        }
        
        try {
            // Run the reminder command
            \Artisan::call('appointments:send-reminders');
            $output = \Artisan::output();
            
            // Parse the output to get success/failure counts
            if (strpos($output, 'No appointments found') !== false) {
                return back()->with('info', 'No appointments found that need reminders.');
            } else {
                return back()->with('success', 'Reminder process completed. Check logs for details.');
            }
            
        } catch (\Exception $e) {
            \Log::error('Error running reminder command: ' . $e->getMessage());
            return back()->with('error', 'Failed to send reminders: ' . $e->getMessage());
        }
    }
}