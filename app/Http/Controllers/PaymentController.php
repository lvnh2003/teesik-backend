<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function process(Request $request)
    {
        $request->validate([
            'order_id' => 'required',
            'payment_method' => 'required|string|in:cod,qr,momo,card,COD,QR,MOMO,CARD'
        ]);

        $orderId = $request->order_id;
        $paymentMethod = $request->payment_method;

        if (!app()->environment('local', 'testing')) {
            return $this->errorResponse('Online payment provider is not configured.', 501);
        }

        // Local/test mock only. In production, integrate with VNPay, Momo, Stripe, etc.
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
