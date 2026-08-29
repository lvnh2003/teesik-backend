<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SepayQrPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sepay.webhook_secret' => 'sepay-secret',
            'services.sepay.webhook_auth_method' => 'hmac',
            'services.sepay.payment_code_prefix' => 'TEESIK',
            'services.sepay.bank_bin' => '970436',
            'services.sepay.bank_account_number' => '0987654321',
            'services.sepay.bank_account_name' => 'TEESIK STORE',
        ]);
    }

    public function test_qr_payment_creation_returns_order_specific_qr_and_stores_metadata(): void
    {
        $order = $this->makeOrder();

        $response = $this->postJson('/api/v1/payment/process', [
            'order_id' => $order->id,
            'payment_method' => 'qr',
            'payment_token' => 'payment-token-test-1234567890',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_method', 'qr')
            ->assertJsonPath('data.amount', 150000)
            ->assertJsonPath('data.status', 'pending');

        $paymentCode = $response->json('data.payment_code');
        $qrCodeUrl = $response->json('data.qr_code_url');

        $this->assertStringStartsWith('TEESIK' . $order->id, $paymentCode);
        $this->assertStringContainsString('img.vietqr.io/image/970436-0987654321-qr_only.png', $qrCodeUrl);
        $this->assertStringContainsString('addInfo=' . $paymentCode, urldecode($qrCodeUrl));

        $order->refresh();
        $this->assertSame('sepay', $order->provider);
        $this->assertSame($paymentCode, $order->provider_order_id);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_invalid_sepay_webhook_signature_is_rejected(): void
    {
        $order = $this->makeOrder([
            'provider' => 'sepay',
            'provider_order_id' => 'TEESIK1ABCD',
        ]);

        $payload = $this->webhookPayload($order);

        $this->call('POST', '/api/v1/payment/sepay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_TIMESTAMP' => (string) time(),
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256=invalid',
        ], json_encode($payload))
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame('unpaid', $order->refresh()->payment_status);
    }

    public function test_matching_sepay_webhook_marks_order_paid_idempotently(): void
    {
        Cart::create(['cart_id' => 'guest-cart-sepay']);

        $order = $this->makeOrder([
            'provider' => 'sepay',
            'provider_order_id' => 'TEESIK1ABCD',
            'data' => [
                'payment_access_token' => 'payment-token-test-1234567890',
                'cart_id' => 'guest-cart-sepay',
            ],
        ]);

        $payload = $this->webhookPayload($order);

        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('success', true);
        $firstPaidAt = $order->refresh()->paid_at;

        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('92704', $order->transaction_id);
        $this->assertTrue($firstPaidAt->equalTo($order->paid_at));
        $this->assertDatabaseMissing('carts', ['cart_id' => 'guest-cart-sepay']);
    }

    public function test_wrong_amount_sepay_webhook_keeps_order_unpaid(): void
    {
        $order = $this->makeOrder([
            'provider' => 'sepay',
            'provider_order_id' => 'TEESIK1ABCD',
        ]);

        $payload = $this->webhookPayload($order, ['transferAmount' => 149000]);

        $this->postSignedWebhook($payload)->assertStatus(400);

        $this->assertSame('unpaid', $order->refresh()->payment_status);
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
            'payment_method' => 'qr',
            'items' => [],
            'data' => [
                'payment_access_token' => 'payment-token-test-1234567890',
            ],
        ], $overrides));
    }

    private function webhookPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'id' => 92704,
            'gateway' => 'Vietcombank',
            'transactionDate' => '2024-07-02 11:08:33',
            'accountNumber' => '0987654321',
            'subAccount' => '',
            'code' => $order->provider_order_id,
            'content' => $order->provider_order_id . ' chuyen tien',
            'transferType' => 'in',
            'description' => 'Test transfer',
            'transferAmount' => 150000,
            'accumulated' => 1000000,
            'referenceCode' => 'FT24012345678',
        ], $overrides);
    }

    private function postSignedWebhook(array $payload)
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'sepay-secret');

        return $this->call('POST', '/api/v1/payment/sepay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SEPAY_SIGNATURE' => $signature,
        ], $body);
    }
}
