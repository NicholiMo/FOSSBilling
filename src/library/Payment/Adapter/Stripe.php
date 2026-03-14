<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Stripe\StripeClient;

class Payment_Adapter_Stripe implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private StripeClient $stripe;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if ($this->config['test_mode']) {
            if (!isset($this->config['test_api_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Stripe', ':missing' => 'Test API Key'], 4001);
            }
            if (!isset($this->config['test_pub_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Stripe', ':missing' => 'Test publishable key'], 4001);
            }

            $this->stripe = new StripeClient($this->config['test_api_key']);
        } else {
            if (!isset($this->config['api_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Stripe', ':missing' => 'API key'], 4001);
            }
            if (!isset($this->config['pub_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Stripe', ':missing' => 'Publishable key'], 4001);
            }

            $this->stripe = new StripeClient($this->config['api_key']);
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => 'You authenticate to the Stripe API by providing one of your API keys in the request. You can manage your API keys from your account.',
            'logo' => [
                'logo' => 'stripe.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'pub_key' => [
                    'text', [
                        'label' => 'Live publishable key:',
                    ],
                ],
                'api_key' => [
                    'text', [
                        'label' => 'Live Secret key:',
                    ],
                ],
                'test_pub_key' => [
                    'text', [
                        'label' => 'Test Publishable key:',
                        'required' => false,
                    ],
                ],
                'test_api_key' => [
                    'text', [
                        'label' => 'Test Secret key:',
                        'required' => false,
                    ],
                ],
                'webhook_secret' => [
                    'text', [
                        'label' => 'Webhook signing secret:',
                        'required' => false,
                    ],
                ],
                'default_product_name' => [
                    'text', [
                        'label' => 'Default product name (optional):',
                        'required' => false,
                    ],
                ],
                'default_product_id' => [
                    'text', [
                        'label' => 'Default product ID (optional):',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        if ($subscription === true) {
            return $this->getSubscriptionHtml($api_admin, $invoiceModel);
        }

        return $this->getOneTimeHtml($invoiceModel);
    }

    private function getOneTimeHtml(Model_Invoice $invoice): string
    {
        return $this->_generateForm($invoice);
    }

    private function getSubscriptionHtml($api_admin, Model_Invoice $invoice): string
    {
        $periodCode = $this->getSubscriptionPeriodCodeForInvoice($invoice);
        $customer = $this->getOrCreateCustomerForInvoice($invoice);
        $subscription = $this->createStripeSubscriptionForInvoice($invoice, $customer, $periodCode);

        $clientSecret = $this->getSubscriptionClientSecret($subscription);
        if (empty($clientSecret)) {
            throw new Payment_Exception('Stripe subscription did not return a client secret for the initial invoice. Ensure the subscription requires confirmation and check latest_invoice.payment_intent or latest_invoice.confirmation_secret.');
        }

        return $this->generateSubscriptionPaymentForm($invoice, $clientSecret);
    }


    private function getSubscriptionClientSecret(\Stripe\Subscription $subscription): ?string
    {
        $latestInvoice = $subscription->latest_invoice ?? null;
        if (!is_object($latestInvoice)) {
            return null;
        }

        $paymentIntent = $latestInvoice->payment_intent ?? null;
        if (is_object($paymentIntent) && !empty($paymentIntent->client_secret)) {
            return (string) $paymentIntent->client_secret;
        }

        $confirmationSecret = $latestInvoice->confirmation_secret ?? null;
        if (is_object($confirmationSecret) && !empty($confirmationSecret->client_secret)) {
            return (string) $confirmationSecret->client_secret;
        }

        return null;
    }

    /**
     * Meta key prefix for storing Stripe customer ID per client per gateway (Phase 3.1 Option B).
     */
    private const EXTENSION_META_STRIPE_CUSTOMER_PREFIX = 'stripe_customer_id_';

    /**
     * Ensures a Stripe Customer exists for the invoice's client.
     * Option B: Persists customer_id in extension_meta per client per gateway and reuses it.
     *
     * @throws Payment_Exception
     */
    private function getOrCreateCustomerForInvoice(Model_Invoice $invoice): \Stripe\Customer
    {
        $clientId = (int) $invoice->client_id;
        $gatewayId = $this->config['gateway_id'] ?? 0;
        $storedCustomerId = $this->getStoredStripeCustomerId($clientId, $gatewayId);

        if ($storedCustomerId !== null && $storedCustomerId !== '') {
            try {
                return $this->stripe->customers->retrieve($storedCustomerId);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Customer was deleted or invalid; create new and update storage
            }
        }

        $params = [
            'email' => $invoice->buyer_email ?? '',
            'name' => trim(($invoice->buyer_first_name ?? '') . ' ' . ($invoice->buyer_last_name ?? '')),
        ];
        $address = [];
        if (!empty($invoice->buyer_address)) {
            $address['line1'] = $invoice->buyer_address;
        }
        if (!empty($invoice->buyer_city)) {
            $address['city'] = $invoice->buyer_city;
        }
        if (!empty($invoice->buyer_state)) {
            $address['state'] = $invoice->buyer_state;
        }
        if (!empty($invoice->buyer_country)) {
            $address['country'] = $invoice->buyer_country;
        }
        if (!empty($invoice->buyer_zip)) {
            $address['postal_code'] = $invoice->buyer_zip;
        }
        if ($address !== []) {
            $params['address'] = $address;
        }

        $customer = $this->stripe->customers->create($params);
        $this->setStoredStripeCustomerId($clientId, $gatewayId, $customer->id);

        return $customer;
    }

    /**
     * Returns stored Stripe customer ID for a client and gateway, if any (Phase 3.1 Option B).
     */
    private function getStoredStripeCustomerId(int $clientId, $gatewayId): ?string
    {
        $metaKey = self::EXTENSION_META_STRIPE_CUSTOMER_PREFIX . $gatewayId;
        $row = $this->di['db']->findOne(
            'extension_meta',
            'extension = ? AND client_id = ? AND meta_key = ?',
            ['mod_invoice', $clientId, $metaKey]
        );

        return $row !== null && isset($row->meta_value) && $row->meta_value !== '' ? (string) $row->meta_value : null;
    }

    /**
     * Stores Stripe customer ID for a client and gateway in extension_meta (Phase 3.1 Option B).
     */
    private function setStoredStripeCustomerId(int $clientId, $gatewayId, string $customerId): void
    {
        $metaKey = self::EXTENSION_META_STRIPE_CUSTOMER_PREFIX . $gatewayId;
        $existing = $this->di['db']->findOne(
            'extension_meta',
            'extension = ? AND client_id = ? AND meta_key = ?',
            ['mod_invoice', $clientId, $metaKey]
        );

        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            $existing->meta_value = $customerId;
            $existing->updated_at = $now;
            $this->di['db']->store($existing);
        } else {
            $meta = $this->di['db']->dispense('extension_meta');
            $meta->extension = 'mod_invoice';
            $meta->client_id = $clientId;
            $meta->meta_key = $metaKey;
            $meta->meta_value = $customerId;
            $meta->created_at = $now;
            $meta->updated_at = $now;
            $this->di['db']->store($meta);
        }
    }

    /**
     * Creates a Stripe Subscription for the invoice (per-subscription Product/Price).
     *
     * @throws Payment_Exception
     */
    private function createStripeSubscriptionForInvoice(Model_Invoice $invoice, \Stripe\Customer $customer, string $periodCode): \Stripe\Subscription
    {
        $productName = $this->getSubscriptionProductNameForInvoice($invoice);
        $product = $this->stripe->products->create([
            'name' => $productName,
        ]);

        $priceParams = $this->buildStripePriceParamsForInvoice($invoice, $periodCode);
        $price = $this->stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $priceParams['unit_amount'],
            'currency' => $priceParams['currency'],
            'recurring' => $priceParams['recurring'],
        ]);

        $gatewayId = $this->config['gateway_id'] ?? null;
        $metadata = [
            'fb_client_id' => (string) $invoice->client_id,
            'fb_invoice_id' => (string) $invoice->id,
            'fb_period' => strtoupper(trim(str_replace(' ', '', $periodCode))),
            // Subscription records in FOSSBilling are intentionally created by webhook handling (Phase 4.1).
            'fb_subscription_create_mode' => 'webhook',
        ];
        if ($gatewayId !== null) {
            $metadata['fb_gateway_id'] = (string) $gatewayId;
        }

        return $this->stripe->subscriptions->create([
            'customer' => $customer->id,
            'items' => [['price' => $price->id]],
            'payment_behavior' => 'default_incomplete',
            'expand' => ['latest_invoice.payment_intent', 'latest_invoice.confirmation_secret'],
            'metadata' => $metadata,
        ]);
    }

    /**
     * Returns HTML form for confirming the subscription's initial payment (same UX as one-time).
     */
    private function generateSubscriptionPaymentForm(Model_Invoice $invoice, string $clientSecret): string
    {
        $pubKey = $this->config['test_mode'] ? $this->config['test_pub_key'] : $this->config['pub_key'];
        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->load('PayGateway', $this->config['gateway_id']);
        $callbackUrl = $payGatewayService->getCallbackUrl($payGateway, $invoice);
        $returnUrl = $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'redirect=true&invoice_hash=' . $invoice->hash . '&mode=subscription';

        $form = '<form id="payment-form" data-secret=":intent_secret">
                <div class="loading" style="display:none;"><span>{% trans \'Loading ...\' %}</span></div>
                <script src="https://js.stripe.com/v3/"></script>

                    <div id="error-message">
                        <!-- Error messages will be displayed here -->
                    </div>
                    <div id="payment-element">
                        <!-- Stripe Elements will create form elements here -->
                    </div>

                    <button id="submit" class="btn btn-primary mt-2" style="margin-top: 0.5em;">Submit</button>

                <script>
                    const stripe = Stripe(\':pub_key\');

                    var elements = stripe.elements({
                        clientSecret: \':intent_secret\',
                      });

                    var paymentElement = elements.create(\'payment\', {
                        billingDetails: {
                            name: \'never\',
                            email: \'never\',
                        },
                    });

                    paymentElement.mount(\'#payment-element\');

                    const form = document.getElementById(\'payment-form\');

                    form.addEventListener(\'submit\', async (event) => {
                    event.preventDefault();

                    const {error} = await stripe.confirmPayment({
                        elements,
                        confirmParams: {
                            return_url: \':return_url\',
                            payment_method_data: {
                                billing_details: {
                                    name: \':buyer_name\',
                                    email: \':buyer_email\',
                                },
                            },
                        },
                    });

                    if (error) {
                        const messageContainer = document.querySelector(\'#error-message\');
                        messageContainer.innerHTML = `<p class="alert alert-danger">${error.message}</p>`;
                    }
                    });
                  </script>
                </form>';

        $bindings = [
            ':pub_key' => $pubKey,
            ':intent_secret' => $clientSecret,
            ':return_url' => $returnUrl,
            ':buyer_name' => trim(($invoice->buyer_first_name ?? '') . ' ' . ($invoice->buyer_last_name ?? '')),
            ':buyer_email' => $invoice->buyer_email ?? '',
        ];

        return strtr($form, $bindings);
    }

    /**
     * Reads the subscription period from the invoice's items (e.g. 1W, 1M, 3M, 1Y).
     *
     * @throws Payment_Exception when no valid period is found on any invoice item
     */
    private function getSubscriptionPeriodCodeForInvoice(Model_Invoice $invoice): string
    {
        $period = $this->di['db']->getCell(
            'SELECT period FROM invoice_item WHERE invoice_id = :invoice_id AND period IS NOT NULL AND period != "" LIMIT 1',
            [':invoice_id' => $invoice->id]
        );

        if ($period === null || $period === '') {
            throw new Payment_Exception('No subscription period found for this invoice. Ensure at least one invoice line has a billing period.');
        }

        return $period;
    }

    /**
     * Maps a FOSSBilling period code (e.g. 1W, 1M, 3M, 1Y) to Stripe recurring interval format.
     *
     * @return array{interval: string, interval_count: int}
     *
     * @throws Payment_Exception for empty, malformed, or unsupported period codes
     */
    private function mapFbPeriodToStripe(string $period): array
    {
        $period = strtoupper(trim(str_replace(' ', '', $period)));

        if ($period === '') {
            throw new Payment_Exception('Subscription period cannot be empty.');
        }

        if (!preg_match('/^(\d+)([DWMY])$/', $period, $m)) {
            throw new Payment_Exception('Invalid subscription period format. Expected a value like 1W, 1M, 3M, or 1Y.');
        }

        $qty = (int) $m[1];
        if ($qty < 1) {
            throw new Payment_Exception('Subscription period quantity must be at least 1.');
        }

        $interval = match ($m[2]) {
            'D' => 'day',
            'W' => 'week',
            'M' => 'month',
            'Y' => 'year',
            default => throw new Payment_Exception('Unsupported subscription period unit. Use D, W, M, or Y.'),
        };

        return [
            'interval' => $interval,
            'interval_count' => $qty,
        ];
    }

    /**
     * Builds Stripe Price parameters for a subscription invoice (no API call).
     * Used when creating a Price in Phase 3.
     */
    private function buildStripePriceParamsForInvoice(Model_Invoice $invoice, string $periodCode): array
    {
        $recurring = $this->mapFbPeriodToStripe($periodCode);

        return [
            'unit_amount' => (int) $this->getAmountInCents($invoice),
            'currency' => strtolower($invoice->currency),
            'recurring' => [
                'interval' => $recurring['interval'],
                'interval_count' => $recurring['interval_count'],
            ],
        ];
    }

    /**
     * Returns a human-readable product name for the Stripe Product (per-subscription strategy).
     */
    private function getSubscriptionProductNameForInvoice(Model_Invoice $invoice): string
    {
        return __trans('Subscription for invoice :serie:id', [
            ':serie' => $invoice->serie,
            ':id' => sprintf('%05s', $invoice->nr),
        ]);
    }

    public function getAmountInCents(Model_Invoice $invoice)
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        return $invoiceService->getTotalWithTax($invoice) * 100;
    }

    public function getInvoiceTitle(Model_Invoice $invoice)
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'],
        ];
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if ((is_countable($invoiceItems) ? count($invoiceItems) : 0) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }

    public function logError($e, Model_Transaction $tx): void
    {
        $body = $e->getJsonBody();
        $err = $body['error'];
        $tx->txn_status = $err['type'];
        $tx->error = $err['message'];
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if (DEBUG) {
            error_log(json_encode($e->getJsonBody()));
        }

        throw new Exception($tx->error);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        $gatewayStatus = 'pending';
        $verifiedWebhookEvent = $this->extractAndVerifyStripeWebhookEvent($data);
        $stripeInvoice = $this->extractSubscriptionPaymentSucceededInvoiceFromWebhook($data, $verifiedWebhookEvent);
        $stripeFailedInvoice = $this->extractSubscriptionInvoiceFromWebhookByType($verifiedWebhookEvent, 'invoice.payment_failed');
        $stripeSubscription = $this->extractStripeSubscriptionObjectFromWebhook($verifiedWebhookEvent);
        $webhookEventType = (string) ($verifiedWebhookEvent['type'] ?? '');

        if (!$tx->invoice_id && $stripeInvoice !== null) {
            $invoiceIdFromMetadata = $this->extractInvoiceIdFromStripeSubscriptionMetadata($stripeInvoice);
            if ($invoiceIdFromMetadata !== null) {
                $tx->invoice_id = $invoiceIdFromMetadata;
            }
        }

        if (!$tx->invoice_id && $stripeFailedInvoice !== null) {
            $invoiceIdFromMetadata = $this->extractInvoiceIdFromStripeSubscriptionMetadata($stripeFailedInvoice);
            if ($invoiceIdFromMetadata !== null) {
                $tx->invoice_id = $invoiceIdFromMetadata;
            }
        }

        try {
            if ($stripeInvoice !== null) {
                $invoice = $this->resolveInvoiceForTransaction($tx, $data);
                $this->applyStripeInvoiceWebhookToTransaction($tx, $stripeInvoice);
                $this->createFossBillingSubscriptionFromStripeInvoice($api_admin, $tx, $stripeInvoice, $invoice, $gateway_id);
                $gatewayStatus = (string) ($tx->txn_status ?: 'succeeded');
            } elseif ($stripeFailedInvoice !== null) {
                $this->applyStripeInvoiceWebhookToTransaction($tx, $stripeFailedInvoice);
                $tx->txn_status = 'failed';
                $tx->note = trim((string) $tx->note . ' (invoice.payment_failed)');
                $this->recordSoftSubscriptionPaymentFailure($stripeFailedInvoice);
                $gatewayStatus = 'failed';
            } elseif ($stripeSubscription !== null) {
                $this->applyStripeSubscriptionWebhookToTransaction($tx, $stripeSubscription, $webhookEventType);
                $this->updateFossBillingSubscriptionStatusFromStripeWebhook($api_admin, $stripeSubscription, $webhookEventType);
                $gatewayStatus = 'pending';
            } elseif (isset($data['get']['payment_intent']) && !empty($data['get']['payment_intent'])) {
                $charge = $this->stripe->paymentIntents->retrieve(
                    $data['get']['payment_intent'],
                    []
                );

                $tx->txn_status = $charge->status;
                $tx->txn_id = $charge->id;
                $tx->amount = $charge->amount / 100;
                $tx->currency = $charge->currency;
                $gatewayStatus = $charge->status;
            } elseif (
                (!isset($data['get']['payment_intent']) || $data['get']['payment_intent'] === '')
                && $this->isWebhookPrePopulatedTransaction($tx)
            ) {
                // Subscription-originated transactions may already be populated from webhook data.
                // In this branch, avoid re-fetching a PaymentIntent and trust stored transaction fields.
                $gatewayStatus = (string) ($tx->txn_status ?: 'pending');
            } else {
                throw new Payment_Exception('Stripe callback did not include a payment_intent or a supported webhook payload.');
            }

            $bd = [
                'amount' => $tx->amount,
                'description' => 'Stripe transaction ' . $tx->txn_id,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ];

            // Only process account crediting/invoice payment once after Stripe confirms success.
            if ($gatewayStatus == 'succeeded' && $tx->status !== 'processed') {
                $invoice = $this->tryResolveInvoiceForTransaction($tx, $data);
                $clientId = $this->resolveClientIdForSuccessfulPayment($tx, $invoice, $stripeInvoice);

                // Instance the services we need
                $clientService = $this->di['mod_service']('client');
                $invoiceService = $this->di['mod_service']('Invoice');

                // Update the account funds
                $client = $this->di['db']->getExistingModelById('Client', $clientId);
                $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

                // Now pay invoice with credits when available; otherwise batch-pay outstanding invoices.
                // Skip direct payment for deposit invoices - funds were already added above.
                if ($invoice instanceof Model_Invoice && !$invoiceService->isInvoiceTypeDeposit($invoice)) {
                    $invoiceService->payInvoiceWithCredits($invoice);
                } else {
                    $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
                }
            }
        } catch (Stripe\Exception\CardException|Stripe\Exception\InvalidRequestException|Stripe\Exception\AuthenticationException|Stripe\Exception\ApiConnectionException|Stripe\Exception\ApiErrorException $e) {
            $this->logError($e, $tx);

            throw new FOSSBilling\Exception('There was an error when processing the transaction');
        }

        $paymentStatus = match ($gatewayStatus) {
            'succeeded' => 'processed',
            'requires_action' => 'received',
            'requires_payment_method' => 'received',
            'requires_confirmation' => 'received',
            'requires_capture' => 'received',
            'processing' => 'received',
            'pending' => 'received',
            'canceled' => 'error',
            'failed' => 'error',
            default => 'received',
        };

        $tx->status = $paymentStatus;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * Resolves the invoice for the transaction, including fallback to callback query params.
     */
    private function resolveInvoiceForTransaction(Model_Transaction $tx, array $data): Model_Invoice
    {
        if ($tx->invoice_id) {
            return $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        }

        if (isset($data['get']['invoice_id']) && !empty($data['get']['invoice_id'])) {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $data['get']['invoice_id']);
            $tx->invoice_id = $invoice->id;

            return $invoice;
        }

        throw new Payment_Exception('Stripe transaction is not associated with any FOSSBilling invoice.');
    }

    /**
     * Attempts to resolve the invoice for the transaction and returns null when none is associated.
     */
    private function tryResolveInvoiceForTransaction(Model_Transaction $tx, array $data): ?Model_Invoice
    {
        if ($tx->invoice_id || (isset($data['get']['invoice_id']) && !empty($data['get']['invoice_id']))) {
            return $this->resolveInvoiceForTransaction($tx, $data);
        }

        return null;
    }

    /**
     * Resolves client ID for successful payment processing when invoice relation may be missing.
     *
     * @throws Payment_Exception
     */
    private function resolveClientIdForSuccessfulPayment(Model_Transaction $tx, ?Model_Invoice $invoice = null, ?array $stripeInvoice = null): int
    {
        if ($invoice instanceof Model_Invoice) {
            return (int) $invoice->client_id;
        }

        if (is_array($stripeInvoice)) {
            $metadata = $this->extractStripeSubscriptionMetadata($stripeInvoice);
            $clientId = isset($metadata['fb_client_id']) ? (int) $metadata['fb_client_id'] : 0;
            if ($clientId > 0) {
                return $clientId;
            }
        }

        if (is_string($tx->s_id) && $tx->s_id !== '') {
            $subscription = $this->di['db']->findOne('Subscription', 'sid = ?', [$tx->s_id]);
            if ($subscription !== null && isset($subscription->client_id) && (int) $subscription->client_id > 0) {
                return (int) $subscription->client_id;
            }
        }

        throw new Payment_Exception('Stripe transaction is not associated with any FOSSBilling client.');
    }

    /**
     * Returns true when a transaction was already populated from Stripe webhook data.
     */
    private function isWebhookPrePopulatedTransaction(Model_Transaction $tx): bool
    {
        $hasStatus = is_string($tx->txn_status) && $tx->txn_status !== '';
        $hasAmount = is_numeric($tx->amount);
        $hasCurrency = is_string($tx->currency) && $tx->currency !== '';
        $hasStripeContext = (is_string($tx->s_id) && $tx->s_id !== '')
            || $tx->type === 'subscription_payment'
            || $tx->type === 'subscription_status_update';

        return $hasStatus && $hasAmount && $hasCurrency && $hasStripeContext;
    }

    /**
     * Verifies Stripe webhook signature and returns decoded event array.
     * Returns null when payload is not a Stripe webhook event.
     *
     * @throws Payment_Exception when webhook signature verification fails
     */
    private function extractAndVerifyStripeWebhookEvent(array $data): ?array
    {
        $payload = $data['http_raw_post_data'] ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return null;
        }

        $isWebhookEvent = isset($event['id'], $event['type']) && isset($event['data']['object']) && is_array($event['data']['object']);
        if (!$isWebhookEvent) {
            return null;
        }

        $signatureHeader = $this->getStripeSignatureHeader($data['server'] ?? []);
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new Payment_Exception('Stripe webhook signature header is missing.');
        }

        $webhookSecret = trim((string) ($this->config['webhook_secret'] ?? ''));
        if ($webhookSecret === '') {
            throw new Payment_Exception('Stripe webhook signing secret is not configured.');
        }

        try {
            $verified = \Stripe\Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException) {
            throw new Payment_Exception('Stripe webhook signature verification failed.');
        }

        if (!method_exists($verified, 'toArray')) {
            throw new Payment_Exception('Stripe webhook event could not be normalized.');
        }

        return $verified->toArray();
    }

    /**
     * Returns Stripe-Signature header from server variables.
     */
    private function getStripeSignatureHeader(array $server): ?string
    {
        $candidates = [
            $server['HTTP_STRIPE_SIGNATURE'] ?? null,
            $server['REDIRECT_HTTP_STRIPE_SIGNATURE'] ?? null,
            $server['Stripe-Signature'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extracts a Stripe subscription invoice object from webhook payload for invoice.payment_succeeded.
     */
    private function extractSubscriptionPaymentSucceededInvoiceFromWebhook(array $data, ?array $verifiedWebhookEvent = null): ?array
    {
        $event = $verifiedWebhookEvent;
        if ($event === null) {
            $payload = $data['http_raw_post_data'] ?? null;
            if (!is_string($payload) || $payload === '') {
                return null;
            }
            $event = json_decode($payload, true);
        }

        if (!is_array($event) || ($event['type'] ?? '') !== 'invoice.payment_succeeded') {
            return null;
        }

        $invoice = $event['data']['object'] ?? null;
        if (!is_array($invoice)) {
            return null;
        }

        if (empty($invoice['subscription'])) {
            return null;
        }

        return $invoice;
    }

    /**
     * Extracts a Stripe subscription invoice object from a specific webhook event type.
     */
    private function extractSubscriptionInvoiceFromWebhookByType(?array $verifiedWebhookEvent, string $eventType): ?array
    {
        if (!is_array($verifiedWebhookEvent) || ($verifiedWebhookEvent['type'] ?? '') !== $eventType) {
            return null;
        }

        $invoice = $verifiedWebhookEvent['data']['object'] ?? null;
        if (!is_array($invoice) || empty($invoice['subscription'])) {
            return null;
        }

        return $invoice;
    }

    /**
     * Extracts Stripe subscription object for customer.subscription lifecycle events.
     */
    private function extractStripeSubscriptionObjectFromWebhook(?array $verifiedWebhookEvent): ?array
    {
        if (!is_array($verifiedWebhookEvent)) {
            return null;
        }

        $eventType = (string) ($verifiedWebhookEvent['type'] ?? '');
        if (!in_array($eventType, ['customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            return null;
        }

        $subscription = $verifiedWebhookEvent['data']['object'] ?? null;
        if (!is_array($subscription)) {
            return null;
        }

        return $subscription;
    }

    /**
     * Maps Stripe invoice webhook fields onto transaction fields.
     */
    private function applyStripeInvoiceWebhookToTransaction(Model_Transaction $tx, array $stripeInvoice): void
    {
        $tx->txn_id = $this->getStripeInvoiceTransactionId($stripeInvoice, (string) $tx->txn_id);
        $tx->txn_status = !empty($stripeInvoice['paid']) ? 'succeeded' : (string) ($stripeInvoice['status'] ?? 'pending');
        $tx->amount = (float) (($stripeInvoice['amount_paid'] ?? $stripeInvoice['amount_due'] ?? 0) / 100);
        $tx->currency = strtoupper((string) ($stripeInvoice['currency'] ?? $tx->currency));
        $tx->type = 'subscription_payment';

        $subscriptionId = $this->sanitizeStripeIdentifier($stripeInvoice['subscription'] ?? null);
        if ($subscriptionId !== '') {
            $tx->s_id = $subscriptionId;
        }

        $customerId = $this->sanitizeStripeIdentifier($stripeInvoice['customer'] ?? null);
        $tx->note = trim(sprintf(
            'Stripe subscription webhook payment%s%s',
            $subscriptionId !== '' ? ' for subscription ' . $subscriptionId : '',
            $customerId !== '' ? ' (customer ' . $customerId . ')' : ''
        ));
    }

    /**
     * Resolves the canonical transaction ID for Stripe invoice webhooks.
     * Prefers invoice ID, then charge ID, then payment intent ID, then fallback.
     */
    private function getStripeInvoiceTransactionId(array $stripeInvoice, string $fallback = ''): string
    {
        $candidates = [
            $stripeInvoice['id'] ?? null,
            $stripeInvoice['charge'] ?? null,
            $stripeInvoice['payment_intent'] ?? null,
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $id = $this->sanitizeStripeIdentifier($candidate);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    /**
     * Maps Stripe subscription webhook fields onto transaction fields.
     */
    private function applyStripeSubscriptionWebhookToTransaction(Model_Transaction $tx, array $stripeSubscription, string $eventType): void
    {
        $subscriptionId = $this->sanitizeStripeIdentifier($stripeSubscription['id'] ?? null);
        if ($subscriptionId !== '') {
            $tx->s_id = $subscriptionId;
            $tx->txn_id = $subscriptionId;
        }

        $status = strtolower((string) ($stripeSubscription['status'] ?? 'pending'));
        $tx->txn_status = $status !== '' ? $status : 'pending';
        $tx->type = 'subscription_status_update';
        $tx->note = trim(sprintf(
            'Stripe webhook %s for subscription %s (status: %s)',
            $eventType,
            $subscriptionId !== '' ? $subscriptionId : 'unknown',
            $tx->txn_status
        ));
    }

    /**
     * Handles phase 5.4 status sync from Stripe subscription lifecycle webhooks.
     */
    private function updateFossBillingSubscriptionStatusFromStripeWebhook($api_admin, array $stripeSubscription, string $eventType): void
    {
        $subscriptionId = $this->sanitizeStripeIdentifier($stripeSubscription['id'] ?? null);
        if ($subscriptionId === '') {
            return;
        }

        $stripeStatus = strtolower((string) ($stripeSubscription['status'] ?? ''));
        $shouldCancel = $eventType === 'customer.subscription.deleted'
            || in_array($stripeStatus, ['canceled', 'unpaid', 'incomplete_expired'], true);

        if (!$shouldCancel) {
            return;
        }

        try {
            $subscription = $api_admin->invoice_subscription_get(['sid' => $subscriptionId]);
        } catch (\Exception) {
            return;
        }

        if (!is_array($subscription) || !isset($subscription['id'])) {
            return;
        }

        if (($subscription['status'] ?? null) === 'canceled') {
            return;
        }

        $api_admin->invoice_subscription_update([
            'id' => $subscription['id'],
            'status' => 'canceled',
        ]);
    }

    /**
     * Records a soft warning for failed recurring charges.
     */
    private function recordSoftSubscriptionPaymentFailure(array $stripeInvoice): void
    {
        $subscriptionId = $this->sanitizeStripeIdentifier($stripeInvoice['subscription'] ?? null);
        $invoiceId = $this->sanitizeStripeIdentifier($stripeInvoice['id'] ?? null);
        $this->di['logger']->warning(
            'Stripe subscription payment failed for subscription %s (invoice %s).',
            $subscriptionId !== '' ? $subscriptionId : 'unknown',
            $invoiceId !== '' ? $invoiceId : 'unknown'
        );
    }

    /**
     * Creates a FOSSBilling subscription from the first successful Stripe subscription invoice.
     * Mirrors PayPal's subscription_create flow while keeping it idempotent.
     */
    private function createFossBillingSubscriptionFromStripeInvoice($api_admin, Model_Transaction $tx, array $stripeInvoice, Model_Invoice $invoice, int $gatewayId): void
    {
        $subscriptionId = $this->sanitizeStripeIdentifier($stripeInvoice['subscription'] ?? null);
        if ($subscriptionId === '') {
            return;
        }

        // Always persist relation fields on the transaction when subscription context exists.
        $tx->s_id = $subscriptionId;

        $periodCode = $this->getFossBillingPeriodForStripeInvoice($stripeInvoice);
        if ($periodCode !== null) {
            $tx->s_period = $periodCode;
        }

        if (!$this->isFirstStripeSubscriptionInvoice($stripeInvoice)) {
            return;
        }

        $existingSubscription = $this->di['db']->findOne('Subscription', 'sid = ?', [$subscriptionId]);
        if ($existingSubscription instanceof Model_Subscription) {
            return;
        }

        $metadata = $this->extractStripeSubscriptionMetadata($stripeInvoice);
        $clientId = (int) ($metadata['fb_client_id'] ?? $invoice->client_id);
        $relatedInvoiceId = (int) ($metadata['fb_invoice_id'] ?? $invoice->id);
        $resolvedGatewayId = (int) ($metadata['fb_gateway_id'] ?? $gatewayId);
        $currency = strtoupper((string) ($stripeInvoice['currency'] ?? $invoice->currency));
        $period = $periodCode ?? $this->getSubscriptionPeriodCodeForInvoice($invoice);
        $amount = (float) (($stripeInvoice['amount_paid'] ?? $stripeInvoice['amount_due'] ?? 0) / 100);

        $api_admin->invoice_subscription_create([
            'client_id' => $clientId,
            'gateway_id' => $resolvedGatewayId,
            'currency' => $currency,
            'sid' => $subscriptionId,
            'status' => 'active',
            'period' => $period,
            'amount' => $amount,
            'rel_type' => 'invoice',
            'rel_id' => $relatedInvoiceId,
        ]);
    }

    /**
     * Returns true if Stripe marks this as the initial subscription invoice.
     */
    private function isFirstStripeSubscriptionInvoice(array $stripeInvoice): bool
    {
        return ($stripeInvoice['billing_reason'] ?? null) === 'subscription_create';
    }

    /**
     * Returns a normalized Stripe identifier (letters, numbers, underscore) or empty string.
     * Used to harden webhook-derived IDs before DB/API lookups and log formatting.
     */
    private function sanitizeStripeIdentifier(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $id = trim((string) $value);
        if ($id === '') {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $id) === 1 ? $id : '';
    }

    /**
     * Reads FOSSBilling metadata from Stripe invoice payload.
     */
    private function extractStripeSubscriptionMetadata(array $stripeInvoice): array
    {
        $metadata = [];

        if (isset($stripeInvoice['parent']['subscription_details']['metadata']) && is_array($stripeInvoice['parent']['subscription_details']['metadata'])) {
            $metadata = $stripeInvoice['parent']['subscription_details']['metadata'];
        } elseif (isset($stripeInvoice['subscription_details']['metadata']) && is_array($stripeInvoice['subscription_details']['metadata'])) {
            $metadata = $stripeInvoice['subscription_details']['metadata'];
        }

        if ($metadata === [] && isset($stripeInvoice['metadata']) && is_array($stripeInvoice['metadata'])) {
            $metadata = $stripeInvoice['metadata'];
        }

        return $metadata;
    }

    /**
     * Extracts FOSSBilling invoice ID from Stripe metadata, if available.
     */
    private function extractInvoiceIdFromStripeSubscriptionMetadata(array $stripeInvoice): ?int
    {
        $metadata = $this->extractStripeSubscriptionMetadata($stripeInvoice);
        $invoiceId = isset($metadata['fb_invoice_id']) ? (int) $metadata['fb_invoice_id'] : 0;

        return $invoiceId > 0 ? $invoiceId : null;
    }

    /**
     * Resolves FOSSBilling period code from Stripe invoice metadata or recurring price fields.
     */
    private function getFossBillingPeriodForStripeInvoice(array $stripeInvoice): ?string
    {
        $metadata = $this->extractStripeSubscriptionMetadata($stripeInvoice);
        if (!empty($metadata['fb_period'])) {
            return strtoupper(trim((string) $metadata['fb_period']));
        }

        $recurring = $stripeInvoice['lines']['data'][0]['price']['recurring'] ?? null;
        if (!is_array($recurring)) {
            return null;
        }

        $interval = (string) ($recurring['interval'] ?? '');
        $intervalCount = (int) ($recurring['interval_count'] ?? 0);
        if ($intervalCount < 1 || $interval === '') {
            return null;
        }

        $unit = match ($interval) {
            'day' => 'D',
            'week' => 'W',
            'month' => 'M',
            'year' => 'Y',
            default => null,
        };

        return $unit !== null ? $intervalCount . $unit : null;
    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $this->getAmountInCents($invoice),
            'currency' => $invoice->currency,
            'description' => $this->getInvoiceTitle($invoice),
            'automatic_payment_methods' => ['enabled' => true],
            'receipt_email' => $invoice->buyer_email,
        ]);

        $pubKey = ($this->config['test_mode']) ? $this->config['test_pub_key'] : $this->config['pub_key'];

        $dataAmount = $this->getAmountInCents($invoice);

        $settingService = $this->di['mod_service']('System');

        $title = $this->getInvoiceTitle($invoice);

        $form = '<form id="payment-form" data-secret=":intent_secret">
                <div class="loading" style="display:none;"><span>{% trans \'Loading ...\' %}</span></div>
                <script src="https://js.stripe.com/v3/"></script>

                    <div id="error-message">
                        <!-- Error messages will be displayed here -->
                    </div>
                    <div id="payment-element">
                        <!-- Stripe Elements will create form elements here -->
                    </div>

                    <button id="submit" class="btn btn-primary mt-2" style="margin-top: 0.5em;">Submit</button>

                <script>
                    const stripe = Stripe(\':pub_key\');

                    var elements = stripe.elements({
                        clientSecret: \':intent_secret\',
                      });

                    var paymentElement = elements.create(\'payment\', {
                        billingDetails: {
                            name: \'never\',
                            email: \'never\',
                        },
                    });

                    paymentElement.mount(\'#payment-element\');

                    const form = document.getElementById(\'payment-form\');

                    form.addEventListener(\'submit\', async (event) => {
                    event.preventDefault();

                    const {error} = await stripe.confirmPayment({
                        elements,
                        confirmParams: {
                            return_url: \':callbackUrl&redirect=true&invoice_hash=:invoice_hash\',
                            payment_method_data: {
                                billing_details: {
                                    name: \':buyer_name\',
                                    email: \':buyer_email\',
                                },
                            },
                        },
                    });

                    if (error) {
                        const messageContainer = document.querySelector(\'#error-message\');
                        messageContainer.innerHTML = `<p class="alert alert-danger">${error.message}</p>`;
                    }
                    });

                  </script>
                </form>';

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Stripe"');
        $bindings = [
            ':pub_key' => $pubKey,
            ':intent_secret' => $intent->client_secret,
            ':amount' => $dataAmount,
            ':currency' => $invoice->currency,
            ':description' => $title,
            ':buyer_email' => $invoice->buyer_email,
            ':buyer_name' => trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name),
            ':callbackUrl' => $payGatewayService->getCallbackUrl($payGateway, $invoice),
            ':redirectUrl' => $this->di['tools']->url('invoice/' . $invoice->hash),
            ':invoice_hash' => $invoice->hash,
        ];

        return strtr($form, $bindings);
    }
}
