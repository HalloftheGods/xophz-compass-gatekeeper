<?php
/**
 * The admin-specific functionality of Gatekeeper.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Xophz_Compass_Gatekeeper_Admin {

	/** The Settings page keeps its old slug so every existing link to Gatekeeper XP Pro still lands. */
	const PAGE_LICENSES = 'gatekeeper-xp-pro';
	const PAGE_XP_PRO   = self::PAGE_LICENSES;

	/** Query args of the redirect back to the page: which notice to show, and for which product. */
	const NOTICE_ARG  = 'xp_pro_notice';
	const PRODUCT_ARG = 'gatekeeper_product';

	/** A form posted without a product (rendered before this update) means xp-pro. */
	const DEFAULT_PRODUCT = 'xp-pro';

	const STATUSES = array( 'active', 'expired', 'revoked' );
	const ENVS     = array( 'production', 'sandbox' );

	/** Webhook slot of a product's webhooks_option => its form field and the words on the page. */
	const WEBHOOK_FIELDS = array(
		'stripe'      => array( 'field' => 'stripe_webhook_id', 'label' => 'Stripe webhook id', 'hook' => 'Stripe', 'mode' => 'HMAC, header Stripe-Signature' ),
		'stripe_test' => array( 'field' => 'stripe_test_webhook_id', 'label' => 'Stripe test webhook id', 'hook' => 'Stripe (test)', 'mode' => 'HMAC, header Stripe-Signature' ),
		'revenuecat'  => array( 'field' => 'revenuecat_webhook_id', 'label' => 'RevenueCat webhook id', 'hook' => 'RevenueCat', 'mode' => 'Bearer' ),
	);

	private string $plugin_name;
	private string $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( 'admin_menu', array( $this, 'register_licenses_page' ) );
		// The action names are the xp-pro ones from before; the product rides along as a hidden field.
		add_action( 'admin_post_gatekeeper_xp_pro_save', array( $this, 'handle_save_xp_pro' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_reset', array( $this, 'handle_reset_xp_pro_devices' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_restore', array( $this, 'handle_restore_xp_pro' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_update_license', array( $this, 'handle_update_xp_pro_license' ) );
	}

	/**
	 * Settings > Gatekeeper Licenses: one section per registered license product with its webhook
	 * ids, its settings and its license list.
	 */
	public function register_licenses_page(): void {
		add_options_page(
			'Gatekeeper Licenses',
			'Gatekeeper Licenses',
			'manage_options',
			self::PAGE_LICENSES,
			array( $this, 'render_licenses_page' )
		);
	}

	public function render_licenses_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'xophz-compass-gatekeeper' ) );
		}
		echo '<div class="wrap"><h1>Gatekeeper Licenses</h1>';
		echo $this->notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice_html().
		$this->render_license_sections();
		echo '</div>';
	}

	/** The notice for the redirect arg, empty when there is none or it is unknown. */
	private function notice_html(): string {
		$key = $this->query_arg( self::NOTICE_ARG );
		$pid = $this->query_arg( self::PRODUCT_ARG );

		$product  = '' === $pid ? null : Gatekeeper_License_Products::get( $pid );
		$label    = null === $product ? 'License' : (string) $product['label'];
		$messages = array(
			'saved'           => array( 'success', $label . ' settings saved.' ),
			'reset'           => array( 'success', 'License devices reset.' ),
			'restored'        => array( 'success', 'License restored to active status.' ),
			'updated'         => array( 'success', 'License updated.' ),
			'unknown_product' => array( 'error', 'Unknown license product.' ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return '';
		}
		list( $type, $text ) = $messages[ $key ];
		return sprintf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	private function query_arg( string $name ): string {
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_key( (string) $_GET[ $name ] ) : '';
	}

	/* ---- Saving ------------------------------------------------------------------------------ */

	/**
	 * Save one product's settings form. Webhook ids are positive integers (0 means not set) and only
	 * the slots the form posted change; an int setting below its minimum (1 unless the schema row says
	 * otherwise) keeps the old value; a setting the form did not post is left alone. Each setting is
	 * stored through the product's settings schema, in the option the schema names.
	 */
	public static function save_product_settings( string $product_id, array $input ): void {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return;
		}

		$webhooks = get_option( $product['webhooks_option'], array() );
		$webhooks = is_array( $webhooks ) ? $webhooks : array();
		foreach ( self::WEBHOOK_FIELDS as $slot => $meta ) {
			if ( array_key_exists( $meta['field'], $input ) ) {
				$webhooks[ $slot ] = max( 0, self::int_of( $input[ $meta['field'] ] ) );
			}
		}
		update_option( $product['webhooks_option'], $webhooks, false );

		foreach ( $product['settings'] as $key => $schema ) {
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( 'int' === $schema['type'] ) {
				$int = filter_var( trim( (string) $value ), FILTER_VALIDATE_INT );
				if ( false === $int || $int < (int) ( $schema['min'] ?? 1 ) ) {
					continue;
				}
				$value = $int;
			} elseif ( 'string' === $schema['type'] ) {
				$value = sanitize_text_field( (string) $value );
			}
			Gatekeeper_License_Products::write_setting( $product_id, $key, $value );
		}
	}

	/** Legacy entry for the xp-pro form fields (stripe_webhook_id, revenuecat_webhook_id, ai_daily_limit). */
	public static function save_xp_pro_settings( array $input ): void {
		self::save_product_settings( self::DEFAULT_PRODUCT, $input );
	}

	private static function int_of( $value ): int {
		return is_scalar( $value ) ? (int) trim( (string) $value ) : 0;
	}

	public function handle_save_xp_pro(): void {
		$product = $this->begin( 'gatekeeper_xp_pro_save' );
		self::save_product_settings( $product['id'], wp_unslash( $_POST ) );
		$this->back_to_licenses( 'saved', $product['id'] );
	}

	public function handle_reset_xp_pro_devices(): void {
		$product = $this->begin( 'gatekeeper_xp_pro_reset' );
		$account = $this->posted( $product['account_param'] );
		if ( Gatekeeper_License_Store::is_account( $product['id'], $account ) ) {
			Gatekeeper_License_Store::reset_devices( $product['id'], $account );
		}
		$this->back_to_licenses( 'reset', $product['id'] );
	}

	public function handle_restore_xp_pro(): void {
		$product = $this->begin( 'gatekeeper_xp_pro_restore' );
		$account = $this->posted( $product['account_param'] );
		if ( Gatekeeper_License_Store::is_account( $product['id'], $account ) ) {
			Gatekeeper_License_Store::restore( $product['id'], $account, get_current_user_id() );
		}
		$this->back_to_licenses( 'restored', $product['id'] );
	}

	public function handle_update_xp_pro_license(): void {
		$product = $this->begin( 'gatekeeper_xp_pro_update_license' );
		$account = $this->posted( $product['account_param'] );
		if ( Gatekeeper_License_Store::is_account( $product['id'], $account ) ) {
			$fields = array(
				'plan'       => $this->posted( 'plan' ),
				'status'     => $this->posted( 'status' ),
				'env'        => $this->posted( 'env' ),
				'expires_at' => $this->posted( 'expires_at' ),
				'email'      => $this->posted( 'email' ),
			);
			Gatekeeper_License_Store::update_admin( $product['id'], $account, $fields, get_current_user_id() );
		}
		$this->back_to_licenses( 'updated', $product['id'] );
	}

	/**
	 * Every write needs manage_options and a valid nonce for its own action and product, and names a
	 * registered product. Returns that product; an unknown one goes back to the page with an error.
	 */
	private function begin( string $action ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'xophz-compass-gatekeeper' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_POST['product'] ) ? sanitize_key( $this->posted( 'product' ) ) : self::DEFAULT_PRODUCT;
		check_admin_referer( self::nonce_action( $action, $id ) );
		$product = Gatekeeper_License_Products::get( $id );
		if ( null === $product ) {
			$this->back_to_licenses( 'unknown_product' );
		}
		return $product;
	}

	/** One nonce per action and product, so a form of one product cannot drive another. */
	private static function nonce_action( string $action, string $product ): string {
		return $action . ':' . $product;
	}

	private function posted( string $name ): string {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) ) : '';
	}

	private function back_to_licenses( string $notice, string $product_id = '' ): void {
		$args = array( 'page' => self::PAGE_LICENSES, self::NOTICE_ARG => $notice );
		if ( '' !== $product_id ) {
			$args[ self::PRODUCT_ARG ] = $product_id;
		}
		$url = add_query_arg( $args, admin_url( 'options-general.php' ) );
		wp_safe_redirect( '' === $product_id ? $url : $url . '#gk-product-' . $product_id );
		exit;
	}

	/* ---- Rendering --------------------------------------------------------------------------- */

	/** One section per registered license product. */
	public function render_license_sections(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$products = Gatekeeper_License_Products::all();
		if ( empty( $products ) ) {
			echo '<p>No license products are registered.</p>';
			return;
		}
		foreach ( $products as $product ) {
			$this->render_product_section( $product );
		}
	}

	private function render_product_section( array $product ): void {
		$id       = $product['id'];
		$label    = (string) $product['label'];
		$webhooks = get_option( $product['webhooks_option'], array() );
		$webhooks = is_array( $webhooks ) ? $webhooks : array();
		$records  = Gatekeeper_License_Store::all( $id );
		$plans    = Gatekeeper_License_Store::plan_ids( $id );
		$post_url = admin_url( 'admin-post.php' );
		$account  = (string) $product['account_param'];
		?>
		<div class="gk-license-product" id="gk-product-<?php echo esc_attr( $id ); ?>">
		<h2><?php echo esc_html( $label ); ?></h2>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php $this->print_hidden( 'gatekeeper_xp_pro_save', $product ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::WEBHOOK_FIELDS as $slot => $meta ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( 'gk-' . $id . '-' . $slot ); ?>"><?php echo esc_html( $meta['label'] ); ?></label></th>
						<td><input id="<?php echo esc_attr( 'gk-' . $id . '-' . $slot ); ?>" name="<?php echo esc_attr( $meta['field'] ); ?>" type="number" min="0" value="<?php echo esc_attr( (int) ( $webhooks[ $slot ] ?? 0 ) ); ?>" class="small-text" />
						<p class="description">Post id of the Hookshot webhook "<?php echo esc_html( $label . ': ' . $meta['hook'] ); ?>" (mode <?php echo esc_html( $meta['mode'] ); ?>).</p></td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $product['settings'] as $key => $schema ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( 'gk-' . $id . '-' . $key ); ?>"><?php echo esc_html( $schema['label'] ); ?></label></th>
						<td><?php $this->print_setting_input( $product, (string) $key, $schema ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( 'Save ' . $label . ' settings' ); ?>
		</form>

		<h3><?php echo esc_html( $label ); ?> licenses</h3>
		<?php if ( empty( $records ) ) : ?>
			<p>No <?php echo esc_html( $label ); ?> licenses yet.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Key</th><th>Plan</th><th>Status</th><th>Expires (blank = never)</th><th>Env</th><th>Email</th><th>Devices</th><th>Last event</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $records as $record ) : ?>
					<?php
					$row     = (string) ( $record[ $account ] ?? '' );
					$form_id = 'gk-lic-' . $id . '-' . $row;
					?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $record['license_key'] ?? '' ) ); ?></code></td>
						<td><select form="<?php echo esc_attr( $form_id ); ?>" name="plan"><?php echo self::options_html( $plans, (string) ( $record['plan'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
						<td><select form="<?php echo esc_attr( $form_id ); ?>" name="status"><?php echo self::options_html( self::STATUSES, (string) ( $record['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
						<td><input form="<?php echo esc_attr( $form_id ); ?>" type="text" name="expires_at" value="<?php echo esc_attr( (string) ( $record['expires_at'] ?? '' ) ); ?>" /></td>
						<td><select form="<?php echo esc_attr( $form_id ); ?>" name="env"><?php echo self::options_html( self::ENVS, (string) ( $record['env'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
						<td><input form="<?php echo esc_attr( $form_id ); ?>" type="email" name="email" value="<?php echo esc_attr( (string) ( $record['email'] ?? '' ) ); ?>" /></td>
						<td><?php echo esc_html( count( (array) ( $record['devices'] ?? array() ) ) ); ?> / <?php echo esc_html( $product['max_devices'] ); ?></td>
						<td class="gk-last-event"><?php echo self::last_event_html( $record ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<?php $this->print_hidden( 'gatekeeper_xp_pro_update_license', $product, $row ); ?>
								<button type="submit" class="button button-primary button-small">Save</button>
							</form>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<?php $this->print_hidden( 'gatekeeper_xp_pro_reset', $product, $row ); ?>
								<button type="submit" class="button button-small">Reset devices</button>
							</form>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<?php $this->print_hidden( 'gatekeeper_xp_pro_restore', $product, $row ); ?>
								<button type="submit" class="button button-small">Restore</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The hidden fields every form carries: the action, the product, the account when the form acts
	 * on one license (named by the product's account_param, family_id for xp-pro) and the nonce.
	 */
	private function print_hidden( string $action, array $product, ?string $account = null ): void {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="product" value="%s" />', esc_attr( $product['id'] ) );
		if ( null !== $account ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $product['account_param'] ), esc_attr( $account ) );
		}
		wp_nonce_field( self::nonce_action( $action, $product['id'] ) );
	}

	/** The input of one settings schema row (int, bool or string), holding the value read through the schema. */
	private function print_setting_input( array $product, string $key, array $schema ): void {
		$dom   = 'gk-' . $product['id'] . '-' . $key;
		$value = Gatekeeper_License_Products::read_setting( $product['id'], $key );
		if ( 'bool' === $schema['type'] ) {
			// The hidden 0 comes first: an unchecked box posts nothing and the last value of a name wins.
			printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $key ) );
			printf( '<input id="%s" name="%s" type="checkbox" value="1"%s />', esc_attr( $dom ), esc_attr( $key ), $value ? ' checked="checked"' : '' );
		} elseif ( 'int' === $schema['type'] ) {
			printf( '<input id="%s" name="%s" type="number" min="%d" value="%s" class="small-text" />', esc_attr( $dom ), esc_attr( $key ), (int) ( $schema['min'] ?? 1 ), esc_attr( (string) $value ) );
		} else {
			printf( '<input id="%s" name="%s" type="text" value="%s" class="regular-text" />', esc_attr( $dom ), esc_attr( $key ), esc_attr( (string) $value ) );
		}
	}

	/** The options of a select. A current value that is not offered (a retired plan) is listed too, so Save never changes it silently. */
	private static function options_html( array $options, string $current ): string {
		if ( '' !== $current && ! in_array( $current, $options, true ) ) {
			array_unshift( $options, $current );
		}
		$html = '';
		foreach ( $options as $option ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( (string) $option ), (string) $option === $current ? ' selected="selected"' : '' );
		}
		return $html;
	}

	/** The Last event cell: what the latest applied event did, where it came from and when. */
	private static function last_event_html( array $record ): string {
		$event  = is_array( $record['last_event'] ?? null ) ? $record['last_event'] : array();
		$action = (string) ( $event['action'] ?? '' );
		if ( '' === $action ) {
			return '<span class="description">none yet</span>';
		}
		$source = (string) ( $event['source'] ?? '' );
		$at     = (string) ( $event['at'] ?? '' );
		$html   = '<strong>' . esc_html( $action ) . '</strong>';
		if ( '' !== $source ) {
			$html .= ' via ' . esc_html( $source );
		}
		if ( '' !== $at ) {
			$html .= '<br /><small>' . esc_html( $at ) . '</small>';
		}
		return $html;
	}

	/**
	 * Render settings/dashboard page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'xophz-compass-gatekeeper' ) );
		}

		$keys     = Gatekeeper_Keys::list_keys();
		$sponsors = Gatekeeper_Entitlements::list_sponsors();
		?>
		<div class="wrap" style="max-width: 1100px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
			<div style="display: flex; align-items: center; gap: 16px; margin: 24px 0 20px;">
				<img src="<?php echo esc_url( XOPHZ_COMPASS_GATEKEEPER_URL . 'icon.png' ); ?>" alt="Gatekeeper Wizard" style="width: 56px; height: 56px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);" />
				<div>
					<h1 style="margin: 0; font-size: 26px; font-weight: 700; color: #1e293b;">Xophz Gatekeeper</h1>
					<p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Central Machine Authentication, API Keys, and SaaS Entitlements (Default Prefix: <code>stargate_</code>)</p>
				</div>
			</div>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
					<div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em;">Active Machine Keys</div>
					<div style="font-size: 32px; font-weight: 800; color: #9333ea; margin-top: 8px;"><?php echo esc_html( count( $keys ) ); ?></div>
					<div style="font-size: 12px; color: #64748b; margin-top: 4px;">For CLI, AI agents, and microservices</div>
				</div>

				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
					<div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em;">Active GitHub Sponsors</div>
					<div style="font-size: 32px; font-weight: 800; color: #0284c7; margin-top: 8px;"><?php echo esc_html( count( $sponsors ) ); ?></div>
					<div style="font-size: 12px; color: #64748b; margin-top: 4px;">Synced via Hookshot Webhook Bridge</div>
				</div>
			</div>

			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<h2 style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 0;">REST API Endpoints</h2>
				<table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
					<thead>
						<tr style="border-bottom: 2px solid #f1f5f9;">
							<th style="padding: 10px 0; color: #64748b;">Method</th>
							<th style="padding: 10px 0; color: #64748b;">Endpoint</th>
							<th style="padding: 10px 0; color: #64748b;">Description</th>
						</tr>
					</thead>
					<tbody>
						<tr style="border-bottom: 1px solid #f1f5f9;">
							<td style="padding: 10px 0;"><span style="background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 4px; font-weight: 600;">GET</span></td>
							<td style="padding: 10px 0;"><code>/wp-json/compass/v1/gatekeeper/status</code></td>
							<td style="padding: 10px 0; color: #475569;">Health check and active metrics</td>
						</tr>
						<tr style="border-bottom: 1px solid #f1f5f9;">
							<td style="padding: 10px 0;"><span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 4px; font-weight: 600;">POST</span></td>
							<td style="padding: 10px 0;"><code>/wp-json/compass/v1/gatekeeper/licenses/validate</code></td>
							<td style="padding: 10px 0; color: #475569;">Validate Chemical X license keys and GitHub sponsor handles</td>
						</tr>
						<tr style="border-bottom: 1px solid #f1f5f9;">
							<td style="padding: 10px 0;"><span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 4px; font-weight: 600;">POST</span></td>
							<td style="padding: 10px 0;"><code>/wp-json/compass/v1/gatekeeper/keys</code></td>
							<td style="padding: 10px 0; color: #475569;">Generate a machine API key with custom prefix (default: <code>stargate_</code>)</td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php $this->render_license_sections(); ?>
		</div>
		<?php
	}
}
