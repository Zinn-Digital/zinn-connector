<?php
/**
 * The Zinn® Connector's screens: connect, control what we may do, and see the truth.
 *
 * ⚖️ **Owner, 2026-09-08:** *"All our pluiigns realluy need to have cusotmisable options …
 * incduing the bridge one between wp and our panel etc. And it needs to show status of
 * ocnnetion and all those things properly."*
 *
 * ⛔⛔ **WHAT THIS SCREEN USED TO BE, AND WHY THAT WAS NOT ENOUGH.** One field, one button and
 * a sentence saying "to disconnect, go and revoke an application password on your profile". A
 * customer handing an outside company the ability to publish to their site had no way to see
 * whether it still could, no way to bound what it may do, and no way to stop it from the
 * screen where they had started it. Every one of those is answered here.
 *
 * ⭐ The chrome, the nonces, the capability checks, the sanitisers and the connection card all
 * come from the shared framework in `wp/admin-ui/` — so this file declares WHAT the connector
 * offers and never re-implements HOW a settings screen is kept safe.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the connector's tabs, fields and actions.
 */
class Zinn_Connector_Settings {

	private const NONCE = 'zinn_connector_claim';

	/** Transient prefix for the last connect outcome, per user. Short-lived by design. */
	private const RESULT_KEY = 'zinn_connector_result_';

	/**
	 * Hook the screens up.
	 */
	public function register(): void {
		Zinn_Connector_Admin_UI::register(
			array(
				'title'      => __( 'Connector', 'zinn-connector' ),
				'option'     => 'zinn_connector_settings',
				'position'   => 10,
				'connection' => array( 'Zinn_Connector_Status', 'status' ),

				// ⛔⛔ THE LEGACY FOLD. Two values were stored as standalone options before
				// this framework existed, and one of them is a live credential. A framework
				// that simply started reading its own array would find it empty on every
				// existing install and the site would stop backing itself up — silently,
				// with a settings screen that looked perfect (§2.44's expensive direction).
				// The originals are left in place for one release.
				'legacy'     => array(
					'backup_token' => 'zinn_connector_backup_token',
				),

				'tabs'       => array(
					'connection' => array(
						'title'  => __( 'Connection', 'zinn-connector' ),
						'fields' => array(),
					),
					'publishing' => array(
						'title'  => __( 'Publishing', 'zinn-connector' ),
						'fields' => array( __CLASS__, 'publishing_fields' ),
					),
					'backups'    => array(
						'title'  => __( 'Backups', 'zinn-connector' ),
						'fields' => array( __CLASS__, 'backup_fields' ),
					),
					'advanced'   => array(
						'title'  => __( 'Advanced', 'zinn-connector' ),
						'fields' => array( __CLASS__, 'advanced_fields' ),
					),
				),

				'screens'    => array(
					array(
						'id'     => 'connection',
						'title'  => __( 'Connection', 'zinn-connector' ),
						'render' => array( $this, 'render_connection_tab' ),
					),
				),

				'actions'    => array(
					array(
						'id'       => 'zinn_connector_recheck',
						'label'    => __( 'Check the connection now', 'zinn-connector' ),
						'callback' => array( 'Zinn_Connector_Status', 'recheck' ),
					),
					array(
						'id'       => 'zinn_connector_disconnect',
						'label'    => __( 'Disconnect this site', 'zinn-connector' ),
						'style'    => 'delete',
						'confirm'  => __( 'This revokes the credential Zinn Digital® uses, so we can no longer publish to this site. Anything already published stays. Continue?', 'zinn-connector' ),
						'callback' => array( 'Zinn_Connector_Status', 'disconnect' ),
					),
				),
			)
		);

		add_action( 'admin_post_zinn_connector_claim', array( $this, 'handle_claim' ) );

		// ⭐ The plugin's own facts, added to the shared diagnostics report rather than by
		// editing the generated collector — which would be overwritten on the next build.
		add_filter( 'zinn_diagnostics_report', array( $this, 'add_diagnostics' ), 10, 2 );
	}

