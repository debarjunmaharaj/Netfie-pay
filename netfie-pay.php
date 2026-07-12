<?php
/**
 * Plugin Name: Netfie Pay
 * Plugin URI:  https://netfie.com
 * Description: Accept manual mobile banking payments (bKash, Nagad, Rocket, Upay, etc.) on WooCommerce checkout. Add unlimited payment methods with icon, number, account type and instructions from the plugin settings page. Customers select a method at checkout, send money manually, then submit the sender number and Transaction ID. Also includes an optional modern, animated, full-width redesign of the [woocommerce_checkout] page.
 * Version:     1.2.0
 * Author:      Netfie
 * Author URI:  https://netfie.com
 * Text Domain: netfie-pay
 * Requires Plugins: woocommerce
 *
 * ------------------------------------------------------------------
 * WHERE TO FIND SETTINGS
 * ------------------------------------------------------------------
 * 1) Manage payment methods (bKash, Nagad...) and the Modern Checkout
 *    UI on/off switch:
 *    WP Admin -> Netfie Pay (left menu)
 *
 * 2) Enable the gateway, set Title/Description and the
 *    "Redirect URL After Checkout":
 *    WP Admin -> WooCommerce -> Settings -> Payments -> Netfie Pay
 *
 * 3) Payment details submitted by the customer (sender number,
 *    transaction id, method, account type) are shown on:
 *    WP Admin -> WooCommerce -> Orders -> (open any order)
 * ------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NETFIE_PAY_OPTION_METHODS', 'netfie_pay_methods' );
define( 'NETFIE_PAY_OPTION_MODERN_UI', 'netfie_pay_modern_checkout' );
define( 'NETFIE_PAY_VERSION', '1.2.0' );

/* =========================================================================
 * 1. METHODS DATA HELPERS
 * ========================================================================= */

/**
 * Get all saved payment methods (bKash, Nagad, Rocket, etc.)
 */
function netfie_pay_get_methods() {
	$methods = get_option( NETFIE_PAY_OPTION_METHODS, array() );
	return is_array( $methods ) ? $methods : array();
}

/**
 * Get only enabled methods, for the checkout popup.
 */
function netfie_pay_get_enabled_methods() {
	return array_filter(
		netfie_pay_get_methods(),
		function ( $m ) {
			return ! empty( $m['enabled'] );
		}
	);
}

function netfie_pay_get_method( $id ) {
	$methods = netfie_pay_get_methods();
	return isset( $methods[ $id ] ) ? $methods[ $id ] : false;
}

function netfie_pay_save_methods( $methods ) {
	update_option( NETFIE_PAY_OPTION_METHODS, $methods );
}

function netfie_pay_account_type_label( $type ) {
	$labels = array(
		'personal'  => 'Personal',
		'agent'     => 'Agent',
		'merchant'  => 'Merchant',
	);
	return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
}

/**
 * Whether the modern, animated, full-width checkout UI is enabled.
 * Enabled by default.
 */
function netfie_pay_is_modern_checkout_enabled() {
	return get_option( NETFIE_PAY_OPTION_MODERN_UI, 'yes' ) === 'yes';
}

add_action( 'admin_post_netfie_pay_save_ui', 'netfie_pay_handle_save_ui' );
function netfie_pay_handle_save_ui() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'netfie_pay_save_ui' );

	update_option( NETFIE_PAY_OPTION_MODERN_UI, isset( $_POST['modern_checkout'] ) ? 'yes' : 'no' );

	wp_safe_redirect( admin_url( 'admin.php?page=netfie-pay-methods&ui_saved=1' ) );
	exit;
}

/* =========================================================================
 * 2. ADMIN MENU - MANAGE PAYMENT METHODS
 * ========================================================================= */

add_action( 'admin_menu', 'netfie_pay_admin_menu' );
function netfie_pay_admin_menu() {
	add_menu_page(
		'Netfie Pay',
		'Netfie Pay',
		'manage_woocommerce',
		'netfie-pay-methods',
		'netfie_pay_methods_page_html',
		'dashicons-money-alt',
		56
	);
}

add_action( 'admin_enqueue_scripts', 'netfie_pay_admin_assets' );
function netfie_pay_admin_assets( $hook ) {
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'netfie-pay-methods' ) {
		wp_enqueue_media();
	}
}

/**
 * Handle Add / Update method form submit.
 */
add_action( 'admin_post_netfie_pay_save_method', 'netfie_pay_handle_save_method' );
function netfie_pay_handle_save_method() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'netfie_pay_save_method' );

	$methods = netfie_pay_get_methods();

	$id = isset( $_POST['method_id'] ) && $_POST['method_id'] !== ''
		? sanitize_key( $_POST['method_id'] )
		: sanitize_key( uniqid( 'nf_' ) );

	$methods[ $id ] = array(
		'name'          => isset( $_POST['method_name'] ) ? sanitize_text_field( wp_unslash( $_POST['method_name'] ) ) : '',
		'icon'          => isset( $_POST['method_icon'] ) ? esc_url_raw( wp_unslash( $_POST['method_icon'] ) ) : '',
		'number'        => isset( $_POST['method_number'] ) ? sanitize_text_field( wp_unslash( $_POST['method_number'] ) ) : '',
		'account_type'  => isset( $_POST['account_type'] ) ? sanitize_text_field( wp_unslash( $_POST['account_type'] ) ) : 'personal',
		'order_status'  => isset( $_POST['order_status'] ) ? sanitize_text_field( wp_unslash( $_POST['order_status'] ) ) : 'on-hold',
		'instructions'  => isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '',
		'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
	);

	netfie_pay_save_methods( $methods );

	wp_safe_redirect( admin_url( 'admin.php?page=netfie-pay-methods&saved=1' ) );
	exit;
}

