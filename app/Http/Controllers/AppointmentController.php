<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AppointmentController extends Controller
{
    /**
     * Get user's appointments
     */
    public function getUserAppointments(Request $request): JsonResponse
    {
        try {
            \Log::info('getUserAppointments called for user: ' . $request->user()->id);
            
            $filter = $request->get('filter', 'upcoming'); // 'upcoming', 'past', or 'all'
            
            $query = Appointment::where('user_id', $request->user()->id)
                ->where('appointment_status', '!=', 'cancelled');
            
            // Apply date filter based on request
            if ($filter === 'upcoming') {
                // Upcoming: scheduled appointments that are in the future
                $query->where('appointment_status', 'scheduled')
                      ->where('appointment_date', '>=', now()->toDateString());
                $query->orderBy('appointment_date', 'asc')
                      ->orderBy('appointment_time', 'asc');
            } elseif ($filter === 'past') {
                // Past: completed appointments OR appointments in the past (regardless of status)
                $query->where(function($q) {
                    $q->where('appointment_status', 'completed')
                      ->orWhere('appointment_date', '<', now()->toDateString());
                });
                $query->orderBy('appointment_date', 'desc')
                      ->orderBy('appointment_time', 'desc');
            } else {
                // 'all' - show all appointments
                $query->orderBy('appointment_date', 'desc')
                      ->orderBy('appointment_time', 'desc');
            }
            
            $appointments = $query->get();
                
            \Log::info('Found ' . $appointments->count() . ' appointments');
            
            $mappedAppointments = $appointments->map(function ($appointment) {
                \Log::info('Processing appointment ID: ' . $appointment->id);
                
                try {
                    $canCancel = $appointment->canBeCancelled();
                    \Log::info('Can cancel result: ' . ($canCancel ? 'true' : 'false'));
                } catch (\Exception $e) {
                    \Log::error('Error checking canBeCancelled for appointment ' . $appointment->id . ': ' . $e->getMessage());
                    $canCancel = false;
                }
                
                $result = [
                    'id' => $appointment->id,
                    'barber_name' => $appointment->barber_name,
                    'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
                    'appointment_time' => $appointment->appointment_time,
                    'services' => $appointment->services ?? [],
                    'deposit_amount' => floatval($appointment->deposit_amount),
                    'total_amount' => floatval($appointment->total_amount ?? 0),
                    'remaining_amount' => floatval($appointment->remaining_amount ?? 0),
                    'payment_status' => $appointment->payment_status,
                    'full_payment_completed' => $appointment->full_payment_completed ?? false,
                    'appointment_status' => $appointment->appointment_status,
                    'is_no_show' => $appointment->is_no_show ?? false,
                    'no_show_charge_amount' => $appointment->no_show_charge_amount ? floatval($appointment->no_show_charge_amount) : null,
                    'no_show_charged_at' => $appointment->no_show_charged_at ? $appointment->no_show_charged_at->format('Y-m-d H:i:s') : null,
                    'tip_amount' => $appointment->tip_amount ? floatval($appointment->tip_amount) : 0,
                    'can_cancel' => $canCancel,
                    'created_at' => $appointment->created_at->format('Y-m-d H:i:s'),
                ];
                
                \Log::info('Mapped appointment: ' . json_encode($result));
                return $result;
            });

            \Log::info('Returning appointments response');
            return response()->json([
                'appointments' => $mappedAppointments
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching user appointments: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'error' => 'Failed to fetch appointments',
                'appointments' => []
            ], 500);
        }
    }

    /**
     * Cancel an appointment
     */
    public function cancelAppointment(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $appointment = Appointment::where('id', $appointmentId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if (!$appointment->canBeCancelled()) {
                return response()->json([
                    'error' => 'This appointment cannot be cancelled. Cancellations must be made at least 24 hours in advance.'
                ], 400);
            }

            $appointment->update([
                'appointment_status' => 'cancelled'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment cancelled successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error cancelling appointment: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to cancel appointment'
            ], 500);
        }
    }

    /**
     * Get appointments for a specific barber and date
     */
    public function getAppointments(string $barberName, string $date): JsonResponse
    {
        try {
            \Log::info("Searching for appointments - Barber: {$barberName}, Date: {$date}");
            
            // Get appointments for the specific date and barber
            $appointments = Appointment::where('barber_name', $barberName)
                ->whereDate('appointment_date', $date)
                ->where('appointment_status', '!=', 'cancelled')
                ->get();
                
            \Log::info("Filtered appointments count: " . $appointments->count());
            
            $blockedTimes = [];
            
            foreach ($appointments as $appointment) {
                // Get the start time
                $startTime = is_string($appointment->appointment_time) 
                    ? $appointment->appointment_time 
                    : $appointment->appointment_time->format('H:i');
                
                \Log::info("Processing appointment at: " . $startTime);
                
                // Calculate total duration from services
                $services = is_string($appointment->services) 
                    ? json_decode($appointment->services, true) 
                    : $appointment->services;
                
                $totalDuration = 0;
                if (is_array($services)) {
                    foreach ($services as $service) {
                        // Add service duration, default to 60 minutes if not specified
                        $duration = isset($service['duration']) ? $service['duration'] : 60;
                        $totalDuration += $duration;
                    }
                } else {
                    // Default to 60 minutes if no services data
                    $totalDuration = 60;
                }
                
                \Log::info("Total service duration: {$totalDuration} minutes");
                
                // Calculate blocked time slots
                $startHour = (int) substr($startTime, 0, 2);
                $endTime = $startHour + ($totalDuration / 60);
                
                // Block each hour slot that this appointment occupies
                for ($hour = $startHour; $hour < $endTime; $hour++) {
                    $blockedTime = sprintf('%02d:00', $hour);
                    if (!in_array($blockedTime, $blockedTimes)) {
                        $blockedTimes[] = $blockedTime;
                    }
                }
            }

            \Log::info("Final blocked times for {$barberName} on {$date}: " . json_encode($blockedTimes));

            return response()->json([
                'booked_times' => $blockedTimes
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching appointments: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch appointments',
                'booked_times' => []
            ], 500);
        }
    }

    /**
     * Clear all past appointments for the authenticated user
     */
    public function clearPastAppointments(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            \Log::info("Clearing past appointments for user: {$user->id}");

            // Get current date and time
            $now = now();
            
            // Find all past appointments (completed, cancelled, or no-show appointments)
            // Also include appointments that are in the past regardless of status
            $pastAppointments = Appointment::where('user_id', $user->id)
                ->where(function ($query) use ($now) {
                    // Past appointments by date/time OR appointments with completed/cancelled/no-show status
                    $query->where('appointment_date', '<', $now->toDateString())
                          ->orWhere(function ($subQuery) use ($now) {
                              $subQuery->where('appointment_date', '=', $now->toDateString())
                                       ->where('appointment_time', '<', $now->format('H:i:s'));
                          })
                          ->orWhereIn('appointment_status', ['completed', 'cancelled', 'no_show']);
                })
                ->get();

            $clearedCount = $pastAppointments->count();
            \Log::info("Found {$clearedCount} past appointments to clear");

            if ($clearedCount > 0) {
                // Delete the past appointments
                Appointment::where('user_id', $user->id)
                    ->where(function ($query) use ($now) {
                        // Past appointments by date/time OR appointments with completed/cancelled/no-show status
                        $query->where('appointment_date', '<', $now->toDateString())
                              ->orWhere(function ($subQuery) use ($now) {
                                  $subQuery->where('appointment_date', '=', $now->toDateString())
                                           ->where('appointment_time', '<', $now->format('H:i:s'));
                              })
                              ->orWhereIn('appointment_status', ['completed', 'cancelled', 'no_show']);
                    })
                    ->delete();

                \Log::info("Successfully cleared {$clearedCount} past appointments");
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully cleared {$clearedCount} past appointments",
                'cleared_count' => $clearedCount
            ]);

        } catch (\Exception $e) {
            \Log::error('Error clearing past appointments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear past appointments. Please try again.'
            ], 500);
        }
    }
}