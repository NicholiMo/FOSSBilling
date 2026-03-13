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

## Cancellation Flow

When a subscription is canceled in FOSSBilling (admin or client), FOSSBilling now attempts to cancel the remote Stripe subscription before persisting the local status change.

- If Stripe gateway setting `cancel_at_period_end` is disabled (default), FOSSBilling sends an immediate cancel request to Stripe.
- If `cancel_at_period_end` is enabled, FOSSBilling updates the Stripe subscription with `cancel_at_period_end=true`.
- If the remote Stripe request fails, FOSSBilling does not finalize the local subscription status update. Retry the cancel action after the error is resolved.

Stripe webhooks still remain the source of lifecycle synchronization:

- `customer.subscription.deleted` and `customer.subscription.updated` may be received after a cancel initiated from FOSSBilling.
- This follow-up sync is expected and generally redundant but harmless.

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
- If remote cancellation fails due to network/API issues, the local status remains unchanged. Retry cancellation from FOSSBilling or cancel manually in Stripe Dashboard, then allow webhook sync to reconcile state.
