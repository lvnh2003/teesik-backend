<?php

namespace App\Services\Payment;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MomoPaymentService
{
    public function createPayment(Order $order): array
    {
        $this->ensureConfigured();

        $requestId = 'TS-' . $order->id . '-' . Str::uuid();
        $providerOrderId = 'TS' . $order->id . '-' . now()->format('YmdHis');
        $amount = (string) (int) round($order->grand_total);
        $extraData = base64_encode(json_encode(['order_id' => $order->id]));

        $payload = [
            'partnerCode' => $this->partnerCode(),
            'partnerName' => config('app.name', 'Teesik'),
            'storeId' => config('app.name', 'Teesik'),
            'requestId' => $requestId,
            'amount' => $amount,
            'orderId' => $providerOrderId,
            'orderInfo' => 'Thanh toan don hang #' . $order->id,
            'redirectUrl' => $this->buildRedirectUrl($order),
            'ipnUrl' => $this->ipnUrl(),
            'lang' => 'vi',
            'requestType' => config('services.momo.request_type', 'captureWallet'),
            'autoCapture' => true,
            'extraData' => $extraData,
        ];

        $payload['signature'] = $this->signCreatePayload($payload);

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('services.momo.timeout', 15))
            ->post($this->endpoint(), $payload);

        if (!$response->successful()) {
            throw new RuntimeException('MoMo payment request failed.');
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('MoMo returned an invalid response.');
        }

        $order->forceFill([
            'provider' => 'momo',
            'provider_order_id' => $providerOrderId,
            'provider_request_id' => $requestId,
            'provider_result_code' => Arr::get($data, 'resultCode'),
            'provider_payload' => [
                'create_request' => Arr::except($payload, ['signature']),
                'create_response' => $data,
            ],
        ])->save();

        if ((int) Arr::get($data, 'resultCode', -1) !== 0) {
            throw new RuntimeException(Arr::get($data, 'message', 'MoMo could not create the payment.'));
        }

        return $data;
    }

    public function verifyCallback(array $payload): bool
    {
        $signature = (string) Arr::get($payload, 'signature', '');
        if ($signature === '') {
            return false;
        }

        return hash_equals($signature, $this->signCallbackPayload($payload));
    }

    public function applyCallback(array $payload): Order
    {
        if (!$this->verifyCallback($payload)) {
            throw new RuntimeException('Invalid MoMo signature.');
        }

        $order = Order::where('provider', 'momo')
            ->where('provider_order_id', Arr::get($payload, 'orderId'))
            ->firstOrFail();

        $providerPayload = $order->provider_payload ?? [];
        $providerPayload['callback'] = $payload;

        if ((int) Arr::get($payload, 'resultCode', -1) === 0
            && (int) Arr::get($payload, 'amount') !== (int) round($order->grand_total)) {
            throw new RuntimeException('MoMo amount does not match the order total.');
        }

        $updates = [
            'provider_transaction_id' => Arr::get($payload, 'transId'),
            'provider_result_code' => Arr::get($payload, 'resultCode'),
            'provider_payload' => $providerPayload,
        ];

        if ((int) Arr::get($payload, 'resultCode', -1) === 0) {
            $updates['payment_status'] = 'paid';
            $updates['transaction_id'] = (string) Arr::get($payload, 'transId', $order->transaction_id);
            $updates['paid_at'] = $order->paid_at ?: now();
        }

        $order->forceFill($updates)->save();

        if ($order->payment_status === 'paid') {
            $this->clearOrderCart($order);
        }

        return $order->refresh();
    }

    public function ipnResponse(array $payload, int $resultCode = 0, string $message = 'Success'): array
    {
        $response = [
            'partnerCode' => $this->partnerCode(),
            'requestId' => (string) Arr::get($payload, 'requestId', ''),
            'orderId' => (string) Arr::get($payload, 'orderId', ''),
            'resultCode' => $resultCode,
            'message' => $message,
            'responseTime' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'),
            'extraData' => (string) Arr::get($payload, 'extraData', ''),
        ];

        $raw = 'accessKey=' . $this->accessKey()
            . '&extraData=' . $response['extraData']
            . '&message=' . $response['message']
            . '&orderId=' . $response['orderId']
            . '&partnerCode=' . $response['partnerCode']
            . '&requestId=' . $response['requestId']
            . '&responseTime=' . $response['responseTime']
            . '&resultCode=' . $response['resultCode'];

        $response['signature'] = $this->hmac($raw);

        return $response;
    }

    private function signCreatePayload(array $payload): string
    {
        $raw = 'accessKey=' . $this->accessKey()
            . '&amount=' . $payload['amount']
            . '&extraData=' . $payload['extraData']
            . '&ipnUrl=' . $payload['ipnUrl']
            . '&orderId=' . $payload['orderId']
            . '&orderInfo=' . $payload['orderInfo']
            . '&partnerCode=' . $payload['partnerCode']
            . '&redirectUrl=' . $payload['redirectUrl']
            . '&requestId=' . $payload['requestId']
            . '&requestType=' . $payload['requestType'];

        return $this->hmac($raw);
    }

    private function signCallbackPayload(array $payload): string
    {
        $keys = [
            'amount',
            'callbackToken',
            'extraData',
            'message',
            'orderId',
            'orderInfo',
            'orderType',
            'partnerClientId',
            'partnerCode',
            'payType',
            'requestId',
            'responseTime',
            'resultCode',
            'transId',
        ];

        $parts = ['accessKey=' . $this->accessKey()];
        foreach ($keys as $key) {
            if (Arr::exists($payload, $key)) {
                $parts[] = $key . '=' . (string) $payload[$key];
            }
        }

        return $this->hmac(implode('&', $parts));
    }

    private function hmac(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->secretKey());
    }

    private function ensureConfigured(): void
    {
        foreach (['partner_code', 'access_key', 'secret_key', 'endpoint', 'redirect_url', 'ipn_url'] as $key) {
            if (!config('services.momo.' . $key)) {
                throw new RuntimeException('MoMo is not configured.');
            }
        }
    }

    private function partnerCode(): string
    {
        return (string) config('services.momo.partner_code');
    }

    private function accessKey(): string
    {
        return (string) config('services.momo.access_key');
    }

    private function secretKey(): string
    {
        return (string) config('services.momo.secret_key');
    }

    private function endpoint(): string
    {
        return (string) config('services.momo.endpoint');
    }

    private function redirectUrl(): string
    {
        return (string) config('services.momo.redirect_url');
    }

    private function ipnUrl(): string
    {
        return (string) config('services.momo.ipn_url');
    }

    private function buildRedirectUrl(Order $order): string
    {
        $redirectUrl = $this->redirectUrl();
        $paymentToken = (string) data_get($order->data, 'payment_access_token', '');
        if ($paymentToken === '') {
            throw new RuntimeException('Order payment token is missing.');
        }

        $separator = str_contains($redirectUrl, '?') ? '&' : '?';

        return $redirectUrl . $separator . 'order_token=' . urlencode($paymentToken);
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
