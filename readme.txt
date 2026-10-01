=== UddoktaGetway for WooCommerce ===
Contributors: uddoktagetway
Tags: payment, bkash, nagad, rocket, woocommerce, bangladesh
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Accept bKash, Nagad, Rocket, cards and bank payments in WooCommerce through your UddoktaGetway merchant store.

== Description ==

* Redirects customers to the secure UddoktaGetway checkout page.
* Every payment is verified server-to-server using the transaction verify API before the order is completed.
* Optional IPN (webhook) endpoint so orders complete even if the customer closes the browser.
* Works with the classic checkout and the new Cart/Checkout Blocks.
* HPOS (custom order tables) compatible.
* Amount mismatch protection: orders are put on hold if the paid amount differs.
* Optional BDT conversion rate for stores that use another currency.

== Installation ==

1. Upload the `uddoktagetway-wc` folder to `/wp-content/plugins/` (or upload the ZIP via Plugins > Add New > Upload Plugin).
2. Activate the plugin. WooCommerce must be active.
3. Go to WooCommerce > Settings > Payments > UddoktaGetway.
4. Enter your Gateway URL (e.g. https://uddoktagetway.com), App Key and App Secret from your merchant dashboard.
5. Copy the IPN URL shown on the settings page and paste it into your store's IPN URL field in the UddoktaGetway dashboard.
6. Enable the gateway and place a small test order.

== Changelog ==

= 1.0.0 =
* Initial release.
