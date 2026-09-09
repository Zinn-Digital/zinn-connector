<?php
/**
 * The customer's limits on what Zinn Digital® may do, enforced on this site.
 *
 * ⛔⛔ **THIS IS THE HALF THAT MAKES THE SETTINGS REAL.** A "Publish as a draft" control that
 * only stores a string is the placeholder §2.41 forbids, and it is worse than an absent
 * control: the customer believes they have turned something off. Every switch on the
 * Publishing tab is read here, on the request it governs.
 *
 * ⛔⛔ **AND IT APPLIES ONLY TO REQUESTS AUTHENTICATED WITH OUR OWN CREDENTIAL.** Forcing
 * `post_status` on every REST write would break the site owner's own app, their editor, Jetpack
 * and anything else that speaks to `wp/v2/posts` — a plugin quietly rewriting other people's
 * API calls is a plugin that gets removed. The discriminator is the application-password uuid
 * WordPress reports at authentication time, compared against the one we minted.
 *
 * ⭐ It hooks `application_password_did_authenticate`, which is a documented action rather
 * than the `$wp_rest_application_password_uuid` global some plugins read. A global is an
 * implementation detail that has moved before; an action is a contract.
 *
 * @package ZinnConnector
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the Publishing tab's choices to requests we make.
 */
class Zinn_Connector_Guard {

	/**
	 * True once this request has been shown to be ours.
	 *
	 * ⛔ Defaults to FALSE and is only ever set by the authentication hook. The failure
	 * direction matters: a bug here that leaves it false means our own publishing is
	 * unconstrained by the customer's preferences, which is visible and reportable. A bug
	 * that left it true would silently rewrite the site owner's own API calls, which is not.
	 */
	/**
	 * Whether the request in flight authenticated with the credential we minted.
	 *
	 * @var bool
	 */
	private static bool $is_ours = false;

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_authentication' ), 10, 2 );

