<?php
/**
 * Plugin Name: WooCommerce → MailerLite: Product-Named Segment on Order Processing
 * Description: When a WooCommerce order moves to the "processing" status, add the customer to a MailerLite segment (group) named exactly the same as each product in the order. If the segment does not exist, create it automatically.
 * Version: 2.0.0
 * Author: Lee Caer
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

		// Core trigger: when order becomes processing
		add_action( 'woocommerce_order_status_processing', [ $this, 'handle_payment_complete' ], 20, 1 );

		// AJAX test connection
		add_action( 'wp_ajax_wc_mlite_test_connection', [ $this, 'ajax_test_connection' ] );

		// Add a direct Settings link on the Plugins page
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

		// Admin diagnostic: show capability and plugin blockers when ?mlite_debug=1
		add_action( 'admin_notices', [ $this, 'maybe_show_admin_debug' ] );
		// Also add an admin bar indicator when debug is requested
		add_action( 'admin_bar_menu', [ $this, 'maybe_add_admin_bar_debug' ], 100 );
		// Add admin bar capability display for the current user
		add_action( 'admin_bar_menu', [ $this, 'add_capabilities_to_admin_bar' ], 80 );
		// Inject full console debug payload into admin footer for admins (sanitised)
		add_action( 'admin_footer', [ $this, 'maybe_print_full_console_debug' ] );
		// Dashboard widget for plugin status/log
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
		// AJAX to clear dashboard log
		add_action( 'wp_ajax_wc_mlite_clear_dashboard_log', [ $this, 'ajax_clear_dashboard_log' ] );
	}

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		wp_add_dashboard_widget( 'wc_mlite_dashboard', __( 'Woo → MailerLite', 'wc-mlite-product-segments' ), [ $this, 'render_dashboard_widget' ] );
	}

	public function render_dashboard_widget() {
		$opts = self::get_settings();
		$safe_opts = $opts;
		if ( ! empty( $safe_opts['api_key'] ) ) { $safe_opts['api_key'] = '[REDACTED]'; }
		$last = get_option( 'wc_mlite_debug_last', null );
		$map = get_transient( self::CACHE_KEY_MAP );
		$messages = get_transient( 'wc_mlite_recent_messages' );
		if ( ! is_array( $messages ) ) { $messages = []; }
		?>
		<div>
			<p><strong><?php esc_html_e( 'Settings (redacted):', 'wc-mlite-product-segments' ); ?></strong></p>
			<pre style="white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $safe_opts ) ); ?></pre>
			<p><strong><?php esc_html_e( 'Last debug payload:', 'wc-mlite-product-segments' ); ?></strong></p>
			<pre style="white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $last ) ); ?></pre>
			<p><strong><?php esc_html_e( 'Group cache (sample):', 'wc-mlite-product-segments' ); ?></strong></p>
			<pre style="white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( is_array( $map ) ? array_slice( $map, 0, 20, true ) : $map ) ); ?></pre>
			<p><strong><?php esc_html_e( 'Recent messages:', 'wc-mlite-product-segments' ); ?></strong></p>
			<ul>
				<?php foreach ( $messages as $m ) : ?>
					<li><?php echo esc_html( $m ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button id="wc-mlite-clear-log" class="button button-secondary"><?php esc_html_e( 'Clear log', 'wc-mlite-product-segments' ); ?></button>
				<span id="wc-mlite-clear-result" style="margin-left:12px"></span>
			</p>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('wc-mlite-clear-log');
			if (!btn) return;
			btn.addEventListener('click', function(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try{ var r = JSON.parse(xhr.responseText); console.info('WC→MLITE clear log response:', r); }
					catch(e){ console.error('WC→MLITE clear log parse error', e, xhr.responseText); }
					var out = document.getElementById('wc-mlite-clear-result');
					if (!out) return;
					if (xhr.status === 200) out.textContent = 'Cleared'; else out.textContent = 'Failed';
				};
				xhr.send('action=wc_mlite_clear_dashboard_log&nonce=' + encodeURIComponent('<?php echo esc_js( wp_create_nonce( 'wc_mlite_clear_dashboard_log' ) ); ?>'));
			});
		})();
		</script>
		<?php
	}

	public function ajax_clear_dashboard_log() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => 'forbidden' ], 403 ); }
		check_ajax_referer( 'wc_mlite_clear_dashboard_log', 'nonce' );
		delete_transient( 'wc_mlite_recent_messages' );
		wp_send_json_success( [ 'message' => 'cleared' ] );
	}

	/**
	 * Print a sanitised debug payload to the browser console for administrators.
	 * This is intended to work even when admin notices are blocked by plugins or WAF.
	 */
	public function maybe_print_full_console_debug() {
		if ( ! is_admin() ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$opts = self::get_settings();
		// Only emit when mlite_debug flag is present or verbose logging is enabled
		if ( empty( $_REQUEST['mlite_debug'] ) && empty( $opts['enable_verbose_logging'] ) ) { return; }
		// Read persisted last debug payload and cache map
		$last = get_option( 'wc_mlite_debug_last', null );
		$map = get_transient( self::CACHE_KEY_MAP );
		// Redact sensitive fields
		$safe_opts = $opts;
		if ( isset( $safe_opts['api_key'] ) && $safe_opts['api_key'] !== '' ) {
			$safe_opts['api_key'] = '[REDACTED]';
		}
		// Build payload
		$payload = [
			'site' => [ 'url' => get_site_url(), 'home' => home_url() ],
			'user' => wp_get_current_user() ? [ 'id' => (int) wp_get_current_user()->ID, 'login' => wp_get_current_user()->user_login ] : null,
			'settings' => $safe_opts,
			'last_debug' => $last,
			'group_cache' => is_array( $map ) ? $map : new stdClass(),
			'time' => current_time( 'mysql' ),
		];
		// Print to console
		echo '<script>console.info("MLite full debug:", ' . wp_json_encode( $payload ) . ');</script>';
	}

	public function maybe_show_admin_debug() {
		if ( ! is_admin() ) { return; }
		// Accept GET/POST/COOKIE (REQUEST) so the flag works in more cases
		if ( empty( $_REQUEST['mlite_debug'] ) || ! current_user_can( 'manage_options' ) ) { return; }
		// Gather diagnostics
		$user = wp_get_current_user();
		$roles = $user->roles;
		$can_manage_options = current_user_can( 'manage_options' ) ? 'yes' : 'no';
		$can_manage_woocommerce = current_user_can( 'manage_woocommerce' ) ? 'yes' : 'no';
		$wordfence_active = class_exists( 'Wordfence' ) || function_exists( 'wordfence' ) || defined( 'WORDFENCE_VERSION' ) ? 'yes' : 'no';
		$settings_url = esc_url( admin_url( 'admin.php?page=wc-mlite-product-segments' ) );

		// Persist a short-lived option so site owners can verify the plugin code executed even if UI is blocked
		$debug_payload = [
			'user' => $user->user_login,
			'user_id' => (int) $user->ID,
			'roles' => $roles,
			'time' => current_time( 'mysql' ),
			'wordfence' => $wordfence_active,
			'url' => ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ),
		];
		update_option( 'wc_mlite_debug_last', $debug_payload );
		echo '<div class="notice notice-info"><p><strong>Woo→MailerLite debug</strong></p>';
		echo '<p>User: ' . esc_html( $user->user_login ) . ' (ID ' . (int) $user->ID . ')</p>';
		echo '<p>Roles: ' . esc_html( implode( ', ', $roles ) ) . '</p>';
		echo '<p>manage_options: ' . esc_html( $can_manage_options ) . '</p>';
		echo '<p>manage_woocommerce: ' . esc_html( $can_manage_woocommerce ) . '</p>';
		echo '<p>Wordfence detected: ' . esc_html( $wordfence_active ) . '</p>';
		echo '<p>Settings URL: <a href="' . $settings_url . '">' . $settings_url . '</a></p>';
		echo '</div>';
		// Also emit payload to browser console for convenience
		echo '<script>console.info("MLite debug payload:", ' . wp_json_encode( $debug_payload ) . ');</script>';
	}

	/**
	 * Add a small admin-bar item when ?mlite_debug=1 is set to make the diagnostic obvious.
	 */
	public function maybe_add_admin_bar_debug( $wp_admin_bar ) {
		if ( ! is_admin() ) { return; }
		if ( empty( $_REQUEST['mlite_debug'] ) || ! current_user_can( 'manage_options' ) ) { return; }
		if ( ! is_object( $wp_admin_bar ) ) { return; }
		$args = array(
			'id'    => 'wc-mlite-debug',
			'title' => 'MLite debug enabled',
			'href'  => admin_url( 'admin.php?page=wc-mlite-product-segments&mlite_debug=1' ),
			'meta'  => array( 'title' => 'Open Woo to Mailerlite settings (debug)')
		);
		$wp_admin_bar->add_node( $args );
	}

	/**
	 * Add current user capability summary and details to the admin bar.
	 */
	public function add_capabilities_to_admin_bar( $wp_admin_bar ) {
		if ( ! is_admin() && ! is_user_logged_in() ) { return; }
		if ( ! is_object( $wp_admin_bar ) ) { return; }
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) { return; }
		// Build a short title e.g., "MLite caps: manage_options, edit_posts"
		$caps = array_keys( $user->allcaps );
		$show = array_slice( $caps, 0, 6 );
		$title = 'MLite caps: ' . implode( ', ', $show );
		$wp_admin_bar->add_node( [ 'id' => 'wc-mlite-caps', 'title' => $title, 'meta' => [ 'title' => 'Current user capabilities' ] ] );
		// Add a submenu with a readable list
		// Add quick Settings link for all logged-in users so they can open the page directly
		$settings_link = admin_url( 'admin.php?page=wc-mlite-product-segments' );
		$wp_admin_bar->add_node( [ 'id' => 'wc-mlite-settings-link', 'parent' => 'wc-mlite-caps', 'title' => 'Open Woo→MailerLite settings', 'href' => esc_url( $settings_link ) ] );
		foreach ( $caps as $i => $cap ) {
			$wp_admin_bar->add_node( [
				'id' => 'wc-mlite-cap-' . $i,
				'parent' => 'wc-mlite-caps',
				'title' => $cap . ': ' . ( current_user_can( $cap ) ? 'yes' : 'no' ),
			] );
		}
	}

	/**
	 * Add Settings action link on the Plugins page for easy access.
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$settings_url = esc_url( admin_url( 'admin.php?page=wc-mlite-product-segments' ) );
		$settings_link = '<a href="' . $settings_url . '">' . esc_html__( 'Settings', 'wc-mlite-product-segments' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * AJAX: test MailerLite connection (harmless GET)
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'wc_mlite_test_connection', 'nonce' );

		$opts = self::get_settings();
		$api_key = trim( (string) $opts['api_key'] );
		if ( $api_key === '' ) {
			wp_send_json_error( [ 'message' => 'missing API key' ] );
		}

		$res = $this->request( 'GET', '/groups', $api_key, [], [ 'limit' => 1 ] );
		if ( ! $res ) {
			wp_send_json_error( [ 'message' => 'request failed' ] );
		}
		if ( (int) $res['code'] !== 200 ) {
			wp_send_json_error( [ 'message' => 'API returned ' . (int) $res['code'] ] );
		}
		wp_send_json_success( [ 'message' => 'OK', 'data' => $res['json'] ] );
	}

	/* ------------------------ Admin Settings ------------------------ */
	public static function get_settings() {
		$defaults = [
			'api_key'        => '',
			'api_base'       => 'https://connect.mailerlite.com/api', // Official v2 base
			'enable_logging' => false,
			'enable_verbose_logging' => false,
		];
		return wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), $defaults );
	}

	public function register_settings() {
		// Only users with manage_options may update/save settings. The page itself
		// will be visible to logged-in users (capability 'read') but saving is locked.
		register_setting( 'wc_mlite_product_segments', self::OPTION_KEY, [ 'capability' => 'manage_options' ] );

		add_settings_section( 'wc_mlite_section_main', __( 'MailerLite Connection', 'wc-mlite-product-segments' ), function() {
			echo '<p>' . esc_html__( 'Enter your MailerLite API key. When an order moves to the processing status, buyers will be added to segments (MailerLite "Groups") named after each product in the order.', 'wc-mlite-product-segments' ) . '</p>';
		}, 'wc_mlite_product_segments' );

		add_settings_field( 'api_key', __( 'API Key', 'wc-mlite-product-segments' ), [ $this, 'field_api_key' ], 'wc_mlite_product_segments', 'wc_mlite_section_main' );
		add_settings_field( 'enable_logging', __( 'Enable Logging', 'wc-mlite-product-segments' ), [ $this, 'field_enable_logging' ], 'wc_mlite_product_segments', 'wc_mlite_section_main' );
		add_settings_field( 'enable_verbose_logging', __( 'Enable Verbose Logging', 'wc-mlite-product-segments' ), [ $this, 'field_enable_verbose_logging' ], 'wc_mlite_product_segments', 'wc_mlite_section_main' );
	}

	public function admin_menu() {
		// If the WooCommerce admin menu exists, place under it for discoverability.
		// Otherwise add the page under Settings so admins can always find it.
		global $menu;
		$has_wc_menu = false;
		if ( isset( $menu ) && is_array( $menu ) ) {
			foreach ( $menu as $m ) {
				if ( isset( $m[2] ) && $m[2] === 'woocommerce' ) { $has_wc_menu = true; break; }
			}
		}
		if ( $has_wc_menu ) {
			// Visible to all logged-in users (read) but saving restricted by register_setting capability
			add_submenu_page(
				'woocommerce',
				__( 'Woo to Mailerlite', 'wc-mlite-product-segments' ),
				__( 'Woo to Mailerlite', 'wc-mlite-product-segments' ),
				'read',
				'wc-mlite-product-segments',
				[ $this, 'render_settings_page' ]
			);
		} else {
			add_options_page(
				__( 'Woo to Mailerlite', 'wc-mlite-product-segments' ),
				__( 'Woo to Mailerlite', 'wc-mlite-product-segments' ),
				'read',
				'wc-mlite-product-segments',
				[ $this, 'render_settings_page' ]
			);
		}
	}

	public function render_settings_page() {
		$can_save = current_user_can( 'manage_options' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Woo to Mailerlite', 'wc-mlite-product-segments' ); ?></h1>
			<?php if ( ! $can_save ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'You can view settings but only Administrators may change them.', 'wc-mlite-product-segments' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'wc_mlite_product_segments' ); ?>
				<?php do_settings_sections( 'wc_mlite_product_segments' ); ?>
				<?php if ( $can_save ) { submit_button(); } else { echo '<p><em>' . esc_html__( 'Settings are read-only for your account.', 'wc-mlite-product-segments' ) . '</em></p>'; } ?>
			</form>
			<p>
				<?php if ( $can_save ) : ?>
					<button type="button" id="wc-mlite-test-connection" class="button"><?php esc_html_e( 'Test connection to MailerLite', 'wc-mlite-product-segments' ); ?></button>
				<?php else : ?>
					<button type="button" class="button" disabled><?php esc_html_e( 'Test connection (Admins only)', 'wc-mlite-product-segments' ); ?></button>
				<?php endif; ?>
				<span id="wc-mlite-test-result" style="margin-left:12px"></span>
			</p>
			<script>
			(function(){
				var btn = document.getElementById('wc-mlite-test-connection');
				var out = document.getElementById('wc-mlite-test-result');
				if (!btn) return;
				btn.addEventListener('click', function(){
					btn.disabled = true;
					out.textContent = 'Testing...';
					var xhr = new XMLHttpRequest();
					xhr.open('POST', ajaxurl);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
					xhr.onload = function(){
						btn.disabled = false;
						try{
							var r = JSON.parse(xhr.responseText);
							console.info('WC→MLITE test response:', r);
							if (r && r.success) {
								out.textContent = 'OK — ' + (r.data.message || 'connected');
							} else {
								out.textContent = 'FAIL — ' + (r && r.data && r.data.message ? r.data.message : 'unknown error');
							}
						} catch (e) {
							console.error('WC→MLITE test parse error', e, xhr.responseText);
							out.textContent = 'Unexpected response';
						}
					};
					xhr.send('action=wc_mlite_test_connection&nonce=' + encodeURIComponent('<?php echo esc_js( wp_create_nonce( 'wc_mlite_test_connection' ) ); ?>'));
				});
			})();
			</script>
			<hr/>
			<h2><?php esc_html_e( 'How it works', 'wc-mlite-product-segments' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'When an order moves to processing, each product in the order is mapped to a MailerLite segment (API: Group) with the exact product name.', 'wc-mlite-product-segments' ); ?></li>
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

	public function field_enable_verbose_logging() {
		$opts = self::get_settings();
		?>
		<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_verbose_logging]" value="1" <?php checked( ! empty( $opts['enable_verbose_logging'] ) ); ?> /> <?php esc_html_e( 'Write detailed request/response and order data to the PHP error log (for debugging).', 'wc-mlite-product-segments' ); ?></label>
		<?php
	}

	/* ------------------------ Core Logic ------------------------ */
	public function handle_payment_complete( $order_id ) {
		$settings = self::get_settings();
		$api_key  = trim( (string) $settings['api_key'] );
		if ( $api_key === '' ) {
			error_log( '[WC→MLITE] Order ' . (int) $order_id . ': missing MailerLite API key' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			error_log( '[WC→MLITE] Order ' . (int) $order_id . ': wc_get_order returned no order' );
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			error_log( '[WC→MLITE] Order ' . (int) $order_id . ': invalid billing email: ' . (string) $email );
			return;
		}

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$products = $this->get_distinct_product_names_from_order( $order );
		if ( empty( $products ) ) {
			error_log( '[WC→MLITE] Order ' . (int) $order_id . ': no product names found in order' );
			return;
		}

		$added_any = false;
		// Verbose logging: dump order info
		$opts = self::get_settings();
		$verbose = ! empty( $opts['enable_verbose_logging'] );
		if ( $verbose ) {
			$order_items = [];
			foreach ( $order->get_items() as $it ) {
				if ( $it instanceof WC_Order_Item_Product ) {
					$prod = $it->get_product();
					$order_items[] = [
						'name' => (string) $it->get_name(),
						'sku' => $prod ? $prod->get_sku() : null,
						'id'  => $it->get_product_id(),
						'qty' => $it->get_quantity(),
					];
				}
			}
			error_log( '[WC→MLITE] Order ' . (int) $order_id . ' verbose: email=' . $email . ' name=' . $customer_name . ' items=' . wp_json_encode( $order_items ) );
		}
		foreach ( $products as $product_name ) {
			$group_id = $this->ensure_group_exists( $api_key, $product_name );
			if ( $group_id ) {
				$ok = $this->add_email_to_group( $api_key, $email, $customer_name, $group_id );
				$this->log( sprintf( 'Order %d: %s %s group "%s"', $order_id, $email, $ok ? '→ added to' : '→ FAILED for', $product_name ) );
				if ( $ok ) { $added_any = true; }
				if ( ! $ok ) {
					error_log( '[WC→MLITE] Order ' . (int) $order_id . ': failed to add ' . $email . ' to group ' . $group_id . ' ("' . $product_name . '")' );
				}
			} else {
				$this->log( sprintf( 'Order %d: could not ensure group for "%s"', $order_id, $product_name ) );
				error_log( '[WC→MLITE] Order ' . (int) $order_id . ': could not ensure group for "' . $product_name . '"' );
			}
		}

		if ( $added_any ) {
			$order->add_order_note( __( 'MailerLite: customer added to product-named segments on order processing.', 'wc-mlite-product-segments' ) );
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
		if ( $create === false ) {
			error_log( '[WC→MLITE] API error while creating group "' . $group_name . '"' );
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
			// If we reach here we failed to attach existing subscriber
			error_log( '[WC→MLITE] API error: failed to attach subscriber ' . $email . ' to group ' . $group_id );
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
		// Log the outgoing request (sanitised)
		$sanitised_args = $args;
		if ( isset( $sanitised_args['headers']['Authorization'] ) ) {
			$sanitised_args['headers']['Authorization'] = 'Bearer [REDACTED]';
		}
		$payload_preview = '';
		if ( isset( $args['body'] ) ) {
			$payload_preview = is_string( $args['body'] ) ? $args['body'] : wp_json_encode( $args['body'] );
			if ( strlen( $payload_preview ) > 1000 ) {
				$payload_preview = substr( $payload_preview, 0, 1000 ) . '... [truncated]';
			}
		}
		$this->log( sprintf( 'API REQ %s %s HEADERS=%s PAYLOAD=%s', $method, $path, wp_json_encode( $sanitised_args['headers'] ), $payload_preview ) );

		// Verbose logging: write full payload (non-redacted) to error_log if enabled
		$opts = self::get_settings();
		if ( ! empty( $opts['enable_verbose_logging'] ) ) {
			error_log( '[WC→MLITE] VERBOSE REQ ' . $method . ' ' . $path . ' HEADERS=' . wp_json_encode( $sanitised_args['headers'] ) . ' FULL_PAYLOAD=' . ( isset( $args['body'] ) ? $args['body'] : '' ) );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		// Truncate response body for logging
		$resp_preview = is_string( $raw ) ? $raw : '';
		if ( strlen( $resp_preview ) > 1000 ) {
			$resp_preview = substr( $resp_preview, 0, 1000 ) . '... [truncated]';
		}
		$this->log( sprintf( 'API RESP %s %s → %d %s', $method, $path, $code, $resp_preview ) );

		if ( ! empty( $opts['enable_verbose_logging'] ) ) {
			error_log( '[WC→MLITE] VERBOSE RESP ' . $method . ' ' . $path . ' HTTP=' . $code . ' BODY=' . $raw );
		}

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
		// Also persist recent messages in a transient for dashboard display (keep last 50)
		$messages = get_transient( 'wc_mlite_recent_messages' );
		if ( ! is_array( $messages ) ) { $messages = []; }
		$messages[] = '[' . current_time( 'mysql' ) . '] ' . $msg;
		if ( count( $messages ) > 50 ) { $messages = array_slice( $messages, -50 ); }
		set_transient( 'wc_mlite_recent_messages', $messages, 12 * HOUR_IN_SECONDS );
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
