<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Appointment;
use App\Http\Controllers\NoShowController;
use Illuminate\Http\Request;

class ProcessNoShows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:process-no-shows {--dry-run : Show what would be processed without actually charging}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process no-show appointments and charge full amount to saved payment methods';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Processing no-show appointments...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No charges will be processed');
        }

        // Get eligible appointments
        $appointments = Appointment::where('appointment_status', 'scheduled')
            ->where('is_no_show', false)
            ->with('user')
            ->get()
            ->filter(function ($appointment) {
                return $appointment->isEligibleForNoShowCharge();
            });

        if ($appointments->isEmpty()) {
            $this->info('No appointments eligible for no-show processing.');
            return 0;
        }

        $this->info("Found {$appointments->count()} appointments eligible for no-show processing:");

        $table = [];
        foreach ($appointments as $appointment) {
            $minutesPast = now()->diffInMinutes(
                \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time)
            );
            
            $hasPaymentMethod = $appointment->user->defaultPaymentMethod !== null;
            
            $table[] = [
                'ID' => $appointment->id,
                'User' => $appointment->user->name,
                'Barber' => $appointment->barber_name,
                'Date' => $appointment->appointment_date->format('Y-m-d'),
                'Time' => $appointment->appointment_time,
                'Amount' => '$' . number_format($appointment->total_amount, 2),
                'Minutes Past' => $minutesPast,
                'Has Payment Method' => $hasPaymentMethod ? 'Yes' : 'No'
            ];
        }

        $this->table([
            'ID', 'User', 'Barber', 'Date', 'Time', 'Amount', 'Minutes Past', 'Has Payment Method'
        ], $table);

        if ($dryRun) {
            $this->info('DRY RUN COMPLETE - No charges were processed');
            return 0;
        }

        if (!$this->confirm('Do you want to process these no-show charges?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Process the charges
        $controller = new NoShowController();
        $request = new Request();
        
        $response = $controller->processAllEligibleNoShows($request);
        $data = $response->getData(true);

        if ($data['success']) {
            $results = $data['results'];
            
            $this->info("Processing complete:");
            $this->line("  Processed: {$results['processed']}");
            $this->line("  Successfully charged: {$results['charged']}");
            $this->line("  No payment method: {$results['no_payment_method']}");
            $this->line("  Failed: {$results['failed']}");

            if (!empty($results['details'])) {
                $this->info("\nDetailed results:");
                foreach ($results['details'] as $detail) {
                    $status = $detail['charged'] ? 'CHARGED' : 'FAILED';
                    $amount = isset($detail['amount']) ? '$' . number_format($detail['amount'], 2) : 'N/A';
                    $reason = $detail['reason'] ?? '';
                    
                    $this->line("  Appointment #{$detail['appointment_id']}: {$status} {$amount} {$reason}");
                }
            }
        } else {
            $this->error('Failed to process no-show charges: ' . $data['error']);
            return 1;
        }

        return 0;
    }
}