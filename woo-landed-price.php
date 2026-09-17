<?php
/**
 * Plugin Name: Woo Landed Price (到手价)
 * Description: 商品页显示「到手价 = 商品价 + 预估运费 + 预估税费」，含目的地切换。
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: vtetech
 * License: GPL-2.0-or-later
 * Text Domain: woo-landed-price
 * WC requires at least: 8.0
 */

defined('ABSPATH') || exit;

define('WLP_VER', '1.1.0');
define('WLP_DIR', plugin_dir_path(__FILE__));
define('WLP_URL', plugin_dir_url(__FILE__));

// ---- Settings ----
class WLP_Settings {
    private static $defaults = [
        'enabled'        => 'yes',
        'show_on'        => ['product'],
        'title'          => '到手价',
        'subtitle'       => '含运费 + 税费（按目的地估算）',
        'default_country'=> 'US',
        'allowed_countries' => 'US,CA,GB,AU,MX,DE,FR,JP,SG',
        'include_shipping'=> 'yes',
        'include_tax'    => 'yes',
        'position'       => 'before_add_to_cart',
        // 是否显示费用明细（商品价/运费/税费拆分）。
        // 默认关闭：只显示总到手价，避免对外承诺运费金额与时效。
        'show_breakdown' => 'no',
    ];

    public static function get($key = null) {
        $opts = get_option('wlp_settings', []);
        $all  = array_merge(self::$defaults, $opts);
        if ($key === null) return $all;
        return isset($all[$key]) ? $all[$key] : (isset(self::$defaults[$key]) ? self::$defaults[$key] : '');
    }

    public static function update($key, $val) {
        $opts = get_option('wlp_settings', []);
        $opts[$key] = $val;
        update_option('wlp_settings', $opts);
    }

    public static function allowed_list() {
        $raw = self::get('allowed_countries');
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}

// ---- Geo ----
class WLP_Geo {
    /** Get visitor country (from cookie override or geolocation). */
    public static function get_country() {
        $override = self::cookie_country();
        if ($override) return $override;
        $gl = self::geolocate();
        return $gl ?: WLP_Settings::get('default_country');
    }

    public static function cookie_country() {
        return isset($_COOKIE['wlp_country']) ? sanitize_text_field(wp_unslash($_COOKIE['wlp_country'])) : '';
    }

    public static function set_cookie($code) {
        $code = strtoupper(sanitize_text_field($code));
        if (!in_array($code, WLP_Settings::allowed_list(), true)) return;
        setcookie('wlp_country', $code, time() + YEAR_IN_SECONDS * 2, '/');
        $_COOKIE['wlp_country'] = $code;
    }

    private static function geolocate() {
        if (class_exists('WC_Geolocation')) {
            $geo = WC_Geolocation::geolocate_ip('', false, false);
            if (!empty($geo['country'])) return $geo['country'];
        }
        return '';
    }

    public static function country_name($code) {
        $countries = WC()->countries->get_shipping_countries();
        if (isset($countries[$code])) return $countries[$code];
        return $code;
    }
}

// ---- Calculator ----
class WLP_Calc {
    /**
     * Calculate landed price components for a product at a destination.
     *
     * @param WC_Product $product
     * @param string     $country  ISO country code.
     * @return array { price, shipping, tax, total }
     */
    public static function landed(WC_Product $product, $country) {
        $currency = get_woocommerce_currency();

        // 1. Base price (converted by CURCY if active)
        $price = (float) $product->get_price();
        if ($price <= 0) {
            $price = (float) $product->get_regular_price();
        }

        // 2. Shipping
        $shipping = 0;
        if (WLP_Settings::get('include_shipping') === 'yes') {
            $shipping = self::estimate_shipping($product, $country);
        }

        // 3. Tax
        $tax = 0;
        if (WLP_Settings::get('include_tax') === 'yes') {
            $tax = self::estimate_tax($price, $country, $product);
        }

        $total = round($price + $shipping + $tax, wc_get_price_decimals());

        return [
            'price'    => $price,
            'shipping' => $shipping,
            'tax'      => $tax,
            'total'    => $total,
            'currency' => $currency,
        ];
    }