/**
 * Handle delete.
 */
add_action( 'admin_post_netfie_pay_delete_method', 'netfie_pay_handle_delete_method' );
function netfie_pay_handle_delete_method() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'netfie_pay_delete_method' );

	$id      = isset( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : '';
	$methods = netfie_pay_get_methods();

	if ( $id && isset( $methods[ $id ] ) ) {
		unset( $methods[ $id ] );
		netfie_pay_save_methods( $methods );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=netfie-pay-methods&deleted=1' ) );
	exit;
}

/**
 * The admin page markup: list of methods + add/edit form.
 */
function netfie_pay_methods_page_html() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$methods    = netfie_pay_get_methods();
	$edit_id    = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
	$editing    = $edit_id ? netfie_pay_get_method( $edit_id ) : false;
	$statuses   = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
	$gateway_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=netfie_pay' );
	?>
	<div class="wrap">
		<h1>Netfie Pay &mdash; Payment Methods</h1>
		<p>Add your Bangladeshi mobile banking methods (bKash, Nagad, Rocket, Upay, etc.) below. Then enable the gateway and set the checkout title/description on the
			<a href="<?php echo esc_url( $gateway_url ); ?>">WooCommerce Payments settings page</a>.</p>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success"><p>Payment method saved.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success"><p>Payment method deleted.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['ui_saved'] ) ) : ?>
			<div class="notice notice-success"><p>Checkout UI setting saved.</p></div>
		<?php endif; ?>

		<div style="background:#fff; border:1px solid #ccd0d4; padding:16px 20px; margin-top:20px; max-width:900px;">
			<h2 style="margin-top:0;">Checkout Page Design</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'netfie_pay_save_ui' ); ?>
				<input type="hidden" name="action" value="netfie_pay_save_ui">
				<label>
					<input type="checkbox" name="modern_checkout" value="1" <?php checked( netfie_pay_is_modern_checkout_enabled() ); ?>>
					Enable modern, animated, full-width design for the checkout page (<code>[woocommerce_checkout]</code>)
				</label>
				<p class="description">Restyles the standard WooCommerce checkout with a modern two-column layout, card-style sections, animations, and a full-width layout. No content or functionality changes &mdash; purely visual. Turn off any time if it conflicts with your theme.</p>
				<p><button type="submit" class="button button-primary">Save</button></p>
			</form>
		</div>

		<div style="display:flex; gap:30px; align-items:flex-start; margin-top:20px;">

			<div style="flex:1; max-width:420px; background:#fff; border:1px solid #ccd0d4; padding:20px;">
				<h2><?php echo $editing ? 'Edit Method' : 'Add New Method'; ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'netfie_pay_save_method' ); ?>
					<input type="hidden" name="action" value="netfie_pay_save_method">
					<input type="hidden" name="method_id" value="<?php echo esc_attr( $edit_id ); ?>">

					<table class="form-table">
						<tr>
							<th><label>Method Name</label></th>
							<td><input type="text" name="method_name" class="regular-text" required
								placeholder="e.g. bKash"
								value="<?php echo $editing ? esc_attr( $editing['name'] ) : ''; ?>"></td>
						</tr>
						<tr>
							<th><label>Icon / Logo</label></th>
							<td>
								<input type="hidden" id="netfie_method_icon" name="method_icon"
									value="<?php echo $editing ? esc_url( $editing['icon'] ) : ''; ?>">
								<div id="netfie_icon_preview" style="margin-bottom:8px;">
									<?php if ( $editing && $editing['icon'] ) : ?>
										<img src="<?php echo esc_url( $editing['icon'] ); ?>" style="max-height:50px;">
									<?php endif; ?>
								</div>
								<button type="button" class="button" id="netfie_upload_icon_btn">Select / Upload Icon</button>
							</td>
						</tr>
						<tr>
							<th><label>Mobile Number</label></th>
							<td><input type="text" name="method_number" class="regular-text" required
								placeholder="e.g. 01XXXXXXXXX"
								value="<?php echo $editing ? esc_attr( $editing['number'] ) : ''; ?>"></td>
						</tr>
						<tr>
							<th><label>Account Type</label></th>
							<td>
								<select name="account_type">
									<?php
									$current_type = $editing ? $editing['account_type'] : 'personal';
									foreach ( array( 'personal', 'agent', 'merchant' ) as $type ) {
										printf(
											'<option value="%1$s" %2$s>%3$s</option>',
											esc_attr( $type ),
											selected( $current_type, $type, false ),
											esc_html( netfie_pay_account_type_label( $type ) )
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label>Order Status After Checkout</label></th>
							<td>
								<select name="order_status">
									<?php
									$current_status = $editing ? $editing['order_status'] : 'wc-on-hold';
									if ( strpos( $current_status, 'wc-' ) !== 0 ) {
										$current_status = 'wc-' . $current_status;
									}
									foreach ( $statuses as $key => $label ) {
										printf(
											'<option value="%1$s" %2$s>%3$s</option>',
											esc_attr( $key ),
											selected( $current_status, $key, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
								<p class="description">Order status set once the customer submits the sender number & transaction ID for this method.</p>
							</td>
						</tr>
						<tr>
							<th><label>Instructions</label></th>
							<td><textarea name="instructions" rows="3" class="large-text"
								placeholder="e.g. Send Money to this number, then enter your number & Transaction ID below."><?php echo $editing ? esc_textarea( $editing['instructions'] ) : ''; ?></textarea></td>
						</tr>
						<tr>
							<th><label>Enabled</label></th>
							<td><label><input type="checkbox" name="enabled" value="1"
								<?php checked( $editing ? ! empty( $editing['enabled'] ) : true ); ?>> Show this method at checkout</label></td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php echo $editing ? 'Update Method' : 'Add Method'; ?></button>
						<?php if ( $editing ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=netfie-pay-methods' ) ); ?>" class="button">Cancel</a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<div style="flex:2;">
				<h2>Existing Methods</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:60px;">Icon</th>
							<th>Name</th>
							<th>Number</th>
							<th>Account Type</th>
							<th>Order Status</th>
							<th>Enabled</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $methods ) ) : ?>
						<tr><td colspan="7">No payment methods added yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $methods as $id => $m ) : ?>
							<tr>
								<td><?php if ( ! empty( $m['icon'] ) ) : ?><img src="<?php echo esc_url( $m['icon'] ); ?>" style="max-height:32px;"><?php endif; ?></td>
								<td><strong><?php echo esc_html( $m['name'] ); ?></strong></td>
								<td><?php echo esc_html( $m['number'] ); ?></td>
								<td><?php echo esc_html( netfie_pay_account_type_label( $m['account_type'] ) ); ?></td>
								<td><?php echo esc_html( isset( $statuses[ $m['order_status'] ] ) ? $statuses[ $m['order_status'] ] : $m['order_status'] ); ?></td>
								<td><?php echo ! empty( $m['enabled'] ) ? '✅' : '—'; ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=netfie-pay-methods&edit=' . $id ) ); ?>">Edit</a> |
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=netfie_pay_delete_method&id=' . $id ), 'netfie_pay_delete_method' ) ); ?>"
										onclick="return confirm('Delete this method?');">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	jQuery(document).ready(function($){
		$('#netfie_upload_icon_btn').on('click', function(e){
			e.preventDefault();
			var frame = wp.media({ title: 'Select Icon', multiple: false, library: { type: 'image' } });
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				$('#netfie_method_icon').val(attachment.url);
				$('#netfie_icon_preview').html('<img src="'+attachment.url+'" style="max-height:50px;">');
			});
			frame.open();
		});
	});
	</script>
	<?php
}

