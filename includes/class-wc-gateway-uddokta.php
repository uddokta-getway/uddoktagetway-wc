<?php
/**
 * UddoktaGetway payment gateway for WooCommerce.
 *
 * Flow:
 *  1. process_payment()  -> POST {api}/api/v1/transaction/create  -> redirect customer to RedirectURL
 *  2. Customer pays, gateway redirects back to ?wc-api=WC_Gateway_UddoktaGetway&ug_action=return|cancel|fail
 *  3. handle_callback()  -> POST {api}/api/v1/transaction/verify  (server-to-server, source of truth)
 *  4. Optional IPN       -> ?wc-api=WC_Gateway_UddoktaGetway_IPN (also re-verified via the verify API)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_UddoktaGetway extends WC_Payment_Gateway
{
    /** @var string */
    protected $api_url;
    /** @var string */
    protected $app_key;
    /** @var string */
    protected $app_secret;
    /** @var float */
    protected $exchange_rate;
    /** @var string */
    protected $final_status;
    /** @var bool */
    protected $debug;

    /** @var WC_Logger|null */
    protected static $logger = null;

    public function __construct()
    {
        $this->id                 = 'uddoktagetway';
        $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = __('UddoktaGetway', 'uddoktagetway-wc');
        $this->method_description = __('Accept bKash, Nagad, Rocket, cards and bank payments through UddoktaGetway.', 'uddoktagetway-wc');
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title         = $this->get_option('title', 'bKash / Nagad / Rocket / Card');
        $this->description   = $this->get_option('description');
        $this->api_url       = untrailingslashit(trim($this->get_option('api_url', 'https://uddoktagetway.com')));
        $this->app_key       = trim($this->get_option('app_key'));
        $this->app_secret    = trim($this->get_option('app_secret'));
        $this->exchange_rate = floatval($this->get_option('exchange_rate', '1'));
        $this->final_status  = $this->get_option('final_status', 'default');
        $this->debug         = 'yes' === $this->get_option('debug', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_wc_gateway_uddoktagetway', [$this, 'handle_callback']);
        add_action('woocommerce_api_wc_gateway_uddoktagetway_ipn', [$this, 'handle_ipn']);
    }

    /* ---------------------------------------------------------------------
     * Admin settings
     * ------------------------------------------------------------------ */

    public function init_form_fields()
    {
        $ipn_url = WC()->api_request_url('WC_Gateway_UddoktaGetway_IPN');

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'uddoktagetway-wc'),
                'type'    => 'checkbox',
                'label'   => __('Enable UddoktaGetway', 'uddoktagetway-wc'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'uddoktagetway-wc'),
                'type'        => 'text',
                'description' => __('Shown to the customer at checkout.', 'uddoktagetway-wc'),
                'default'     => __('bKash / Nagad / Rocket / Card', 'uddoktagetway-wc'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'uddoktagetway-wc'),
                'type'        => 'textarea',
                'description' => __('Shown below the title at checkout.', 'uddoktagetway-wc'),
                'default'     => __('Pay securely with mobile banking, card or internet banking.', 'uddoktagetway-wc'),
                'desc_tip'    => true,
            ],
            'api_section' => [
                'title'       => __('API Credentials', 'uddoktagetway-wc'),
                'type'        => 'title',
                'description' => __('Find these in your UddoktaGetway dashboard under Store Management / Developer Integration.', 'uddoktagetway-wc'),
            ],
            'api_url' => [
                'title'       => __('Gateway URL', 'uddoktagetway-wc'),
                'type'        => 'text',
                'description' => __('Base URL of your UddoktaGetway installation, without a trailing slash.', 'uddoktagetway-wc'),
                'default'     => 'https://uddoktagetway.com',
                'desc_tip'    => true,
            ],
            'app_key' => [
                'title'    => __('App Key', 'uddoktagetway-wc'),
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => false,
            ],
            'app_secret' => [
                'title'    => __('App Secret', 'uddoktagetway-wc'),
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => false,
            ],
            'options_section' => [
                'title' => __('Payment Options', 'uddoktagetway-wc'),
                'type'  => 'title',
            ],
            'exchange_rate' => [
                'title'       => __('Currency Conversion Rate', 'uddoktagetway-wc'),
                'type'        => 'number',
                'description' => __('UddoktaGetway charges in BDT. If your store currency is not BDT, enter how many BDT equal 1 unit of your store currency. Ignored when the store currency is BDT.', 'uddoktagetway-wc'),
                'default'     => '1',
                'desc_tip'    => true,
                'custom_attributes' => ['step' => '0.0001', 'min' => '0'],
            ],
            'final_status' => [
                'title'       => __('Order Status After Payment', 'uddoktagetway-wc'),
                'type'        => 'select',
                'description' => __('"Default" lets WooCommerce decide (Processing, or Completed for virtual/downloadable orders).', 'uddoktagetway-wc'),
                'default'     => 'default',
                'desc_tip'    => true,
                'options'     => [
                    'default'   => __('Default (WooCommerce decides)', 'uddoktagetway-wc'),
                    'completed' => __('Completed', 'uddoktagetway-wc'),
                ],
            ],
            'debug' => [
                'title'       => __('Debug Log', 'uddoktagetway-wc'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'uddoktagetway-wc'),
                'default'     => 'no',
                'description' => __('Logs API requests and responses (credentials are never logged) under WooCommerce > Status > Logs.', 'uddoktagetway-wc'),
            ],
            'ipn_section' => [
                'title'       => __('IPN / Webhook (recommended)', 'uddoktagetway-wc'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: IPN URL */
                    __('Set this as the <strong>IPN URL</strong> of your store in the UddoktaGetway dashboard, so orders are completed even if the customer closes the browser before returning: <br><code>%s</code>', 'uddoktagetway-wc'),
                    esc_html($ipn_url)
                ),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * Availability
     * ------------------------------------------------------------------ */

    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }

        if ('' === $this->app_key || '' === $this->app_secret || '' === $this->api_url) {
            return false;
        }

        if ('BDT' !== get_woocommerce_currency() && $this->exchange_rate <= 0) {
            return false;
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Checkout: create the transaction and redirect the customer
     * ------------------------------------------------------------------ */

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'uddoktagetway-wc'), 'error');
            return ['result' => 'failure'];
        }

        $amount = $this->to_bdt($order->get_total());

        if ($amount <= 0) {
            wc_add_notice(__('Invalid order amount for UddoktaGetway.', 'uddoktagetway-wc'), 'error');
            return ['result' => 'failure'];
        }

        // Unique per attempt. Underscore separator avoids the gateway's "-R123" suffix stripping.
        $merchant_txn_id = 'WC' . $order->get_id() . '_' . strtolower(wp_generate_password(8, false, false));

        $callback_base = WC()->api_request_url('WC_Gateway_UddoktaGetway');
        $common_args   = [
            'wc_order' => $order->get_id(),
            'wc_key'   => $order->get_order_key(),
        ];

        // NOTE: the gateway appends its own "order_id", "status" etc. to these URLs, so we use different parameter names.
        $success_url = add_query_arg(array_merge($common_args, ['ug_action' => 'return']), $callback_base);
        $cancel_url  = add_query_arg(array_merge($common_args, ['ug_action' => 'cancel']), $callback_base);
        $fail_url    = add_query_arg(array_merge($common_args, ['ug_action' => 'fail']), $callback_base);

        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        $body = [
            'amount'                  => $amount,
            'order_id'                => (string) $order->get_order_number(),
            'merchant_transaction_id' => $merchant_txn_id,
            'customer_name'           => '' !== $name ? $name : 'Customer',
            'customer_email'          => $order->get_billing_email(),
            'customer_phone'          => $order->get_billing_phone(),
            'success_url'             => $success_url,
            'cancel_url'              => $cancel_url,
            'fail_url'                => $fail_url,
            'metadata'                => [
                'source'      => 'woocommerce',
                'wc_order_id' => $order->get_id(),
                'site'        => home_url('/'),
            ],
        ];

        $result = $this->api_request('transaction/create', $body);

        if (is_wp_error($result)) {
            $this->log('Create transaction failed for order #' . $order->get_id() . ': ' . $result->get_error_message(), 'error');
            wc_add_notice(
                /* translators: %s: error message */
                sprintf(__('Payment error: %s', 'uddoktagetway-wc'), $result->get_error_message()),
                'error'
            );
            return ['result' => 'failure'];
        }

        $data         = $result['data'];
        $redirect_url = $data['RedirectURL'] ?? ($data['data']['payment_url'] ?? '');

        if (empty($redirect_url)) {
            $this->log('No RedirectURL in response for order #' . $order->get_id() . ': ' . wp_json_encode($data), 'error');
            wc_add_notice(__('Payment error: could not start the payment session. Please try again.', 'uddoktagetway-wc'), 'error');
            return ['result' => 'failure'];
        }

        // Remember every attempt so a late IPN for an older attempt can still be matched.
        $ids   = $order->get_meta('_ugwc_merchant_txn_ids');
        $ids   = is_array($ids) ? $ids : [];
        $ids[] = $merchant_txn_id;

        $order->update_meta_data('_ugwc_merchant_txn_ids', $ids);
        $order->update_meta_data('_ugwc_merchant_txn_id', $merchant_txn_id);
        $order->update_meta_data('_ugwc_amount_bdt', number_format($amount, 2, '.', ''));
        if (!empty($data['data']['transaction_id'])) {
            $order->update_meta_data('_ugwc_gateway_txn_id', sanitize_text_field($data['data']['transaction_id']));
        }
        $order->add_order_note(
            /* translators: %s: merchant transaction id */
            sprintf(__('UddoktaGetway payment started. Reference: %s. Customer redirected to the payment page.', 'uddoktagetway-wc'), $merchant_txn_id)
        );
        $order->save();

        return [
            'result'   => 'success',
            'redirect' => esc_url_raw($redirect_url),
        ];
    }

    /* ---------------------------------------------------------------------
     * Customer returns from the gateway
     * ------------------------------------------------------------------ */

    public function handle_callback()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- external gateway redirect; authenticated by order key + server-side verification.
        $action   = isset($_GET['ug_action']) ? sanitize_key(wp_unslash($_GET['ug_action'])) : '';
        $order_id = isset($_GET['wc_order']) ? absint($_GET['wc_order']) : 0;
        $key      = isset($_GET['wc_key']) ? sanitize_text_field(wp_unslash($_GET['wc_key'])) : '';
        // phpcs:enable

        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order || '' === $key || !hash_equals((string) $order->get_order_key(), $key) || $order->get_payment_method() !== $this->id) {
            wc_add_notice(__('Invalid payment return request.', 'uddoktagetway-wc'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // Already paid (e.g. IPN arrived first) -> just show the thank-you page.
        if ($order->is_paid()) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        if ('cancel' === $action) {
            $order->add_order_note(__('UddoktaGetway: customer cancelled the payment.', 'uddoktagetway-wc'));
            wc_add_notice(__('You cancelled the payment. You can try again below.', 'uddoktagetway-wc'), 'notice');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        if ('fail' === $action) {
            // The customer can retry; only mark failed if still unpaid.
            $verification = $this->verify_order($order);
            if (!is_wp_error($verification) && $this->is_success_status($verification['status'])) {
                $this->complete_order($order, $verification);
                wp_safe_redirect($this->get_return_url($order));
                exit;
            }
            if ($order->has_status(['pending', 'failed'])) {
                $order->update_status('failed', __('UddoktaGetway: payment failed.', 'uddoktagetway-wc'));
            }
            wc_add_notice(__('Your payment was not successful. Please try again.', 'uddoktagetway-wc'), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        // Default: "return" (success URL). Never trust the browser; verify server-to-server.
        $verification = $this->verify_order($order);

        if (is_wp_error($verification)) {
            $this->log('Verify failed for order #' . $order->get_id() . ': ' . $verification->get_error_message(), 'error');
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf(__('UddoktaGetway verification error: %s', 'uddoktagetway-wc'), $verification->get_error_message())
            );
            wc_add_notice(__('We could not confirm your payment yet. If money was deducted, the order will update automatically shortly.', 'uddoktagetway-wc'), 'notice');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        if ($this->is_success_status($verification['status'])) {
            $this->complete_order($order, $verification);
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        $order->add_order_note(
            /* translators: %s: gateway status */
            sprintf(__('UddoktaGetway returned status "%s" on return. Order left unpaid.', 'uddoktagetway-wc'), $verification['status'])
        );
        wc_add_notice(__('Your payment is not confirmed yet. Please try again or contact support.', 'uddoktagetway-wc'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /* ---------------------------------------------------------------------
     * IPN (server-to-server notification from the gateway)
     * ------------------------------------------------------------------ */

    public function handle_ipn()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';

        if ('POST' !== $method) {
            wp_send_json(['status' => 'error', 'message' => 'Method not allowed. Use POST.'], 405);
        }

        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $data = wp_unslash($_POST);
        }

        $this->log('IPN received: ' . wp_json_encode($data));

        $merchant_txn_id = '';
        foreach (['merchant_transaction_id', 'MerchantTransactionId', 'transaction_id'] as $field) {
            if (!empty($data[$field]) && is_scalar($data[$field])) {
                $merchant_txn_id = sanitize_text_field((string) $data[$field]);
                break;
            }
        }

        if (!preg_match('/^WC(\d+)_[a-z0-9]+$/i', $merchant_txn_id, $m)) {
            wp_send_json(['status' => 'ignored', 'message' => 'Unrecognised transaction reference.'], 200);
        }

        $order = wc_get_order((int) $m[1]);

        if (!$order || $order->get_payment_method() !== $this->id) {
            wp_send_json(['status' => 'ignored', 'message' => 'Order not found.'], 200);
        }

        $known_ids = $order->get_meta('_ugwc_merchant_txn_ids');
        if (!is_array($known_ids) || !in_array($merchant_txn_id, $known_ids, true)) {
            wp_send_json(['status' => 'ignored', 'message' => 'Reference does not match this order.'], 200);
        }

        if ($order->is_paid()) {
            wp_send_json(['status' => 'success', 'message' => 'Already processed.', 'duplicate' => true], 200);
        }

        // Never trust the IPN body: confirm with the verify API.
        $verification = $this->verify_order($order, $merchant_txn_id);

        if (is_wp_error($verification)) {
            $this->log('IPN verify error for order #' . $order->get_id() . ': ' . $verification->get_error_message(), 'error');
            // 500 so the gateway can resend later.
            wp_send_json(['status' => 'error', 'message' => 'Verification failed.'], 500);
        }

        if (!$this->is_success_status($verification['status'])) {
            wp_send_json(['status' => 'ignored', 'message' => 'Payment not successful.'], 200);
        }

        $this->complete_order($order, $verification);

        wp_send_json(['status' => 'success', 'message' => 'Order updated.'], 200);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Ask the gateway for the real transaction status.
     *
     * @return array|WP_Error
     */
    protected function verify_order(WC_Order $order, $merchant_txn_id = '')
    {
        if ('' === $merchant_txn_id) {
            $merchant_txn_id = (string) $order->get_meta('_ugwc_merchant_txn_id');
        }

        if ('' === $merchant_txn_id) {
            return new WP_Error('ugwc_no_txn', __('No gateway reference stored for this order.', 'uddoktagetway-wc'));
        }

        $result = $this->api_request('transaction/verify', ['merchant_transaction_id' => $merchant_txn_id]);

        if (is_wp_error($result)) {
            return $result;
        }

        $d = $result['data']['data'] ?? [];

        return [
            'status'          => strtolower((string) ($d['status'] ?? ($d['payment_status'] ?? ''))),
            'amount'          => floatval($d['amount'] ?? 0),
            'total_amount'    => floatval($d['total_amount'] ?? 0),
            'transaction_id'  => (string) ($d['transaction_id'] ?? ''),
            'merchant_txn_id' => $merchant_txn_id,
            'method'          => (string) ($d['payment_method'] ?? ($d['payment_entity'] ?? '')),
        ];
    }

    protected function is_success_status($status)
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'completed', 'paid'], true);
    }

    /**
     * Mark the order paid (idempotent, with a short lock against IPN/return races).
     */
    protected function complete_order(WC_Order $order, array $verification)
    {
        $lock_key = 'ugwc_lock_' . $order->get_id();

        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 30);

        try {
            // Re-read in case another request just finished the job.
            $order = wc_get_order($order->get_id());

            if ($order->is_paid()) {
                return;
            }

            $expected = floatval($order->get_meta('_ugwc_amount_bdt'));

            if ($expected > 0 && abs($verification['amount'] - $expected) > 0.01) {
                $order->update_status(
                    'on-hold',
                    sprintf(
                        /* translators: 1: paid amount, 2: expected amount */
                        __('UddoktaGetway amount mismatch: gateway reports %1$s BDT, expected %2$s BDT. Please review manually.', 'uddoktagetway-wc'),
                        number_format($verification['amount'], 2, '.', ''),
                        number_format($expected, 2, '.', '')
                    )
                );
                $this->log('Amount mismatch on order #' . $order->get_id(), 'warning');
                return;
            }

            $txn_id = '' !== $verification['transaction_id'] ? $verification['transaction_id'] : $verification['merchant_txn_id'];

            $order->payment_complete($txn_id);
            $order->update_meta_data('_ugwc_gateway_txn_id', $txn_id);
            if ('' !== $verification['method']) {
                $order->update_meta_data('_ugwc_payment_method', $verification['method']);
            }
            $order->add_order_note(
                sprintf(
                    /* translators: 1: transaction id, 2: payment method */
                    __('UddoktaGetway payment confirmed. Transaction ID: %1$s. Method: %2$s.', 'uddoktagetway-wc'),
                    $txn_id,
                    '' !== $verification['method'] ? $verification['method'] : 'N/A'
                )
            );
            $order->save();

            if ('completed' === $this->final_status && !$order->has_status('completed')) {
                $order->update_status('completed');
            }

            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Convert the order total to BDT.
     */
    protected function to_bdt($amount)
    {
        $amount = floatval($amount);

        if ('BDT' === get_woocommerce_currency()) {
            return round($amount, 2);
        }

        return round($amount * $this->exchange_rate, 2);
    }

    /**
     * Call the gateway API.
     *
     * @return array|WP_Error  array{code:int, data:array}
     */
    protected function api_request($endpoint, array $body)
    {
        $url = $this->api_url . '/api/v1/' . ltrim($endpoint, '/');

        $response = wp_remote_post($url, [
            'timeout'     => 45,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-App-Key'    => $this->app_key,
                'X-App-Secret' => $this->app_secret,
            ],
            'body'        => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('ugwc_http', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        $this->log(sprintf('%s -> HTTP %d: %s', $endpoint, $code, $raw));

        if (!is_array($data)) {
            return new WP_Error('ugwc_bad_json', sprintf(__('Unexpected response from the payment gateway (HTTP %d).', 'uddoktagetway-wc'), $code));
        }

        if ($code < 200 || $code >= 300 || (isset($data['status']) && 'success' !== strtolower((string) $data['status']))) {
            $message = !empty($data['message']) ? wp_strip_all_tags((string) $data['message']) : sprintf(__('Gateway returned HTTP %d.', 'uddoktagetway-wc'), $code);
            return new WP_Error('ugwc_api', $message);
        }

        return ['code' => $code, 'data' => $data];
    }

    protected function log($message, $level = 'info')
    {
        if (!$this->debug) {
            return;
        }

        if (null === self::$logger) {
            self::$logger = wc_get_logger();
        }

        self::$logger->log($level, $message, ['source' => 'uddoktagetway']);
    }
}
