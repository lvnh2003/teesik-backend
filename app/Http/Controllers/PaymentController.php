<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function process(Request $request)
    {
        $request->validate([
            'order_id' => 'required',
            'payment_method' => 'required|string'
        ]);

        $orderId = $request->order_id;
        $paymentMethod = $request->payment_method;

        // Mock Payment Logic
        // In production, integrate with VNPay, Momo, Stripe, etc.
        // and optionally update the order status on Pancake via PancakeService.

        $success = true; // Simulate success

        if ($success) {
            return $this->successResponse([
                'order_id' => $orderId,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'transaction_id' => 'MOCK-' . strtoupper(uniqid()),
            ], 'Payment successful');
        } else {
            return $this->errorResponse('Payment failed', 400);
        }
    }
}
