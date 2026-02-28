# Stripe Subscriptions in FOSSBilling

This guide documents how to configure and operate Stripe-managed subscriptions with the built-in Stripe payment adapter.

## Gateway Configuration

In the admin area, edit your Stripe payment gateway and configure:

- `Live publishable key` / `Live Secret key` for production.
- `Test Publishable key` / `Test Secret key` for test mode.
- `Webhook signing secret` from your Stripe webhook endpoint.

For subscription and one-time callbacks, use the FOSSBilling callback URL pattern:

- `https://<your-fossbilling-host>/ipn.php?gateway_id=<stripe_gateway_id>`

## Stripe Webhook Events to Subscribe To

Configure your Stripe webhook endpoint to send at minimum:

- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

The adapter requires valid Stripe signatures (`Stripe-Signature`) and the configured `webhook_secret` for webhook processing.

## Mapping: Stripe vs FOSSBilling Subscription State

FOSSBilling creates/maintains subscriptions from Stripe webhooks:

- Stripe `invoice.payment_succeeded` (first subscription invoice) creates a FOSSBilling subscription (`status=active`) if it does not already exist.
- Stripe `invoice.payment_succeeded` (renewals) credits client funds and applies invoice payments via existing transaction logic.
- Stripe `invoice.payment_failed` marks the related transaction as failed and logs a soft warning.
- Stripe `customer.subscription.deleted` or `customer.subscription.updated` with status `canceled`, `unpaid`, or `incomplete_expired` updates FOSSBilling subscription status to `canceled`.

## Period and Amount Mapping

- Recurrence starts from FOSSBilling invoice item period codes (for example `1D`, `1W`, `1M`, `1Y`) and maps to Stripe recurring interval fields.
- Price amount is generated from FOSSBilling invoice total (including tax) and sent to Stripe in minor units (for example cents).
- Metadata links Stripe objects back to FOSSBilling using:
  - `fb_client_id`
  - `fb_invoice_id`
  - `fb_gateway_id`
  - `fb_period`

## Operational Notes

- One-time PaymentIntent checkout remains supported and unchanged.
- Subscription checkout uses Stripe Subscriptions with `payment_behavior=default_incomplete` and confirms the initial payment through Stripe Elements.
- Keep webhook delivery enabled in Stripe and monitor failed webhook deliveries; subscription lifecycle sync depends on webhook events.