	/**
	 * What the customer may allow us to do when we publish.
	 *
	 * ⛔ Every one of these is READ by the publishing path — none is decoration (§2.41). A
	 * setting shown on a screen that changes nothing is worse than an absent one, because the
	 * customer believes they have turned something off.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function publishing_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'What we may do on this site', 'zinn-connector' ),
				'description' => __( 'The credential we hold is an ordinary WordPress application password, so WordPress itself decides what is possible. These narrow it further, on your side.', 'zinn-connector' ),
			),
			array(
				'key'         => 'publish_status',
				'type'        => 'select',
				'label'       => __( 'Publish articles as', 'zinn-connector' ),
				'description' => __( 'Choose “a draft” if you would rather read everything before it goes live.', 'zinn-connector' ),
				'choices'     => array(
					'publish' => __( 'Published straight away', 'zinn-connector' ),
					'draft'   => __( 'A draft, for me to review', 'zinn-connector' ),
					'pending' => __( 'Pending review', 'zinn-connector' ),
					'future'  => __( 'Scheduled, using the date we send', 'zinn-connector' ),
				),
				'default'     => 'publish',
			),
			array(
				'key'         => 'default_author',
				'type'        => 'select',
				'label'       => __( 'Show the author as', 'zinn-connector' ),
				'description' => __( 'Which of your users appears as the author. This does not change who we sign in as.', 'zinn-connector' ),
				'choices'     => array( __CLASS__, 'author_choices' ),
				'default'     => '0',
			),
			array(
				'key'     => 'default_category',
				'type'    => 'select',
				'label'   => __( 'Put articles in', 'zinn-connector' ),
				'choices' => array( __CLASS__, 'category_choices' ),
				'default' => '0',
			),
			array(
				'key'            => 'allow_media',
				'type'           => 'toggle',
				'label'          => __( 'Allow images', 'zinn-connector' ),
				'checkbox_label' => __( 'Let Zinn Digital® upload images into this site’s media library', 'zinn-connector' ),
				'description'    => __( 'Turn this off and articles arrive without their images. Nothing already uploaded is removed.', 'zinn-connector' ),
				'default'        => true,
			),
			array(
				'key'            => 'allow_edit_existing',
				'type'           => 'toggle',
				'label'          => __( 'Allow edits', 'zinn-connector' ),
				'checkbox_label' => __( 'Let Zinn Digital® update an article it published before', 'zinn-connector' ),
				'description'    => __( 'Used for corrections and re-optimisation. It never touches anything you wrote yourself.', 'zinn-connector' ),
				'default'        => true,
			),
			array(
				'key'         => 'notify_email',
				'type'        => 'email',
				'label'       => __( 'Tell me when something is published', 'zinn-connector' ),
				'description' => __( 'Leave blank for no email. One message per article, from your own site.', 'zinn-connector' ),
				'default'     => '',
				'placeholder' => 'you@example.com',
			),
		);
	}

	/**
	 * The backup half's controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function backup_fields(): array {
		return array(
			array(
				'type'        => 'heading',
				'label'       => __( 'Off-site backups', 'zinn-connector' ),
				'description' => __( 'This site sends its own backup straight to Zinn® storage. Nothing passes through our servers on the way.', 'zinn-connector' ),
			),
			array(
				'key'            => 'backups_enabled',
				'type'           => 'toggle',
				'label'          => __( 'Backups', 'zinn-connector' ),
				'checkbox_label' => __( 'Back this site up to Zinn Digital®', 'zinn-connector' ),
				'default'        => true,
			),
			array(
				'key'         => 'backup_token',
				'type'        => 'password',
				'secret'      => true,
				'label'       => __( 'Backup token', 'zinn-connector' ),
				'description' => __( 'Set for you when the site was connected. You will only need to touch this if support asks.', 'zinn-connector' ),
				'default'     => '',
				'show_if'     => array( 'backups_enabled' => true ),
			),
			array(
				'key'         => 'backup_exclude',
				'type'        => 'textarea',
				'sanitize'    => 'csv',
				'label'       => __( 'Leave these out', 'zinn-connector' ),
				'description' => __( 'One path per line, relative to wp-content — for example <code>cache</code> or <code>uploads/backups</code>. Caches and other backup plugins’ folders are the usual candidates.', 'zinn-connector' ),
				'rows'        => 4,
				'default'     => array(),
				'show_if'     => array( 'backups_enabled' => true ),
			),
			array(
				'key'         => 'backup_max_mb',
				'type'        => 'number',
				'label'       => __( 'Stop if the archive would exceed', 'zinn-connector' ),
				'description' => __( 'Megabytes. A shared host will usually kill a very large archive part-way; a bound turns that into a message instead of a silent failure.', 'zinn-connector' ),
				'min'         => 50,
				'max'         => 51200,
				'step'        => 50,
				'default'     => 2048,
				'show_if'     => array( 'backups_enabled' => true ),
			),
		);
	}

	/**
	 * Controls for people who need them and nobody else.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function advanced_fields(): array {
		return array(
			array(
				'key'            => 'verbose_log',
				'type'           => 'toggle',
				'label'          => __( 'Detailed logging', 'zinn-connector' ),
				'checkbox_label' => __( 'Record what this plugin does, for support', 'zinn-connector' ),
				'description'    => __( 'Kept on this site, capped at the last 50 entries, and included in a diagnostics report. Turn it on before reproducing a problem.', 'zinn-connector' ),
				'default'        => false,
			),
			array(
				'key'            => 'remove_data_on_uninstall',
				'type'           => 'toggle',
				'label'          => __( 'On uninstall', 'zinn-connector' ),
				'checkbox_label' => __( 'Delete this plugin’s settings when it is uninstalled', 'zinn-connector' ),
				'description'    => __( 'Uninstalling never revokes the application password — that is deliberate, so uninstalling to try something else does not break your publishing. Use Disconnect above if you want the credential gone.', 'zinn-connector' ),
				'default'        => false,
			),
		);
	}

	/**
	 * The site's users who can publish, as a choice list.
	 *
	 * ⛔ Bounded. A membership site can have a hundred thousand users, and a settings screen
	 * that renders every one of them is a settings screen that times out — on exactly the
	 * large site whose owner most needs it (§2.16).
	 *
	 * @return array<string, string>
	 */
	public static function author_choices(): array {
		$choices = array( '0' => __( 'The user we sign in as', 'zinn-connector' ) );
		$users   = get_users(
			array(
				'capability' => 'publish_posts',
				'number'     => 100,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		foreach ( $users as $user ) {
			$choices[ (string) $user->ID ] = (string) $user->display_name;
		}
		return $choices;
	}

	/**
	 * The site's categories, as a choice list.
	 *
	 * @return array<string, string>
	 */
	public static function category_choices(): array {
		$choices = array( '0' => __( 'The site’s default category', 'zinn-connector' ) );
		$terms   = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$choices[ (string) $term->term_id ] = $term->name;
				}
			}
		}
		return $choices;
	}

	/**
	 * The Connection tab: the pairing form, or the fact that there is nothing to do.
	 *
	 * ⛔ The card above this — drawn by the framework — has already said whether the site is
	 * connected and why not. This tab is the ACTION, and it does not repeat the diagnosis.
	 *
	 * @return void
	 */
	public function render_connection_tab(): void {
		/*
		 * ⛔⛔ The outcome travels in a per-user TRANSIENT, not in the query string, and that
		 * is a correctness choice before it is a lint one: a message rendered straight out of
		 * the URL is a surface anyone can put text into by handing an administrator a link.
		 * Escaping makes that harmless rather than absent. A transient cannot be set by
		 * whoever crafted the link, and it is deleted on read so a refresh does not re-show a
		 * result the customer has already acted on.
		 */
		$result = get_transient( self::RESULT_KEY . get_current_user_id() );
		delete_transient( self::RESULT_KEY . get_current_user_id() );
		$notice = is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '';
		$ok     = is_array( $result ) && ! empty( $result['ok'] );

		$state     = (array) get_option( 'zinn_connector_state', array() );
		$connected = ! empty( $state['connected_at'] );
		?>
		<?php if ( '' !== $notice ) : ?>
			<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<h2><?php echo $connected ? esc_html__( 'Connect this site again', 'zinn-connector' ) : esc_html__( 'Connect this site', 'zinn-connector' ); ?></h2>
		<p>
			<?php esc_html_e( 'Open your Zinn Digital® dashboard, go to Content → Other sites, and press “Get a pairing code”. Paste it below.', 'zinn-connector' ); ?>
		</p>
		<?php if ( $connected ) : ?>
			<p class="description">
				<?php esc_html_e( 'Connecting again creates a fresh credential and leaves the old one in place — use Disconnect first if you want the old one gone.', 'zinn-connector' ); ?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zinn_connector_claim" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="zinn_connector_code"><?php esc_html_e( 'Pairing code', 'zinn-connector' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="zinn_connector_code"
							name="zinn_connector_code"
							class="regular-text"
							autocomplete="off"
							spellcheck="false"
							required
						/>
						<p class="description">
							<?php esc_html_e( 'It expires thirty minutes after you generate it, and can only be used once.', 'zinn-connector' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Connect this site', 'zinn-connector' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the pairing form.
	 *
	 * ⛔ Capability check **and** nonce, in that order. The nonce stops a cross-site request
	 * riding an administrator's session; the capability check stops a subscriber who has one
	 * legitimately. Neither substitutes for the other, and this form's outcome is handing a
	 * live publishing credential to an external service.
	 */
	public function handle_claim(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'zinn-connector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$code = isset( $_POST['zinn_connector_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['zinn_connector_code'] ) )
			: '';

		$result = ( new Zinn_Connector_Claim() )->redeem( $code );

		// 60 seconds: long enough for the redirect that follows, short enough that a result
		// cannot resurface on a screen opened much later.
		set_transient( self::RESULT_KEY . get_current_user_id(), $result, 60 );

		wp_safe_redirect( Zinn_Connector_Admin_UI::page_url( 'connection' ) );
		exit;
	}

	/**
	 * When the next backup poll is due, or null if none is scheduled.
	 *
	 * @return int|null A Unix timestamp.
	 */
	private static function next_backup(): ?int {
		$next = wp_next_scheduled( Zinn_Connector_Backup::CRON_HOOK );
		return false === $next ? null : (int) $next;
	}

	/**
	 * Add the connector's own facts to a diagnostics report.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param string               $slug   The plugin building it.
	 * @return array<string, mixed>
	 */
	public function add_diagnostics( array $report, string $slug ): array {
		if ( 'zinn-connector' !== $slug ) {
			return $report;
		}
		$state               = (array) get_option( 'zinn_connector_state', array() );
		$report['connector'] = array(
			'connected_at'  => empty( $state['connected_at'] ) ? null : gmdate( 'c', (int) $state['connected_at'] ),
			'publishing_as' => (string) ( $state['user_login'] ?? '' ),
			'site_id'       => (string) ( $state['site_id'] ?? '' ),
			'backup_next'   => self::next_backup(),
			'app_passwords' => function_exists( 'wp_is_application_passwords_available' )
				? wp_is_application_passwords_available()
				: null,
			'rest_url'      => get_rest_url(),
		);
		return $report;
	}
}
