# JOVEpay for Shopware 6

Accept cryptocurrency payments in your Shopware store with [JOVEpay](https://www.jovepay.com). Customers pay in USDT, ETH, BTC, BNB and other supported assets across 13 blockchain networks; you settle instantly to your wallet at a flat 0.5% fee.

**Compatible with Shopware 6.2 – 6.7** (one plugin package for the full range).

---

## Overview

JOVEpay Crypto Payments is a Shopware 6 payment extension that:

- Adds a **JOVEpay** payment method to checkout
- Redirects the customer to the hosted JOVEpay payment page
- Returns the browser to Shopware’s payment finalize URL so the order can complete
- Receives **IPN (Instant Payment Notification)** callbacks that authoritatively update the order transaction state (paid, cancelled, failed, etc.)
- Works across Shopware major versions by bridging payment-handler and routing API differences (6.2 async handlers through 6.7 `AbstractPaymentHandler`)

### Payment flow

1. Customer selects JOVEpay at checkout and places the order.
2. Shopware calls the plugin payment handler, which creates a JOVEpay payment session and redirects the customer.
3. Customer pays on the JOVEpay hosted page.
4. Browser return hits Shopware’s payment finalize endpoint (`_sw_payment_token`).
5. JOVEpay sends an IPN to `/checkout/jovepay/callback`. The plugin verifies the request and updates the order transaction status.

IPN is the source of truth for final payment status. Browser return alone does not invent a “paid” state.

---

## Requirements

| Requirement | Version |
| --- | --- |
| **Shopware** | **6.2, 6.3, 6.4, 6.5, 6.6, or 6.7** |
| PHP | 7.4+ (match your Shopware version; e.g. Shopware 6.7 typically needs PHP 8.2+) |
| Extensions | Shopware **Storefront** (required) |
| Merchant account | [JOVEpay](https://www.jovepay.com) API key and IPN secret |

---

## Installation

### Option A — Admin upload (recommended)

1. Download the release ZIP (`jovepay-shopware.zip` / `jovepay-shopware-1.0.0.zip`).
2. In the Shopware Administration go to **Extensions → My extensions**.
3. Click **Upload extension** and select the ZIP.
4. **Install**, then **Activate** **JOVEpay Crypto Payments**.
5. Clear the shop cache if prompted, or run:
   ```bash
   bin/console cache:clear
   ```

On activation the plugin:

- Creates the JOVEpay payment method (if missing)
- Activates it
- Assigns it to all sales channels

### Option B — Manual / Composer custom repository

1. Unpack the ZIP so the plugin folder is named `JovepayPlugin` or keep the archive layout as `jovepay-shopware/` under `custom/plugins/`:
   ```text
   custom/plugins/jovepay-shopware/
   ├── composer.json
   ├── src/
   └── …
   ```
2. Refresh and install via CLI:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate JovepayPlugin
   bin/console cache:clear
   ```

### Option C — Update an existing install

1. Upload the new ZIP (or replace the plugin directory).
2. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:update JovepayPlugin
   bin/console cache:clear
   ```

Always clear the cache after install or update so routes and event subscribers are registered.

---

## Configuration

1. Open **Extensions → My extensions → JOVEpay Crypto Payments → Configure** (per sales channel as needed).
2. Enter credentials from your JOVEpay merchant dashboard.

| Setting | Description |
| --- | --- |
| **Testnet mode** | Use JOVEpay sandbox payments (default: on) |
| **Dark payment theme** | Open the hosted checkout in dark mode |
| **API URL** | JOVEpay payment endpoint (fixed default) |
| **API key** | Merchant API key (required) |
| **IPN secret** | Merchant IPN secret (required for callback verification) |
| **Transmit customer data** | Send customer name/email with the payment request |
| **Transmit product data** | Send line-item details with the payment request |
| **Enable debug logging** | Write detailed logs to `var/log/JovepayPlugin-YYYY-MM-DD.log` |

3. Confirm **Settings → Shop → Payment** (or sales channel payment assignment) shows **JOVEpay Crypto Payments** as available for your storefront.
4. Place a test order with Testnet mode enabled before going live.

---

## Endpoints

| Route name | Path | Purpose |
| --- | --- | --- |
| `frontend.checkout.jovepay.callback` | `/checkout/jovepay/callback` | IPN / payment status updates |
| `frontend.checkout.jovepay.process` | `/checkout/jovepay/process` | Legacy/process helper |
| `frontend.checkout.jovepay.error` | `/checkout/jovepay/error` | Cancelled / failed return UI |

Ensure your shop’s public URL is reachable by JOVEpay for IPN (HTTPS recommended).

---

## Build release ZIP (developers)

From the plugin source directory:

```bash
./bin/build-release.sh
```

Creates `../jovepay-shopware.zip` with the folder layout expected by Shopware Admin upload and Store packaging.

Validate before release:

```bash
shopware-cli extension validate .
```

---

## Compatibility notes

- **Shopware 6.2 – 6.6**: Uses the asynchronous payment handler interface (with polyfills where needed).
- **Shopware 6.7**: Uses `AbstractPaymentHandler`.
- Routing and `_routeScope` differences across 6.2–6.7 are handled inside the plugin so a single ZIP works on all supported majors.

---

## Uninstall

- **Deactivate** or **uninstall** keeps the payment method entity so historical orders stay consistent.
- If you uninstall **without** keeping user data, Shopware removes plugin configuration; order history references are still preserved by design.

---

## Support

- Website: [https://www.jovepay.com](https://www.jovepay.com)
- License: MIT
