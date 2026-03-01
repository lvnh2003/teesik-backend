<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;

class PaymentController extends Controller
{
    public function process(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string'
        ]);

        $order = Order::find($request->order_id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid'], 400);
        }

        // Mock Payment Logic
        // In a real app, this would redirect to stripe/paypal or handle server-side processing

        $success = true; // Simulate success

        if ($success) {
            $order->update([
                'payment_status' => 'paid',
                'payment_method' => $request->payment_method,
                'transaction_id' => 'MOCK-' . strtoupper(uniqid()),
                'status' => 'processing' // Move to processing after payment
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment successful',
                'data' => $order
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Payment failed'
            ], 400);
        }
    }
}
