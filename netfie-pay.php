<?php
/**
 * Plugin Name: Netfie Pay
 * Plugin URI:  https://netfie.com
 * Description: Accept manual mobile banking payments (bKash, Nagad, Rocket, Upay, etc.) on WooCommerce checkout. Add unlimited payment methods with icon, number, account type and instructions from the plugin settings page. Customers select a method at checkout, send money manually, then submit the sender number and Transaction ID. Also includes an optional modern, animated, full-width redesign of the [woocommerce_checkout] page.
 * Version:     1.7.1
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
define( 'NETFIE_PAY_VERSION', '1.7.1' );

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

	$methods     = netfie_pay_get_methods();
	$edit_id     = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
	$editing     = $edit_id ? netfie_pay_get_method( $edit_id ) : false;
	$statuses    = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
	$gateway_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=netfie_pay' );
	$enabled_count = count( netfie_pay_get_enabled_methods() );
	$total_count   = count( $methods );
	?>
	<div class="wrap netfie-admin-wrap">

		<div class="netfie-admin-header">
			<div class="netfie-admin-header-brand">
				<span class="netfie-admin-logo">N</span>
				<div>
					<h1>Netfie Pay</h1>
					<p>Manual mobile banking payments for WooCommerce &mdash; bKash, Nagad, Rocket & more.</p>
				</div>
			</div>
			<div class="netfie-admin-header-stats">
				<div class="netfie-stat"><span class="netfie-stat-num"><?php echo (int) $enabled_count; ?></span><span class="netfie-stat-label">Active Methods</span></div>
				<div class="netfie-stat"><span class="netfie-stat-num"><?php echo (int) $total_count; ?></span><span class="netfie-stat-label">Total Methods</span></div>
				<a href="<?php echo esc_url( $gateway_url ); ?>" class="button button-primary netfie-header-btn">Gateway Settings</a>
			</div>
		</div>

		<p class="netfie-admin-intro">Add your Bangladeshi mobile banking methods below, then place <code>[netfie-woocommerce_checkout]</code> on your Checkout page instead of the default <code>[woocommerce_checkout]</code> shortcode to use Netfie Pay's checkout experience.</p>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Payment method saved.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Payment method deleted.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['ui_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Checkout UI setting saved.</p></div>
		<?php endif; ?>

		<div class="netfie-card netfie-ui-card">
			<div class="netfie-card-head">
				<span class="netfie-card-icon">🎨</span>
				<div>
					<h2>Checkout Page Design</h2>
					<p>Controls the look of the <code>[netfie-woocommerce_checkout]</code> shortcode.</p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'netfie_pay_save_ui' ); ?>
				<input type="hidden" name="action" value="netfie_pay_save_ui">
				<label class="netfie-toggle-row">
					<span class="netfie-toggle">
						<input type="checkbox" name="modern_checkout" value="1" <?php checked( netfie_pay_is_modern_checkout_enabled() ); ?>>
						<span class="netfie-toggle-slider"></span>
					</span>
					<span>Enable modern, animated, full-width checkout design</span>
				</label>
				<p class="description">Renders a two-column, card-style, animated checkout with a full-width layout when customers use the <code>[netfie-woocommerce_checkout]</code> shortcode. Purely visual &mdash; no content or functionality changes. Turn off any time if it conflicts with your theme.</p>
				<button type="submit" class="button button-primary">Save Design Setting</button>
			</form>
		</div>

		<div class="netfie-columns">

			<div class="netfie-card netfie-form-card">
				<div class="netfie-card-head">
					<span class="netfie-card-icon"><?php echo $editing ? '✏️' : '➕'; ?></span>
					<div><h2><?php echo $editing ? 'Edit Method' : 'Add New Method'; ?></h2></div>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="netfie-form">
					<?php wp_nonce_field( 'netfie_pay_save_method' ); ?>
					<input type="hidden" name="action" value="netfie_pay_save_method">
					<input type="hidden" name="method_id" value="<?php echo esc_attr( $edit_id ); ?>">

					<div class="netfie-field">
						<label>Method Name</label>
						<input type="text" name="method_name" required
							placeholder="e.g. bKash"
							value="<?php echo $editing ? esc_attr( $editing['name'] ) : ''; ?>">
					</div>

					<div class="netfie-field">
						<label>Icon / Logo</label>
						<div class="netfie-icon-uploader">
							<div id="netfie_icon_preview" class="netfie-icon-preview">
								<?php if ( $editing && $editing['icon'] ) : ?>
									<img src="<?php echo esc_url( $editing['icon'] ); ?>">
								<?php else : ?>
									<span class="netfie-icon-placeholder">＋</span>
								<?php endif; ?>
							</div>
							<input type="hidden" id="netfie_method_icon" name="method_icon"
								value="<?php echo $editing ? esc_url( $editing['icon'] ) : ''; ?>">
							<button type="button" class="button" id="netfie_upload_icon_btn">Select / Upload Icon</button>
						</div>
					</div>

					<div class="netfie-field">
						<label>Mobile Number</label>
						<input type="text" name="method_number" required
							placeholder="e.g. 01XXXXXXXXX"
							value="<?php echo $editing ? esc_attr( $editing['number'] ) : ''; ?>">
					</div>

					<div class="netfie-field">
						<label>Account Type</label>
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
					</div>

					<div class="netfie-field">
						<label>Order Status After Checkout</label>
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
						<p class="description">Set once the customer submits the sender number & transaction ID for this method.</p>
					</div>

					<div class="netfie-field">
						<label>Instructions</label>
						<textarea name="instructions" rows="3"
							placeholder="e.g. Send Money to this number, then enter your number & Transaction ID below."><?php echo $editing ? esc_textarea( $editing['instructions'] ) : ''; ?></textarea>
					</div>

					<div class="netfie-field">
						<label class="netfie-toggle-row">
							<span class="netfie-toggle">
								<input type="checkbox" name="enabled" value="1"
									<?php checked( $editing ? ! empty( $editing['enabled'] ) : true ); ?>>
								<span class="netfie-toggle-slider"></span>
							</span>
							<span>Show this method at checkout</span>
						</label>
					</div>

					<div class="netfie-form-actions">
						<button type="submit" class="button button-primary"><?php echo $editing ? 'Update Method' : 'Add Method'; ?></button>
						<?php if ( $editing ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=netfie-pay-methods' ) ); ?>" class="button">Cancel</a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<div class="netfie-card netfie-list-card">
				<div class="netfie-card-head">
					<span class="netfie-card-icon">📋</span>
					<div><h2>Existing Methods</h2></div>
				</div>

				<?php if ( empty( $methods ) ) : ?>
					<div class="netfie-empty-state">
						<span>🏦</span>
						<p>No payment methods added yet. Use the form to add your first one, e.g. bKash.</p>
					</div>
				<?php else : ?>
					<table class="netfie-table">
						<thead>
							<tr>
								<th>Method</th>
								<th>Number</th>
								<th>Type</th>
								<th>Order Status</th>
								<th>Status</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $methods as $id => $m ) : ?>
							<tr>
								<td>
									<div class="netfie-method-cell">
										<span class="netfie-method-cell-icon">
											<?php if ( ! empty( $m['icon'] ) ) : ?>
												<img src="<?php echo esc_url( $m['icon'] ); ?>">
											<?php else : ?>
												<?php echo esc_html( mb_substr( $m['name'], 0, 1 ) ); ?>
											<?php endif; ?>
										</span>
										<strong><?php echo esc_html( $m['name'] ); ?></strong>
									</div>
								</td>
								<td><?php echo esc_html( $m['number'] ); ?></td>
								<td><span class="netfie-pill netfie-pill-<?php echo esc_attr( $m['account_type'] ); ?>"><?php echo esc_html( netfie_pay_account_type_label( $m['account_type'] ) ); ?></span></td>
								<td><?php echo esc_html( isset( $statuses[ $m['order_status'] ] ) ? $statuses[ $m['order_status'] ] : $m['order_status'] ); ?></td>
								<td>
									<?php if ( ! empty( $m['enabled'] ) ) : ?>
										<span class="netfie-pill netfie-pill-on">Active</span>
									<?php else : ?>
										<span class="netfie-pill netfie-pill-off">Disabled</span>
									<?php endif; ?>
								</td>
								<td class="netfie-actions-cell">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=netfie-pay-methods&edit=' . $id ) ); ?>" class="netfie-action-link">Edit</a>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=netfie_pay_delete_method&id=' . $id ), 'netfie_pay_delete_method' ) ); ?>"
										class="netfie-action-link netfie-action-danger"
										onclick="return confirm('Delete this method?');">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<style>
	.netfie-admin-wrap{ --nf-primary:#6C2BD9; --nf-primary-dark:#54209f; --nf-accent:#FF7A00; --nf-bg:#f6f5fb; --nf-border:#e7e4f2; --nf-text:#2a2438; --nf-muted:#7a7488; max-width:1300px; }
	.netfie-admin-wrap *{ box-sizing:border-box; }

	.netfie-admin-header{
		display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;
		background:linear-gradient(135deg,var(--nf-primary),var(--nf-primary-dark));
		color:#fff; border-radius:16px; padding:26px 28px; margin:18px 0 20px;
	}
	.netfie-admin-header-brand{ display:flex; align-items:center; gap:16px; }
	.netfie-admin-logo{
		width:52px; height:52px; border-radius:14px; background:rgba(255,255,255,.18);
		display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800;
	}
	.netfie-admin-header h1{ color:#fff; margin:0 0 4px; font-size:22px; padding:0; }
	.netfie-admin-header p{ margin:0; opacity:.85; font-size:13px; }
	.netfie-admin-header-stats{ display:flex; align-items:center; gap:22px; }
	.netfie-stat{ text-align:center; }
	.netfie-stat-num{ display:block; font-size:22px; font-weight:800; }
	.netfie-stat-label{ font-size:11px; opacity:.8; text-transform:uppercase; letter-spacing:.4px; }
	.netfie-header-btn{ background:#fff !important; color:var(--nf-primary) !important; border:none !important; font-weight:700 !important; }

	.netfie-admin-intro{ max-width:900px; color:var(--nf-muted); }
	.netfie-admin-intro code{ background:#efe9fb; color:var(--nf-primary-dark); padding:2px 6px; border-radius:5px; }

	.netfie-card{
		background:#fff; border:1px solid var(--nf-border); border-radius:14px; padding:22px 24px;
		box-shadow:0 4px 18px rgba(40,20,90,.05); margin-bottom:22px;
	}
	.netfie-card-head{ display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
	.netfie-card-icon{ font-size:22px; }
	.netfie-card-head h2{ margin:0 0 2px; font-size:16px; }
	.netfie-card-head p{ margin:0; font-size:12.5px; color:var(--nf-muted); }

	.netfie-columns{ display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; }
	.netfie-form-card{ flex:1 1 380px; max-width:420px; }
	.netfie-list-card{ flex:2 1 520px; }

	.netfie-field{ margin-bottom:16px; }
	.netfie-field label{ display:block; font-weight:600; font-size:13px; color:var(--nf-muted); margin-bottom:6px; }
	.netfie-field input[type=text],
	.netfie-field select,
	.netfie-field textarea{
		width:100%; border:1.5px solid var(--nf-border); border-radius:9px; padding:10px 12px;
		font-size:14px; background:#fcfbfe; transition:border-color .15s ease, box-shadow .15s ease;
	}
	.netfie-field input:focus, .netfie-field select:focus, .netfie-field textarea:focus{
		border-color:var(--nf-primary); box-shadow:0 0 0 3px rgba(108,43,217,.12); outline:none;
	}

	.netfie-icon-uploader{ display:flex; align-items:center; gap:14px; }
	.netfie-icon-preview{
		width:56px; height:56px; border-radius:12px; border:1.5px dashed var(--nf-border);
		display:flex; align-items:center; justify-content:center; overflow:hidden; background:#fcfbfe; flex-shrink:0;
	}
	.netfie-icon-preview img{ max-width:80%; max-height:80%; object-fit:contain; }
	.netfie-icon-placeholder{ font-size:22px; color:var(--nf-muted); }

	.netfie-toggle-row{ display:flex; align-items:center; gap:10px; font-weight:500; cursor:pointer; }
	.netfie-toggle{ position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
	.netfie-toggle input{ opacity:0; width:0; height:0; }
	.netfie-toggle-slider{
		position:absolute; inset:0; background:#d9d5e8; border-radius:22px; transition:background .18s ease;
	}
	.netfie-toggle-slider:before{
		content:""; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%;
		transition:transform .18s ease; box-shadow:0 1px 3px rgba(0,0,0,.25);
	}
	.netfie-toggle input:checked + .netfie-toggle-slider{ background:var(--nf-primary); }
	.netfie-toggle input:checked + .netfie-toggle-slider:before{ transform:translateX(18px); }

	.netfie-form-actions{ display:flex; gap:10px; margin-top:4px; }

	.netfie-empty-state{ text-align:center; padding:36px 20px; color:var(--nf-muted); }
	.netfie-empty-state span{ font-size:34px; display:block; margin-bottom:10px; }

	.netfie-table{ width:100%; border-collapse:collapse; }
	.netfie-table th{
		text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--nf-muted);
		padding:0 10px 10px; border-bottom:2px solid var(--nf-bg);
	}
	.netfie-table td{ padding:12px 10px; border-bottom:1px solid var(--nf-bg); font-size:13.5px; vertical-align:middle; }
	.netfie-table tr:last-child td{ border-bottom:none; }

	.netfie-method-cell{ display:flex; align-items:center; gap:10px; }
	.netfie-method-cell-icon{
		width:32px; height:32px; border-radius:50%; background:var(--nf-bg); display:flex; align-items:center;
		justify-content:center; font-weight:700; color:var(--nf-primary); overflow:hidden; flex-shrink:0; font-size:13px;
	}
	.netfie-method-cell-icon img{ width:100%; height:100%; object-fit:contain; }

	.netfie-pill{
		display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700;
		background:var(--nf-bg); color:var(--nf-primary-dark);
	}
	.netfie-pill-on{ background:#e6f6ea; color:#1e8a42; }
	.netfie-pill-off{ background:#f5f0f0; color:#9a8f8f; }

	.netfie-actions-cell{ white-space:nowrap; }
	.netfie-action-link{ font-weight:600; text-decoration:none; margin-right:12px; color:var(--nf-primary); }
	.netfie-action-link:hover{ text-decoration:underline; }
	.netfie-action-danger{ color:#c0392b; }

	@media (max-width:782px){
		.netfie-admin-header{ flex-direction:column; align-items:flex-start; }
	}
	</style>

	<script>
	jQuery(document).ready(function($){
		$('#netfie_upload_icon_btn').on('click', function(e){
			e.preventDefault();
			var frame = wp.media({ title: 'Select Icon', multiple: false, library: { type: 'image' } });
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				$('#netfie_method_icon').val(attachment.url);
				$('#netfie_icon_preview').html('<img src="'+attachment.url+'">');
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
					'description' => 'To add or edit bKash / Nagad / Rocket etc. go to <a href="' . esc_url( admin_url( 'admin.php?page=netfie-pay-methods' ) ) . '">Netfie Pay</a> in the left admin menu. Remember to use the <code>[netfie-woocommerce_checkout]</code> shortcode on your Checkout page (instead of <code>[woocommerce_checkout]</code>) to get the modern Netfie Pay checkout design.',
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

			// Get the formatted total cart payable amount
			$cart_total = WC()->cart->get_total();
			?>
			<div id="netfie-pay-box">
				<!-- Standard fields mapped inside form, populated in background from popup -->
				<input type="hidden" name="netfie_method_id" id="netfie_method_id" value="">
				<input type="hidden" name="netfie_sender_number" id="netfie_sender_number" value="">
				<input type="hidden" name="netfie_transaction_id" id="netfie_transaction_id" value="">

				<button type="button" class="netfie-select-btn" id="netfie-open-popup">
					<span class="netfie-select-btn-icon">💳</span>
					<span id="netfie-open-popup-label">Select Payment Method</span>
					<span class="netfie-select-btn-arrow">&rsaquo;</span>
				</button>

				<div id="netfie-selected-summary" class="netfie-summary-card" style="display:none;"></div>
			</div>

			<!-- Popup overlay which is safely moved to body by jquery -->
			<div id="netfie-popup-overlay" class="netfie-popup-overlay">
				<div class="netfie-popup-panel" role="dialog" aria-modal="true" aria-label="Choose a payment method">
					
					<!-- SCREEN 1: Grid selection screen -->
					<div id="netfie-screen-grid">
						<div class="netfie-popup-header">
							<div>
								<h3>Choose a Payment Method</h3>
								<p class="netfie-popup-subtitle">Select where you'll send the payment from</p>
							</div>
							<button type="button" class="netfie-popup-close" aria-label="Close">&times;</button>
						</div>
						<div class="netfie-method-grid">
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

					<!-- SCREEN 2: Secure input & confirmation panel (shown when method is clicked) -->
					<div id="netfie-screen-details" style="display: none; padding: 24px;">
						<button type="button" class="netfie-popup-back" id="netfie-btn-back">
							&larr; Back to payment methods
						</button>

						<div class="netfie-popup-method-info">
							<span id="netfie-detail-avatar" class="netfie-popup-avatar-container"></span>
							<div>
								<h4 id="netfie-detail-name" style="margin: 0; font-size: 18px; font-weight: 700; color: var(--netfie-text);"></h4>
								<p id="netfie-detail-type" style="margin: 2px 0 0; font-size: 13px; color: var(--netfie-muted);"></p>
							</div>
						</div>

						<!-- Large Total Payable Amount -->
						<div class="netfie-popup-amount-box">
							<span class="netfie-amount-label">Amount to Send</span>
							<div class="netfie-amount-val"><?php echo $cart_total; ?></div>
						</div>

						<!-- Copy recipient number block -->
						<div class="netfie-popup-number-box">
							<div class="netfie-popup-number-info">
								<span class="netfie-popup-number-label">Send payment to:</span>
								<span id="netfie-detail-number" class="netfie-popup-number-val"></span>
							</div>
							<button type="button" class="netfie-copy-btn" id="netfie-copy-btn">
								📋 Copy Number
							</button>
						</div>

						<!-- Dynamic instructions -->
						<p id="netfie-detail-instructions" class="netfie-popup-instructions-text"></p>

						<!-- Sender Inputs -->
						<div class="netfie-popup-fields">
							<p class="form-row">
								<label for="popup_netfie_sender_number">Your Sender Number <span class="required">*</span></label>
								<input type="tel" id="popup_netfie_sender_number" class="input-text" placeholder="e.g. 01XXXXXXXXX">
							</p>
							<p class="form-row">
								<label for="popup_netfie_transaction_id">Transaction ID <span class="required">*</span></label>
								<input type="text" id="popup_netfie_transaction_id" class="input-text" placeholder="e.g. 8N7A6XXXXX">
							</p>
						</div>

						<!-- Mirror Terms of service inside popup if activated in WC settings -->
						<?php if ( wc_terms_and_conditions_checkbox_enabled() ) : ?>
							<div class="woocommerce-terms-and-conditions-wrapper popup-terms-wrapper" style="margin-bottom: 20px;">
								<label class="checkbox">
									<input type="checkbox" id="popup_terms" style="margin-top:3px; accent-color: var(--netfie-primary);">
									<span>I have read and agree to the website <a href="<?php echo esc_url( wc_get_page_permalink( 'terms' ) ); ?>" target="_blank" class="woocommerce-terms-and-conditions-link">terms and conditions</a> <span class="required">*</span></span>
								</label>
							</div>
						<?php endif; ?>

						<!-- Popup Checkout actions block -->
						<div class="netfie-popup-action-block">
							<button type="button" class="netfie-popup-submit-btn" id="popup_place_order">
								Place order
							</button>
							<p class="netfie-popup-secure-note">
								🔒 Secure checkout &mdash; your information is protected
							</p>
						</div>
					</div>

				</div>
			</div>

			<style>
			#netfie-pay-box{ margin-top:4px; }

			.netfie-select-btn{
				display:flex; align-items:center; gap:12px; width:100%;
				background:linear-gradient(135deg,#6C2BD9,#54209f); color:#fff;
				border:none; border-radius:12px; padding:16px 20px; font-size:15px; font-weight:600;
				cursor:pointer; box-shadow:0 4px 14px rgba(108, 43, 217, 0.2);
				transition:all 0.2s ease;
			}
			.netfie-select-btn:hover{ transform:translateY(-1px); box-shadow:0 6px 20px rgba(108, 43, 217, 0.3); }
			.netfie-select-btn-icon{ font-size:18px; }
			.netfie-select-btn-arrow{ margin-left:auto; font-size:20px; opacity:.85; }

			.netfie-summary-card{
				margin-top:16px; padding:20px; border-radius:12px;
				background:#fcfbfe; border:1.5px solid #e7dcfa; display:flex; gap:16px; align-items:flex-start;
				animation:netfiePopIn .3s cubic-bezier(0.16, 1, 0.3, 1) both;
			}
			.netfie-summary-avatar{
				width:48px; height:48px; border-radius:50%; background:#fff; border:1.5px solid #e7dcfa;
				display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;
			}
			.netfie-summary-avatar img{ max-width:70%; max-height:70%; object-fit:contain; }
			.netfie-summary-title{ font-weight:700; font-size:15px; margin:0 0 4px; color: #2a2438; }
			.netfie-summary-number{ font-size:14px; margin:0; color: #4a4458; }
			.netfie-summary-number strong{ color:#6C2BD9; }

			.netfie-popup-overlay{
				position:fixed; inset:0; z-index:99999999 !important; background:rgba(15, 23, 42, 0.6) !important;
				backdrop-filter:blur(8px) !important; -webkit-backdrop-filter:blur(8px) !important;
				display:flex; align-items:center; justify-content:center; padding:20px;
				opacity:0; visibility:hidden; transition:all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
			}
			.netfie-popup-overlay.is-open{ opacity:1 !important; visibility:visible !important; }

			.netfie-popup-panel{
				background:#fff; width:100%; max-width:580px; max-height:90vh; overflow-y:auto;
				border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative;
				transform:scale(.95) translateY(15px); transition:all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
			}
			.netfie-popup-overlay.is-open .netfie-popup-panel{ transform:scale(1) translateY(0); }

			.netfie-popup-header{
				display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
				padding:24px 24px 18px; position:sticky; top:0; background:#fff; border-bottom:1px solid #f1f5f9; z-index:1;
			}
			.netfie-popup-header h3{ margin:0 0 4px; font-size:19px; font-weight:700; color: #0f172a; }
			.netfie-popup-subtitle{ margin:0; font-size:13px; color:#64748b; }
			.netfie-popup-close{
				border:none; background:#f1f5f9; color:#475569; width:32px; height:32px; border-radius:50%;
				font-size:18px; line-height:1; cursor:pointer; flex-shrink:0; transition:all .15s ease;
				display: flex; align-items: center; justify-content: center;
			}
			.netfie-popup-close:hover{ background:#e2e8f0; transform:rotate(90deg); color: #0f172a; }

			.netfie-method-grid{
				display:grid; grid-template-columns:1fr 1fr; gap:16px; padding:24px;
			}
			@media (max-width:480px){ .netfie-method-grid{ grid-template-columns:1fr; } }

			.netfie-method-item{
				position:relative; border:1.5px solid #e2e8f0; border-radius:14px; padding:20px 16px;
				display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px;
				cursor:pointer; background:#ffffff; transition:all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
			}
			.netfie-method-item:hover{ border-color:#6C2BD9; transform:translateY(-2px); box-shadow:0 10px 15px -3px rgba(108,43,217,.05); }
			.netfie-method-item.is-selected{ border-color:#6C2BD9; background:#faf5ff; box-shadow:0 0 0 4px rgba(108,43,217,.1); }

			.netfie-method-check{
				position:absolute; top:12px; right:12px; width:20px; height:20px; border-radius:50%;
				background:#6C2BD9; color:#fff; font-size:12px; display:flex; align-items:center; justify-content:center;
				opacity:0; transform:scale(.5); transition:all .15s cubic-bezier(0.16, 1, 0.3, 1);
			}
			.netfie-method-item.is-selected .netfie-method-check{ opacity:1; transform:scale(1); }

			.netfie-method-avatar{
				width:52px; height:52px; border-radius:50%; background:#fff; border:1px solid #e2e8f0;
				display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:4px;
			}
			.netfie-method-avatar img{ max-width:65%; max-height:65%; object-fit:contain; }
			.netfie-method-avatar-fallback{ font-weight:700; color:#6C2BD9; font-size:18px; }

			.netfie-method-name{ font-weight:700; font-size:14px; color: #0f172a; }
			.netfie-method-sub{ font-size:12px; color:#64748b; }

			/* ---- SCREEN 2: Secure popup details page styling ---- */
			.netfie-popup-back {
				background: none;
				border: none;
				color: var(--netfie-primary);
				font-size: 14px;
				font-weight: 600;
				cursor: pointer;
				display: flex;
				align-items: center;
				gap: 6px;
				padding: 0;
				margin-bottom: 22px;
				transition: color 0.15s ease;
			}
			.netfie-popup-back:hover { color: var(--netfie-primary-dark); }

			.netfie-popup-method-info {
				display: flex;
				align-items: center;
				gap: 14px;
				margin-bottom: 20px;
			}
			.netfie-popup-avatar-container {
				width: 46px;
				height: 46px;
				border-radius: 50%;
				background: #fff;
				border: 1.5px solid #e2e8f0;
				display: flex;
				align-items: center;
				justify-content: center;
				overflow: hidden;
			}
			.netfie-popup-avatar-container img { max-width: 65%; max-height: 65%; object-fit: contain; }

			.netfie-popup-amount-box {
				background: #faf5ff;
				border: 1.5px dashed #d8b4fe;
				border-radius: 12px;
				padding: 16px;
				text-align: center;
				margin-bottom: 20px;
			}
			.netfie-amount-label {
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				color: var(--netfie-muted);
				letter-spacing: 0.5px;
				display: block;
				margin-bottom: 4px;
			}
			.netfie-amount-val {
				font-size: 32px !important;
				font-weight: 800 !important;
				color: var(--netfie-primary) !important;
				line-height: 1.2;
			}
			.netfie-amount-val .woocommerce-Price-amount {
				font-size: 32px !important;
				font-weight: 800 !important;
				color: var(--netfie-primary) !important;
			}

			.netfie-popup-number-box {
				display: flex;
				align-items: center;
				justify-content: space-between;
				background: #f8fafc;
				border: 1.5px solid #e2e8f0;
				border-radius: 12px;
				padding: 14px 18px;
				margin-bottom: 16px;
				gap: 12px;
			}
			.netfie-popup-number-info {
				display: flex;
				flex-direction: column;
				gap: 2px;
			}
			.netfie-popup-number-label {
				font-size: 11px;
				color: var(--netfie-muted);
				text-transform: uppercase;
				font-weight: 700;
				letter-spacing: 0.3px;
			}
			.netfie-popup-number-val {
				font-size: 20px;
				font-weight: 700;
				color: var(--netfie-text);
				letter-spacing: 0.2px;
			}
			.netfie-copy-btn {
				background: #ffffff;
				border: 1.5px solid var(--netfie-border);
				color: var(--netfie-text);
				padding: 8px 14px;
				border-radius: 8px;
				font-size: 13px;
				font-weight: 600;
				cursor: pointer;
				transition: all 0.15s ease;
				white-space: nowrap;
			}
			.netfie-copy-btn:hover {
				border-color: var(--netfie-primary);
				color: var(--netfie-primary);
				background: #faf5ff;
			}

			.netfie-popup-instructions-text {
				font-size: 13.5px;
				color: var(--netfie-muted);
				line-height: 1.5;
				margin: 0 0 20px 0;
			}

			.netfie-popup-fields {
				margin-bottom: 20px;
			}
			.netfie-popup-fields .form-row {
				margin-bottom: 16px;
			}
			.netfie-popup-fields label {
				font-weight: 600;
				font-size: 13.5px;
				color: var(--netfie-text);
				margin-bottom: 6px;
				display: block;
			}

			.netfie-popup-action-block {
				border-top: 1.5px solid #f1f5f9;
				padding-top: 20px;
				margin-top: 20px;
			}
			.netfie-popup-submit-btn {
				width: 100%;
				background: linear-gradient(135deg, var(--netfie-primary), #ea580c) !important;
				color: #ffffff !important;
				border: none !important;
				border-radius: 12px !important;
				padding: 16px 24px !important;
				font-size: 16px !important;
				font-weight: 700 !important;
				cursor: pointer !important;
				box-shadow: 0 10px 25px -5px rgba(108, 43, 217, 0.3) !important;
				transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
				display: block;
				text-align: center;
			}
			.netfie-popup-submit-btn:hover {
				transform: translateY(-2px) !important;
				box-shadow: 0 15px 30px -5px rgba(108, 43, 217, 0.4) !important;
				filter: brightness(1.05);
			}
			.netfie-popup-submit-btn:active {
				transform: translateY(0) !important;
			}
			.netfie-popup-secure-note {
				text-align: center;
				font-size: 12.5px;
				font-weight: 500;
				color: var(--netfie-muted);
				margin: 12px 0 0;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
			}

			@keyframes netfiePopIn{
				from{ opacity:0; transform:translateY(8px); }
				to{ opacity:1; transform:translateY(0); }
			}
			</style>

			<script>
			(function($){
				// Append floating overlay container to page body to ensure correct viewport positioning
				function netfieMovePopupToBody(){
					var $popup = $('#netfie-popup-overlay');
					if ($popup.length && !$popup.parent().is('body')) {
						$('body').append($popup);
					}
				}

				function netfieOpenPopup(){
					netfieMovePopupToBody();
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
				$(document).on('click.netfie', '.netfie-popup-close', function(){
					netfieClosePopup();
				});
				$(document).on('click.netfie', '#netfie-popup-overlay', function(e){
					if ( e.target === this ) { netfieClosePopup(); }
				});
				$(document).on('keydown.netfie', function(e){
					if ( e.key === 'Escape' ) { netfieClosePopup(); }
				});

				// Grid method selection handler
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

					// Sync selected method ID with the hidden input field
					$('#netfie_method_id').val(id);

					// Populate details screen inputs
					$('#netfie-detail-avatar').html(iconHtml);
					$('#netfie-detail-name').text(name);
					$('#netfie-detail-type').text(type + ' Account');
					$('#netfie-detail-number').text(number);
					$('#netfie-detail-instructions').text(instructions || '');
					$('#netfie-copy-btn').data('number', number);

					// Reset inner details values before typing
					$('#popup_netfie_sender_number').val($('#netfie_sender_number').val());
					$('#popup_netfie_transaction_id').val($('#netfie_transaction_id').val());

					// Switch popup screen views with basic fade effect
					$('#netfie-screen-grid').hide();
					$('#netfie-screen-details').fadeIn(200);
				});

				// Back to selection screen handler
				$(document).on('click.netfie', '#netfie-btn-back', function(e){
					e.preventDefault();
					$('#netfie-screen-details').hide();
					$('#netfie-screen-grid').fadeIn(200);
				});

				// Real-time synchronization of inner fields with WooCommerce checkout form
				$(document).on('input', '#popup_netfie_sender_number', function() {
					$('#netfie_sender_number').val($(this).val());
				});
				$(document).on('input', '#popup_netfie_transaction_id', function() {
					$('#netfie_transaction_id').val($(this).val());
				});
				$(document).on('change', '#popup_terms', function() {
					$('input#terms, input[name="terms"]').prop('checked', $(this).is(':checked')).trigger('change');
				});

				// Copy recipient mobile number function with fallback mechanism
				$(document).on('click', '#netfie-copy-btn', function(e) {
					e.preventDefault();
					var num = $(this).data('number');
					var $btn = $(this);
					if (navigator.clipboard && window.isSecureContext) {
						navigator.clipboard.writeText(num).then(function() {
							netfieShowCopied($btn);
						}).catch(function() {
							netfieFallbackCopy(num, $btn);
						});
					} else {
						netfieFallbackCopy(num, $btn);
					}
				});

				function netfieFallbackCopy(text, $btn) {
					var $temp = $("<input>");
					$("body").append($temp);
					$temp.val(text).select();
					document.execCommand("copy");
					$temp.remove();
					netfieShowCopied($btn);
				}

				function netfieShowCopied($btn) {
					$btn.html('✅ Copied!');
					setTimeout(function() {
						$btn.html('📋 Copy Number');
					}, 2000);
				}

				// Place order action - maps directly to native checkout submission
				$(document).on('click', '#popup_place_order', function(e) {
					e.preventDefault();
					
					// Re-trigger synchronization check
					$('#netfie_sender_number').val($('#popup_netfie_sender_number').val());
					$('#netfie_transaction_id').val($('#popup_netfie_transaction_id').val());
					
					if ($('#popup_terms').length) {
						$('input#terms, input[name="terms"]').prop('checked', $('#popup_terms').is(':checked')).trigger('change');
					}

					// Close popup then trigger place order
					netfieClosePopup();
					
					// Trigger standard WooCommerce form submit button
					$('#place_order').trigger('click');
				});

				// Main checkout update sync
				function netfieUpdateCheckoutPreview() {
					var id = $('#netfie_method_id').val();
					if (id) {
						var $item = $('.netfie-method-item[data-id="' + id + '"]');
						if ($item.length) {
							var name = $item.data('name');
							var number = $item.data('number');
							var type = $item.data('type');
							var avatarHtml = $item.find('.netfie-method-avatar').html();

							var html = '<span class="netfie-summary-avatar">' + avatarHtml + '</span>' +
								'<div>' +
									'<p class="netfie-summary-title">' + name + ' &middot; ' + type + '</p>' +
									'<p class="netfie-summary-number">Send payment to: <strong>' + number + '</strong></p>' +
								'</div>';

							$('#netfie-selected-summary').html(html).show();
							$('#netfie-open-popup-label').text('Change: ' + name);
						}
					}
				}

				$(document).on('updated_checkout checkout_error', function(){
					netfieUpdateCheckoutPreview();
				});

				$(document).ready(function(){
					netfieMovePopupToBody();
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
 *    Provides a dedicated [netfie-woocommerce_checkout] shortcode.
 *    Use this instead of the default [woocommerce_checkout] shortcode
 *    on your Checkout page to get the modern Netfie Pay design.
 *    Internally it just renders the normal WooCommerce checkout and
 *    wraps it - no WooCommerce templates are overridden and no
 *    field/markup logic is changed, so it's safe to toggle on/off.
 * ========================================================================= */

/**
 * [netfie-woocommerce_checkout] - drop-in replacement for
 * [woocommerce_checkout] that renders the modern Netfie Pay design.
 */
add_shortcode( 'netfie-woocommerce_checkout', 'netfie_pay_checkout_shortcode' );
function netfie_pay_checkout_shortcode( $atts = array() ) {
	if ( ! function_exists( 'WC' ) ) {
		return '';
	}

	$output = do_shortcode( '[woocommerce_checkout]' );

	if ( netfie_pay_is_modern_checkout_enabled() ) {
		$header = '
		<div class="netfie-checkout-header">
			<div class="netfie-checkout-header-left">
				<h1 class="netfie-checkout-title">Checkout</h1>
				<p class="netfie-checkout-subtitle">Please review your billing details and select a secure payment method below.</p>
			</div>
			<div class="netfie-checkout-header-right">
				<span class="netfie-badge-secure">🔒 Secure 256-Bit SSL Checkout</span>
			</div>
		</div>';
		$output = '<div class="netfie-checkout-fullwidth">' . $header . $output . '</div>';
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
		--netfie-primary-dark:#4f1bb5;
		--netfie-accent:#FF7A00;
		--netfie-bg:#f8fafc;
		--netfie-card:#ffffff;
		--netfie-border:#e2e8f0;
		--netfie-text:#0f172a;
		--netfie-muted:#64748b;
		--netfie-radius:16px;
	}

	body.netfie-modern-checkout {
		overflow-x: hidden;
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

	/* Beautiful 2-column grid structure: Left gets Billing Details, Right gets Order / Subtotal review */
	body.netfie-modern-checkout .netfie-checkout-fullwidth form.woocommerce-checkout{
		max-width:1400px;
		width:100%;
		margin:0 auto;
		font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
		color:var(--netfie-text);
		display:grid;
		grid-template-columns:1fr 450px;
		column-gap:40px;
		row-gap:0;
		align-items:start;
	}

	@media (max-width:1024px){
		body.netfie-modern-checkout .netfie-checkout-fullwidth form.woocommerce-checkout{
			grid-template-columns:1fr;
			gap:24px;
		}
	}

	/* ---- Header Section ---- */
	body.netfie-modern-checkout .netfie-checkout-header {
		max-width: 1400px;
		width: 100%;
		margin: 0 auto 32px;
		display: flex;
		justify-content: space-between;
		align-items: flex-end;
		flex-wrap: wrap;
		gap: 16px;
		border-bottom: 2px solid var(--netfie-border);
		padding-bottom: 24px;
	}
	body.netfie-modern-checkout .netfie-checkout-header h1.netfie-checkout-title {
		font-size: 32px;
		font-weight: 800;
		color: var(--netfie-text);
		margin: 0 0 6px;
		letter-spacing: -0.5px;
		line-height: 1.2;
	}
	body.netfie-modern-checkout .netfie-checkout-header .netfie-checkout-subtitle {
		font-size: 15px;
		color: var(--netfie-muted);
		margin: 0;
	}
	body.netfie-modern-checkout .netfie-checkout-header .netfie-badge-secure {
		background: #e2e8f0;
		color: var(--netfie-text);
		padding: 8px 16px;
		border-radius: 20px;
		font-size: 13px;
		font-weight: 600;
	}

	/* Left side columns mapped into left grid side stack */
	body.netfie-modern-checkout .netfie-checkout-fullwidth #customer_details{
		grid-column:1;
		grid-row:1 / span 3;
	}
	
	/* Order heading mapped neatly above the order review container */
	body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review_heading {
		grid-column: 2;
		grid-row: 1;
		margin: 0 0 16px 0;
		font-size: 20px;
		font-weight: 700;
		color: var(--netfie-text);
		display: flex;
		align-items: center;
		gap: 8px;
	}

	/* Right column containing order summaries, products and gateways */
	body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review{
		grid-column:2;
		grid-row:2;
		position:sticky;
		top:24px;
		background:var(--netfie-card);
		border:1px solid var(--netfie-border);
		border-radius:var(--netfie-radius);
		padding:32px;
		box-shadow:0 10px 25px -5px rgba(0,0,0,0.02), 0 8px 10px -6px rgba(0,0,0,0.02);
		animation:netfieFadeUp .5s ease both;
		animation-delay:.15s;
	}

	@media (max-width:1024px){
		body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review_heading {
			grid-column:1;
			grid-row: auto;
			margin-top: 24px;
		}
		body.netfie-modern-checkout .netfie-checkout-fullwidth #order_review{
			grid-column:1;
			grid-row: auto;
			position:static;
		}
	}

	/* Stack standard floated layout column systems beautifully */
	body.netfie-modern-checkout .col-1,
	body.netfie-modern-checkout .col-2 {
		float: none !important;
		width: 100% !important;
		margin: 0 !important;
		padding: 0 !important;
	}

	/* ---- Card sections ---- */
	body.netfie-modern-checkout .woocommerce-billing-fields,
	body.netfie-modern-checkout .woocommerce-additional-fields {
		background:var(--netfie-card);
		border:1px solid var(--netfie-border);
		border-radius:var(--netfie-radius);
		padding:32px;
		margin-bottom:24px;
		box-shadow:0 10px 25px -5px rgba(0, 0, 0, 0.02);
		animation:netfieFadeUp .5s ease both;
	}
	body.netfie-modern-checkout .woocommerce-additional-fields{ animation-delay:.1s; }

	/* Handle Shipping Card elegantly when active */
	body.netfie-modern-checkout .woocommerce-shipping-fields {
		background: transparent !important;
		border: none !important;
		box-shadow: none !important;
		padding: 0 !important;
		margin-bottom: 24px;
	}
	body.netfie-modern-checkout #ship-to-different-address {
		background: var(--netfie-card);
		border: 1px solid var(--netfie-border);
		border-radius: var(--netfie-radius);
		padding: 20px 24px;
		margin-bottom: 0;
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01);
		display: flex;
		align-items: center;
		gap: 12px;
	}
	body.netfie-modern-checkout #ship-to-different-address label {
		margin: 0;
		display: inline-flex;
		align-items: center;
		cursor: pointer;
		font-size: 16px;
		font-weight: 700;
		color: var(--netfie-text);
	}
	body.netfie-modern-checkout #ship-to-different-address input[type="checkbox"] {
		margin: 0;
		width: 18px;
		height: 18px;
		accent-color: var(--netfie-primary);
	}
	body.netfie-modern-checkout .shipping_address {
		background: var(--netfie-card) !important;
		border: 1px solid var(--netfie-border) !important;
		border-radius: var(--netfie-radius) !important;
		padding: 32px !important;
		margin-top: 20px !important;
		box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.02) !important;
	}

	body.netfie-modern-checkout .woocommerce-billing-fields > h3,
	body.netfie-modern-checkout .woocommerce-additional-fields > h3{
		font-size:18px;
		font-weight:700;
		margin-top:0;
		margin-bottom:20px;
		padding-bottom:14px;
		border-bottom:2px solid var(--netfie-bg);
		color: var(--netfie-text);
	}

	/* ---- Inputs & Fields ---- */
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
	body.netfie-modern-checkout .form-row{ margin-bottom:18px; }

	body.netfie-modern-checkout .form-row-first,
	body.netfie-modern-checkout .form-row-last {
		width: 48% !important;
		float: left !important;
	}
	body.netfie-modern-checkout .form-row-wide {
		width: 100% !important;
		clear: both !important;
	}
	body.netfie-modern-checkout .woocommerce-billing-fields__field-wrapper::after,
	body.netfie-modern-checkout .woocommerce-shipping-fields__field-wrapper::after,
	body.netfie-modern-checkout .form-row::after {
		content: "";
		display: table;
		clear: both;
	}

	/* ---- Order review table ---- */
	body.netfie-modern-checkout table.shop_table{
		border:none;
		border-collapse:collapse;
		width:100%;
		margin-bottom: 24px;
	}
	body.netfie-modern-checkout table.shop_table th{
		font-size: 13px;
		font-weight: 700;
		text-transform: uppercase;
		color: var(--netfie-muted);
		letter-spacing: 0.5px;
		padding-bottom: 12px;
		border-bottom: 2px solid #f1f5f9;
	}
	body.netfie-modern-checkout table.shop_table td{
		border:none;
		border-bottom:1px solid #f1f5f9;
		padding:16px 0;
		font-size:14px;
		color: var(--netfie-text);
	}
	body.netfie-modern-checkout table.shop_table .cart_item td:first-child{
		font-weight: 500;
	}
	body.netfie-modern-checkout table.shop_table .product-total{
		text-align: right;
		font-weight: 600;
	}
	body.netfie-modern-checkout table.shop_table tfoot th{
		font-weight: 600;
		color: var(--netfie-text);
		padding: 14px 0;
		border-bottom: 1px solid #f1f5f9;
	}
	body.netfie-modern-checkout table.shop_table tfoot td{
		text-align: right;
		font-weight: 600;
		padding: 14px 0;
		border-bottom: 1px solid #f1f5f9;
	}
	body.netfie-modern-checkout table.shop_table tfoot tr.order-total th{
		font-size: 16px;
		font-weight: 700;
		color: var(--netfie-text);
		border-bottom: none;
	}
	body.netfie-modern-checkout table.shop_table tfoot tr.order-total td{
		font-size: 18px;
		font-weight: 800;
		color: var(--netfie-primary);
		border-bottom: none;
	}

	/* ---- Payment methods list ---- */
	body.netfie-modern-checkout #payment {
		background: transparent !important;
		padding: 0 !important;
		border: none !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods{
		list-style:none;
		margin:0 0 24px !important;
		padding:0 !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method{
		border:1.5px solid var(--netfie-border) !important;
		border-radius:12px !important;
		margin-bottom:12px !important;
		padding:16px 18px !important;
		transition:all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
		background:#f8fafc !important;
		list-style: none !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method:hover{
		border-color:#cbd5e1 !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.netfie-selected{
		border-color:var(--netfie-primary) !important;
		box-shadow:0 0 0 4px rgba(108,43,217,.08) !important;
		background:#fff !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method label{
		font-weight:600;
		font-size:15px !important;
		color: var(--netfie-text) !important;
		cursor: pointer !important;
	}
	body.netfie-modern-checkout ul.wc_payment_methods li.wc_payment_method input[type="radio"] {
		margin-right: 10px !important;
		accent-color: var(--netfie-primary) !important;
	}
	body.netfie-modern-checkout .payment_box{
		background:#f1f5f9 !important;
		border-radius:8px !important;
		padding:16px !important;
		margin-top:12px !important;
		border:none !important;
		font-size: 13.5px !important;
		line-height: 1.5 !important;
		color: var(--netfie-muted) !important;
	}
	body.netfie-modern-checkout .payment_box:before{ display:none; }

	/* ---- Privacy & Terms Checklist ---- */
	body.netfie-modern-checkout .woocommerce-privacy-policy-text {
		font-size: 13px;
		color: var(--netfie-muted);
		line-height: 1.5;
		margin-bottom: 16px;
	}
	body.netfie-modern-checkout .woocommerce-terms-and-conditions-wrapper {
		margin-bottom: 24px;
	}
	body.netfie-modern-checkout .woocommerce-terms-and-conditions-wrapper label {
		font-size: 13.5px;
		font-weight: 500;
		color: var(--netfie-text);
		display: flex !important;
		align-items: flex-start;
		gap: 8px;
		cursor: pointer;
	}
	body.netfie-modern-checkout .woocommerce-terms-and-conditions-wrapper input[type="checkbox"] {
		margin-top: 3px;
		accent-color: var(--netfie-primary);
	}

	/* ---- Place order button ---- */
	body.netfie-modern-checkout #place_order{
		width:100%;
		background:linear-gradient(135deg, var(--netfie-primary), #ea580c) !important;
		color:#fff !important;
		border:none !important;
		border-radius:12px !important;
		padding:18px 24px !important;
		font-size:16px !important;
		font-weight:700 !important;
		letter-spacing:.3px !important;
		cursor:pointer !important;
		transition:all .2s cubic-bezier(0.16, 1, 0.3, 1) !important;
		box-shadow:0 10px 25px -5px rgba(108,43,217,.3) !important;
	}
	body.netfie-modern-checkout #place_order:hover{
		transform:translateY(-2px) !important;
		box-shadow:0 15px 30px -5px rgba(108,43,217,.4) !important;
		filter:brightness(1.05);
	}
	body.netfie-modern-checkout #place_order:active{
		transform:translateY(0) !important;
	}

	/* ---- Coupon box ---- */
	body.netfie-modern-checkout .woocommerce-form-coupon-toggle .woocommerce-info{
		background: #f1f5f9;
		border: 1px solid #e2e8f0;
		border-left: 4px solid var(--netfie-primary);
		border-radius:12px;
		color: var(--netfie-text);
		padding: 16px 20px;
		font-size: 14px;
		font-weight: 500;
	}
	body.netfie-modern-checkout .woocommerce-form-coupon-toggle .woocommerce-info a {
		color: var(--netfie-primary);
		font-weight: 600;
		text-decoration: none;
	}
	body.netfie-modern-checkout .woocommerce-form-coupon-toggle .woocommerce-info a:hover {
		text-decoration: underline;
	}
	body.netfie-modern-checkout form.checkout_coupon {
		background: var(--netfie-card);
		border: 1px solid var(--netfie-border);
		border-radius: var(--netfie-radius);
		padding: 24px;
		margin-bottom: 24px;
		box-shadow: 0 4px 12px rgba(0,0,0,0.02);
	}
	body.netfie-modern-checkout form.checkout_coupon .button {
		border-radius: 10px !important;
		background: var(--netfie-primary) !important;
		padding: 12px 20px !important;
	}

	/* ---- Notices ---- */
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-error,
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-message{
		border-radius:12px !important;
		padding:18px 24px !important;
		font-size:14px !important;
		font-weight:500 !important;
		line-height:1.5 !important;
		margin-bottom:24px !important;
		border:1px solid rgba(0,0,0,0.05) !important;
		animation:netfieFadeUp .3s ease both;
	}
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-error {
		background-color: #fef2f2 !important;
		border-left: 4px solid #ef4444 !important;
		color: #991b1b !important;
	}
	body.netfie-modern-checkout .woocommerce-NoticeGroup .woocommerce-message {
		background-color: #f0fdf4 !important;
		border-left: 4px solid #22c55e !important;
		color: #166534 !important;
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
		}

		function netfieAddSecureNote(){
			if ( $('.netfie-secure-note').length ) { return; }
			$('.woocommerce-checkout-payment, #payment').last()
				.append('<p class="netfie-secure-note">🔒 Secure checkout &mdash; your information is protected</p>');
		}

		// Dynamically hide shipping or additional notes card wrappers if they are empty
		function netfieHideEmptyCards(){
			$('.woocommerce-additional-fields').each(function(){
				if ( $(this).find('input, textarea, select').length === 0 ) {
					$(this).hide();
				} else {
					$(this).show();
				}
			});
			if ( ! $('#ship-to-different-address-checkbox').is(':checked') ) {
				$('.shipping_address').hide();
			}
		}

		function netfieRunAll(){
			netfieHighlightSelected();
			netfieAddSectionIcons();
			netfieAddSecureNote();
			netfieHideEmptyCards();
		}

		$(document.body).on('updated_checkout payment_method_selected change', netfieRunAll);
		$(document).ready(netfieRunAll);
	})(jQuery);
	</script>
	<style>
	.netfie-h-icon{ margin-right:4px; }
	.netfie-secure-note{
		text-align:center; font-size:13px; font-weight:500; color:#64748b; margin:18px 0 0;
		display: flex; align-items: center; justify-content: center; gap: 6px;
	}
	</style>
	<?php
}

