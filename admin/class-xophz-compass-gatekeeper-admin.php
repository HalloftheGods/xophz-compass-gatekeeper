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

	private string $plugin_name;
	private string $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( 'admin_menu', array( $this, 'register_xp_pro_page' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_save', array( $this, 'handle_save_xp_pro' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_reset', array( $this, 'handle_reset_xp_pro_devices' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_restore', array( $this, 'handle_restore_xp_pro' ) );
		add_action( 'admin_post_gatekeeper_xp_pro_update_license', array( $this, 'handle_update_xp_pro_license' ) );
	}

	const PAGE_XP_PRO = 'gatekeeper-xp-pro';

	/**
	 * Settings > Gatekeeper XP Pro: the webhook ids, the AI daily limit and the license list.
	 */
	public function register_xp_pro_page(): void {
		add_options_page(
			'Gatekeeper XP Pro',
			'Gatekeeper XP Pro',
			'manage_options',
			self::PAGE_XP_PRO,
			array( $this, 'render_xp_pro_page' )
		);
	}

	public function render_xp_pro_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'xophz-compass-gatekeeper' ) );
		}
		echo '<div class="wrap"><h1>Gatekeeper XP Pro</h1>';
		$notice = isset( $_GET['xp_pro_notice'] ) ? sanitize_key( (string) $_GET['xp_pro_notice'] ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>XP Pro settings saved.</p></div>';
		} elseif ( 'reset' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>License devices reset.</p></div>';
		} elseif ( 'restored' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>License restored to active status.</p></div>';
		} elseif ( 'updated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>License updated.</p></div>';
		}
		$this->render_xp_pro_section();
		echo '</div>';
	}

	/**
	 * Save the XP Pro settings. Ids are positive integers (0 means not set); the AI limit
	 * must be at least 1 or the old value stays.
	 */
	public static function save_xp_pro_settings( array $input ): void {
		$id = static function ( $value ): int {
			$n = is_scalar( $value ) ? (int) trim( (string) $value ) : 0;
			return $n > 0 ? $n : 0;
		};

		update_option(
			Gatekeeper_Xp_Pro_Intake::OPTION_WEBHOOKS,
			array(
				'stripe'     => $id( $input['stripe_webhook_id'] ?? 0 ),
				'revenuecat' => $id( $input['revenuecat_webhook_id'] ?? 0 ),
			),
			false
		);

		$limit = $id( $input['ai_daily_limit'] ?? 0 );
		if ( $limit >= 1 ) {
			update_option( 'xp_pro_ai_daily_limit', $limit, false );
		}
	}

	public function handle_save_xp_pro(): void {
		$this->require_admin( 'gatekeeper_xp_pro_save' );
		self::save_xp_pro_settings( wp_unslash( $_POST ) );
		$this->back_to_xp_pro( 'saved' );
	}

	public function handle_reset_xp_pro_devices(): void {
		$this->require_admin( 'gatekeeper_xp_pro_reset' );
		$family_id = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
		if ( Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id ) ) {
			Gatekeeper_Xp_Pro_Licenses::reset_devices( $family_id );
		}
		$this->back_to_xp_pro( 'reset' );
	}

	public function handle_restore_xp_pro(): void {
		$this->require_admin( 'gatekeeper_xp_pro_restore' );
		$family_id = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
		if ( Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id ) ) {
			Gatekeeper_Xp_Pro_Licenses::restore( $family_id, get_current_user_id() );
		}
		$this->back_to_xp_pro( 'restored' );
	}

	public function handle_update_xp_pro_license(): void {
		$this->require_admin( 'gatekeeper_xp_pro_update_license' );
		$family_id = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
		if ( Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id ) ) {
			$fields = array(
				'plan'       => isset( $_POST['plan'] ) ? sanitize_text_field( wp_unslash( $_POST['plan'] ) ) : '',
				'status'     => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
				'env'        => isset( $_POST['env'] ) ? sanitize_text_field( wp_unslash( $_POST['env'] ) ) : '',
				'expires_at' => isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '',
				'email'      => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
			);
			Gatekeeper_Xp_Pro_Licenses::update_admin( $family_id, $fields, get_current_user_id() );
		}
		$this->back_to_xp_pro( 'updated' );
	}

	/** Every write needs manage_options and a valid nonce for its own action. */
	private function require_admin( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'xophz-compass-gatekeeper' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function back_to_xp_pro( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_XP_PRO, 'xp_pro_notice' => $notice ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_xp_pro_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$webhooks = get_option( Gatekeeper_Xp_Pro_Intake::OPTION_WEBHOOKS, array() );
		$webhooks = is_array( $webhooks ) ? $webhooks : array();
		$limit    = (int) get_option( 'xp_pro_ai_daily_limit', 100 );
		$records  = Gatekeeper_Xp_Pro_Licenses::all();
		$post_url = admin_url( 'admin-post.php' );
		?>
		<h2>XP Pro</h2>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<input type="hidden" name="action" value="gatekeeper_xp_pro_save" />
			<?php wp_nonce_field( 'gatekeeper_xp_pro_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gk-xp-pro-stripe">Stripe webhook id</label></th>
					<td><input id="gk-xp-pro-stripe" name="stripe_webhook_id" type="number" min="0" value="<?php echo esc_attr( (int) ( $webhooks['stripe'] ?? 0 ) ); ?>" class="small-text" />
					<p class="description">Post id of the Hookshot webhook "XP Pro: Stripe" (mode HMAC, header Stripe-Signature).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="gk-xp-pro-rc">RevenueCat webhook id</label></th>
					<td><input id="gk-xp-pro-rc" name="revenuecat_webhook_id" type="number" min="0" value="<?php echo esc_attr( (int) ( $webhooks['revenuecat'] ?? 0 ) ); ?>" class="small-text" />
					<p class="description">Post id of the Hookshot webhook "XP Pro: RevenueCat" (mode Bearer).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="gk-xp-pro-limit">AI calls per license per day</label></th>
					<td><input id="gk-xp-pro-limit" name="ai_daily_limit" type="number" min="1" value="<?php echo esc_attr( $limit ); ?>" class="small-text" /></td>
				</tr>
			</table>
			<?php submit_button( 'Save XP Pro settings' ); ?>
		</form>

		<h3>XP Pro licenses</h3>
		<?php if ( empty( $records ) ) : ?>
			<p>No XP Pro licenses yet.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Key</th><th>Plan</th><th>Status</th><th>Expires (blank = never)</th><th>Env</th><th>Email</th><th>Devices</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $records as $record ) : ?>
					<?php
					$form_id = 'gk-lic-' . esc_attr( (string) ( $record['family_id'] ?? '' ) );
					$choose  = static function ( array $options, string $current ): void {
						foreach ( $options as $option ) {
							printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $current, $option, false ) );
						}
					};
					?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $record['license_key'] ?? '' ) ); ?></code></td>
						<td><select form="<?php echo $form_id; ?>" name="plan"><?php $choose( array( 'monthly', 'yearly', 'lifetime' ), (string) ( $record['plan'] ?? '' ) ); ?></select></td>
						<td><select form="<?php echo $form_id; ?>" name="status"><?php $choose( array( 'active', 'expired', 'revoked' ), (string) ( $record['status'] ?? '' ) ); ?></select></td>
						<td><input form="<?php echo $form_id; ?>" type="text" name="expires_at" value="<?php echo esc_attr( (string) ( $record['expires_at'] ?? '' ) ); ?>" /></td>
						<td><select form="<?php echo $form_id; ?>" name="env"><?php $choose( array( 'production', 'sandbox' ), (string) ( $record['env'] ?? '' ) ); ?></select></td>
						<td><input form="<?php echo $form_id; ?>" type="email" name="email" value="<?php echo esc_attr( (string) ( $record['email'] ?? '' ) ); ?>" /></td>
						<td><?php echo esc_html( count( (array) ( $record['devices'] ?? array() ) ) ); ?> / <?php echo esc_html( Gatekeeper_Xp_Pro_Licenses::MAX_DEVICES ); ?></td>
						<td>
							<form id="<?php echo $form_id; ?>" method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<input type="hidden" name="action" value="gatekeeper_xp_pro_update_license" />
								<input type="hidden" name="family_id" value="<?php echo esc_attr( (string) ( $record['family_id'] ?? '' ) ); ?>" />
								<?php wp_nonce_field( 'gatekeeper_xp_pro_update_license' ); ?>
								<button type="submit" class="button button-primary button-small">Save</button>
							</form>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<input type="hidden" name="action" value="gatekeeper_xp_pro_reset" />
								<input type="hidden" name="family_id" value="<?php echo esc_attr( (string) ( $record['family_id'] ?? '' ) ); ?>" />
								<?php wp_nonce_field( 'gatekeeper_xp_pro_reset' ); ?>
								<button type="submit" class="button button-small">Reset devices</button>
							</form>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
								<input type="hidden" name="action" value="gatekeeper_xp_pro_restore" />
								<input type="hidden" name="family_id" value="<?php echo esc_attr( (string) ( $record['family_id'] ?? '' ) ); ?>" />
								<?php wp_nonce_field( 'gatekeeper_xp_pro_restore' ); ?>
								<button type="submit" class="button button-small">Restore</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
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

			<?php $this->render_xp_pro_section(); ?>
		</div>
		<?php
	}
}
