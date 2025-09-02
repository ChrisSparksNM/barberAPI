<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Appointment;
use App\Models\User;
use Twilio\Rest\Client;
use Exception;

class NotificationController extends Controller
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
    /**
     * Send appointment reminder notification
     */
    public function sendAppointmentReminder(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Only barbers and admins can send reminders
            if (!$user->isStaff()) {
                return response()->json([
                    'error' => 'You do not have permission to send reminders.'
                ], 403);
            }
            
            $appointment = Appointment::with('user')->find($appointmentId);
            
            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }
            
            // If user is a barber, they can only send reminders for their own appointments
            if ($user->isBarber() && $appointment->barber_name !== $user->barber_name) {
                return response()->json([
                    'error' => 'You can only send reminders for your own appointments.'
                ], 403);
            }
            
            // Get customer's phone number
            $customer = $appointment->user;
            $phoneNumber = $customer->phone_number;
            
            if (!$phoneNumber) {
                return response()->json([
                    'error' => 'Customer has no phone number on file'
                ], 400);
            }
            
            if (!$customer->sms_notifications_enabled) {
                return response()->json([
                    'error' => 'Customer has disabled SMS notifications'
                ], 400);
            }
            
            // Prepare SMS message
            $appointmentDate = $appointment->appointment_date->format('l, F j, Y');
            $appointmentTime = $this->formatAppointmentTime($appointment->appointment_time);
            
            // Create message
            $message = $this->createReminderMessage($appointment, $customer, $appointmentDate, $appointmentTime);
            
            // Log the message being sent for debugging
            \Log::info('Sending SMS reminder', [
                'phone' => $phoneNumber,
                'message' => $message,
                'message_length' => strlen($message)
            ]);
            
            // Send SMS
            $result = $this->sendSMS($phoneNumber, $message);
            
            if ($result['success']) {
                // Log the reminder sent
                \Log::info("SMS reminder sent", [
                    'appointment_id' => $appointment->id,
                    'customer_id' => $customer->id,
                    'customer_phone' => $phoneNumber,
                    'barber_name' => $appointment->barber_name,
                    'sent_by' => $user->id,
                    'message_sid' => $result['message_sid'] ?? null
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'SMS reminder sent successfully to ' . $customer->name . ' (' . $this->formatPhoneNumber($phoneNumber) . ')'
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to send SMS: ' . $result['error']
                ], 500);
            }
            
        } catch (Exception $e) {
            \Log::error('Error sending appointment reminder: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send reminder'
            ], 500);
        }
    }
    
    /**
     * Send SMS using Twilio
     */
    public function sendSMS(string $phoneNumber, string $message): array
    {
        try {
            // Initialize Twilio client
            $twilioSid = env('TWILIO_SID');
            $twilioToken = env('TWILIO_AUTH_TOKEN');
            $twilioFromNumber = env('TWILIO_FROM_NUMBER');
            
            if (!$twilioSid || !$twilioToken || !$twilioFromNumber) {
                return ['success' => false, 'error' => 'Twilio credentials not configured'];
            }
            
            $twilio = new Client($twilioSid, $twilioToken);
            
            // Format phone number to E.164 format
            $formattedPhone = $this->formatPhoneNumberForTwilio($phoneNumber);
            
            // Send SMS
            $smsMessage = $twilio->messages->create(
                $formattedPhone, // To
                [
                    'from' => $twilioFromNumber,
                    'body' => $message
                ]
            );
            
            return [
                'success' => true,
                'message_sid' => $smsMessage->sid
            ];
            
        } catch (\Twilio\Exceptions\RestException $e) {
            \Log::error('Twilio REST Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            \Log::error('SMS sending error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Format phone number for Twilio (E.164 format)
     */
    private function formatPhoneNumberForTwilio(string $phoneNumber): string
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If it's a 10-digit US number, add +1
        if (strlen($cleaned) === 10) {
            return '+1' . $cleaned;
        }
        
        // If it's an 11-digit number starting with 1, add +
        if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '1') {
            return '+' . $cleaned;
        }
        
        // If it already starts with +, return as is
        if (substr($phoneNumber, 0, 1) === '+') {
            return $phoneNumber;
        }
        
        // Default: assume US number and add +1
        return '+1' . $cleaned;
    }
    
    /**
     * Format phone number for display
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        if (strlen($cleaned) === 10) {
            return '(' . substr($cleaned, 0, 3) . ') ' . substr($cleaned, 3, 3) . '-' . substr($cleaned, 6);
        }
        
        return $phoneNumber;
    }
    
    /**
     * Create reminder message (optimized for Twilio trial account limits)
     */
    public function createReminderMessage($appointment, $customer, $appointmentDate, $appointmentTime, $isTomorrow = false): string
    {
        // For Twilio trial accounts, keep messages under 160 characters per segment
        
        if ($isTomorrow) {
            $message = "Appointment Tomorrow!\n";
            $message .= "{$customer->name}\n";
            $message .= "{$appointment->barber_name} at {$appointmentTime}\n";
            $message .= "Total: $" . number_format($appointment->total_amount, 2) . "\n";
            $message .= "Taos Empire Barber";
        } else {
            $message = "Appointment Reminder\n";
            $message .= "{$customer->name}\n";
            $message .= "{$appointment->barber_name} - {$appointmentTime}\n";
            $message .= "Total: $" . number_format($appointment->total_amount, 2) . "\n";
            $message .= "Taos Empire Barber";
        }
        
        return $message;
    }
    
    /**
     * Update user's phone number and SMS preferences
     */
    public function updatePhoneNumber(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone_number' => 'required|string|regex:/^[\+]?[1-9][\d]{0,15}$/',
                'sms_notifications_enabled' => 'boolean'
            ]);
            
            $user = $request->user();
            
            $user->update([
                'phone_number' => $request->phone_number,
                'sms_notifications_enabled' => $request->sms_notifications_enabled ?? true
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Phone number updated successfully'
            ]);
            
        } catch (Exception $e) {
            \Log::error('Error updating phone number: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to update phone number'
            ], 500);
        }
    }

    /**
     * Test SMS notification (for development)
     */
    public function testNotification(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone_number' => 'required|string',
                'message' => 'nullable|string'
            ]);
            
            $message = $request->message ?? 'Test SMS from Taos Empire Barber Shop! 🪒✂️';
            
            $result = $this->sendSMS($request->phone_number, $message);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test SMS sent successfully to ' . $this->formatPhoneNumber($request->phone_number),
                    'message_sid' => $result['message_sid']
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to send test SMS: ' . $result['error']
                ], 500);
            }
            
        } catch (Exception $e) {
            \Log::error('Error sending test SMS: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send test SMS'
            ], 500);
        }
    }
}