/* =========================================================================
 * 6. CUSTOM FRONT-END STATUS OVERRIDES FOR MY ACCOUNT / VIEW ORDER PAGE
 * ========================================================================= */

/**
 * Filter the paid statuses array on front-end order view pages.
 * If the customer submitted manual payment details (Sender + Txn ID),
 * we dynamically treat the order status as a paid state, clearing theme "Unpaid" tags.
 */
add_filter( 'woocommerce_order_is_paid_statuses', 'netfie_pay_conditional_paid_status', 10, 1 );
function netfie_pay_conditional_paid_status( $statuses ) {
	if ( is_admin() ) {
		return $statuses;
	}

	global $wp;
	if ( isset( $wp->query_vars['view-order'] ) ) {
		$order_id = absint( $wp->query_vars['view-order'] );
		$order    = wc_get_order( $order_id );
		if ( $order && $order->get_payment_method() === 'netfie_pay' ) {
			$sender = $order->get_meta( '_netfie_sender_number' );
			$txn    = $order->get_meta( '_netfie_transaction_id' );
			if ( $sender && $txn ) {
				// Safely append current order status to the paid collection
				$statuses[] = $order->get_status();
			}
		}
	}
	return $statuses;
}

/* =========================================================================
 * 7. ACTIVATION NOTICE IF WOOCOMMERCE MISSING
 * ========================================================================= */

add_action( 'admin_notices', 'netfie_pay_missing_wc_notice' );
function netfie_pay_missing_wc_notice() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>Netfie Pay</strong> requires WooCommerce to be installed and active.</p></div>';
	}
}