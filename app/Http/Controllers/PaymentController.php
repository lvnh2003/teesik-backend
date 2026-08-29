<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payment\MomoPaymentService;
use App\Services\Payment\SepayQrPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private MomoPaymentService $momo,
        private SepayQrPaymentService $sepayQr
    )
    {
    }

    public function process(Request $request)
    {
        $request->validate([
            'order_id' => 'required',
            'payment_method' => 'required|string|in:cod,qr,momo,COD,QR,MOMO',
            'payment_token' => 'required|string|min:24',
        ]);

        $orderId = $request->order_id;
        $paymentMethod = strtolower($request->payment_method);
        $paymentToken = (string) $request->input('payment_token');

        $order = Order::query()->find($orderId);
        if (!$order) {
            return $this->errorResponse('Order not found', 404);
        }

        if (!$this->hasValidPaymentToken($order, $paymentToken)) {
            return $this->errorResponse('Invalid payment token', 403);
        }

        if ($paymentMethod === 'momo') {
            return $this->processMomo($order);
        }

        if ($paymentMethod === 'qr') {
            return $this->processQr($order);
        }

        return $this->errorResponse('Unsupported payment method', 400);
    }

    public function status(Request $request)
    {
        $request->validate([
            'order_id' => 'required',
            'payment_token' => 'required|string|min:24',
        ]);

        $orderId = $request->input('order_id');
        $paymentToken = (string) $request->input('payment_token');

        $order = Order::query()->find($orderId);
        if (!$order) {
            return $this->errorResponse('Order not found', 404);
        }

        if (!$this->hasValidPaymentToken($order, $paymentToken)) {
            return $this->errorResponse('Invalid payment token', 403);
        }

        return $this->successResponse([
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'provider' => $order->provider,
            'provider_order_id' => $order->provider_order_id,
            'provider_transaction_id' => $order->provider_transaction_id,
            'paid_at' => $order->paid_at?->toISOString(),
        ]);
    }

    public function sepayWebhook(Request $request)
    {
        try {
            $this->sepayQr->applyWebhook($request);

            return response()->json(['success' => true]);
        } catch (RuntimeException $e) {
            Log::warning('SePay webhook rejected', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('SePay webhook failed', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'SePay webhook could not be processed.',
            ], 400);
        }
    }

    public function momoIpn(Request $request)
    {
        $payload = $request->all();

        try {
            $order = $this->momo->applyCallback($payload);

            return response()->json($this->momo->ipnResponse($payload, 0, 'Success'));
        } catch (RuntimeException $e) {
            Log::warning('MoMo IPN rejected', ['message' => $e->getMessage(), 'payload' => Arr::except($payload, ['signature'])]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('MoMo IPN failed', ['message' => $e->getMessage(), 'payload' => Arr::except($payload, ['signature'])]);

            return response()->json($this->momo->ipnResponse($payload, 99, 'Failed'), 200);
        }
    }

    public function momoReturn(Request $request)
    {
        $payload = $request->query();

        try {
            $order = $this->momo->applyCallback($payload);

            return $this->successResponse([
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'result_code' => $order->provider_result_code,
            ], $order->payment_status === 'paid' ? 'Payment successful' : 'Payment pending');
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->errorResponse('MoMo return could not be verified.', 400);
        }
    }

    private function processMomo(Order $order)
    {
        if ($order->payment_status === 'paid') {
            return $this->errorResponse('Order is already paid', 409);
        }

        if (strtolower($order->payment_method) !== 'momo') {
            return $this->errorResponse('Order payment method is not MoMo', 422);
        }

        try {
            $payment = $this->momo->createPayment($order);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }

        return $this->successResponse([
            'order_id' => $order->id,
            'payment_method' => 'momo',
            'pay_url' => Arr::get($payment, 'payUrl'),
            'deeplink' => Arr::get($payment, 'deeplink'),
            'qr_code_url' => Arr::get($payment, 'qrCodeUrl'),
            'status' => 'pending',
        ], 'MoMo payment created');
    }

    private function processQr(Order $order)
    {
        if ($order->payment_status === 'paid') {
            return $this->errorResponse('Order is already paid', 409);
        }

        if (strtolower($order->payment_method) !== 'qr') {
            return $this->errorResponse('Order payment method is not QR', 422);
        }

        try {
            $payment = $this->sepayQr->createPayment($order);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        }

        return $this->successResponse($payment, 'QR payment created');
    }

    private function hasValidPaymentToken(Order $order, string $paymentToken): bool
    {
        $storedToken = (string) data_get($order->data, 'payment_access_token', '');
        if ($storedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $paymentToken);
    }
}
