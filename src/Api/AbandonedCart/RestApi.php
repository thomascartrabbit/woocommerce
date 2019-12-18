<?php

namespace Rnoc\Retainful\Api\AbandonedCart;

use DateTime;
use Exception;
use Rnoc\Retainful\Admin\Settings;
use Rnoc\Retainful\library\RetainfulApi;
use Rnoc\Retainful\WcFunctions;

class RestApi
{
    public static $cart, $checkout, $settings, $api, $woocommerce;
    protected $cart_token_key = "rnoc_user_cart_token", $cart_token_key_for_db = "_rnoc_user_cart_token";
    protected $user_ip_key = "rnoc_user_ip_address", $user_ip_key_for_db = "_rnoc_user_ip_address";
    protected $order_placed_date_key_for_db = "_rnoc_order_placed_at", $order_cancelled_date_key_for_db = "_rnoc_order_cancelled_at";
    protected $pending_recovery_key = "rnoc_is_pending_recovery", $pending_recovery_key_for_db = "_rnoc_is_pending_recovery";
    protected $cart_tracking_started_key = "rnoc_cart_tracking_started_at", $cart_tracking_started_key_for_db = "_rnoc_cart_tracking_started_at";
    protected $order_note_key = "rnoc_order_note", $order_note_key_for_db = "_rnoc_order_note";
    protected $order_recovered_key = "rnoc_order_recovered", $order_recovered_key_for_db = "_rnoc_order_recovered";
    protected $accepts_marketing_key_for_db = "_rnoc_is_buyer_accepts_marketing";
    protected $previous_cart_hash_key = "rnoc_previous_cart_hash";
    protected $cart_hash_key_for_db = "_rnoc_cart_hash";
    /** The cipher method name to use to encrypt the cart data */
    const CIPHER_METHOD = 'AES256';
    /** The HMAC hash algorithm to use to sign the encrypted cart data */
    const HMAC_ALGORITHM = 'sha256';

    function __construct()
    {
        self::$settings = !empty(self::$settings) ? self::$settings : new Settings();
        self::$api = !empty(self::$api) ? self::$api : new RetainfulApi();
        self::$woocommerce = !empty(self::$woocommerce) ? self::$woocommerce : new WcFunctions();
    }

    /**
     * @param $price
     * @return string
     */
    function formatDecimalPrice($price)
    {
        return round($price, self::$woocommerce->priceDecimals());
    }

    /**
     * Set the session shipping details
     * @param $shipping_address
     */
    function setSessionShippingDetails($shipping_address)
    {
        self::$woocommerce->setPHPSession('rnoc_shipping_address', $shipping_address);
    }

    /**
     * generate cart hash
     * @return string
     */
    function generateCartHash()
    {
        $cart = self::$woocommerce->getCart();
        $cart_session = array();
        if (!empty($cart)) {
            foreach ($cart as $key => $values) {
                $cart_session[$key] = $values;
                unset($cart_session[$key]['data']); // Unset product object.
            }
        }
        return $cart_session ? md5(wp_json_encode($cart_session) . self::$woocommerce->getCartTotalForEdit()) : '';
    }

    /**
     * Set the session billing details
     * @param $billing_address
     */
    function setSessionBillingDetails($billing_address)
    {
        self::$woocommerce->setPHPSession('rnoc_billing_address', $billing_address);
    }

    /**
     * Check the cart is in pending recovery
     * @param null $user_id
     * @return array|mixed|string|null
     */
    function isPendingRecovery($user_id = NULL)
    {
        if ($user_id || ($user_id = get_current_user_id())) {
            return (bool)get_user_meta($user_id, $this->pending_recovery_key_for_db, true);
        } elseif (self::$woocommerce->getPHPSession($this->pending_recovery_key)) {
            return (bool)self::$woocommerce->getPHPSession($this->pending_recovery_key);
        }
        return false;
    }

    /**
     * retrieve cart token from session
     * @param $user_id
     * @return array|mixed|string|null
     */
    function retrieveCartToken($user_id = null)
    {
        if (!empty($user_id) || $user_id = get_current_user_id()) {
            return get_user_meta($user_id, $this->cart_token_key_for_db, true);
        } else {
            return self::$woocommerce->getPHPSession($this->cart_token_key);
        }
    }

    /**
     * Recovery link to recover the user cart
     *
     * @param $cart_token
     * @return string
     */
    function getRecoveryLink($cart_token)
    {
        $data = array('cart_token' => $cart_token);
        // encode
        $data = base64_encode(wp_json_encode($data));
        // add hash for easier verification that the checkout URL hasn't been tampered with
        $hash = $this->hashTheData($data);
        $url = self::getRetainfulApiUrl();
        // returns URL like:
        // pretty permalinks enabled - https://example.com/wc-api/retainful?token=abc123&hash=xyz
        // pretty permalinks disabled - https://example.com?wc-api=retainful&token=abc123&hash=xyz
        return esc_url_raw(add_query_arg(array('token' => rawurlencode($data), 'hash' => $hash), $url));
    }

