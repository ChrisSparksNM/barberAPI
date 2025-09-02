<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Appointment;
use App\Http\Controllers\NotificationController;
use Carbon\Carbon;
use Exception;

class SendAppointmentReminders extends Command
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
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:send-reminders {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send SMS reminders to customers with appointments in the next 24 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        $this->info('🔍 Looking for appointments in the next 24 hours...');
        
        // Get appointments that are:
        // 1. Scheduled for tomorrow (within 24 hours)
        // 2. Status is 'scheduled' (not cancelled, completed, or no-show)
        // 3. User has SMS notifications enabled
        // 4. User has a phone number
        // 5. Reminder hasn't been sent yet (we'll track this)
        
        $tomorrow = Carbon::now()->addDay();
        $startOfTomorrow = $tomorrow->copy()->startOfDay();
        $endOfTomorrow = $tomorrow->copy()->endOfDay();
        
        $appointments = Appointment::with('user')
            ->where('appointment_status', 'scheduled')
            ->where('is_no_show', false)
            ->whereBetween('appointment_date', [$startOfTomorrow, $endOfTomorrow])
            ->whereHas('user', function($query) {
                $query->whereNotNull('phone_number')
                      ->where('sms_notifications_enabled', true);
            })
            ->where(function($query) {
                // Only send if reminder hasn't been sent yet, or was sent more than 23 hours ago
                $query->whereNull('reminder_sent_at')
                      ->orWhere('reminder_sent_at', '<', Carbon::now()->subHours(23));
            })
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get();
        
        if ($appointments->isEmpty()) {
            $this->info('✅ No appointments found that need reminders.');
            return 0;
        }
        
        $this->info("📱 Found {$appointments->count()} appointment(s) that need reminders:");
        $this->newLine();
        
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($appointments as $appointment) {
            $customer = $appointment->user;
            $appointmentDate = $appointment->appointment_date->format('l, F j, Y');
            $appointmentTime = $this->formatAppointmentTime($appointment->appointment_time);
            
            $this->line("👤 {$customer->name} ({$customer->phone_number})");
            $this->line("   📅 {$appointmentDate} at {$appointmentTime} with {$appointment->barber_name}");
            
            if ($isDryRun) {
                $this->line("   🔍 [DRY RUN] Would send SMS reminder");
                $successCount++;
            } else {
                // Send the SMS reminder
                $result = $this->sendReminderSMS($appointment);
                
                if ($result['success']) {
                    $this->line("   ✅ SMS reminder sent successfully");
                    
                    // Update the appointment to mark reminder as sent
                    $appointment->update([
                        'reminder_sent_at' => Carbon::now()
                    ]);
                    
                    $successCount++;
                } else {
                    $this->error("   ❌ Failed to send SMS: {$result['error']}");
                    $failureCount++;
                }
            }
            
            $this->newLine();
        }
        
        // Summary
        if ($isDryRun) {
            $this->info("🔍 DRY RUN COMPLETE");
            $this->info("Would send {$successCount} reminder(s)");
        } else {
            $this->info("📊 SUMMARY");
            $this->info("✅ Successfully sent: {$successCount}");
            if ($failureCount > 0) {
                $this->error("❌ Failed to send: {$failureCount}");
            }
        }
        
        return 0;
    }
    
    /**
     * Send SMS reminder for an appointment
     */
    private function sendReminderSMS(Appointment $appointment): array
    {
        try {
            $customer = $appointment->user;
            $appointmentDate = $appointment->appointment_date->format('l, F j, Y');
            $appointmentTime = $this->formatAppointmentTime($appointment->appointment_time);
            
            // Use the NotificationController to create and send SMS
            $notificationController = new NotificationController();
            
            $message = $notificationController->createReminderMessage(
                $appointment, 
                $customer, 
                $appointmentDate, 
                $appointmentTime, 
                true // isTomorrow = true
            );
            
            return $notificationController->sendSMS($customer->phone_number, $message);
            
        } catch (\Exception $e) {
            \Log::error('Error sending automated reminder SMS: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'customer_id' => $appointment->user_id
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}