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
		</div>
		<?php
	}
}
