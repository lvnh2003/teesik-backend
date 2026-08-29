<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MomoPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.momo.partner_code' => 'MOMO',
            'services.momo.access_key' => 'access-key',
            'services.momo.secret_key' => 'secret-key',
            'services.momo.endpoint' => 'https://test-payment.momo.vn/v2/gateway/api/create',
            'services.momo.redirect_url' => 'https://teesik.test/api/v1/payment/momo/return',
            'services.momo.ipn_url' => 'https://teesik.test/api/v1/payment/momo/ipn',
        ]);
    }

    public function test_momo_payment_creation_signs_request_and_stores_pending_metadata(): void
    {
        Http::fake([
            'https://test-payment.momo.vn/*' => Http::response([
                'partnerCode' => 'MOMO',
                'requestId' => 'ignored',
                'orderId' => 'ignored',
                'amount' => 150000,
                'message' => 'Success',
                'resultCode' => 0,
                'payUrl' => 'https://test-payment.momo.vn/pay',
                'deeplink' => 'momo://pay',
                'qrCodeUrl' => 'https://test-payment.momo.vn/qr-data',
            ], 200),
        ]);

        $order = $this->makeOrder();

        $response = $this->postJson('/api/v1/payment/process', [
            'order_id' => $order->id,
            'payment_method' => 'momo',
            'payment_token' => 'payment-token-test-1234567890',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_method', 'momo')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pay_url', 'https://test-payment.momo.vn/pay')
            ->assertJsonPath('data.deeplink', 'momo://pay')
            ->assertJsonPath('data.qr_code_url', 'https://test-payment.momo.vn/qr-data');

        Http::assertSent(function (Request $request) {
            $payload = $request->data();
            $raw = 'accessKey=access-key'
                . '&amount=' . $payload['amount']
                . '&extraData=' . $payload['extraData']
                . '&ipnUrl=' . $payload['ipnUrl']
                . '&orderId=' . $payload['orderId']
                . '&orderInfo=' . $payload['orderInfo']
                . '&partnerCode=' . $payload['partnerCode']
                . '&redirectUrl=' . $payload['redirectUrl']
                . '&requestId=' . $payload['requestId']
                . '&requestType=' . $payload['requestType'];

            return $payload['signature'] === hash_hmac('sha256', $raw, 'secret-key');
        });

        $order->refresh();

        $this->assertSame('momo', $order->provider);
        $this->assertNotNull($order->provider_order_id);
        $this->assertNotNull($order->provider_request_id);
        $this->assertSame(0, $order->provider_result_code);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_invalid_momo_ipn_signature_is_rejected(): void
    {
        $order = $this->makeOrder([
            'provider' => 'momo',
            'provider_order_id' => 'TS1-test',
            'provider_request_id' => 'REQ-test',
        ]);

        $payload = $this->callbackPayload($order, ['signature' => 'invalid']);

        $this->postJson('/api/v1/payment/momo/ipn', $payload)
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame('unpaid', $order->refresh()->payment_status);
    }

    public function test_successful_momo_ipn_marks_order_paid_idempotently(): void
    {
        Cart::create(['cart_id' => 'guest-cart-momo']);

        $order = $this->makeOrder([
            'provider' => 'momo',
            'provider_order_id' => 'TS1-test',
            'provider_request_id' => 'REQ-test',
            'data' => [
                'payment_access_token' => 'payment-token-test-1234567890',
                'cart_id' => 'guest-cart-momo',
            ],
        ]);

        $payload = $this->signedCallbackPayload($order);

        $this->postJson('/api/v1/payment/momo/ipn', $payload)->assertOk();
        $firstPaidAt = $order->refresh()->paid_at;

        $this->postJson('/api/v1/payment/momo/ipn', $payload)->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('123456789', $order->transaction_id);
        $this->assertTrue($firstPaidAt->equalTo($order->paid_at));
        $this->assertDatabaseMissing('carts', ['cart_id' => 'guest-cart-momo']);
    }

    public function test_failed_momo_ipn_keeps_order_unpaid(): void
    {
        $order = $this->makeOrder([
            'provider' => 'momo',
            'provider_order_id' => 'TS1-test',
            'provider_request_id' => 'REQ-test',
        ]);

        $payload = $this->signedCallbackPayload($order, [
            'resultCode' => 1006,
            'message' => 'Transaction denied by user.',
        ]);

        $this->postJson('/api/v1/payment/momo/ipn', $payload)->assertOk();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(1006, $order->provider_result_code);
        $this->assertNull($order->paid_at);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_name' => 'Test Customer',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '0900000000',
            'shipping_address' => '123 Test Street',
            'total_amount' => 150000,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'grand_total' => 150000,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'momo',
            'items' => [],
            'data' => [
                'payment_access_token' => 'payment-token-test-1234567890',
            ],
        ], $overrides));
    }

    private function signedCallbackPayload(Order $order, array $overrides = []): array
    {
        $payload = $this->callbackPayload($order, $overrides);
        $raw = 'accessKey=access-key'
            . '&amount=' . $payload['amount']
            . '&extraData=' . $payload['extraData']
            . '&message=' . $payload['message']
            . '&orderId=' . $payload['orderId']
            . '&orderInfo=' . $payload['orderInfo']
            . '&orderType=' . $payload['orderType']
            . '&partnerCode=' . $payload['partnerCode']
            . '&payType=' . $payload['payType']
            . '&requestId=' . $payload['requestId']
            . '&responseTime=' . $payload['responseTime']
            . '&resultCode=' . $payload['resultCode']
            . '&transId=' . $payload['transId'];

        $payload['signature'] = hash_hmac('sha256', $raw, 'secret-key');

        return $payload;
    }

    private function callbackPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'partnerCode' => 'MOMO',
            'orderId' => $order->provider_order_id,
            'requestId' => $order->provider_request_id,
            'amount' => '150000',
            'orderInfo' => 'Thanh toan don hang #' . $order->id,
            'orderType' => 'momo_wallet',
            'transId' => '123456789',
            'resultCode' => 0,
            'message' => 'Successful.',
            'payType' => 'web',
            'responseTime' => '1716600000000',
            'extraData' => '',
        ], $overrides);
    }
}
