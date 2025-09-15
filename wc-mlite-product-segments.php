<?php
/**
 * Plugin Name: WooCommerce MailerLite Product Segments
 * Plugin URI: https://github.com/Nightsun1973/woo_mailerlite-product_cust-upoad
 * Description: Add customers to product-specific MailerLite groups on purchase.
 * Version: 1.0.0
 * Author: Nightsun1973
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-mlite-product-segments
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Main WC MailerLite Product Segments Class
 */
class WC_MailerLite_Product_Segments {
    
    /**
     * Plugin version
     */
    const VERSION = '1.0.0';
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('woocommerce_order_status_completed', array($this, 'process_completed_order'));
        add_action('woocommerce_payment_complete', array($this, 'process_payment_complete'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Product meta fields
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_mlite_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_mlite_fields'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('wc-mlite-product-segments', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Process completed order
     */
    public function process_completed_order($order_id) {
        $this->add_customer_to_segments($order_id);
    }
    
    /**
     * Process payment complete
     */
    public function process_payment_complete($order_id) {
        $this->add_customer_to_segments($order_id);
    }
    
    /**
     * Add customer to MailerLite segments based on purchased products
     */
    private function add_customer_to_segments($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $customer_email = $order->get_billing_email();
        
        if (empty($customer_email)) {
            return;
        }
        
        // Get MailerLite API key
        $api_key = get_option('wc_mlite_api_key', '');
        
        if (empty($api_key)) {
            error_log('WC MailerLite Product Segments: API key not configured');
            return;
        }
        
        // Get order items
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $mlite_group_id = get_post_meta($product_id, '_mlite_group_id', true);
            
            if (!empty($mlite_group_id)) {
                $this->add_subscriber_to_group($customer_email, $mlite_group_id, $api_key, $order);
            }
        }
    }
    
    /**
     * Add subscriber to MailerLite group
     */
    private function add_subscriber_to_group($email, $group_id, $api_key, $order) {
        $subscriber_data = array(
            'email' => $email,
            'fields' => array(
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'country' => $order->get_billing_country(),
                'city' => $order->get_billing_city(),
                'phone' => $order->get_billing_phone(),
                'state' => $order->get_billing_state(),
                'z_i_p' => $order->get_billing_postcode(),
            )
        );
        
        // Remove empty fields
        $subscriber_data['fields'] = array_filter($subscriber_data['fields']);
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => json_encode($subscriber_data),
            'timeout' => 30,
        );
        
        $response = wp_remote_post("https://connect.mailerlite.com/api/subscribers", $args);
        
        if (is_wp_error($response)) {
            error_log('WC MailerLite Product Segments: Failed to add subscriber - ' . $response->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            error_log('WC MailerLite Product Segments: API error - ' . $response_code . ' - ' . $response_body);
            return;
        }
        
        $subscriber_response = json_decode($response_body, true);
        
        if (isset($subscriber_response['data']['id'])) {
            $subscriber_id = $subscriber_response['data']['id'];
            $this->assign_subscriber_to_group($subscriber_id, $group_id, $api_key);
        }
    }
    
    /**
     * Assign subscriber to group
     */
    private function assign_subscriber_to_group($subscriber_id, $group_id, $api_key) {
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout' => 30,
        );
        
        $response = wp_remote_post("https://connect.mailerlite.com/api/subscribers/{$subscriber_id}/groups/{$group_id}", $args);
        
        if (is_wp_error($response)) {
            error_log('WC MailerLite Product Segments: Failed to assign to group - ' . $response->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            $response_body = wp_remote_retrieve_body($response);
            error_log('WC MailerLite Product Segments: Group assignment error - ' . $response_code . ' - ' . $response_body);
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('MailerLite Product Segments', 'wc-mlite-product-segments'),
            __('MailerLite Product Segments', 'wc-mlite-product-segments'),
            'manage_options',
            'wc-mlite-product-segments',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_mlite_product_segments', 'wc_mlite_api_key');
        register_setting('wc_mlite_product_segments', 'wc_mlite_debug_mode');
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('MailerLite Product Segments Settings', 'wc-mlite-product-segments'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_mlite_product_segments'); ?>
                <?php do_settings_sections('wc_mlite_product_segments'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('MailerLite API Key', 'wc-mlite-product-segments'); ?></th>
                        <td>
                            <input type="text" name="wc_mlite_api_key" value="<?php echo esc_attr(get_option('wc_mlite_api_key')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Enter your MailerLite API key. You can find it in your MailerLite account settings.', 'wc-mlite-product-segments'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Debug Mode', 'wc-mlite-product-segments'); ?></th>
                        <td>
                            <input type="checkbox" name="wc_mlite_debug_mode" value="1" <?php checked(1, get_option('wc_mlite_debug_mode'), true); ?> />
                            <label for="wc_mlite_debug_mode"><?php _e('Enable debug logging', 'wc-mlite-product-segments'); ?></label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Add product MailerLite fields
     */
    public function add_product_mlite_fields() {
        global $woocommerce, $post;
        
        echo '<div class="options_group">';
        
        woocommerce_wp_text_input(array(
            'id' => '_mlite_group_id',
            'label' => __('MailerLite Group ID', 'wc-mlite-product-segments'),
            'placeholder' => __('Enter MailerLite Group ID', 'wc-mlite-product-segments'),
            'desc_tip' => 'true',
            'description' => __('Enter the MailerLite Group ID that customers should be added to when they purchase this product.', 'wc-mlite-product-segments'),
        ));
        
        echo '</div>';
    }
    
    /**
     * Save product MailerLite fields
     */
    public function save_product_mlite_fields($post_id) {
        $mlite_group_id = $_POST['_mlite_group_id'];
        
        if (!empty($mlite_group_id)) {
            update_post_meta($post_id, '_mlite_group_id', sanitize_text_field($mlite_group_id));
        } else {
            delete_post_meta($post_id, '_mlite_group_id');
        }
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        // Enqueue any frontend scripts if needed
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables or perform activation tasks if needed
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function wc_mailerlite_product_segments_init() {
    WC_MailerLite_Product_Segments::get_instance();
}

add_action('plugins_loaded', 'wc_mailerlite_product_segments_init');

/**
 * Plugin activation hook
 */
function wc_mailerlite_product_segments_activate() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-mlite-product-segments'));
    }
}

register_activation_hook(__FILE__, 'wc_mailerlite_product_segments_activate');