    /**
     * Estimate shipping cost for a single product to a country.
     */
    private static function estimate_shipping(WC_Product $product, $country) {
        $package = [
            'destination' => [
                'country'  => $country,
                'state'    => '',
                'postcode' => '',
                'city'     => '',
            ],
            'contents' => [[
                'product_id' => $product->get_id(),
                'quantity'   => 1,
                'data'       => $product,
            ]],
            'contents_weight' => (float) $product->get_weight(),
            'contents_cost'   => (float) $product->get_price(),
            'cart_subtotal'   => (float) $product->get_price(),
        ];

        $zone = WC_Shipping_Zones::get_zone_matching_package($package);
        if (!$zone) return 0;

        $methods = $zone->get_shipping_methods(true); // only enabled
        if (empty($methods)) return 0;

        // Try each method, pick the cheapest positive rate
        $costs = [];
        foreach ($methods as $method) {
            try {
                $method->calculate_shipping($package);
                if (!empty($method->rates)) {
                    foreach ($method->rates as $rate) {
                        $c = (float) $rate->cost;
                        if ($c >= 0) {
                            $costs[] = $c;
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return empty($costs) ? 0 : min($costs);
    }

    /**
     * Estimate tax for a price at a country destination.
     */
    private static function estimate_tax($price, $country, $product) {
        if (!class_exists('WC_Tax')) return 0;
        $class = $product ? $product->get_tax_class() : '';

        // Save current customer location
        $cust = WC()->customer;
        $saved_country  = $cust ? $cust->get_shipping_country() : '';
        $saved_state    = $cust ? $cust->get_shipping_state() : '';
        $saved_postcode = $cust ? $cust->get_shipping_postcode() : '';
        $saved_city     = $cust ? $cust->get_shipping_city() : '';

        // Set temporary destination
        if ($cust) {
            $cust->set_location($country, '', '', '');
        }

        // Get rates using WC's internal customer location
        $rates = WC_Tax::get_rates($class);

        // Restore original location
        if ($cust) {
            $cust->set_location($saved_country, $saved_state, $saved_postcode, $saved_city);
        }

        if (empty($rates)) return 0;

        $taxes = WC_Tax::calc_tax($price, $rates, false); // false = tax exclusive
        $total = 0.0;
        foreach ($taxes as $t) {
            $total += (float) $t;
        }
        return round($total, wc_get_price_decimals());
    }
}

// ---- Frontend ----
class WLP_Frontend {
    public static function init() {
        add_filter('woocommerce_before_add_to_cart_form', [__CLASS__, 'render_above_cart'], 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_ajax_wlp_switch_country', [__CLASS__, 'ajax_switch']);
        add_action('wp_ajax_nopriv_wlp_switch_country', [__CLASS__, 'ajax_switch']);
        add_shortcode('wlp_landed_price', [__CLASS__, 'shortcode']);
    }

    public static function assets() {
        if (!is_product()) return;
        wp_enqueue_style('wlp-style', WLP_URL . 'assets/style.css', [], WLP_VER);
        wp_enqueue_script('wlp-script', WLP_URL . 'assets/script.js', ['jquery'], WLP_VER, true);
        wp_localize_script('wlp-script', 'wlpAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wlp_switch'),
        ]);
    }

    public static function render_above_cart() {
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) return;
        if (WLP_Settings::get('enabled') !== 'yes') return;
        self::render_box($product);
    }

    public static function render_box($product) {
        $country  = WLP_Geo::get_country();
        $allowed  = WLP_Settings::allowed_list();
        $current  = in_array($country, $allowed, true) ? $country : WLP_Settings::get('default_country');
        $data     = WLP_Calc::landed($product, $current);
        ?>
        <div class="wlp-box" id="wlp-box" data-product-id="<?php echo (int) $product->get_id(); ?>">
            <div class="wlp-header">
                <span class="wlp-title"><?php echo esc_html(WLP_Settings::get('title')); ?></span>
                <span class="wlp-subtitle"><?php echo esc_html(WLP_Settings::get('subtitle')); ?></span>
            </div>
            <div class="wlp-dest">
                <label><?php _e('目的地', 'woo-landed-price'); ?>：</label>
                <select id="wlp-country" class="wlp-select">
                    <?php foreach ($allowed as $code) :
                        $name = WLP_Geo::country_name($code);
                        if ($code !== $name) :
                    ?>
                        <option value="<?php echo esc_attr($code); ?>"
                            <?php selected($code, $current); ?>><?php echo esc_html($name); ?></option>
                    <?php endif; endforeach; ?>
                </select>
                <span class="wlp-flag" id="wlp-flag"><?php echo self::flag_emoji($current); ?></span>
            </div>
            <div class="wlp-breakdown" id="wlp-breakdown">
                <?php if (WLP_Settings::get('show_breakdown') === 'yes') : ?>
                <div class="wlp-row wlp-price-row">
                    <span><?php _e('商品价格', 'woo-landed-price'); ?></span>
                    <span id="wlp-price"><?php echo wc_price($data['price']); ?></span>
                </div>
                <div class="wlp-row wlp-ship-row">
                    <span><?php _e('预估运费', 'woo-landed-price'); ?></span>
                    <span id="wlp-shipping"><?php
                        echo $data['shipping'] > 0 ? wc_price($data['shipping']) : '<em>' . __('待定', 'woo-landed-price') . '</em>';
                    ?></span>
                </div>
                <div class="wlp-row wlp-tax-row">
                    <span><?php _e('预估税费', 'woo-landed-price'); ?></span>
                    <span id="wlp-tax"><?php
                        echo $data['tax'] > 0 ? wc_price($data['tax']) : '<em>' . __('无', 'woo-landed-price') . '</em>';
                    ?></span>
                </div>
                <?php endif; ?>
                <div class="wlp-row wlp-total-row">
                    <span><strong><?php _e('到手价', 'woo-landed-price'); ?></strong></span>
                    <span><strong id="wlp-total"><?php echo wc_price($data['total']); ?></strong></span>
                </div>
            </div>
            <p class="wlp-disclaimer"><?php _e('* 以上为按目的地估算，实际金额以下单时为准', 'woo-landed-price'); ?></p>
        </div>
        <?php
    }

    public static function shortcode($atts) {
        global $product;
        if (!$product) return '';
        return self::render_box($product);
    }

    public static function ajax_switch() {
        check_ajax_referer('wlp_switch', 'nonce');
        $country = isset($_POST['country']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country']))) : '';
        if (!$country) wp_send_json_error('missing country');
        WLP_Geo::set_cookie($country);
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $product    = wc_get_product($product_id);
        if (!$product) wp_send_json_error('product not found');

        $data = WLP_Calc::landed($product, $country);
        wp_send_json_success([
            'price'    => wc_price($data['price']),
            'shipping' => $data['shipping'] > 0 ? wc_price($data['shipping']) : '<em>' . __('待定', 'woo-landed-price') . '</em>',
            'tax'      => $data['tax'] > 0 ? wc_price($data['tax']) : '<em>' . __('无', 'woo-landed-price') . '</em>',
            'total'    => wc_price($data['total']),
            'flag'     => self::flag_emoji($country),
        ]);
    }

    private static function flag_emoji($country) {
        $map = [
            'US'=>'🇺🇸','CA'=>'🇨🇦','GB'=>'🇬🇧','AU'=>'🇦🇺','MX'=>'🇲🇽',
            'DE'=>'🇩🇪','FR'=>'🇫🇷','JP'=>'🇯🇵','SG'=>'🇸🇬','CN'=>'🇨🇳',
            'NZ'=>'🇳🇿','BR'=>'🇧🇷','KR'=>'🇰🇷','IN'=>'🇮🇳','IT'=>'🇮🇹',
            'ES'=>'🇪🇸','NL'=>'🇳🇱','SE'=>'🇸🇪','TH'=>'🇹🇭','MY'=>'🇲🇾',
        ];
        return $map[$country] ?? '🌍';
    }
}

// ---- Admin ----
class WLP_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_wlp_save_settings', [__CLASS__, 'save']);
    }

    public static function menu() {
        add_submenu_page(
            'woocommerce',
            '到手价设置',
            '到手价',
            'manage_woocommerce',
            'wlp-settings',
            [__CLASS__, 'page']
        );
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) wp_die('权限不足');
        if (isset($_POST['wlp_save_nonce']) && wp_verify_nonce(wp_unslash($_POST['wlp_save_nonce']), 'wlp_save')) {
            self::handle_save();
        }
        $s = WLP_Settings::get();
        ?>
        <div class="wrap">
            <h1>到手价设置</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wlp_save', 'wlp_save_nonce'); ?>
                <input type="hidden" name="action" value="wlp_save_settings">
                <table class="form-table">
                    <tr>
                        <th><label for="enabled">启用到手价</label></th>
                        <td><input type="checkbox" name="enabled" value="yes" <?php checked($s['enabled'], 'yes'); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="title">标题</label></th>
                        <td><input type="text" name="title" value="<?php echo esc_attr($s['title']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="subtitle">副标题</label></th>
                        <td><input type="text" name="subtitle" value="<?php echo esc_attr($s['subtitle']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="default_country">默认目的地</label></th>
                        <td><input type="text" name="default_country" value="<?php echo esc_attr($s['default_country']); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="allowed_countries">可用国家（逗号分隔）</label></th>
                        <td><input type="text" name="allowed_countries" value="<?php echo esc_attr($s['allowed_countries']); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th>包含运费</th>
                        <td><input type="checkbox" name="include_shipping" value="yes" <?php checked($s['include_shipping'], 'yes'); ?>></td>
                    </tr>
                    <tr>
                        <th>包含税费</th>
                        <td><input type="checkbox" name="include_tax" value="yes" <?php checked($s['include_tax'], 'yes'); ?>></td>
                    </tr>
                    <tr>
                        <th>显示费用明细</th>
                        <td>
                            <input type="checkbox" name="show_breakdown" value="yes" <?php checked($s['show_breakdown'], 'yes'); ?>>
                            <p class="description">
                                勾选后会拆分显示「商品价格 / 预估运费 / 预估税费」。<br>
                                <strong>建议关闭</strong>：只显示总到手价，避免把运费金额暴露给客户（国内直邮运费随货代报价浮动，不适合对外承诺）。
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
            <hr>
            <h2>运费状态</h2>
            <?php
            $zones = WC_Shipping_Zones::get_zones();
            if (empty($zones)) {
                echo '<p style="color:orange">⚠ 尚未配置运费区。到手价中的运费会显示「待定」直到你在 WooCommerce → 设置 → 配送 里添加运费方式。</p>';
            } else {
                echo '<table class="widefat"><thead><tr><th>区域</th><th>覆盖</th><th>启用的方式</th></tr></thead><tbody>';
                foreach ($zones as $z) {
                    $enabled = 0;
                    $total   = count($z['shipping_methods']);
                    foreach ($z['shipping_methods'] as $m) { if ($m->enabled === 'yes') $enabled++; }
                    $locations = array_map(function($l){ return $l['code']; }, $z['zone_locations']);
                    echo "<tr><td>{$z['zone_name']}</td><td>" . implode(', ', $locations) . "</td><td>{$enabled}/{$total} 启用</td></tr>";
                }
                echo '</tbody></table>';
            }
            ?>
        </div>
        <?php
    }

    private static function handle_save() {
        if (!current_user_can('manage_woocommerce')) wp_die('权限不足');
        $fields = ['title','subtitle','default_country','allowed_countries'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                WLP_Settings::update($f, sanitize_text_field(wp_unslash($_POST[$f])));
            }
        }
        foreach (['enabled','include_shipping','include_tax','show_breakdown'] as $f) {
            WLP_Settings::update($f, isset($_POST[$f]) ? 'yes' : 'no');
        }
    }
}

// ---- Activation: install default settings ----
register_activation_hook(__FILE__, function () {
    if (!get_option('wlp_settings')) {
        update_option('wlp_settings', []);
    }
});

// ---- Init ----
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;
    if (is_admin()) {
        WLP_Admin::init();
    }
    WLP_Frontend::init();
});

// WooCommerce compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