/* =========================================================================
 * 3. WOOCOMMERCE PAYMENT GATEWAY
 * ========================================================================= */

add_action( 'plugins_loaded', 'netfie_pay_init_gateway', 11 );
function netfie_pay_init_gateway() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class WC_Netfie_Pay_Gateway extends WC_Payment_Gateway {

		public $redirect_url;

		public function __construct() {
			$this->id                 = 'netfie_pay';
			$this->icon               = '';
			$this->has_fields         = true;
			$this->method_title       = 'Netfie Pay';
			$this->method_description = 'Accept manual mobile banking payments (bKash, Nagad, Rocket, etc). Manage payment methods under the "Netfie Pay" admin menu.';
			$this->supports           = array( 'products' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->enabled      = $this->get_option( 'enabled' );
			$this->redirect_url = $this->get_option( 'redirect_url' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'      => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable Netfie Pay',
					'default' => 'yes',
				),
				'title'        => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'Shown to the customer at checkout.',
					'default'     => 'Mobile Banking (bKash, Nagad, Rocket)',
				),
				'description'  => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'Shown to the customer at checkout, above the "Select Payment Method" button.',
					'default'     => 'Pay via bKash, Nagad, Rocket or any other mobile banking method.',
				),
				'methods_info' => array(
					'title'       => 'Payment Methods',
					'type'        => 'title',
					'description' => 'To add or edit bKash / Nagad / Rocket etc. go to <a href="' . esc_url( admin_url( 'admin.php?page=netfie-pay-methods' ) ) . '">Netfie Pay</a> in the left admin menu.',
				),
				'redirect_url' => array(
					'title'       => 'Redirect URL After Checkout',
					'type'        => 'text',
					'description' => 'Optional. Customer is redirected here instead of the default order-received page after a successful checkout. Leave blank to use the default WooCommerce order confirmation page.',
					'default'     => '',
					'placeholder' => 'https://example.com/thank-you/',
				),
			);
		}

		/**
		 * Fields shown on the checkout page for this gateway.
		 */
		public function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wp_kses_post( $this->description ) );
			}

			$methods = netfie_pay_get_enabled_methods();

			if ( empty( $methods ) ) {
				echo '<p style="color:#a00;">No payment methods have been configured yet.</p>';
				return;
			}
			?>
			<div id="netfie-pay-box">
				<input type="hidden" name="netfie_method_id" id="netfie_method_id" value="">

				<button type="button" class="netfie-select-btn" id="netfie-open-popup">
					<span class="netfie-select-btn-icon">💳</span>
					<span id="netfie-open-popup-label">Select Payment Method</span>
					<span class="netfie-select-btn-arrow">&rsaquo;</span>
				</button>

				<div id="netfie-selected-summary" class="netfie-summary-card" style="display:none;"></div>

				<div id="netfie-fields" class="netfie-tx-fields" style="display:none;">
					<p class="form-row form-row-wide">
						<label>Your Sender Number <span class="required">*</span></label>
						<input type="tel" name="netfie_sender_number" id="netfie_sender_number" class="input-text" placeholder="e.g. 01XXXXXXXXX">
					</p>
					<p class="form-row form-row-wide">
						<label>Transaction ID <span class="required">*</span></label>
						<input type="text" name="netfie_transaction_id" id="netfie_transaction_id" class="input-text" placeholder="e.g. 8N7A6XXXXX">
					</p>
				</div>
			</div>

			<!-- Popup -->
			<div id="netfie-popup-overlay" class="netfie-popup-overlay">
				<div class="netfie-popup-panel" role="dialog" aria-modal="true" aria-label="Choose a payment method">
					<div class="netfie-popup-header">
						<div>
							<h3>Choose a Payment Method</h3>
							<p class="netfie-popup-subtitle">Select where you'll send the payment from</p>
						</div>
						<button type="button" id="netfie-popup-close" class="netfie-popup-close" aria-label="Close">&times;</button>
					</div>
					<div id="netfie-method-list" class="netfie-method-grid">
						<?php foreach ( $methods as $id => $m ) : ?>
							<div class="netfie-method-item"
								tabindex="0"
								data-id="<?php echo esc_attr( $id ); ?>"
								data-name="<?php echo esc_attr( $m['name'] ); ?>"
								data-number="<?php echo esc_attr( $m['number'] ); ?>"
								data-type="<?php echo esc_attr( netfie_pay_account_type_label( $m['account_type'] ) ); ?>"
								data-instructions="<?php echo esc_attr( $m['instructions'] ); ?>">
								<span class="netfie-method-check">✓</span>
								<span class="netfie-method-avatar">
									<?php if ( ! empty( $m['icon'] ) ) : ?>
										<img src="<?php echo esc_url( $m['icon'] ); ?>" alt="<?php echo esc_attr( $m['name'] ); ?>">
									<?php else : ?>
										<span class="netfie-method-avatar-fallback"><?php echo esc_html( mb_substr( $m['name'], 0, 1 ) ); ?></span>
									<?php endif; ?>
								</span>
								<span class="netfie-method-name"><?php echo esc_html( $m['name'] ); ?></span>
								<span class="netfie-method-sub"><?php echo esc_html( netfie_pay_account_type_label( $m['account_type'] ) ); ?> &middot; <?php echo esc_html( $m['number'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<style>
			#netfie-pay-box{ margin-top:4px; }

			.netfie-select-btn{
				display:flex; align-items:center; gap:10px; width:100%;
				background:linear-gradient(135deg,#6C2BD9,#54209f); color:#fff;
				border:none; border-radius:10px; padding:14px 18px; font-size:15px; font-weight:600;
				cursor:pointer; transition:transform .12s ease, box-shadow .18s ease;
			}
			.netfie-select-btn:hover{ transform:translateY(-1px); box-shadow:0 10px 22px rgba(108,43,217,.28); }
			.netfie-select-btn-icon{ font-size:18px; }
			.netfie-select-btn-arrow{ margin-left:auto; font-size:20px; opacity:.85; }

			.netfie-summary-card{
				margin-top:14px; padding:16px; border-radius:12px;
				background:#faf8ff; border:1.5px solid #e7dcfa; display:flex; gap:14px; align-items:flex-start;
				animation:netfiePopIn .25s ease both;
			}
			.netfie-summary-avatar{
				width:44px; height:44px; border-radius:50%; background:#fff; border:1px solid #e7dcfa;
				display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;
			}
			.netfie-summary-avatar img{ max-width:70%; max-height:70%; object-fit:contain; }
			.netfie-summary-title{ font-weight:700; font-size:15px; margin:0 0 4px; }
			.netfie-summary-number{ font-size:14px; margin:0 0 4px; }
			.netfie-summary-number strong{ color:#6C2BD9; }
			.netfie-summary-instructions{ font-size:13px; color:#7a7488; margin:0; }

			.netfie-tx-fields{ margin-top:14px; animation:netfiePopIn .25s ease both; }

			.netfie-popup-overlay{
				position:fixed; inset:0; z-index:100000; background:rgba(24,12,46,.55);
				backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
				display:flex; align-items:center; justify-content:center; padding:20px;
				opacity:0; visibility:hidden; transition:opacity .2s ease, visibility .2s ease;
			}
			.netfie-popup-overlay.is-open{ opacity:1; visibility:visible; }

			.netfie-popup-panel{
				background:#fff; width:100%; max-width:560px; max-height:85vh; overflow:auto;
				border-radius:18px; box-shadow:0 30px 70px rgba(20,10,40,.35); position:relative;
				transform:scale(.94) translateY(10px); opacity:0; transition:transform .22s ease, opacity .22s ease;
			}
			.netfie-popup-overlay.is-open .netfie-popup-panel{ transform:scale(1) translateY(0); opacity:1; }

			.netfie-popup-header{
				display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
				padding:22px 24px 16px; position:sticky; top:0; background:#fff; border-bottom:1px solid #f0edf7; z-index:1;
			}
			.netfie-popup-header h3{ margin:0 0 4px; font-size:19px; font-weight:700; }
			.netfie-popup-subtitle{ margin:0; font-size:13px; color:#7a7488; }
			.netfie-popup-close{
				border:none; background:#f3f1fa; color:#4a4458; width:32px; height:32px; border-radius:50%;
				font-size:18px; line-height:1; cursor:pointer; flex-shrink:0; transition:background .15s ease, transform .15s ease;
			}
			.netfie-popup-close:hover{ background:#e7dcfa; transform:rotate(90deg); }

			.netfie-method-grid{
				display:grid; grid-template-columns:1fr 1fr; gap:12px; padding:20px 24px 24px;
			}
			@media (max-width:480px){ .netfie-method-grid{ grid-template-columns:1fr; } }

			.netfie-method-item{
				position:relative; border:1.5px solid #ece8f7; border-radius:14px; padding:16px 14px;
				display:flex; flex-direction:column; align-items:center; text-align:center; gap:6px;
				cursor:pointer; background:#fcfbfe; transition:border-color .15s ease, transform .12s ease, box-shadow .15s ease;
			}
			.netfie-method-item:hover{ border-color:#6C2BD9; transform:translateY(-2px); box-shadow:0 8px 20px rgba(108,43,217,.14); }
			.netfie-method-item.is-selected{ border-color:#6C2BD9; background:#faf8ff; box-shadow:0 0 0 3px rgba(108,43,217,.12); }

			.netfie-method-check{
				position:absolute; top:8px; right:8px; width:20px; height:20px; border-radius:50%;
				background:#6C2BD9; color:#fff; font-size:12px; display:flex; align-items:center; justify-content:center;
				opacity:0; transform:scale(.5); transition:opacity .15s ease, transform .15s ease;
			}
			.netfie-method-item.is-selected .netfie-method-check{ opacity:1; transform:scale(1); }

			.netfie-method-avatar{
				width:52px; height:52px; border-radius:50%; background:#fff; border:1px solid #ece8f7;
				display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:4px;
			}
			.netfie-method-avatar img{ max-width:65%; max-height:65%; object-fit:contain; }
			.netfie-method-avatar-fallback{ font-weight:700; color:#6C2BD9; font-size:18px; }

			.netfie-method-name{ font-weight:700; font-size:14px; }
			.netfie-method-sub{ font-size:12px; color:#7a7488; }

			@keyframes netfiePopIn{
				from{ opacity:0; transform:translateY(6px); }
				to{ opacity:1; transform:translateY(0); }
			}
			</style>

			<script>
			(function($){
				function netfieOpenPopup(){
					$('#netfie-popup-overlay').addClass('is-open');
					$('body').css('overflow','hidden');
				}
				function netfieClosePopup(){
					$('#netfie-popup-overlay').removeClass('is-open');
					$('body').css('overflow','');
				}

				$(document).off('.netfie');

				$(document).on('click.netfie', '#netfie-open-popup', function(){
					netfieOpenPopup();
				});
				$(document).on('click.netfie', '#netfie-popup-close', function(){
					netfieClosePopup();
				});
				$(document).on('click.netfie', '#netfie-popup-overlay', function(e){
					if ( e.target === this ) { netfieClosePopup(); }
				});
				$(document).on('keydown.netfie', function(e){
					if ( e.key === 'Escape' ) { netfieClosePopup(); }
				});

				$(document).on('click.netfie keydown.netfie', '.netfie-method-item', function(e){
					if ( e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' ) { return; }
					e.preventDefault();

					var $el = $(this);
					var id = $el.data('id');
					var name = $el.data('name');
					var number = $el.data('number');
					var type = $el.data('type');
					var instructions = $el.data('instructions');
					var iconHtml = $el.find('.netfie-method-avatar').html();

					$('.netfie-method-item').removeClass('is-selected');
					$el.addClass('is-selected');

					$('#netfie_method_id').val(id);

					var html = '<span class="netfie-summary-avatar">' + iconHtml + '</span>' +
						'<div>' +
							'<p class="netfie-summary-title">' + name + ' &middot; ' + type + '</p>' +
							'<p class="netfie-summary-number">Send payment to: <strong>' + number + '</strong></p>' +
							( instructions ? '<p class="netfie-summary-instructions">' + instructions + '</p>' : '' ) +
						'</div>';

					$('#netfie-selected-summary').html(html).show();
					$('#netfie-fields').show();
					$('#netfie-open-popup-label').text('Change: ' + name);

					setTimeout(netfieClosePopup, 180);
				});
			})(jQuery);
			</script>
			<?php
		}

		/**
		 * Validate fields before order is placed.
		 */
		public function validate_fields() {
			$method_id = isset( $_POST['netfie_method_id'] ) ? sanitize_key( $_POST['netfie_method_id'] ) : '';
			$sender    = isset( $_POST['netfie_sender_number'] ) ? sanitize_text_field( wp_unslash( $_POST['netfie_sender_number'] ) ) : '';
			$txn       = isset( $_POST['netfie_transaction_id'] ) ? sanitize_text_field( wp_unslash( $_POST['netfie_transaction_id'] ) ) : '';

			if ( ! $method_id || ! netfie_pay_get_method( $method_id ) ) {
				wc_add_notice( 'Please select a payment method (bKash, Nagad, etc.) before placing your order.', 'error' );
				return false;
			}
			if ( ! $sender ) {
				wc_add_notice( 'Please enter the sender number you used to send the payment.', 'error' );
				return false;
			}
			if ( ! $txn ) {
				wc_add_notice( 'Please enter the Transaction ID.', 'error' );
				return false;
			}
			return true;
		}

		/**
		 * Process the payment: save meta + set order status.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			$method_id = sanitize_key( $_POST['netfie_method_id'] );
			$sender    = sanitize_text_field( wp_unslash( $_POST['netfie_sender_number'] ) );
			$txn       = sanitize_text_field( wp_unslash( $_POST['netfie_transaction_id'] ) );

			$method = netfie_pay_get_method( $method_id );

			$order->update_meta_data( '_netfie_method_id', $method_id );
			$order->update_meta_data( '_netfie_method_name', $method['name'] );
			$order->update_meta_data( '_netfie_number', $method['number'] );
			$order->update_meta_data( '_netfie_account_type', netfie_pay_account_type_label( $method['account_type'] ) );
			$order->update_meta_data( '_netfie_sender_number', $sender );
			$order->update_meta_data( '_netfie_transaction_id', $txn );

			$status = ! empty( $method['order_status'] ) ? str_replace( 'wc-', '', $method['order_status'] ) : 'on-hold';
			$order->update_status( $status, 'Netfie Pay: awaiting manual verification. ' );

			$order->save();

			// Reduce stock and empty cart as usual.
			wc_reduce_stock_levels( $order_id );
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}

	add_filter( 'woocommerce_payment_gateways', 'netfie_pay_add_gateway' );
	function netfie_pay_add_gateway( $gateways ) {
		$gateways[] = 'WC_Netfie_Pay_Gateway';
		return $gateways;
	}
}

/**
 * Override the return/redirect URL if the admin set a custom one.
 */
add_filter( 'woocommerce_get_return_url', 'netfie_pay_custom_redirect', 10, 2 );
function netfie_pay_custom_redirect( $return_url, $order ) {
	if ( $order && $order->get_payment_method() === 'netfie_pay' ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['netfie_pay'] ) ) {
			$custom_url = $gateways['netfie_pay']->get_option( 'redirect_url' );
			if ( ! empty( $custom_url ) ) {
				return esc_url_raw( add_query_arg( 'order_id', $order->get_id(), $custom_url ) );
			}
		}
	}
	return $return_url;
}

/* =========================================================================
 * 4. SHOW PAYMENT DETAILS ON THE ADMIN ORDER PAGE
 *    Works for both classic (post-based) and HPOS order edit screens,
 *    e.g. admin.php?page=wc-orders&action=edit&id=30400
 * ========================================================================= */

add_action( 'woocommerce_admin_order_data_after_billing_address', 'netfie_pay_show_order_details' );
function netfie_pay_show_order_details( $order ) {
	if ( ! $order || $order->get_payment_method() !== 'netfie_pay' ) {
		return;
	}

	$method_name = $order->get_meta( '_netfie_method_name' );
	$number      = $order->get_meta( '_netfie_number' );
	$type        = $order->get_meta( '_netfie_account_type' );
	$sender      = $order->get_meta( '_netfie_sender_number' );
	$txn         = $order->get_meta( '_netfie_transaction_id' );

	if ( ! $method_name && ! $sender && ! $txn ) {
		return;
	}
	?>
	<div class="netfie-pay-order-details" style="clear:both; margin-top:16px; padding:12px; border:1px solid #dcdcde; background:#f6f7f7;">
		<h3 style="margin-top:0;">Netfie Pay - Payment Details</h3>
		<p><strong>Method:</strong> <?php echo esc_html( $method_name ); ?></p>
		<p><strong>Account Type:</strong> <?php echo esc_html( $type ); ?></p>
		<p><strong>Received On Number:</strong> <?php echo esc_html( $number ); ?></p>
		<p><strong>Sender Number:</strong> <?php echo esc_html( $sender ); ?></p>
		<p><strong>Transaction ID:</strong> <?php echo esc_html( $txn ); ?></p>
	</div>
	<?php
}

/**
 * Also show a summary column on the WooCommerce Orders list table (optional, nice-to-have).
 */
add_filter( 'woocommerce_admin_order_preview_get_order_details', 'netfie_pay_order_preview_details', 10, 2 );
function netfie_pay_order_preview_details( $data, $order ) {
	if ( $order && $order->get_payment_method() === 'netfie_pay' ) {
		$data['netfie_transaction_id'] = $order->get_meta( '_netfie_transaction_id' );
		$data['netfie_sender_number']  = $order->get_meta( '_netfie_sender_number' );
	}
	return $data;
}

/* =========================================================================
 * 5. MODERN / ANIMATED / FULL-WIDTH CHECKOUT UI
 *    Purely visual - restyles the existing [woocommerce_checkout] output.
 *    No WooCommerce templates are overridden and no field/markup logic
 *    is changed, so this is safe to toggle on/off at any time.
 * ========================================================================= */

/**
 * Wrap the [woocommerce_checkout] shortcode output in a full-width
 * container so the CSS "full-bleed" technique below has something
 * reliable to target, regardless of the active theme's markup.
 */
add_filter( 'do_shortcode_tag', 'netfie_pay_wrap_checkout_shortcode', 10, 2 );
function netfie_pay_wrap_checkout_shortcode( $output, $tag ) {
	if ( 'woocommerce_checkout' === $tag && netfie_pay_is_modern_checkout_enabled() ) {
		$output = '<div class="netfie-checkout-fullwidth">' . $output . '</div>';
	}
	return $output;
}

/**
 * Add a body class so the CSS can scope itself safely.
 */
add_filter( 'body_class', 'netfie_pay_checkout_body_class' );
function netfie_pay_checkout_body_class( $classes ) {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && netfie_pay_is_modern_checkout_enabled() ) {
		$classes[] = 'netfie-modern-checkout';
	}
	return $classes;
}

/**
 * Print the CSS/JS only on the checkout page.
 */
add_action( 'wp_head', 'netfie_pay_checkout_ui_css' );
function netfie_pay_checkout_ui_css() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	if ( ! netfie_pay_is_modern_checkout_enabled() ) {
		return;
	}
	?>
	<style id="netfie-pay-checkout-ui">
	:root{
		--netfie-primary:#6C2BD9;
		--netfie-primary-dark:#54209f;
		--netfie-accent:#FF7A00;
		--netfie-bg:#f6f5fb;
		--netfie-card:#ffffff;
		--netfie-border:#e7e4f2;
		--netfie-text:#2a2438;
		--netfie-muted:#7a7488;
		--netfie-radius:14px;
	}

	/* ---- Full width breakout ---- */
	body.netfie-modern-checkout .netfie-checkout-fullwidth{
		position:relative;
		left:50%;
		right:50%;
		margin-left:-50vw;
		margin-right:-50vw;
		width:100vw;
		max-width:100vw;
		box-sizing:border-box;
		background:var(--netfie-bg);
		padding:48px 5%;
		animation:netfieFadeIn .5s ease both;
	}
	@media (max-width:782px){
		body.netfie-modern-checkout .netfie-checkout-fullwidth{ padding:28px 4%; }
	}

	body.netfie-modern-checkout .netfie-checkout-fullwidth form.woocommerce-checkout{
		max-width:1440px;
		width:100%;
		margin:0 auto;
		font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
		color:var(--netfie-text);
	}

	body.netfie-modern-checkout .netfie-checkout-fullwidth h2.netfie-checkout-title{
		font-size:28px;
		font-weight:700;
		margin:0 0 28px;
		letter-spacing:-.5px;
	}

	/* ---- Two column grid: details left, sticky order review right ---- */
	body.netfie-modern-checkout .netfie-checkout-fullwidth form.woocommerce-checkout{
		display:grid;
		grid-template-columns:1fr 420px;
		grid-template-rows:auto auto;
		column-gap:36px;
		row-gap:0;
		align-items:start;
	}
	body.netfie-modern-checkout .netfie-checkout-fullwidth #customer_details{
		grid-column:1;
		grid-row:1 / span 2;
	}
	body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review_heading{
		grid-column:2;
		grid-row:1;
		margin-top:0;
	}
	body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review{
		grid-column:2;
		grid-row:2;
		position:sticky;
		top:24px;
	}
	@media (max-width:960px){
		body.netfie-modern-checkout .netfie-checkout-fullwidth form.woocommerce-checkout{
			display:block;
		}
		body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review{
			position:static;
			margin-top:24px;
		}
	}

	/* ---- Card sections ---- */
	body.netfie-modern-checkout .woocommerce-billing-fields,
	body.netfie-modern-checkout .woocommerce-shipping-fields,
	body.netfie-modern-checkout .woocommerce-additional-fields,
	body.netfie-modern-checkout #order_review{
		background:var(--netfie-card);
		border:1px solid var(--netfie-border);
		border-radius:var(--netfie-radius);
		padding:28px;
		margin-bottom:24px;
		box-shadow:0 4px 24px rgba(40,20,90,.05);
		animation:netfieFadeUp .5s ease both;
	}
	body.netfie-modern-checkout .woocommerce-shipping-fields{ animation-delay:.05s; }
	body.netfie-modern-checkout .woocommerce-additional-fields{ animation-delay:.1s; }
	body.netfie-modern-checkout #order_review{ animation-delay:.15s; }

	body.netfie-modern-checkout h3#ship-to-different-address,
	body.netfie-modern-checkout .woocommerce-billing-fields > h3,
	body.netfie-modern-checkout .woocommerce-additional-fields > h3{
		font-size:17px;
		font-weight:700;
		margin-top:0;
		margin-bottom:18px;
		padding-bottom:12px;
		border-bottom:2px solid var(--netfie-bg);
	}

	/* ---- Inputs ---- */
	body.netfie-modern-checkout .input-text,
	body.netfie-modern-checkout select,
	body.netfie-modern-checkout textarea,
	body.netfie-modern-checkout .select2-container .select2-selection--single{
		border:1.5px solid var(--netfie-border) !important;
		border-radius:10px !important;
		padding:12px 14px !important;
		height:auto !important;
		background:#fcfcfe !important;
		font-size:15px;
		transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
	}
	body.netfie-modern-checkout .select2-container .select2-selection--single{
		display:flex; align-items:center;
	}
	body.netfie-modern-checkout .input-text:focus,
	body.netfie-modern-checkout select:focus,
	body.netfie-modern-checkout textarea:focus{
		border-color:var(--netfie-primary) !important;
		box-shadow:0 0 0 4px rgba(108,43,217,.12) !important;
		background:#fff !important;
		outline:none;
	}
	body.netfie-modern-checkout .form-row label{
		font-weight:600;
		font-size:13px;
		color:var(--netfie-muted);
		margin-bottom:6px;
		display:block;
	}
	body.netfie-modern-checkout .form-row{ margin-bottom:16px; }

	/* ---- Order review table ---- */
	body.netfie-modern-checkout table.shop_table{
		border:none;
		border-collapse:collapse;
		width:100%;
	}
	body.netfie-modern-checkout table.shop_table th,
	body.netfie-modern-checkout table.shop_table td{
		border:none;
		border-bottom:1px solid var(--netfie-bg);
		padding:12px 0;
	}
	body.netfie-modern-checkout table.shop_table tfoot tr:last-child th,
	body.netfie-modern-checkout table.shop_table tfoot tr:last-child td{
		border-bottom:none;
		font-size:18px;
		font-weight:700;
		color:var(--netfie-primary);
	}

	/* ---- Payment methods list ---- */
	body.netfie-modern-checkout ul.wc_payment_methods{
		list-style:none;
		margin:0 0 16px;
		padding:0;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method{
		border:1.5px solid var(--netfie-border);
		border-radius:12px;
		margin-bottom:10px;
		padding:14px 16px;
		transition:border-color .18s ease, box-shadow .18s ease, transform .12s ease;
		background:#fcfcfe;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method:hover{
		border-color:var(--netfie-primary);
		transform:translateY(-1px);
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.netfie-selected{
		border-color:var(--netfie-primary);
		box-shadow:0 0 0 4px rgba(108,43,217,.10);
		background:#fff;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method label{
		font-weight:600;
	}
	body.netfie-modern-checkout .payment_box{
		background:var(--netfie-bg) !important;
		border-radius:10px;
		margin-top:10px !important;
		border:none !important;
	}
	body.netfie-modern-checkout .payment_box:before{ display:none; }

	/* ---- Place order button ---- */
	body.netfie-modern-checkout #place_order{
		width:100%;
		background:linear-gradient(135deg,var(--netfie-primary),var(--netfie-accent)) !important;
		color:#fff !important;
		border:none !important;
		border-radius:12px !important;
		padding:16px !important;
		font-size:17px !important;
		font-weight:700 !important;
		letter-spacing:.2px;
		cursor:pointer;
		transition:transform .12s ease, box-shadow .2s ease, filter .2s ease;
		box-shadow:0 10px 24px rgba(108,43,217,.25);
	}
	body.netfie-modern-checkout #place_order:hover{
		transform:translateY(-2px);
		filter:brightness(1.05);
		box-shadow:0 14px 28px rgba(108,43,217,.32);
	}
	body.netfie-modern-checkout #place_order:active{
		transform:translateY(0);
	}

	/* ---- Coupon box ---- */
	body.netfie-modern-checkout .woocommerce-form-coupon-toggle .woocommerce-info{
		border-radius:10px;
		border-top-color:var(--netfie-primary);
	}
	body.netfie-modern-checkout .woocommerce-form-coupon .button{
		border-radius:10px !important;
		background:var(--netfie-primary) !important;
	}

	/* ---- Notices ---- */
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-error,
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-message{
		border-radius:10px;
		animation:netfieFadeUp .3s ease both;
	}

	/* ---- Processing overlay ---- */
	body.netfie-modern-checkout .blockUI.blockOverlay{
		border-radius:var(--netfie-radius);
	}

	@keyframes netfieFadeIn{
		from{ opacity:0; }
		to{ opacity:1; }
	}
	@keyframes netfieFadeUp{
		from{ opacity:0; transform:translateY(14px); }
		to{ opacity:1; transform:translateY(0); }
	}
	</style>
	<?php
}

/**
 * Small JS enhancements: highlight the selected payment method card,
 * and add a heading above the order-review sidebar so the two-column
 * grid always has a stable visual anchor.
 */
add_action( 'wp_footer', 'netfie_pay_checkout_ui_js' );
function netfie_pay_checkout_ui_js() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	if ( ! netfie_pay_is_modern_checkout_enabled() ) {
		return;
	}
	?>
	<script>
	(function($){
		function netfieHighlightSelected(){
			$('ul.wc_payment_methods li.wc_payment_method').removeClass('netfie-selected');
			$('ul.wc_payment_methods input[name="payment_method"]:checked')
				.closest('li.wc_payment_method').addClass('netfie-selected');
		}

		function netfieAddSectionIcons(){
			$('.woocommerce-billing-fields > h3').each(function(){
				if ( ! $(this).find('.netfie-h-icon').length ) { $(this).prepend('<span class="netfie-h-icon">👤</span> '); }
			});
			$('.woocommerce-additional-fields > h3').each(function(){
				if ( ! $(this).find('.netfie-h-icon').length ) { $(this).prepend('<span class="netfie-h-icon">📝</span> '); }
			});
			$('#order_review_heading').each(function(){
				if ( ! $(this).find('.netfie-h-icon').length ) { $(this).prepend('<span class="netfie-h-icon">🧾</span> '); }
			});
		}

		function netfieAddSecureNote(){
			if ( $('.netfie-secure-note').length ) { return; }
			$('.woocommerce-checkout-payment, #payment').last()
				.append('<p class="netfie-secure-note">🔒 Secure checkout &mdash; your information is protected</p>');
		}

		function netfieRunAll(){
			netfieHighlightSelected();
			netfieAddSectionIcons();
			netfieAddSecureNote();
		}

		$(document.body).on('updated_checkout payment_method_selected change', netfieRunAll);
		$(document).ready(netfieRunAll);
	})(jQuery);
	</script>
	<style>
	.netfie-h-icon{ margin-right:4px; }
	.netfie-secure-note{
		text-align:center; font-size:12.5px; color:#7a7488; margin:14px 0 0;
	}
	</style>
	<?php
}

/* =========================================================================
 * 6. ACTIVATION NOTICE IF WOOCOMMERCE MISSING
 * ========================================================================= */

add_action( 'admin_notices', 'netfie_pay_missing_wc_notice' );
function netfie_pay_missing_wc_notice() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>Netfie Pay</strong> requires WooCommerce to be installed and active.</p></div>';
	}
}
