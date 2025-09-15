<?php
/**
 * Plugin Name: WooCommerce → MailerLite: Product-Named Segment on Payment Complete
 * Description: When a WooCommerce order's payment is completed, add the customer to a MailerLite segment (group) named exactly the same as each product in the order. If the segment does not exist, create it automatically.
 * Version: 1.0.0
 * Author: Lee Carter
 * Author URI: https://example.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-mlite-product-segments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WC_MLite_Product_Segments' ) ) :
class WC_MLite_Product_Segments {
	const OPTION_KEY    = 'wc_mlite_product_segments_settings';
	const CACHE_KEY_MAP = 'wc_mlite_group_name_to_id_map'; // transient cache key

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init() {
		// HPOS compatibility
		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} );

		// Settings UI
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );

		// Core trigger: payment complete
		add_action( 'woocommerce_payment_complete', [ $this, 'handle_payment_complete' ], 20, 1 );
	}

	/* ------------------------ Admin Settings ------------------------ */
	public static function get_settings() {
		$defaults = [
			'api_key'        => '',
			'api_base'       => 'https://connect.mailerlite.com/api', // Official v2 base
			'enable_logging' => false,
		];
		return wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), $defaults );
	}

	public function register_settings() {
		register_setting( 'wc_mlite_product_segments', self::OPTION_KEY );

		add_settings_section( 'wc_mlite_section_main', __( 'MailerLite Connection', 'wc-mlite-product-segments' ), function() {
			echo '<p>' . esc_html__( 'Enter your MailerLite API key. On each payment completion, buyers will be added to segments (MailerLite "Groups") named after each product in the order.', 'wc-mlite-product-segments' ) . '</p>';
		}, 'wc_mlite_product_segments' );

		add_settings_field( 'api_key', __( 'API Key', 'wc-mlite-product-segments' ), [ $this, 'field_api_key' ], 'wc_mlite_product_segments', 'wc_mlite_section_main' );
		add_settings_field( 'enable_logging', __( 'Enable Logging', 'wc-mlite-product-segments' ), [ $this, 'field_enable_logging' ], 'wc_mlite_product_segments', 'wc_mlite_section_main' );
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'MailerLite Product Segments', 'wc-mlite-product-segments' ),
			__( 'MailerLite Segments', 'wc-mlite-product-segments' ),
			'manage_woocommerce',
			'wc-mlite-product-segments',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce → MailerLite: Product-Named Segments', 'wc-mlite-product-segments' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wc_mlite_product_segments' ); ?>
				<?php do_settings_sections( 'wc_mlite_product_segments' ); ?>
				<?php submit_button(); ?>
			</form>
			<hr/>
			<h2><?php esc_html_e( 'How it works', 'wc-mlite-product-segments' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'On payment complete, each product in the order is mapped to a MailerLite segment (API: Group) with the exact product name.', 'wc-mlite-product-segments' ); ?></li>
				<li><?php esc_html_e( 'If the segment does not exist, it is created automatically.', 'wc-mlite-product-segments' ); ?></li>
				<li><?php esc_html_e( 'The customer (order billing email) is added to each segment.', 'wc-mlite-product-segments' ); ?></li>
			</ol>
			<p><em><?php esc_html_e( 'Note: In the MailerLite API, these are called “Groups”. In the MailerLite UI they appear as static segments you can target in campaigns/automations.', 'wc-mlite-product-segments' ); ?></em></p>
		</div>
		<?php
	}

	public function field_api_key() {
		$opts = self::get_settings();
		?>
		<input type="password" style="width:420px" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $opts['api_key'] ); ?>" placeholder="<?php esc_attr_e( 'MailerLite API key', 'wc-mlite-product-segments' ); ?>" />
		<?php
	}

	public function field_enable_logging() {
		$opts = self::get_settings();
		?>
		<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_logging]" value="1" <?php checked( ! empty( $opts['enable_logging'] ) ); ?> /> <?php esc_html_e( 'Write debug messages to WooCommerce logs', 'wc-mlite-product-segments' ); ?></label>
		<?php
	}

	/* ------------------------ Core Logic ------------------------ */
	public function handle_payment_complete( $order_id ) {
		$settings = self::get_settings();
		$api_key  = trim( (string) $settings['api_key'] );
		if ( $api_key === '' ) { return; }

		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) { return; }

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$products = $this->get_distinct_product_names_from_order( $order );
		if ( empty( $products ) ) { return; }

		$added_any = false;
		foreach ( $products as $product_name ) {
			$group_id = $this->ensure_group_exists( $api_key, $product_name );
			if ( $group_id ) {
				$ok = $this->add_email_to_group( $api_key, $email, $customer_name, $group_id );
				$this->log( sprintf( 'Order %d: %s %s group "%s"', $order_id, $email, $ok ? '→ added to' : '→ FAILED for', $product_name ) );
				if ( $ok ) { $added_any = true; }
			} else {
				$this->log( sprintf( 'Order %d: could not ensure group for "%s"', $order_id, $product_name ) );
			}
		}

		if ( $added_any ) {
			$order->add_order_note( __( 'MailerLite: customer added to product-named segments on payment complete.', 'wc-mlite-product-segments' ) );
		}
	}

	private function get_distinct_product_names_from_order( WC_Order $order ) {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
			$product = $item->get_product();
			if ( ! $product ) { continue; }
			$names[] = (string) $product->get_name();
		}
		$names = array_filter( array_unique( array_map( 'trim', $names ) ) );
		return array_values( $names );
	}

	/* ------------------------ MailerLite API (v2) ------------------------ */
	private function api_base() {
		$opts = self::get_settings();
		$base = rtrim( $opts['api_base'], '/' );
		return $base ?: 'https://connect.mailerlite.com/api';
	}

	/**
	 * Ensure a MailerLite group exists with the given name. Returns group ID or false.
	 */
	private function ensure_group_exists( $api_key, $group_name ) {
		$group_name = trim( $group_name );
		if ( $group_name === '' ) { return false; }

		// Try cache first
		$cache = get_transient( self::CACHE_KEY_MAP );
		if ( is_array( $cache ) && isset( $cache[ $group_name ] ) ) {
			return $cache[ $group_name ];
		}

		// 1) Try to create straight away (idempotent-ish): if exists, API may return 409.
		$create = $this->request( 'POST', '/groups', $api_key, [ 'name' => $group_name ] );
		if ( $create && in_array( $create['code'], [200,201], true ) && ! empty( $create['json']['data']['id'] ) ) {
			$group_id = (string) $create['json']['data']['id'];
			$this->cache_group( $group_name, $group_id );
			return $group_id;
		}
		if ( $create && (int) $create['code'] === 409 ) {
			// Exists — fall through to lookup by name
		}

		// 2) List groups and find by name
		$group_id = $this->find_group_id_by_name( $api_key, $group_name );
		if ( $group_id ) {
			$this->cache_group( $group_name, $group_id );
			return $group_id;
		}
		return false;
	}

	private function cache_group( $name, $id ) {
		$map = get_transient( self::CACHE_KEY_MAP );
		if ( ! is_array( $map ) ) { $map = []; }
		$map[ $name ] = (string) $id;
		set_transient( self::CACHE_KEY_MAP, $map, HOUR_IN_SECONDS );
	}

	private function find_group_id_by_name( $api_key, $needle ) {
		$needle = (string) $needle;
		$after  = null;
		for ( $i = 0; $i < 20; $i++ ) { // paginate up to ~20 pages as a safety cap
			$params = [ 'limit' => 200 ];
			if ( $after ) { $params['cursor'] = $after; }
			$res = $this->request( 'GET', '/groups', $api_key, [], $params );
			if ( ! $res || (int) $res['code'] !== 200 || empty( $res['json']['data'] ) ) { break; }
			foreach ( (array) $res['json']['data'] as $g ) {
				if ( isset( $g['name'] ) && (string) $g['name'] === $needle && ! empty( $g['id'] ) ) {
					return (string) $g['id'];
				}
			}
			$after = isset( $res['json']['links']['next']) ? $res['json']['links']['next'] : null; // optimistic cursor support
			if ( ! $after ) { break; }
		}
		return false;
	}

	/**
	 * Add (or upsert) an email to a group. If the subscriber exists, ensure they belong to the group.
	 */
	private function add_email_to_group( $api_key, $email, $name, $group_id ) {
		// First try creating/upserting the subscriber with the target group directly
		$payload = [
			'email'  => $email,
			'fields' => [ 'name' => $name ],
			'groups' => [ (string) $group_id ],
		];
		$res = $this->request( 'POST', '/subscribers', $api_key, $payload );
		if ( $res && in_array( (int) $res['code'], [200,201,202], true ) ) {
			return true;
		}
		if ( $res && (int) $res['code'] === 409 ) {
			// Subscriber exists — fetch their ID and attach to group
			$sub_id = $this->get_subscriber_id_by_email( $api_key, $email );
			if ( $sub_id ) {
				$attach = $this->request( 'POST', '/subscribers/' . rawurlencode( $sub_id ) . '/groups', $api_key, [ 'groups' => [ (string) $group_id ] ] );
				if ( $attach && in_array( (int) $attach['code'], [200,201,202], true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function get_subscriber_id_by_email( $api_key, $email ) {
		// Try a filtered list (API commonly supports filtering by email exactly)
		$params = [ 'filter[email]' => $email, 'limit' => 1 ];
		$res = $this->request( 'GET', '/subscribers', $api_key, [], $params );
		if ( $res && (int) $res['code'] === 200 && ! empty( $res['json']['data'][0]['id'] ) ) {
			return (string) $res['json']['data'][0]['id'];
		}
		return false;
	}

	/* ------------------------ HTTP Helper ------------------------ */
	private function request( $method, $path, $api_key, $body = [], $query = [] ) {
		$url = $this->api_base() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
		];
		if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );
		$this->log( sprintf( 'API %s %s → %d %s', $method, $path, $code, is_string( $raw ) ? substr( $raw, 0, 300 ) : '' ) );
		return [ 'code' => $code, 'json' => is_array( $json ) ? $json : [] ];
	}

	private function log( $msg ) {
		$opts = self::get_settings();
		if ( empty( $opts['enable_logging'] ) ) { return; }
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( '[WC→MLITE] ' . $msg, [ 'source' => 'wc-mlite-product-segments' ] );
		} else {
			error_log( '[WC→MLITE] ' . $msg );
		}
	}
}
endif;

add_action( 'plugins_loaded', function() {
	new WC_MLite_Product_Segments();
} );

/**
 * IMPORTANT NOTES
 * - MailerLite’s public API refers to static audience containers as “Groups”. In the UI these are often surfaced as segments you can target. This plugin creates/uses Groups via the official v2 API at connect.mailerlite.com.
 * - Ensure you have consent to add customers to your mailing list per your privacy policy and local laws.
 */
