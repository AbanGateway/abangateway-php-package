# Changelog

## 1.0.1

- Laravel 13 is supported: the bridge is tested on Laravel 13 (PHP 8.3 and 8.4) next to 10, 11 and 12. No code change.

## 1.0.0

First release.

- `Client`: create, read, verify (once), cancel and simulate (test keys) invoices; amounts in Rial or Toman.
- `Client::invoiceForOrder()`: one open invoice per order, with a new attempt (`ORDER#2`, …) after expiry or a changed total, and never a second charge for an order already paid.
- `Webhook`: HMAC-SHA256 signature check on the raw body, and `confirm()` to read the invoice back from the API before acting.
- Typed exceptions carrying the API's error code.
- Laravel 10, 11 and 12: auto-discovered provider, publishable config, `AbanGateway` facade, a webhook route that fires `InvoicePaid`, `InvoicePartiallyPaid`, `InvoiceExpired` and `InvoiceCancelled`.
- PHP 7.4 or newer for the core; Laravel's own PHP requirement for the bridge.
