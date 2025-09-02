<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Appointment;
use App\Models\UserPaymentMethod;
use Exception;

class NoShowController extends Controller
{
    public function __construct()
    {
        // Set your Stripe secret key
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    }

    /**
     * Mark appointment as no-show and charge full amount
     */
    public function markNoShowAndCharge(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $request->validate([
                'notes' => 'nullable|string|max:500',
            ]);

            $appointment = Appointment::find($appointmentId);

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if (!$appointment->isEligibleForNoShowCharge()) {
                return response()->json([
                    'error' => 'Appointment is not eligible for no-show charge (must be scheduled and not already marked as no-show)'
                ], 400);
            }

            if ($appointment->is_no_show) {
                return response()->json([
                    'error' => 'Appointment already marked as no-show'
                ], 400);
            }

            // Get user's default payment method
            $defaultPaymentMethod = $appointment->user->defaultPaymentMethod;
            
            if (!$defaultPaymentMethod) {
                // Mark as no-show but don't charge (no payment method available)
                $appointment->markAsNoShow($request->notes . ' (No payment method available for charge)');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment marked as no-show. No charge applied - no payment method available.',
                    'charged' => false
                ]);
            }

            // Calculate charge amount (full service cost)
            $chargeAmount = $appointment->getNoShowChargeAmount();
            
            // Create payment intent for no-show charge (works in both test and live mode)
            try {
                $paymentIntent = PaymentIntent::create([
                    'amount' => intval($chargeAmount * 100), // Convert to cents
                    'currency' => 'usd',
                    'payment_method' => $defaultPaymentMethod->stripe_payment_method_id,
                    'customer' => $this->getOrCreateStripeCustomer($appointment->user),
                    'confirmation_method' => 'manual',
                    'confirm' => true,
                    'off_session' => true,
                    'metadata' => [
                        'appointment_id' => $appointment->id,
                        'charge_type' => 'no_show',
                        'user_id' => $appointment->user_id,
                        'barber_name' => $appointment->barber_name,
                        'customer_name' => $appointment->user->name,
                        'customer_email' => $appointment->user->email,
                    ],
                    'description' => 'No-show charge for appointment #' . $appointment->id . ' - ' . $appointment->barber_name,
                ]);

                if ($paymentIntent->status === 'succeeded') {
                    // Update appointment with no-show charge details
                    $appointment->update([
                        'is_no_show' => true,
                        'appointment_status' => 'no_show',
                        'no_show_charge_amount' => $chargeAmount,
                        'no_show_payment_intent_id' => $paymentIntent->id,
                        'no_show_charged_at' => now(),
                        'no_show_notes' => $request->notes,
                    ]);

                    $isTestMode = str_starts_with(env('STRIPE_SECRET_KEY'), 'sk_test_');
                    $modeText = $isTestMode ? ' (test mode)' : '';

                    return response()->json([
                        'success' => true,
                        'message' => 'No-show charge applied successfully' . $modeText,
                        'charged' => true,
                        'charge_amount' => $chargeAmount,
                        'payment_intent_id' => $paymentIntent->id,
                        'test_mode' => $isTestMode
                    ]);
                } else {
                    // Payment failed, mark as no-show but note the failed charge
                    $appointment->markAsNoShow($request->notes . ' (Charge failed: ' . $paymentIntent->status . ')');
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Appointment marked as no-show. Charge failed.',
                        'charged' => false,
                        'error' => 'Payment failed: ' . $paymentIntent->status
                    ]);
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Handle invalid payment method or other Stripe errors
                $appointment->markAsNoShow($request->notes . ' (Stripe error: ' . $e->getMessage() . ')');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment marked as no-show. Payment processing failed.',
                    'charged' => false,
                    'error' => 'Payment error: ' . $e->getMessage()
                ]);
            } catch (Exception $e) {
                // Handle any other errors
                \Log::error('Error processing no-show charge: ' . $e->getMessage());
                $appointment->markAsNoShow($request->notes . ' (Error: ' . $e->getMessage() . ')');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment marked as no-show. Payment processing failed.',
                    'charged' => false,
                    'error' => 'Processing error: ' . $e->getMessage()
                ]);
            }

        } catch (Exception $e) {
            \Log::error('Error processing no-show charge: ' . $e->getMessage());
            
            // Still mark as no-show even if charging fails
            if (isset($appointment)) {
                $appointment->markAsNoShow($request->notes . ' (Charge error: ' . $e->getMessage() . ')');
            }
            
            return response()->json([
                'error' => 'Failed to process no-show charge: ' . $e->getMessage(),
                'charged' => false
            ], 500);
        }
    }

    /**
     * Get appointments eligible for no-show charges
     */
    public function getEligibleNoShowAppointments(Request $request): JsonResponse
    {
        try {
            $appointments = Appointment::where('appointment_status', 'scheduled')
                ->where('is_no_show', false)
                ->with('user')
                ->get()
                ->filter(function ($appointment) {
                    return $appointment->isEligibleForNoShowCharge();
                })
                ->map(function ($appointment) {
                    return [
                        'id' => $appointment->id,
                        'user_name' => $appointment->user->name,
                        'user_email' => $appointment->user->email,
                        'barber_name' => $appointment->barber_name,
                        'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
                        'appointment_time' => $appointment->appointment_time,
                        'services' => $appointment->services,
                        'total_amount' => $appointment->total_amount,
                        'has_payment_method' => $appointment->user->defaultPaymentMethod !== null,
                        'minutes_past_appointment' => now()->diffInMinutes(
                            \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time)
                        )
                    ];
                })
                ->values();

            return response()->json([
                'appointments' => $appointments
            ]);

        } catch (Exception $e) {
            \Log::error('Error fetching eligible no-show appointments: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch appointments',
                'appointments' => []
            ], 500);
        }
    }

    /**
     * Process all eligible no-show charges (for automated processing)
     */
    public function processAllEligibleNoShows(Request $request): JsonResponse
    {
        try {
            $appointments = Appointment::where('appointment_status', 'scheduled')
                ->where('is_no_show', false)
                ->with('user')
                ->get()
                ->filter(function ($appointment) {
                    return $appointment->isEligibleForNoShowCharge();
                });

            $results = [
                'processed' => 0,
                'charged' => 0,
                'failed' => 0,
                'no_payment_method' => 0,
                'details' => []
            ];

            foreach ($appointments as $appointment) {
                $result = $this->processNoShowCharge($appointment, 'Automated no-show processing');
                $results['details'][] = $result;
                $results['processed']++;
                
                if ($result['charged']) {
                    $results['charged']++;
                } elseif ($result['reason'] === 'no_payment_method') {
                    $results['no_payment_method']++;
                } else {
                    $results['failed']++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Processed {$results['processed']} no-show appointments",
                'results' => $results
            ]);

        } catch (Exception $e) {
            \Log::error('Error processing automated no-show charges: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to process no-show charges'
            ], 500);
        }
    }

    /**
     * Process individual no-show charge
     */
    private function processNoShowCharge(Appointment $appointment, string $notes = null): array
    {
        try {
            $defaultPaymentMethod = $appointment->user->defaultPaymentMethod;
            
            if (!$defaultPaymentMethod) {
                $appointment->markAsNoShow($notes . ' (No payment method available)');
                return [
                    'appointment_id' => $appointment->id,
                    'charged' => false,
                    'reason' => 'no_payment_method',
                    'message' => 'No payment method available'
                ];
            }

            $chargeAmount = $appointment->getNoShowChargeAmount();
            
            // Create payment intent for no-show charge
            try {
                $paymentIntent = PaymentIntent::create([
                    'amount' => intval($chargeAmount * 100),
                    'currency' => 'usd',
                    'payment_method' => $defaultPaymentMethod->stripe_payment_method_id,
                    'customer' => $this->getOrCreateStripeCustomer($appointment->user),
                    'confirmation_method' => 'manual',
                    'confirm' => true,
                    'off_session' => true,
                    'metadata' => [
                        'appointment_id' => $appointment->id,
                        'charge_type' => 'no_show',
                        'user_id' => $appointment->user_id,
                        'barber_name' => $appointment->barber_name,
                        'customer_name' => $appointment->user->name,
                        'customer_email' => $appointment->user->email,
                    ],
                    'description' => 'No-show charge for appointment #' . $appointment->id . ' - ' . $appointment->barber_name,
                ]);

                if ($paymentIntent->status === 'succeeded') {
                    $appointment->update([
                        'is_no_show' => true,
                        'appointment_status' => 'no_show',
                        'no_show_charge_amount' => $chargeAmount,
                        'no_show_payment_intent_id' => $paymentIntent->id,
                        'no_show_charged_at' => now(),
                        'no_show_notes' => $notes,
                    ]);

                    return [
                        'appointment_id' => $appointment->id,
                        'charged' => true,
                        'amount' => $chargeAmount,
                        'payment_intent_id' => $paymentIntent->id
                    ];
                } else {
                    $appointment->markAsNoShow($notes . ' (Charge failed: ' . $paymentIntent->status . ')');
                    return [
                        'appointment_id' => $appointment->id,
                        'charged' => false,
                        'reason' => 'payment_failed',
                        'message' => 'Payment failed: ' . $paymentIntent->status
                    ];
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $appointment->markAsNoShow($notes . ' (Stripe error: ' . $e->getMessage() . ')');
                return [
                    'appointment_id' => $appointment->id,
                    'charged' => false,
                    'reason' => 'error',
                    'message' => $e->getMessage()
                ];
            } catch (Exception $e) {
                $appointment->markAsNoShow($notes . ' (Error: ' . $e->getMessage() . ')');
                return [
                    'appointment_id' => $appointment->id,
                    'charged' => false,
                    'reason' => 'error',
                    'message' => $e->getMessage()
                ];
            }

        } catch (Exception $e) {
            $appointment->markAsNoShow($notes . ' (Error: ' . $e->getMessage() . ')');
            return [
                'appointment_id' => $appointment->id,
                'charged' => false,
                'reason' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Charge an existing no-show appointment
     */
    public function chargeExistingNoShow(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $appointment = Appointment::find($appointmentId);

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if (!$appointment->is_no_show) {
                return response()->json([
                    'error' => 'Appointment is not marked as no-show'
                ], 400);
            }

            if ($appointment->no_show_charge_amount) {
                return response()->json([
                    'error' => 'No-show has already been charged'
                ], 400);
            }

            // Get user's default payment method
            $defaultPaymentMethod = $appointment->user->defaultPaymentMethod;
            
            if (!$defaultPaymentMethod) {
                return response()->json([
                    'error' => 'Customer has no saved payment method to charge'
                ], 400);
            }

            // Calculate charge amount (full service cost)
            $chargeAmount = $appointment->getNoShowChargeAmount();
            
            // Create payment intent for retroactive no-show charge
            try {
                $paymentIntent = PaymentIntent::create([
                    'amount' => intval($chargeAmount * 100), // Convert to cents
                    'currency' => 'usd',
                    'payment_method' => $defaultPaymentMethod->stripe_payment_method_id,
                    'customer' => $this->getOrCreateStripeCustomer($appointment->user),
                    'confirmation_method' => 'manual',
                    'confirm' => true,
                    'off_session' => true,
                    'metadata' => [
                        'appointment_id' => $appointment->id,
                        'charge_type' => 'no_show_retroactive',
                        'user_id' => $appointment->user_id,
                        'barber_name' => $appointment->barber_name,
                        'customer_name' => $appointment->user->name,
                        'customer_email' => $appointment->user->email,
                    ],
                    'description' => 'Retroactive no-show charge for appointment #' . $appointment->id . ' - ' . $appointment->barber_name,
                ]);

                if ($paymentIntent->status === 'succeeded') {
                    // Update appointment with no-show charge details
                    $appointment->update([
                        'no_show_charge_amount' => $chargeAmount,
                        'no_show_payment_intent_id' => $paymentIntent->id,
                        'no_show_charged_at' => now(),
                        'no_show_notes' => ($appointment->no_show_notes ?? '') . ' (Retroactively charged)',
                    ]);

                    $isTestMode = str_starts_with(env('STRIPE_SECRET_KEY'), 'sk_test_');
                    $modeText = $isTestMode ? ' (test mode)' : '';

                    return response()->json([
                        'success' => true,
                        'message' => 'No-show charge applied successfully' . $modeText,
                        'charged' => true,
                        'charge_amount' => $chargeAmount,
                        'payment_intent_id' => $paymentIntent->id,
                        'test_mode' => $isTestMode
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Charge failed',
                        'charged' => false,
                        'error' => 'Payment failed: ' . $paymentIntent->status
                    ]);
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Handle invalid payment method or other Stripe errors
                return response()->json([
                    'success' => false,
                    'message' => 'Payment processing failed',
                    'charged' => false,
                    'error' => 'Payment error: ' . $e->getMessage()
                ]);
            } catch (Exception $e) {
                \Log::error('Error processing retroactive no-show charge: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Payment processing failed',
                    'charged' => false,
                    'error' => 'Processing error: ' . $e->getMessage()
                ]);
            }

        } catch (Exception $e) {
            \Log::error('Error processing retroactive no-show charge: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to process no-show charge: ' . $e->getMessage(),
                'charged' => false
            ], 500);
        }
    }

    /**
     * Get or create Stripe customer for user
     */
    private function getOrCreateStripeCustomer($user): string
    {
        try {
            $customers = \Stripe\Customer::all(['email' => $user->email, 'limit' => 1]);
            
            if ($customers->data) {
                return $customers->data[0]->id;
            }

            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
            ]);

            return $customer->id;
        } catch (Exception $e) {
            \Log::error('Error creating Stripe customer: ' . $e->getMessage());
            throw $e;
        }
    }
}