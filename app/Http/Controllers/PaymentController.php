<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\PaymentMethod;
use App\Models\Appointment;
use App\Models\UserPaymentMethod;
use Exception;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set your Stripe secret key
        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            \Log::error('Stripe secret key is not configured');
            throw new \Exception('Stripe configuration is missing');
        }
        Stripe::setApiKey($stripeSecret);
    }

    /**
     * Create a payment intent for appointment booking
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        try {
            // Log the authenticated user for debugging
            \Log::info('Payment intent request from user: ' . $request->user()->id);
            
            $request->validate([
                'amount' => 'required|integer|min:1000', // Amount in cents (minimum $10.00)
                'barber_name' => 'required|string',
                'appointment_date' => 'required|date',
                'appointment_time' => 'required|string',
                'services' => 'required|array|min:1',
                'services.*.id' => 'required|string',
                'services.*.name' => 'required|string',
                'services.*.price' => 'required|numeric|min:0',
                'payment_method_id' => 'nullable|integer', // For saved payment methods
            ]);

            $paymentIntentData = [
                'amount' => $request->amount, // Amount in cents
                'currency' => 'usd',
                'metadata' => [
                    'barber_name' => $request->barber_name,
                    'appointment_date' => $request->appointment_date,
                    'appointment_time' => $request->appointment_time,
                    'services' => json_encode($request->services),
                    'user_id' => $request->user()->id,
                    'user_email' => $request->user()->email,
                ],
                'description' => 'Appointment deposit for ' . $request->barber_name,
            ];

            // If using saved payment method, attach it
            if ($request->payment_method_id) {
                $userPaymentMethod = UserPaymentMethod::where('id', $request->payment_method_id)
                    ->where('user_id', $request->user()->id)
                    ->first();
                
                if ($userPaymentMethod) {
                    // Verify the payment method still exists in Stripe before using it
                    try {
                        $stripePaymentMethod = PaymentMethod::retrieve($userPaymentMethod->stripe_payment_method_id);
                        
                        // Check if payment method is still attached to a customer
                        if (!$stripePaymentMethod->customer) {
                            \Log::warning('Payment method not attached to customer: ' . $userPaymentMethod->stripe_payment_method_id);
                            return response()->json([
                                'error' => 'The saved payment method is no longer valid. Please try a different card.',
                                'status' => 'requires_payment_method'
                            ], 400);
                        }
                        
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        \Log::error('Invalid payment method: ' . $userPaymentMethod->stripe_payment_method_id . ' - ' . $e->getMessage());
                        return response()->json([
                            'error' => 'The saved payment method is no longer valid. Please try a different card.',
                            'status' => 'requires_payment_method'
                        ], 400);
                    }
                    
                    $customerId = $this->getOrCreateStripeCustomer($request->user());
                    
                    // Ensure payment method is attached to customer
                    if ($stripePaymentMethod->customer !== $customerId) {
                        \Log::info('Attaching payment method to customer', [
                            'payment_method' => $userPaymentMethod->stripe_payment_method_id,
                            'customer' => $customerId
                        ]);
                        $stripePaymentMethod->attach(['customer' => $customerId]);
                    }
                    
                    $paymentIntentData['payment_method'] = $userPaymentMethod->stripe_payment_method_id;
                    $paymentIntentData['customer'] = $customerId;
                    $paymentIntentData['confirmation_method'] = 'automatic';
                    $paymentIntentData['confirm'] = true; // Auto-confirm for saved payment methods
                    $paymentIntentData['off_session'] = true; // This is key for saved payment methods
                    
                    \Log::info('Creating payment intent with saved payment method', [
                        'payment_method' => $userPaymentMethod->stripe_payment_method_id,
                        'customer' => $customerId
                    ]);
                } else {
                    \Log::error('Saved payment method not found for user: ' . $request->user()->id . ', payment_method_id: ' . $request->payment_method_id);
                    return response()->json([
                        'error' => 'Saved payment method not found',
                        'message' => 'The selected payment method is no longer available'
                    ], 400);
                }
            } else {
                $paymentIntentData['automatic_payment_methods'] = [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ];
            }

            $paymentIntent = PaymentIntent::create($paymentIntentData);
            
            \Log::info('Payment intent created with status: ' . $paymentIntent->status);

            return response()->json([
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'payment_already_confirmed' => $request->payment_method_id && $paymentIntent->status === 'succeeded',
            ]);

        } catch (Exception $e) {
            \Log::error('Payment intent creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Failed to create payment intent'
            ], 400);
        }
    }

    /**
     * Confirm payment and create appointment
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'payment_intent_id' => 'required|string',
                'barber_name' => 'required|string',
                'appointment_date' => 'required|date',
                'appointment_time' => 'required|string',
                'services' => 'required|array|min:1',
            ]);

            // Retrieve the payment intent to verify it was successful
            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);
            
            \Log::info('Retrieved payment intent with status: ' . $paymentIntent->status . ' for ID: ' . $request->payment_intent_id);

            if ($paymentIntent->status !== 'succeeded') {
                \Log::warning('Payment intent status is not succeeded: ' . $paymentIntent->status . ' for ID: ' . $request->payment_intent_id);
                
                // Provide more specific error messages based on status
                $errorMessage = 'Payment was not successful';
                switch ($paymentIntent->status) {
                    case 'requires_payment_method':
                        $errorMessage = 'Payment method is required';
                        break;
                    case 'requires_confirmation':
                        $errorMessage = 'Payment requires confirmation';
                        break;
                    case 'requires_action':
                        $errorMessage = 'Payment requires additional action';
                        break;
                    case 'processing':
                        $errorMessage = 'Payment is still processing';
                        break;
                    case 'canceled':
                        $errorMessage = 'Payment was canceled';
                        break;
                    case 'requires_capture':
                        $errorMessage = 'Payment requires capture';
                        break;
                }
                
                return response()->json([
                    'error' => $errorMessage,
                    'status' => $paymentIntent->status,
                    'payment_intent_id' => $request->payment_intent_id
                ], 400);
            }

            // Check if appointment already exists for this payment intent
            $existingAppointment = Appointment::where('payment_intent_id', $request->payment_intent_id)->first();
            if ($existingAppointment) {
                \Log::info('Appointment already exists for payment intent: ' . $request->payment_intent_id);
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment already exists',
                    'appointment' => [
                        'id' => $existingAppointment->id,
                        'barber_name' => $existingAppointment->barber_name,
                        'date' => $existingAppointment->appointment_date->format('Y-m-d'),
                        'time' => $existingAppointment->appointment_time,
                        'payment_amount' => $existingAppointment->deposit_amount,
                        'payment_id' => $existingAppointment->payment_intent_id,
                        'status' => $existingAppointment->appointment_status,
                    ]
                ]);
            }

            // Calculate amounts
            $depositAmount = $paymentIntent->amount / 100; // This should be $10.00
            $totalAmount = collect($request->services)->sum('price'); // Calculate total from services
            $remainingAmount = max(0, $totalAmount - $depositAmount);
            
            // Log the data being used to create appointment
            $appointmentData = [
                'user_id' => $request->user()->id,
                'barber_name' => $request->barber_name,
                'appointment_date' => $request->appointment_date,
                'appointment_time' => $request->appointment_time,
                'services' => $request->services,
                'deposit_amount' => $depositAmount,
                'total_amount' => $totalAmount,
                'remaining_amount' => $remainingAmount,
                'payment_intent_id' => $paymentIntent->id,
                'payment_status' => 'completed',
                'full_payment_completed' => false, // Only deposit paid, not full amount
                'appointment_status' => 'scheduled',
            ];
            
            \Log::info('Creating appointment with data: ' . json_encode($appointmentData));

            // Create the appointment record
            $appointment = Appointment::create($appointmentData);
            
            \Log::info('Appointment created successfully with ID: ' . $appointment->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Appointment booked successfully',
                'appointment' => [
                    'id' => $appointment->id,
                    'barber_name' => $appointment->barber_name,
                    'date' => $appointment->appointment_date->format('Y-m-d'),
                    'time' => $appointment->appointment_time,
                    'payment_amount' => $appointment->deposit_amount,
                    'payment_id' => $appointment->payment_intent_id,
                    'status' => $appointment->appointment_status,
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Pay remaining balance for an appointment
     */
    public function payRemainingBalance(Request $request): JsonResponse
    {
        try {
            \Log::info('=== payRemainingBalance METHOD CALLED ===');
            \Log::info('payRemainingBalance called with data:', $request->all());
            
            $request->validate([
                'appointment_id' => 'required|integer',
                'amount' => 'required|integer|min:100', // Amount in cents (minimum $1.00)
                'payment_method_id' => 'nullable|integer', // For saved payment methods
            ]);

            // Get the appointment
            $appointment = Appointment::where('id', $request->appointment_id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if ($appointment->full_payment_completed) {
                return response()->json([
                    'error' => 'This appointment is already fully paid'
                ], 400);
            }

            // DOUBLE CHARGE PROTECTION: Check if there's already a pending payment intent for remaining balance
            if ($appointment->remaining_balance_payment_intent_id) {
                try {
                    $existingPaymentIntent = PaymentIntent::retrieve($appointment->remaining_balance_payment_intent_id);
                    if ($existingPaymentIntent->status === 'succeeded') {
                        \Log::warning('Existing payment intent already succeeded', [
                            'appointment_id' => $appointment->id,
                            'existing_payment_intent_id' => $appointment->remaining_balance_payment_intent_id
                        ]);
                        return response()->json([
                            'error' => 'This appointment has already been paid'
                        ], 400);
                    } elseif (in_array($existingPaymentIntent->status, ['requires_payment_method', 'requires_confirmation', 'processing'])) {
                        \Log::info('Reusing existing payment intent', [
                            'appointment_id' => $appointment->id,
                            'existing_payment_intent_id' => $appointment->remaining_balance_payment_intent_id,
                            'status' => $existingPaymentIntent->status
                        ]);
                        return response()->json([
                            'client_secret' => $existingPaymentIntent->client_secret,
                            'payment_intent_id' => $existingPaymentIntent->id,
                            'status' => $existingPaymentIntent->status,
                        ]);
                    }
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    \Log::info('Previous payment intent no longer exists, creating new one', [
                        'appointment_id' => $appointment->id,
                        'old_payment_intent_id' => $appointment->remaining_balance_payment_intent_id
                    ]);
                    // Payment intent doesn't exist anymore, we can create a new one
                }
            }

            $paymentIntentData = [
                'amount' => $request->amount, // Amount in cents
                'currency' => 'usd',
                'metadata' => [
                    'appointment_id' => $appointment->id,
                    'payment_type' => 'remaining_balance',
                    'user_id' => $request->user()->id,
                    'user_email' => $request->user()->email,
                ],
                'description' => 'Remaining balance for appointment #' . $appointment->id,
            ];

            // If using saved payment method, attach it
            if ($request->payment_method_id) {
                $userPaymentMethod = UserPaymentMethod::where('id', $request->payment_method_id)
                    ->where('user_id', $request->user()->id)
                    ->first();
                
                if ($userPaymentMethod) {
                    // Verify the payment method still exists in Stripe before using it
                    try {
                        $stripePaymentMethod = PaymentMethod::retrieve($userPaymentMethod->stripe_payment_method_id);
                        
                        // Check if payment method is still attached to a customer
                        if (!$stripePaymentMethod->customer) {
                            \Log::warning('Payment method not attached to customer: ' . $userPaymentMethod->stripe_payment_method_id);
                            return response()->json([
                                'error' => 'The saved payment method is no longer valid. Please try a different card.',
                                'status' => 'requires_payment_method'
                            ], 400);
                        }
                        
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        \Log::error('Invalid payment method: ' . $userPaymentMethod->stripe_payment_method_id . ' - ' . $e->getMessage());
                        return response()->json([
                            'error' => 'The saved payment method is no longer valid. Please try a different card.',
                            'status' => 'requires_payment_method'
                        ], 400);
                    }
                    
                    $customerId = $this->getOrCreateStripeCustomer($request->user());
                    
                    // Ensure payment method is attached to customer
                    if ($stripePaymentMethod->customer !== $customerId) {
                        \Log::info('Attaching payment method to customer', [
                            'payment_method' => $userPaymentMethod->stripe_payment_method_id,
                            'customer' => $customerId
                        ]);
                        $stripePaymentMethod->attach(['customer' => $customerId]);
                    }
                    
                    $paymentIntentData['payment_method'] = $userPaymentMethod->stripe_payment_method_id;
                    $paymentIntentData['customer'] = $customerId;
                    $paymentIntentData['confirmation_method'] = 'automatic';
                    $paymentIntentData['confirm'] = true; // Auto-confirm for saved payment methods
                    $paymentIntentData['off_session'] = true; // This is key for saved payment methods
                    
                    // Small delay to ensure payment method attachment is fully processed
                    if ($stripePaymentMethod->customer !== $customerId) {
                        usleep(500000); // 0.5 seconds after attachment
                        \Log::info('Payment method was attached, waited for processing');
                    }
                    
                    \Log::info('Creating remaining balance payment intent with saved payment method', [
                        'payment_method' => $userPaymentMethod->stripe_payment_method_id,
                        'customer' => $customerId,
                        'off_session' => true
                    ]);
                } else {
                    \Log::error('Saved payment method not found for user: ' . $request->user()->id . ', payment_method_id: ' . $request->payment_method_id);
                    return response()->json([
                        'error' => 'Saved payment method not found',
                        'message' => 'The selected payment method is no longer available'
                    ], 400);
                }
            } else {
                $paymentIntentData['automatic_payment_methods'] = [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ];
            }

            // Create payment intent for remaining balance
            \Log::info('Creating payment intent with data', [
                'amount' => $paymentIntentData['amount'],
                'currency' => $paymentIntentData['currency'],
                'payment_method' => $paymentIntentData['payment_method'] ?? 'none',
                'customer' => $paymentIntentData['customer'] ?? 'none',
                'confirmation_method' => $paymentIntentData['confirmation_method'] ?? 'automatic',
                'confirm' => $paymentIntentData['confirm'] ?? false,
                'off_session' => $paymentIntentData['off_session'] ?? false
            ]);
            
            $paymentIntent = PaymentIntent::create($paymentIntentData);
            
            \Log::info('Payment intent created', [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'payment_method' => $paymentIntent->payment_method,
                'customer' => $paymentIntent->customer,
                'last_payment_error' => $paymentIntent->last_payment_error,
                'confirmation_method' => $paymentIntent->confirmation_method,
                'off_session' => $paymentIntentData['off_session'] ?? false
            ]);
            
            // If using saved payment method and auto-confirm failed due to timing, retry once
            if ($request->payment_method_id && $paymentIntent->status === 'requires_payment_method') {
                \Log::info('Auto-confirm failed, retrying payment intent creation after brief delay');
                sleep(1); // Wait 1 second
                
                try {
                    $paymentIntent = PaymentIntent::create($paymentIntentData);
                    \Log::info('Retry payment intent created', [
                        'id' => $paymentIntent->id,
                        'status' => $paymentIntent->status,
                        'payment_method' => $paymentIntent->payment_method
                    ]);
                } catch (Exception $retryError) {
                    \Log::error('Retry payment intent creation failed: ' . $retryError->getMessage());
                    // Continue with original payment intent
                }
            }

            // If using saved payment method and auto-confirm failed, check if we can still use it for manual confirmation
            if ($request->payment_method_id && $paymentIntent->status !== 'succeeded') {
                \Log::warning('Auto-confirm failed for saved payment method. Status: ' . $paymentIntent->status, [
                    'payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                    'last_payment_error' => $paymentIntent->last_payment_error
                ]);
                
                // If status is requires_payment_method, the payment method is invalid
                if ($paymentIntent->status === 'requires_payment_method') {
                    return response()->json([
                        'error' => 'The saved payment method is no longer valid. Please try a different card.',
                        'status' => $paymentIntent->status
                    ], 400);
                }
                
                // For other statuses (like requires_confirmation), allow manual confirmation
                \Log::info('Auto-confirm failed but payment intent can be manually confirmed', [
                    'payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status
                ]);
            }

            return response()->json([
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'payment_already_confirmed' => $request->payment_method_id && $paymentIntent->status === 'succeeded',
            ]);

        } catch (Exception $e) {
            \Log::error('Remaining balance payment intent creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Failed to create payment intent'
            ], 400);
        }
    }

    /**
     * Confirm remaining balance payment
     */
    public function confirmRemainingBalance(Request $request): JsonResponse
    {
        try {
            // Add immediate logging to see if endpoint is reached
            \Log::info('confirmRemainingBalance endpoint reached', [
                'request_data' => $request->all(),
                'user_id' => $request->user() ? $request->user()->id : 'no user',
                'stripe_key_set' => !empty(env('STRIPE_SECRET_KEY'))
            ]);
            $validated = $request->validate([
                'payment_intent_id' => 'required|string',
                'appointment_id' => 'required|numeric',
            ]);
            
            // Ensure appointment_id is an integer
            $appointmentId = intval($validated['appointment_id']);

            \Log::info('Confirming remaining balance payment', [
                'payment_intent_id' => $request->payment_intent_id,
                'appointment_id' => $request->appointment_id,
                'user_id' => $request->user()->id
            ]);

            // Retrieve the payment intent to verify it was successful
            try {
                $paymentIntent = PaymentIntent::retrieve($validated['payment_intent_id']);
                \Log::info('Retrieved payment intent in confirmRemainingBalance', [
                    'payment_intent_id' => $validated['payment_intent_id'],
                    'status' => $paymentIntent->status,
                    'amount' => $paymentIntent->amount,
                    'payment_method' => $paymentIntent->payment_method,
                    'customer' => $paymentIntent->customer,
                    'last_payment_error' => $paymentIntent->last_payment_error,
                    'confirmation_method' => $paymentIntent->confirmation_method,
                    'created' => $paymentIntent->created,
                    'metadata' => $paymentIntent->metadata
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                \Log::error('Invalid payment intent ID: ' . $e->getMessage());
                return response()->json([
                    'error' => 'Invalid payment intent',
                    'message' => $e->getMessage()
                ], 400);
            }

            // Handle case where payment intent might still be processing
            if ($paymentIntent->status !== 'succeeded') {
                \Log::warning('Payment intent status is not succeeded: ' . $paymentIntent->status);
                
                // If status is 'processing', wait a moment and retry once
                if ($paymentIntent->status === 'processing') {
                    \Log::info('Payment intent is processing, waiting and retrying...');
                    sleep(2); // Wait 2 seconds
                    
                    try {
                        $paymentIntent = PaymentIntent::retrieve($validated['payment_intent_id']);
                        \Log::info('Retried payment intent retrieval', [
                            'payment_intent_id' => $validated['payment_intent_id'],
                            'new_status' => $paymentIntent->status
                        ]);
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        \Log::error('Failed to retry payment intent retrieval: ' . $e->getMessage());
                    }
                }
                
                // Check status again after potential retry
                if ($paymentIntent->status !== 'succeeded') {
                    $errorMessage = 'Payment was not successful';
                    switch ($paymentIntent->status) {
                        case 'requires_payment_method':
                            $errorMessage = 'The saved payment method is no longer valid. Please try a different card.';
                            break;
                        case 'requires_confirmation':
                            $errorMessage = 'Payment requires confirmation';
                            break;
                        case 'requires_action':
                            $errorMessage = 'Payment requires additional action';
                            break;
                        case 'processing':
                            $errorMessage = 'Payment is still processing. Please wait a moment and try again.';
                            break;
                        case 'canceled':
                            $errorMessage = 'Payment was canceled';
                            break;
                    }
                    
                    return response()->json([
                        'error' => $errorMessage,
                        'status' => $paymentIntent->status
                    ], 400);
                }
            }

            // Get the appointment
            $appointment = Appointment::where('id', $appointmentId)
                ->where('user_id', $request->user()->id)
                ->first();

            \Log::info('Looking for appointment', [
                'appointment_id' => $appointmentId,
                'user_id' => $request->user()->id,
                'found' => $appointment ? 'yes' : 'no'
            ]);

            if (!$appointment) {
                \Log::error('Appointment not found for remaining balance payment', [
                    'appointment_id' => $appointmentId,
                    'user_id' => $request->user()->id
                ]);
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            // DOUBLE CHARGE PROTECTION: Check if this payment intent was already processed
            if ($appointment->remaining_balance_payment_intent_id === $validated['payment_intent_id']) {
                \Log::info('Payment intent already processed for appointment', [
                    'appointment_id' => $appointment->id,
                    'payment_intent_id' => $validated['payment_intent_id']
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already processed',
                    'appointment' => [
                        'id' => $appointment->id,
                        'remaining_amount' => $appointment->remaining_amount,
                        'full_payment_completed' => $appointment->full_payment_completed,
                        'appointment_status' => $appointment->appointment_status,
                    ]
                ]);
            }

            // DOUBLE CHARGE PROTECTION: Check if appointment is already fully paid
            if ($appointment->full_payment_completed) {
                \Log::warning('Attempt to pay remaining balance on already fully paid appointment', [
                    'appointment_id' => $appointment->id,
                    'payment_intent_id' => $validated['payment_intent_id']
                ]);
                return response()->json([
                    'error' => 'This appointment is already fully paid'
                ], 400);
            }

            // Update appointment with remaining balance payment
            $paidAmount = $paymentIntent->amount / 100; // Convert back to dollars
            $currentRemainingAmount = floatval($appointment->remaining_amount);
            $newRemainingAmount = max(0, $currentRemainingAmount - $paidAmount);
            $isFullyPaid = $newRemainingAmount <= 0;
            
            \Log::info('Updating appointment remaining balance', [
                'appointment_id' => $appointment->id,
                'current_remaining' => $currentRemainingAmount,
                'paid_amount' => $paidAmount,
                'new_remaining' => $newRemainingAmount,
                'is_fully_paid' => $isFullyPaid
            ]);
            
            try {
                $updateData = [
                    'remaining_amount' => $newRemainingAmount,
                    'full_payment_completed' => $isFullyPaid,
                    'remaining_balance_payment_intent_id' => $validated['payment_intent_id'],
                ];
                
                // Mark appointment as completed when fully paid
                if ($isFullyPaid && $appointment->appointment_status === 'scheduled') {
                    $updateData['appointment_status'] = 'completed';
                    \Log::info('Marking appointment as completed due to full payment', [
                        'appointment_id' => $appointment->id
                    ]);
                }
                
                $appointment->update($updateData);
                \Log::info('Appointment updated successfully', [
                    'appointment_id' => $appointment->id,
                    'new_status' => $appointment->appointment_status
                ]);
            } catch (Exception $updateException) {
                \Log::error('Failed to update appointment', [
                    'appointment_id' => $appointment->id,
                    'error' => $updateException->getMessage(),
                    'update_data' => $updateData ?? []
                ]);
                throw $updateException;
            }

            return response()->json([
                'success' => true,
                'message' => $isFullyPaid ? 'Payment completed! Your appointment is now fully paid.' : 'Remaining balance paid successfully',
                'appointment' => [
                    'id' => $appointment->id,
                    'remaining_amount' => $appointment->remaining_amount,
                    'full_payment_completed' => $appointment->full_payment_completed,
                    'appointment_status' => $appointment->appointment_status,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in confirmRemainingBalance: ' . $e->getMessage(), [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe API error in confirmRemainingBalance: ' . $e->getMessage(), [
                'stripe_error' => $e->getError(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'Payment processing error',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            \Log::error('Unexpected error in confirmRemainingBalance: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Create setup intent for saving payment method
     */
    public function createSetupIntent(Request $request): JsonResponse
    {
        try {
            $setupIntent = SetupIntent::create([
                'customer' => $this->getOrCreateStripeCustomer($request->user()),
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
            ]);

            return response()->json([
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ]);

        } catch (Exception $e) {
            \Log::error('Setup intent creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Failed to create setup intent'
            ], 400);
        }
    }

    /**
     * Save payment method after setup intent confirmation
     */
    public function savePaymentMethod(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'setup_intent_id' => 'required|string',
            ]);

            // Retrieve the setup intent
            $setupIntent = SetupIntent::retrieve($request->setup_intent_id);

            if ($setupIntent->status !== 'succeeded') {
                return response()->json([
                    'error' => 'Setup intent was not successful'
                ], 400);
            }

            // Get the payment method
            $paymentMethod = PaymentMethod::retrieve($setupIntent->payment_method);

            // Save to our database
            $userPaymentMethod = UserPaymentMethod::create([
                'user_id' => $request->user()->id,
                'stripe_payment_method_id' => $paymentMethod->id,
                'card_brand' => $paymentMethod->card->brand,
                'card_last_four' => $paymentMethod->card->last4,
                'card_exp_month' => $paymentMethod->card->exp_month,
                'card_exp_year' => $paymentMethod->card->exp_year,
                'is_default' => $request->user()->paymentMethods()->count() === 0, // First card is default
            ]);

            return response()->json([
                'success' => true,
                'payment_method' => [
                    'id' => $userPaymentMethod->id,
                    'card_display' => $userPaymentMethod->card_display,
                    'is_default' => $userPaymentMethod->is_default,
                ]
            ]);

        } catch (Exception $e) {
            \Log::error('Error saving payment method: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get user's saved payment methods
     */
    public function getPaymentMethods(Request $request): JsonResponse
    {
        try {
            $paymentMethods = $request->user()->paymentMethods()
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(function ($pm) {
                    // Verify each payment method still exists in Stripe
                    try {
                        $stripePaymentMethod = PaymentMethod::retrieve($pm->stripe_payment_method_id);
                        return $stripePaymentMethod->customer !== null;
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        // Payment method no longer exists, remove it from our database
                        \Log::info('Removing invalid payment method: ' . $pm->stripe_payment_method_id);
                        $pm->delete();
                        return false;
                    }
                })
                ->map(function ($pm) {
                    return [
                        'id' => $pm->id,
                        'card_display' => $pm->card_display,
                        'card_brand' => $pm->card_brand,
                        'card_last_four' => $pm->card_last_four,
                        'card_exp_month' => $pm->card_exp_month,
                        'card_exp_year' => $pm->card_exp_year,
                        'is_default' => $pm->is_default,
                    ];
                });

            return response()->json([
                'payment_methods' => $paymentMethods->values()
            ]);

        } catch (Exception $e) {
            \Log::error('Error fetching payment methods: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch payment methods',
                'payment_methods' => []
            ], 500);
        }
    }

    /**
     * Delete a saved payment method
     */
    public function deletePaymentMethod(Request $request, int $paymentMethodId): JsonResponse
    {
        try {
            $userPaymentMethod = UserPaymentMethod::where('id', $paymentMethodId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$userPaymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found'
                ], 404);
            }

            // Detach from Stripe
            $paymentMethod = PaymentMethod::retrieve($userPaymentMethod->stripe_payment_method_id);
            $paymentMethod->detach();

            // Delete from our database
            $userPaymentMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully'
            ]);

        } catch (Exception $e) {
            \Log::error('Error deleting payment method: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to delete payment method'
            ], 500);
        }
    }

    /**
     * Set default payment method
     */
    public function setDefaultPaymentMethod(Request $request, int $paymentMethodId): JsonResponse
    {
        try {
            $userPaymentMethod = UserPaymentMethod::where('id', $paymentMethodId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$userPaymentMethod) {
                return response()->json([
                    'error' => 'Payment method not found'
                ], 404);
            }

            $userPaymentMethod->setAsDefault();

            return response()->json([
                'success' => true,
                'message' => 'Default payment method updated'
            ]);

        } catch (Exception $e) {
            \Log::error('Error setting default payment method: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to set default payment method'
            ], 500);
        }
    }

    /**
     * Confirm setup intent and save payment method
     */
    public function confirmSetupIntent(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'setup_intent_id' => 'required|string',
            ]);

            \Log::info('Confirming setup intent: ' . $request->setup_intent_id);

            // Retrieve the setup intent
            $setupIntent = SetupIntent::retrieve($request->setup_intent_id);
            \Log::info('Setup intent status: ' . $setupIntent->status);

            if (strtolower($setupIntent->status) !== 'succeeded') {
                return response()->json([
                    'error' => 'Setup intent was not successful. Status: ' . $setupIntent->status
                ], 400);
            }

            if (!$setupIntent->payment_method) {
                return response()->json([
                    'error' => 'No payment method found on setup intent'
                ], 400);
            }

            // Get the payment method (it's already attached to the customer via setup intent)
            $paymentMethod = PaymentMethod::retrieve($setupIntent->payment_method);
            \Log::info('Payment method type: ' . $paymentMethod->type);

            if ($paymentMethod->type !== 'card') {
                return response()->json([
                    'error' => 'Only card payment methods are supported'
                ], 400);
            }

            if (!$paymentMethod->card) {
                return response()->json([
                    'error' => 'Payment method does not have card information'
                ], 400);
            }

            // Check if this payment method is already saved in our database
            $existingPaymentMethod = UserPaymentMethod::where('user_id', $request->user()->id)
                ->where('stripe_payment_method_id', $paymentMethod->id)
                ->first();

            if ($existingPaymentMethod) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment method already saved',
                    'payment_method' => [
                        'id' => $existingPaymentMethod->id,
                        'card_display' => $existingPaymentMethod->card_display,
                        'card_brand' => $existingPaymentMethod->card_brand,
                        'card_last_four' => $existingPaymentMethod->card_last_four,
                        'card_exp_month' => $existingPaymentMethod->card_exp_month,
                        'card_exp_year' => $existingPaymentMethod->card_exp_year,
                        'is_default' => $existingPaymentMethod->is_default,
                    ]
                ]);
            }

            // Save to our database
            \Log::info('Saving payment method to database from setup intent');
            $userPaymentMethod = UserPaymentMethod::create([
                'user_id' => $request->user()->id,
                'stripe_payment_method_id' => $paymentMethod->id,
                'card_brand' => $paymentMethod->card->brand,
                'card_last_four' => $paymentMethod->card->last4,
                'card_exp_month' => $paymentMethod->card->exp_month,
                'card_exp_year' => $paymentMethod->card->exp_year,
                'is_default' => $request->user()->paymentMethods()->count() === 0, // First card is default
            ]);

            \Log::info('Payment method saved to database with ID: ' . $userPaymentMethod->id);

            return response()->json([
                'success' => true,
                'message' => 'Payment method saved successfully',
                'payment_method' => [
                    'id' => $userPaymentMethod->id,
                    'card_display' => $userPaymentMethod->card_display,
                    'card_brand' => $userPaymentMethod->card_brand,
                    'card_last_four' => $userPaymentMethod->card_last_four,
                    'card_exp_month' => $userPaymentMethod->card_exp_month,
                    'card_exp_year' => $userPaymentMethod->card_exp_year,
                    'is_default' => $userPaymentMethod->is_default,
                ]
            ]);

        } catch (Exception $e) {
            \Log::error('Error confirming setup intent: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Save payment method from a successful payment intent (legacy method - kept for backward compatibility)
     */
    public function savePaymentMethodFromIntent(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'payment_intent_id' => 'required|string',
            ]);

            \Log::info('Saving payment method from intent: ' . $request->payment_intent_id);

            // Retrieve the payment intent
            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);
            \Log::info('Payment intent status: ' . $paymentIntent->status);
            \Log::info('Payment method ID: ' . ($paymentIntent->payment_method ?? 'null'));

            if (strtolower($paymentIntent->status) !== 'succeeded') {
                return response()->json([
                    'error' => 'Payment intent was not successful. Status: ' . $paymentIntent->status
                ], 400);
            }

            if (!$paymentIntent->payment_method) {
                return response()->json([
                    'error' => 'No payment method found on payment intent'
                ], 400);
            }

            // Get the payment method
            $paymentMethod = PaymentMethod::retrieve($paymentIntent->payment_method);
            \Log::info('Payment method type: ' . $paymentMethod->type);
            
            if ($paymentMethod->type !== 'card') {
                return response()->json([
                    'error' => 'Only card payment methods can be saved'
                ], 400);
            }
            
            if (!$paymentMethod->card) {
                return response()->json([
                    'error' => 'Payment method does not have card information'
                ], 400);
            }

            // Check if this payment method is already saved
            $existingPaymentMethod = UserPaymentMethod::where('user_id', $request->user()->id)
                ->where('stripe_payment_method_id', $paymentMethod->id)
                ->first();

            if ($existingPaymentMethod) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment method already saved',
                    'payment_method' => [
                        'id' => $existingPaymentMethod->id,
                        'card_display' => $existingPaymentMethod->card_display,
                        'is_default' => $existingPaymentMethod->is_default,
                    ]
                ]);
            }

            // Attach payment method to customer
            $customerId = $this->getOrCreateStripeCustomer($request->user());
            \Log::info('Attaching payment method to customer: ' . $customerId);
            
            try {
                $paymentMethod->attach(['customer' => $customerId]);
                \Log::info('Payment method attached successfully');
            } catch (Exception $attachError) {
                \Log::error('Error attaching payment method: ' . $attachError->getMessage());
                
                // Check if payment method is already attached
                if (strpos($attachError->getMessage(), 'already been attached') !== false) {
                    \Log::info('Payment method already attached, continuing...');
                } 
                // Check if payment method was previously used without being attached (common with one-time payments)
                else if (strpos($attachError->getMessage(), 'previously used without being attached') !== false) {
                    \Log::info('Payment method was used for one-time payment and cannot be saved. This is normal for card payments.');
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment method cannot be saved after one-time use. This is a Stripe security limitation.',
                        'info' => 'Your payment was successful, but the card cannot be saved for future use.'
                    ], 200); // Return 200 since this is expected behavior, not an error
                } 
                else {
                    throw $attachError;
                }
            }

            // Save to our database
            \Log::info('Saving payment method to database');
            try {
                $userPaymentMethod = UserPaymentMethod::create([
                    'user_id' => $request->user()->id,
                    'stripe_payment_method_id' => $paymentMethod->id,
                    'card_brand' => $paymentMethod->card->brand,
                    'card_last_four' => $paymentMethod->card->last4,
                    'card_exp_month' => $paymentMethod->card->exp_month,
                    'card_exp_year' => $paymentMethod->card->exp_year,
                    'is_default' => $request->user()->paymentMethods()->count() === 0, // First card is default
                ]);
                \Log::info('Payment method saved to database with ID: ' . $userPaymentMethod->id);
            } catch (Exception $dbError) {
                \Log::error('Database error saving payment method: ' . $dbError->getMessage());
                throw $dbError;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method saved successfully',
                'payment_method' => [
                    'id' => $userPaymentMethod->id,
                    'card_display' => $userPaymentMethod->card_display,
                    'is_default' => $userPaymentMethod->is_default,
                ]
            ]);

        } catch (Exception $e) {
            \Log::error('Error saving payment method from intent: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Charge remaining balance for completed appointment
     */
    public function chargeRemainingBalance(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'appointment_id' => 'required|integer',
            ]);

            $appointment = Appointment::find($request->appointment_id);

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if (!$appointment->hasRemainingBalance()) {
                return response()->json([
                    'error' => 'No remaining balance to charge'
                ], 400);
            }

            // Get user's default payment method
            $defaultPaymentMethod = $appointment->user->defaultPaymentMethod;
            
            if (!$defaultPaymentMethod) {
                return response()->json([
                    'error' => 'Customer has no saved payment method to charge'
                ], 400);
            }

            // Calculate remaining balance amount
            $remainingAmount = $appointment->getRemainingBalanceAmount();
            
            // Create payment intent for remaining balance charge
            $paymentIntent = PaymentIntent::create([
                'amount' => intval($remainingAmount * 100), // Convert to cents
                'currency' => 'usd',
                'payment_method' => $defaultPaymentMethod->stripe_payment_method_id,
                'customer' => $this->getOrCreateStripeCustomer($appointment->user),
                'confirmation_method' => 'manual',
                'confirm' => true,
                'off_session' => true,
                'metadata' => [
                    'appointment_id' => $appointment->id,
                    'charge_type' => 'remaining_balance',
                    'user_id' => $appointment->user_id,
                    'barber_name' => $appointment->barber_name,
                    'customer_name' => $appointment->user->name,
                    'customer_email' => $appointment->user->email,
                ],
                'description' => 'Remaining balance for appointment #' . $appointment->id . ' - ' . $appointment->barber_name,
            ]);

            if ($paymentIntent->status === 'succeeded') {
                // Update appointment with remaining balance payment details
                $appointment->update([
                    'remaining_amount' => 0,
                    'full_payment_completed' => true,
                    'payment_status' => 'completed',
                ]);

                $isTestMode = str_starts_with(env('STRIPE_SECRET_KEY'), 'sk_test_');
                $modeText = $isTestMode ? ' (test mode)' : '';

                return response()->json([
                    'success' => true,
                    'message' => 'Remaining balance charged successfully' . $modeText,
                    'charged' => true,
                    'charge_amount' => $remainingAmount,
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
            \Log::error('Error processing remaining balance charge: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'charged' => false,
                'error' => 'Processing error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Pay tip for an appointment
     */
    public function payTip(Request $request): JsonResponse
    {
        try {
            \Log::info('payTip called with data:', $request->all());
            
            $request->validate([
                'appointment_id' => 'required|integer',
                'tip_amount' => 'required|numeric|min:1|max:1000', // $1 to $1000 tip
                'payment_method_id' => 'nullable|integer', // For saved payment methods
            ]);

            // Get the appointment
            $appointment = Appointment::where('id', $request->appointment_id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            if (!$appointment->canReceiveTip()) {
                return response()->json([
                    'error' => 'This appointment cannot receive a tip. Only completed appointments are eligible.'
                ], 400);
            }

            $tipAmountCents = intval($request->tip_amount * 100); // Convert to cents

            $paymentIntentData = [
                'amount' => $tipAmountCents,
                'currency' => 'usd',
                'metadata' => [
                    'appointment_id' => $appointment->id,
                    'payment_type' => 'tip',
                    'barber_name' => $appointment->barber_name,
                    'user_id' => $request->user()->id,
                    'user_email' => $request->user()->email,
                ],
                'description' => 'Tip for ' . $appointment->barber_name . ' - Appointment #' . $appointment->id,
            ];

            // If using saved payment method, attach it
            if ($request->payment_method_id) {
                $userPaymentMethod = UserPaymentMethod::where('id', $request->payment_method_id)
                    ->where('user_id', $request->user()->id)
                    ->first();
                
                if ($userPaymentMethod) {
                    $paymentIntentData['payment_method'] = $userPaymentMethod->stripe_payment_method_id;
                    $paymentIntentData['customer'] = $this->getOrCreateStripeCustomer($request->user());
                    $paymentIntentData['confirmation_method'] = 'manual';
                    $paymentIntentData['confirm'] = true;
                    $paymentIntentData['return_url'] = 'https://taos-empire.app/payment-return';
                    
                    \Log::info('Creating tip payment intent with saved payment method: ' . $userPaymentMethod->stripe_payment_method_id);
                } else {
                    \Log::error('Saved payment method not found for user: ' . $request->user()->id . ', payment_method_id: ' . $request->payment_method_id);
                    return response()->json([
                        'error' => 'Saved payment method not found',
                        'message' => 'The selected payment method is no longer available'
                    ], 400);
                }
            } else {
                $paymentIntentData['automatic_payment_methods'] = [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ];
            }

            // Create payment intent for tip
            $paymentIntent = PaymentIntent::create($paymentIntentData);
            
            \Log::info('Tip payment intent created with status: ' . $paymentIntent->status);

            return response()->json([
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
            ]);

        } catch (Exception $e) {
            \Log::error('Tip payment intent creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Failed to create tip payment intent'
            ], 400);
        }
    }

    /**
     * Confirm tip payment
     */
    public function confirmTipPayment(Request $request): JsonResponse
    {
        try {
            \Log::info('confirmTipPayment called with data:', $request->all());
            
            $validated = $request->validate([
                'payment_intent_id' => 'required|string',
                'appointment_id' => 'required|numeric',
                'tip_amount' => 'required|numeric|min:1',
            ]);
            
            $appointmentId = intval($validated['appointment_id']);

            // Retrieve the payment intent to verify it was successful
            try {
                $paymentIntent = PaymentIntent::retrieve($validated['payment_intent_id']);
                \Log::info('Retrieved tip payment intent', [
                    'payment_intent_id' => $validated['payment_intent_id'],
                    'status' => $paymentIntent->status,
                    'amount' => $paymentIntent->amount
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                \Log::error('Invalid tip payment intent ID: ' . $e->getMessage());
                return response()->json([
                    'error' => 'Invalid payment intent',
                    'message' => $e->getMessage()
                ], 400);
            }

            if ($paymentIntent->status !== 'succeeded') {
                \Log::warning('Tip payment intent status is not succeeded: ' . $paymentIntent->status);
                
                $errorMessage = 'Tip payment was not successful';
                switch ($paymentIntent->status) {
                    case 'requires_payment_method':
                        $errorMessage = 'The saved payment method is no longer valid. Please try a different card.';
                        break;
                    case 'requires_confirmation':
                        $errorMessage = 'Payment requires confirmation';
                        break;
                    case 'requires_action':
                        $errorMessage = 'Payment requires additional action';
                        break;
                    case 'processing':
                        $errorMessage = 'Payment is still processing. Please wait a moment and try again.';
                        break;
                    case 'canceled':
                        $errorMessage = 'Payment was canceled';
                        break;
                }
                
                return response()->json([
                    'error' => $errorMessage,
                    'status' => $paymentIntent->status
                ], 400);
            }

            // Get the appointment
            $appointment = Appointment::where('id', $appointmentId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$appointment) {
                \Log::error('Appointment not found for tip payment', [
                    'appointment_id' => $appointmentId,
                    'user_id' => $request->user()->id
                ]);
                return response()->json([
                    'error' => 'Appointment not found'
                ], 404);
            }

            // Update appointment with tip information
            $tipAmount = $paymentIntent->amount / 100; // Convert back to dollars
            
            \Log::info('Updating appointment with tip', [
                'appointment_id' => $appointment->id,
                'tip_amount' => $tipAmount,
                'payment_intent_id' => $paymentIntent->id
            ]);
            
            try {
                $appointment->update([
                    'tip_amount' => $tipAmount,
                    'tip_payment_intent_id' => $paymentIntent->id,
                    'tip_paid_at' => now(),
                ]);
                \Log::info('Appointment updated with tip successfully', ['appointment_id' => $appointment->id]);
            } catch (Exception $updateException) {
                \Log::error('Failed to update appointment with tip', [
                    'appointment_id' => $appointment->id,
                    'error' => $updateException->getMessage()
                ]);
                throw $updateException;
            }

            return response()->json([
                'success' => true,
                'message' => 'Tip payment successful',
                'appointment' => [
                    'id' => $appointment->id,
                    'tip_amount' => $appointment->tip_amount,
                    'total_with_tip' => $appointment->getTotalWithTip(),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in confirmTipPayment: ' . $e->getMessage(), [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe API error in confirmTipPayment: ' . $e->getMessage(), [
                'stripe_error' => $e->getError(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'Payment processing error',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            \Log::error('Unexpected error in confirmTipPayment: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Get or create Stripe customer for user
     */
    private function getOrCreateStripeCustomer($user): string
    {
        // In a real app, you'd store the customer ID in the users table
        // For now, we'll create a new customer each time or use email to find existing
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