		add_filter( 'rest_pre_insert_post', array( __CLASS__, 'apply_publishing_rules' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'refuse_disallowed' ), 10, 3 );
		add_action( 'rest_after_insert_post', array( __CLASS__, 'after_insert' ), 10, 3 );
	}

	/**
	 * Note whether this request authenticated with the credential we minted.
	 *
	 * @param WP_User              $user The user that authenticated.
	 * @param array<string, mixed> $item The application password used.
	 * @return void
	 */
	public static function note_authentication( $user, $item ): void {
		$state = get_option( 'zinn_connector_state', array() );
		$uuid  = is_array( $state ) ? (string) ( $state['password_uuid'] ?? '' ) : '';
		if ( '' === $uuid || ! is_array( $item ) ) {
			return;
		}
		self::$is_ours = hash_equals( $uuid, (string) ( $item['uuid'] ?? '' ) );
	}

	/**
	 * Is the request in flight one of ours?
	 *
	 * @return bool
	 */
	public static function is_ours(): bool {
		return self::$is_ours;
	}

	/**
	 * One setting from the connector's own screen.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value if the framework has not loaded.
	 * @return mixed
	 */
	private static function setting( string $key, $fallback ) {
		if ( ! class_exists( 'Zinn_Connector_Admin_UI' ) ) {
			return $fallback;
		}
		return Zinn_Connector_Admin_UI::get( $key, $fallback );
	}

	/**
	 * Force the status, author and other publishing choices on a post we are creating.
	 *
	 * ⛔ `$request` is unused and stays, because `rest_pre_insert_post` passes two arguments
	 * and a two-argument filter registered with one is a PHP 8 `ArgumentCountError` the first
	 * time somebody adds a third listener. The signature belongs to WordPress, not to us.
	 *
	 * @param stdClass        $prepared The post about to be inserted.
	 * @param WP_REST_Request $request  The request. Part of the filter's signature.
	 * @return stdClass
	 */
	public static function apply_publishing_rules( $prepared, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- see the docblock: the signature is WordPress's.
		if ( ! self::$is_ours || ! is_object( $prepared ) ) {
			return $prepared;
		}

		$status = (string) self::setting( 'publish_status', 'publish' );
		if ( in_array( $status, array( 'publish', 'draft', 'pending', 'future' ), true ) ) {
			// ⛔ `future` only when a date was actually supplied: WordPress silently demotes a
			// `future` post with no future date to `publish`, which would quietly do the
			// opposite of what the customer asked for.
			$has_date              = ! empty( $prepared->post_date_gmt ) && strtotime( (string) $prepared->post_date_gmt ) > time();
			$prepared->post_status = ( 'future' === $status && ! $has_date ) ? 'draft' : $status;
		}

		$author = (int) self::setting( 'default_author', 0 );
		if ( $author > 0 && user_can( $author, 'publish_posts' ) ) {
			$prepared->post_author = $author;
		}

		return $prepared;
	}

	/**
	 * Refuse media uploads and edits of existing posts when the customer has turned them off.
	 *
	 * ⛔⛔ **REFUSED WITH A SENTENCE THE READER CAN ACT ON, and a `403` rather than a `500`.**
	 * A refusal our own engine cannot distinguish from a fault is a refusal that gets
	 * retried for ever and paged somebody at 3am — §2.57's whole point, applied to the
	 * request we make to ourselves.
	 *
	 * @param mixed           $result  Response to replace the request's, or null.
	 * @param WP_REST_Server  $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_disallowed( $result, $server, $request ) {
		if ( ! self::$is_ours || null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		if ( 'POST' === $method && 1 === preg_match( '#^/wp/v2/media\b#', $route ) && ! self::setting( 'allow_media', true ) ) {
			return new WP_Error(
				'zinn_connector_media_disabled',
				__( 'This site’s owner has turned off image uploads for Zinn Digital® in the Zinn® Connector settings.', 'zinn-connector' ),
				array( 'status' => 403 )
			);
		}

		$is_update = in_array( $method, array( 'PUT', 'PATCH', 'POST' ), true )
			&& 1 === preg_match( '#^/wp/v2/(posts|pages)/\d+#', $route );

		if ( $is_update && ! self::setting( 'allow_edit_existing', true ) ) {
			return new WP_Error(
				'zinn_connector_edit_disabled',
				__( 'This site’s owner has turned off edits to existing articles for Zinn Digital® in the Zinn® Connector settings.', 'zinn-connector' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	/**
	 * Put the article in the chosen category and tell the customer it arrived.
	 *
	 * @param WP_Post         $post     The post just written.
	 * @param WP_REST_Request $request  The request.
	 * @param bool            $creating True on create, false on update.
	 * @return void
	 */
	public static function after_insert( $post, $request, $creating ): void {
		if ( ! self::$is_ours || ! $post instanceof WP_Post ) {
			return;
		}

		$category = (int) self::setting( 'default_category', 0 );
		// ⛔ Only when the request set NO categories of its own. Overriding a category the
		// engine deliberately chose would make the setting a trap rather than a default.
		if ( $creating && $category > 0 && term_exists( $category, 'category' ) ) {
			$current = wp_get_post_categories( $post->ID );
			$default = (int) get_option( 'default_category', 0 );
			if ( array() === $current || array( $default ) === array_map( 'intval', $current ) ) {
				wp_set_post_categories( $post->ID, array( $category ) );
			}
		}

		if ( ! $creating ) {
			return;
		}

		$email = (string) self::setting( 'notify_email', '' );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		// ⛔ Sent from the SITE, about the site, in the site's own language. It is the
		// customer's own notification and nothing about it is a Zinn footprint on a public
		// page (`docs/203` §8).
		wp_mail(
			$email,
			sprintf(
				/* translators: %s: the title of the article that was published. */
				__( 'New article on your site: %s', 'zinn-connector' ),
				$post->post_title
			),
			sprintf(
				/* translators: 1: article title, 2: link to edit it, 3: its status. */
				__( "Zinn Digital® has added an article to your site.\n\nTitle: %1\$s\nEdit it: %2\$s\nStatus: %3\$s\n\nTurn these emails off on the Zinn Digital® → Connector → Publishing screen.", 'zinn-connector' ),
				$post->post_title,
				get_edit_post_link( $post->ID, 'raw' ),
				$post->post_status
			)
		);
	}
}
