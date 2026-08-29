<?php

namespace App\Services\Payment;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class SepayQrPaymentService
{
    public function createPayment(Order $order): array
    {
        $this->ensureConfigured();

        $paymentCode = $order->provider === 'sepay' && $order->provider_order_id
            ? $order->provider_order_id
            : $this->makePaymentCode($order);

        $amount = (int) round($order->grand_total);
        $qrCodeUrl = $this->makeVietQrUrl($amount, $paymentCode);

        $payload = $order->provider_payload ?? [];
        $payload['qr_payment'] = [
            'bank_bin' => $this->bankBin(),
            'account_number' => $this->accountNumber(),
            'account_name' => $this->accountName(),
            'payment_code' => $paymentCode,
            'amount' => $amount,
            'qr_code_url' => $qrCodeUrl,
        ];

        $order->forceFill([
            'provider' => 'sepay',
            'provider_order_id' => $paymentCode,
            'provider_request_id' => $paymentCode,
            'provider_result_code' => null,
            'provider_payload' => $payload,
        ])->save();

        return [
            'order_id' => $order->id,
            'payment_method' => 'qr',
            'qr_code_url' => $qrCodeUrl,
            'payment_code' => $paymentCode,
            'amount' => $amount,
            'status' => 'pending',
        ];
    }

    public function applyWebhook(Request $request): Order
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            throw new RuntimeException('Invalid SePay payload.');
        }

        $this->verifyRequest($request, $rawBody);

        if (Arr::get($payload, 'transferType') !== 'in') {
            throw new RuntimeException('SePay transaction is not incoming.');
        }

        $paymentCode = $this->extractPaymentCode($payload);
        if (!$paymentCode) {
            throw new RuntimeException('SePay payment code was not found.');
        }

        $order = Order::where('provider', 'sepay')
            ->where('provider_order_id', $paymentCode)
            ->firstOrFail();

        $amount = (int) Arr::get($payload, 'transferAmount', 0);
        if ($amount !== (int) round($order->grand_total)) {
            $this->storeWebhookPayload($order, $payload, 'amount_mismatch');
            throw new RuntimeException('SePay amount does not match the order total.');
        }

        if ($order->payment_status === 'paid') {
            $this->storeWebhookPayload($order, $payload, 'duplicate_paid');
            return $order->refresh();
        }

        $providerTransactionId = (string) (Arr::get($payload, 'id') ?: Arr::get($payload, 'referenceCode'));

        $this->storeWebhookPayload($order, $payload, 'paid', [
            'payment_status' => 'paid',
            'transaction_id' => $providerTransactionId,
            'provider_transaction_id' => $providerTransactionId,
            'provider_result_code' => 0,
            'paid_at' => now(),
        ]);

        $this->clearOrderCart($order);

        return $order->refresh();
    }

    public function verifyRequest(Request $request, string $rawBody): void
    {
        $method = config('services.sepay.webhook_auth_method', 'hmac');
        if ($method === 'none' && app()->environment('local', 'testing')) {
            return;
        }

        if ($method !== 'hmac') {
            throw new RuntimeException('Unsupported SePay webhook authentication method.');
        }

        $secret = (string) config('services.sepay.webhook_secret');
        $signature = (string) $request->headers->get('X-SePay-Signature', '');
        $timestamp = (int) $request->headers->get('X-SePay-Timestamp', 0);

        if (!$secret || !$signature || !$timestamp) {
            throw new RuntimeException('Missing SePay webhook authentication.');
        }

        if (abs(time() - $timestamp) > 300) {
            throw new RuntimeException('SePay webhook request expired.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid SePay webhook signature.');
        }
    }

    private function extractPaymentCode(array $payload): ?string
    {
        $code = Arr::get($payload, 'code');
        if (is_string($code) && $code !== '') {
            return strtoupper(trim($code));
        }

        $content = strtoupper((string) Arr::get($payload, 'content', ''));
        $prefix = preg_quote(strtoupper($this->paymentCodePrefix()), '/');
        if (preg_match('/\b(' . $prefix . '[A-Z0-9]+)\b/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function storeWebhookPayload(Order $order, array $payload, string $status, array $updates = []): void
    {
        $providerPayload = $order->provider_payload ?? [];
        $providerPayload['sepay_webhook'] = [
            'status' => $status,
            'payload' => $payload,
            'received_at' => now()->toISOString(),
        ];

        $order->forceFill(array_merge([
            'provider_payload' => $providerPayload,
        ], $updates))->save();
    }

    private function makePaymentCode(Order $order): string
    {
        return strtoupper($this->paymentCodePrefix() . $order->id . Str::upper(Str::random(4)));
    }

    private function makeVietQrUrl(int $amount, string $paymentCode): string
    {
        $query = http_build_query([
            'amount' => $amount,
            'addInfo' => $paymentCode,
            'accountName' => $this->accountName(),
        ]);

        return 'https://img.vietqr.io/image/'
            . rawurlencode($this->bankBin())
            . '-'
            . rawurlencode($this->accountNumber())
            . '-qr_only.png?'
            . $query;
    }

    private function ensureConfigured(): void
    {
        foreach (['bank_bin', 'bank_account_number', 'bank_account_name'] as $key) {
            if (!config('services.sepay.' . $key)) {
                throw new RuntimeException('QR payment is not configured.');
            }
        }
    }

    private function paymentCodePrefix(): string
    {
        return (string) config('services.sepay.payment_code_prefix', 'TEESIK');
    }

    private function bankBin(): string
    {
        return (string) config('services.sepay.bank_bin');
    }

    private function accountNumber(): string
    {
        return (string) config('services.sepay.bank_account_number');
    }

    private function accountName(): string
    {
        return (string) config('services.sepay.bank_account_name');
    }

    private function clearOrderCart(Order $order): void
    {
        if ($order->user_id) {
            Cart::where('user_id', $order->user_id)->delete();
        }

        $cartId = (string) data_get($order->data, 'cart_id', '');
        if ($cartId !== '') {
            Cart::where('cart_id', $cartId)->delete();
        }
    }
}
