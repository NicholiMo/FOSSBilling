<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('Core')]
final class Payment_Adapter_StripeTest extends BBTestCase
{
    public function testGetConfigExposesSubscriptionCapabilities(): void
    {
        $config = Payment_Adapter_Stripe::getConfig();

        $this->assertTrue($config['supports_one_time_payments']);
        $this->assertTrue($config['supports_subscriptions']);
        $this->assertArrayHasKey('webhook_secret', $config['form']);
    }

    #[DataProvider('validPeriodProvider')]
    public function testMapFbPeriodToStripeValidPeriods(string $period, array $expected): void
    {
        $adapter = $this->createAdapter();

        $mapped = $this->invokePrivateMethod($adapter, 'mapFbPeriodToStripe', [$period]);

        $this->assertSame($expected, $mapped);
    }

    #[DataProvider('invalidPeriodProvider')]
    public function testMapFbPeriodToStripeRejectsInvalidPeriods(string $period): void
    {
        $adapter = $this->createAdapter();

        $this->expectException(Payment_Exception::class);
        $this->invokePrivateMethod($adapter, 'mapFbPeriodToStripe', [$period]);
    }

    public function testWebhookVerificationRequiresSignatureHeader(): void
    {
        $adapter = $this->createAdapter();
        $payload = json_encode([
            'id' => 'evt_test_1',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_test_1']],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(Payment_Exception::class);
        $this->expectExceptionMessage('Stripe webhook signature header is missing.');

        $this->invokePrivateMethod($adapter, 'extractAndVerifyStripeWebhookEvent', [[
            'http_raw_post_data' => $payload,
            'server' => [],
        ]]);
    }

    public function testWebhookVerificationAcceptsValidSignature(): void
    {
        $secret = 'whsec_test_secret';
        $adapter = $this->createAdapter(['webhook_secret' => $secret]);
        $payload = json_encode([
            'id' => 'evt_test_valid',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_test_valid']],
        ], JSON_THROW_ON_ERROR);
        $signature = $this->buildStripeSignature($payload, $secret);

        $event = $this->invokePrivateMethod($adapter, 'extractAndVerifyStripeWebhookEvent', [[
            'http_raw_post_data' => $payload,
            'server' => ['HTTP_STRIPE_SIGNATURE' => $signature],
        ]]);

        $this->assertIsArray($event);
        $this->assertSame('evt_test_valid', $event['id']);
        $this->assertSame('invoice.payment_succeeded', $event['type']);
    }

    public function testWebhookVerificationRejectsInvalidSignature(): void
    {
        $secret = 'whsec_test_secret';
        $adapter = $this->createAdapter(['webhook_secret' => $secret]);
        $payload = json_encode([
            'id' => 'evt_test_invalid',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_test_invalid']],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(Payment_Exception::class);
        $this->expectExceptionMessage('Stripe webhook signature verification failed.');

        $this->invokePrivateMethod($adapter, 'extractAndVerifyStripeWebhookEvent', [[
            'http_raw_post_data' => $payload,
            'server' => ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid'],
        ]]);
    }

    public function testGetStripeInvoiceTransactionIdSkipsUnsafeIdentifiers(): void
    {
        $adapter = $this->createAdapter();

        $txnId = $this->invokePrivateMethod($adapter, 'getStripeInvoiceTransactionId', [[
            'id' => 'in_valid_123',
            'charge' => 'ch_invalid;drop table',
            'payment_intent' => 'pi_valid_123',
        ]]);
        $this->assertSame('in_valid_123', $txnId);

        $fallbackId = $this->invokePrivateMethod($adapter, 'getStripeInvoiceTransactionId', [[
            'id' => 'in invalid with spaces',
            'charge' => 'ch/invalid/slash',
            'payment_intent' => 'pi-invalid-dash',
        ], 'tx_safe_123']);
        $this->assertSame('tx_safe_123', $fallbackId);
    }

    public static function validPeriodProvider(): array
    {
        return [
            'daily' => ['1D', ['interval' => 'day', 'interval_count' => 1]],
            'weekly' => ['2W', ['interval' => 'week', 'interval_count' => 2]],
            'monthly with whitespace' => [' 3 m ', ['interval' => 'month', 'interval_count' => 3]],
            'yearly' => ['1Y', ['interval' => 'year', 'interval_count' => 1]],
        ];
    }

    public static function invalidPeriodProvider(): array
    {
        return [
            'empty' => [''],
            'missing unit' => ['1'],
            'invalid unit' => ['1Q'],
            'zero quantity' => ['0M'],
            'letters only' => ['MONTH'],
        ];
    }

    private function createAdapter(array $overrides = []): Payment_Adapter_Stripe
    {
        $config = array_merge([
            'test_mode' => true,
            'test_api_key' => 'sk_test_123',
            'test_pub_key' => 'pk_test_123',
            'webhook_secret' => 'whsec_default',
        ], $overrides);

        return new Payment_Adapter_Stripe($config);
    }

    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $targetMethod = $reflection->getMethod($method);

        return $targetMethod->invokeArgs($object, $args);
    }

    private function buildStripeSignature(string $payload, string $secret): string
    {
        $timestamp = (string) time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return sprintf('t=%s,v1=%s', $timestamp, $signature);
    }
}