    /**
     * Return the WC API URL for handling Retainful recovery links by accounting
     * for whether pretty permalinks are enabled or not.
     *
     * @return string
     * @since 1.1.0
     */
    private static function getRetainfulApiUrl()
    {
        $scheme = wc_site_is_https() ? 'https' : 'http';
        return get_option('permalink_structure')
            ? get_home_url(null, 'wc-api/retainful', $scheme)
            : add_query_arg('wc-api', 'retainful', get_home_url(null, null, $scheme));
    }

    /**
     * Hash the data
     * @param $data
     * @return false|string
     */
    function hashTheData($data)
    {
        $secret = self::$settings->getSecretKey();
        return hash_hmac(self::HMAC_ALGORITHM, $data, $secret);
    }

    /**
     * Get the client IP address
     * @return mixed|string
     */
    function getClientIp()
    {
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $client_ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $client_ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $client_ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $client_ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $client_ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $client_ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $client_ip = '';
        }
        return $client_ip;
    }

    /**
     * retrieve User IP address
     * @param null $user_id
     * @return array|mixed|string|null
     */
    function retrieveUserIp($user_id = NULL)
    {
        if ($user_id) {
            $ip = get_user_meta($user_id, $this->user_ip_key_for_db);
        } else {
            $ip = self::$woocommerce->getPHPSession($this->user_ip_key);
        }
        return $this->formatUserIP($ip);
    }

    /**
     * Sometimes the IP address returne is not formatted quite well.
     * So it requires a basic formating.
     * @param $ip
     * @return String
     */
    function formatUserIP($ip)
    {
        //check for commas in the IP
        $ip = trim(current(preg_split('/,/', sanitize_text_field(wp_unslash($ip)))));
        return (string)$ip;
    }

    /**
     * generate the random cart token
     * @return string
     */
    function generateCartToken()
    {
        try {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
            $token = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (Exception $e) {
            // fall back to mt_rand if random_bytes is unavailable
            $token = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                // 32 bits for "time_low"
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                // 16 bits for "time_mid"
                mt_rand(0, 0xffff),
                // 16 bits for "time_hi_and_version",
                // four most significant bits holds version number 4
                mt_rand(0, 0x0fff) | 0x4000,
                // 16 bits, 8 bits for "clk_seq_hi_res",
                // 8 bits for "clk_seq_low",
                // two most significant bits holds zero and one for variant DCE1.1
                mt_rand(0, 0x3fff) | 0x8000,
                // 48 bits for "node"
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
        return md5($token);
    }

    /**
     * Check that the order status is valid to clear temp data
     * @param $order_status
     * @return bool
     */
    function isValidOrderStatusToResetCartToken($order_status)
    {
        $to_clear_order_status = apply_filters('rnoc_to_clear_temp_data_order_status', array('failed', 'pending'));
        return in_array($order_status, $to_clear_order_status);
    }

    /**
     * Check the order has valid order statuses
     * @param $order_status
     * @return bool
     */
    function isOrderHasValidOrderStatus($order_status)
    {
        $valid_order_status = apply_filters('rnoc_abandoned_cart_invalid_order_statuses', array('pending', 'failed'));
        $consider_on_hold_order_as_ac = $this->considerOnHoldAsAbandoned();
        if ($consider_on_hold_order_as_ac == 1) {
            $valid_order_status[] = 'on-hold';
        }
        $valid_order_status = array_unique($valid_order_status);
        return (!in_array($order_status, $valid_order_status));
    }

    /**
     * Checks whether an order is pending recovery.
     *
     * @param int|string $order_id order ID
     * @return bool
     * @since 2.1.0
     *
     */
    public function isOrderInPendingRecovery($order_id)
    {
        $order = self::$woocommerce->getOrder($order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }
        return (bool)get_post_meta($order_id, $this->pending_recovery_key_for_db, true);
    }

    /**
     * Checks whether an order is recovered.
     * @param int|string $order_id order ID
     * @return bool
     */
    public function isOrderRecovered($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }
        return (bool)get_post_meta($order_id, $this->order_recovered_key_for_db, true);
    }

    /**
     * Mark order as recovered
     * @param $order_id
     */
    function markOrderAsRecovered($order_id)
    {
        $order = self::$woocommerce->getOrder($order_id);
        if (!$order instanceof \WC_Order || $this->isOrderRecovered($order_id)) {
            return;
        }
        self::$woocommerce->deleteOrderMeta($order_id, $this->pending_recovery_key_for_db);
        self::$woocommerce->setOrderMeta($order_id, $this->order_recovered_key_for_db, true);
        self::$woocommerce->setOrderNote($order_id, __('Order recovered by Retainful.', RNOC_TEXT_DOMAIN));
        do_action('rnoc_abandoned_order_recovered', $order);
    }

    /**
     * Consider on hold payment as abandoned
     * @return int
     */
    function considerOnHoldAsAbandoned()
    {
        $settings = self::$settings->getAdminSettings();
        return isset($settings[RNOC_PLUGIN_PREFIX . 'consider_on_hold_as_abandoned_status']) ? $settings[RNOC_PLUGIN_PREFIX . 'consider_on_hold_as_abandoned_status'] : 0;
    }

    /**
     * Format the date to ISO8601
     * @param $timestamp
     * @return string|null
     */
    function formatToIso8601($timestamp)
    {
        if (empty($timestamp)) {
            $timestamp = current_time('timestamp', true);
        }
        try {
            $date = date('Y-m-d H:i:s', $timestamp);
            $date_time = new DateTime($date);
            return $date_time->format(DateTime::ATOM);
        } catch (Exception $e) {
            return NULL;
        }
    }

    /**
     * Convert price to another price as per currency rate
     * @param $price
     * @param $rate
     * @return float|int
     */
    function convertToCurrency($price, $rate)
    {
        if (!empty($price) && !empty($rate)) {
            return $price / $rate;
        }
        return $price;
    }

    /**
     * Encrypt the cart
     * @param $data
     * @param $secret
     * @return array
     */
    function encryptData($data, $secret = NULL)
    {
        if (extension_loaded('openssl')) {
            if (is_array($data) || is_object($data)) {
                $data = wp_json_encode($data);
            }
            try {
                if (empty($secret)) {
                    $secret = self::$settings->getSecretKey();
                }
                $iv_len = openssl_cipher_iv_length(self::CIPHER_METHOD);
                $iv = openssl_random_pseudo_bytes($iv_len);
                $cipher_text_raw = openssl_encrypt($data, self::CIPHER_METHOD, $secret, OPENSSL_RAW_DATA, $iv);
                $hmac = hash_hmac(self::HMAC_ALGORITHM, $cipher_text_raw, $secret, true);
                $encrypt = base64_encode(bin2hex($iv) . ':retainful:' . bin2hex($hmac) . ':retainful:' . bin2hex($cipher_text_raw));
                #$this->decryptCart($encrypt);
                return $encrypt;
            } catch (Exception $e) {
                return NULL;
            }
        }
        return NULL;
    }

    /**
     * Decrypt the user cart
     * @param $data_hash
     * @return string
     */
    function decryptData($data_hash)
    {
        $secret = self::$settings->getSecretKey();
        $string = base64_decode($data_hash);
        list($iv, $hmac, $cipher_text_raw) = explode(':retainful:', $string);
        $reverse_hmac = hash_hmac(self::HMAC_ALGORITHM, $cipher_text_raw, $secret, true);
        if (hash_equals($reverse_hmac, $hmac)) {
            return openssl_decrypt($cipher_text_raw, self::CIPHER_METHOD, $secret, OPENSSL_RAW_DATA, $iv);
        }
        return NULL;
    }

    /**
     * get the active currency code
     * @return String|null
     */
    function getCurrentCurrencyCode()
    {
        $default_currency = self::$settings->getBaseCurrency();
        return apply_filters('rnoc_get_current_currency_code', $default_currency);
    }

    /**
     * Get the date of cart tracing started
     * @param $user_id
     * @return array|mixed|string|null
     */
    function userCartCreatedAt($user_id = NULL)
    {
        if ($user_id || $user_id = get_current_user_id()) {
            $cart_created_at = get_user_meta($user_id, $this->cart_tracking_started_key_for_db, true);
        } else {
            $cart_created_at = self::$woocommerce->getPHPSession($this->cart_tracking_started_key);
        }
        return $cart_created_at;
    }

    /**
     * Synchronize cart with SaaS
     * @param $cart_details
     * @return array|bool|mixed|object|string
     */
    function syncCart($cart_details)
    {
        $app_id = self::$settings->getApiKey();
        $response = false;
        if (!empty($cart_details)) {
            $response = self::$api->syncCartDetails($app_id, $cart_details);
        }
        return $response;
    }

    /**
     * Check is buyer accepts marketing
     * @return bool
     */
    function isBuyerAcceptsMarketing()
    {
        if (is_user_logged_in()) {
            return true;
        } else {
            $is_buyer_accepts_marketing = self::$woocommerce->getPHPSession('is_buyer_accepting_marketing');
            if ($is_buyer_accepts_marketing == 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * need to track carts or not
     * @param string $ip_address
     * @return bool
     */
    function canTrackAbandonedCarts($ip_address = NULL)
    {
        if (!apply_filters('rnoc_can_track_abandoned_carts', true, $ip_address)) {
            return false;
        }
        return true;
    }
}