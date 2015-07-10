<?php

class Two_Factor_Core {

	const PROVIDER_USER_META_KEY = '_two_factor_provider';
	const USER_META_NONCE_KEY    = '_two_factor_nonce';

	/**
	 * Set up filters and actions.
	 */
	public static function add_hooks() {
		add_action( 'init',                     array( __CLASS__, 'get_providers' ) );
		add_action( 'wp_login',                 array( __CLASS__, 'wp_login' ), 10, 2 );
		add_action( 'login_form_twostep',       array( __CLASS__, 'login_form_twostep' ) );
		add_action( 'show_user_profile',        array( __CLASS__, 'user_two_factor_options' ) );
		add_action( 'edit_user_profile',        array( __CLASS__, 'user_two_factor_options' ) );
		add_action( 'personal_options_update',  array( __CLASS__, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'user_two_factor_options_update' ) );
	}

	/**
	 * For each provider, include it and then instantiate it.
	 *
	 * @return array
	 */
	public static function get_providers() {
		$providers = array(
			'Two_Factor_Email'    => TWO_FACTOR_DIR . 'providers/class.two-factor-email.php',
			'Two_Factor_Totp'     => TWO_FACTOR_DIR . 'providers/class.two-factor-totp.php',
			'Two_Factor_Fido_U2f' => TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f.php',
			'Two_Factor_Dummy'    => TWO_FACTOR_DIR . 'providers/class.two-factor-dummy.php',
		);

		/**
		 * Filter the supplied providers.
		 *
		 * This lets third-parties either remove providers (such as Email), or
		 * add their own providers (such as text message or Clef).
		 *
		 * @param array $providers A key-value array where the key is the class name, and
		 *                         the value is the path to the file containing the class.
		 */
		$providers = apply_filters( 'two_factor_providers', $providers );

		/**
		 * For each filtered provider,
		 */
		foreach ( $providers as $class => $path ) {
			include_once( $path );

			/**
			 * Confirm that it's been successfully included before instantiating.
			 */
			if ( class_exists( $class ) ) {
				$providers[ $class ] = call_user_func( array( $class, 'get_instance' ) );
			}
		}

		return $providers;
	}

	/**
	 * Get all available Two-Factor Auth providers for the specified|current user.
	 *
	 * @param $user WP_User
	 *
	 * @return array
	 */
	public static function get_available_providers_for_user( $user = null ) {
		if ( empty( $user ) || ! is_a( $user, 'WP_User' ) ) {
			$user = wp_get_current_user();
		}

		$providers            = self::get_providers();
		$configured_providers = array();

		foreach ( $providers as $classname => $provider ) {
			if ( $provider->is_available_for_user( $user ) ) {
				$configured_providers[ $classname ] = $provider;
			}
		}

		return $configured_providers;
	}

	/**
	 * Gets the Two-Factor Auth provider for the specified|current user.
	 *
	 * @param $user_id optional
	 *
	 * @return object|null
	 */
	public static function get_primary_provider_for_user( $user_id = null ) {
		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			$user_id = get_current_user_id();
		}
		$provider = get_user_meta( $user_id, self::PROVIDER_USER_META_KEY, true );
		$providers = self::get_providers();

		if ( isset( $providers[ $provider ] ) ) {
			return $providers[ $provider ];
		}

		return null;
	}

	/**
	 * Quick boolean check for whether a given user is using two-step.
	 */
	public static function is_user_using_two_factor( $user_id = null ) {
		$provider = self::get_primary_provider_for_user( $user_id );
		return ! empty( $provider );
	}

	/**
	 * Handle the browser-based login.
	 */
	public static function wp_login( $user_login, $user ) {
		if ( ! self::is_user_using_two_factor( $user->ID ) ) {
			return;
		}

		wp_clear_auth_cookie();

		self::show_two_factor_login( $user );
		exit;
	}

	public static function show_two_factor_login( $user ) {
		if ( ! function_exists( 'login_header' ) ) {
			require_once( ABSPATH . WPINC . '/functions.wp-login.php' );
		}

		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		$login_nonce = self::create_login_nonce( $user->ID );
		if ( ! $login_nonce ) {
			wp_die( __( 'Could not save login nonce.', 'two-factor' ) );
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : $_SERVER['REQUEST_URI'];

		self::login_html( $user, $login_nonce['key'], $redirect_to );
	}

	/**
	 * Generates the html form for the second step of the authentication process.
	 *
	 * @param $user                   A WP_User Object.
	 * @param $login_nonce            A string nonce stored in usermeta.
	 * @param $redirect_to            The URL to which the user would like to be redirected.
	 * @param string $error_msg       An error message (optional)
	 * @param string|object $provider An override to the provider.
	 */
	public static function login_html( $user, $login_nonce, $redirect_to, $error_msg = '', $provider = null ) {
		if ( empty( $provider ) ) {
			$provider = self::get_primary_provider_for_user( $user->ID );
		} elseif ( is_string( $provider ) && method_exists( $provider, 'get_instance' ) ) {
			$provider = call_user_func( array( $provider, 'get_instance' ) );
		}

		$rememberme = 0;
		if ( isset ( $_REQUEST[ 'rememberme' ] ) && $_REQUEST[ 'rememberme' ] ) {
			$rememberme = 1;
		}

		login_header();

		if ( ! empty( $error_msg ) ) {
			echo '<div id="login_error"><strong>' . esc_html( $error_msg ) . '</strong><br /></div>';
		}
		?>

		<form name="twostepform" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php?action=twostep', 'login_post' ) ); ?>" method="post" autocomplete="off">
				<input type="hidden" name="wp-auth-id" id="wp-auth-id" value="<?php echo esc_attr( $user->ID ) ?>" />
				<input type="hidden" name="redirect_to" id="redirect_to" value="<?php echo esc_attr( $redirect_to ) ?>"/>
				<input type="hidden" name="rememberme" id="rememberme" value="<?php echo esc_attr( $rememberme ) ?>"/>
				<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />

				<?php $provider->authentication_page( $user ); ?>

		</form>

		<p id="backtoblog"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php esc_attr_e( 'Are you lost?' ); ?>"><?php printf( __( '&larr; Back to %s' ), get_bloginfo( 'title', 'display' ) ); ?></a></p>

		</body>
		</html>
		<?php
	}

	public static function create_login_nonce( $user_id ) {
		$login_nonce               = array();
		$login_nonce['key']        = wp_hash( $user_id . mt_rand() . microtime(), 'nonce' );
		$login_nonce['expiration'] = time() + HOUR_IN_SECONDS;

		if ( ! update_user_meta( $user_id, self::USER_META_NONCE_KEY, $login_nonce ) ) {
			return false;
		}

		return $login_nonce;
	}

	public static function delete_login_nonce( $user_id ) {
		return delete_user_meta( $user_id, self::USER_META_NONCE_KEY );
	}

	public static function verify_login_nonce( $user_id, $nonce ) {
		$login_nonce = get_user_meta( $user_id, self::USER_META_NONCE_KEY, true );
		if ( ! $login_nonce ) {
			return false;
		}

		if ( $nonce != $login_nonce['key'] || time() > $login_nonce['expiration'] ) {
			self::delete_login_nonce( $user_id );
			return false;
		}

		return true;
	}

	function login_form_twostep() {
		if ( ! isset( $_POST['wp-auth-id'], $_POST['wp-auth-nonce'] ) ) {
			return;
		}

		$user = get_userdata( $_POST['wp-auth-id'] );
		if ( ! $user ) {
			return;
		}
		
		$nonce = $_POST['wp-auth-nonce'];
		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo('url') );
			exit();
		}

		$provider = self::get_primary_provider_for_user( $user->ID );
		if ( true !== $provider->validate_authentication( $user ) ) {
			do_action( 'wp_login_failed', $user->user_login );

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				return;
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], __( 'ERROR: Invalid verification code.', 'two-factor' ) );
			exit;
		}

		self::delete_login_nonce( $user->ID );

		$rememberme = false;
		if ( isset ( $_REQUEST[ 'rememberme' ] ) && $_REQUEST[ 'rememberme' ] ) {
			$rememberme = true;
		}
		
		wp_set_auth_cookie( $user->ID, $rememberme );

		$redirect_to = apply_filters( 'login_redirect', $_REQUEST['redirect_to'], $_REQUEST['redirect_to'], $user );
		wp_safe_redirect( $redirect_to );

		exit();
	}

	/**
	 * Add user profile fields.
	 */
	public static function user_two_factor_options( $user ) {
		$primary_provider = get_user_meta( $user->ID, self::PROVIDER_USER_META_KEY, true );
		wp_nonce_field( 'user_two_factor_options', '_nonce_user_two_factor_options', false );
		?>
		<table class="form-table">
			<tr>
				<th>
					<?php esc_html_e( 'Two-Factor Options', 'two-factor' ); ?>
				</th>
				<td>
					<table class="two-factor-methods-table">
						<thead>
							<tr>
								<th style="width: 5%;" scope="col"><?php esc_html_e( 'Primary', 'two-factor' ); ?></th>
								<th style="width: 90%;" scope="col"><?php esc_html_e( 'Name', 'two-factor' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( self::get_providers() as $class => $object ) : ?>
							<tr>
								<td><input type="radio" name="<?php echo esc_attr( self::PROVIDER_USER_META_KEY ); ?>" value="<?php echo esc_attr( $class ); ?>" <?php checked( $class, $primary_provider ); ?> /></td>
								<td>
									<?php $object->print_label(); ?>
									<?php do_action( 'two-factor-user-options-' . $class, $user ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Update the user meta value.
	 */
	public static function user_two_factor_options_update( $user_id ) {
		if ( isset( $_POST[ self::PROVIDER_USER_META_KEY ] ) ) {
			check_admin_referer( 'user_two_factor_options', '_nonce_user_two_factor_options' );
			$new_provider = $_POST[ self::PROVIDER_USER_META_KEY ];
			$providers = self::get_providers();

			/**
			 * Whitelist the new values to only the available classes and empty.
			 */
			if ( empty( $new_provider ) || array_key_exists( $new_provider, $providers ) ) {
				update_user_meta( $user_id, self::PROVIDER_USER_META_KEY, $new_provider );
			} else {
				echo 'WTF M8^^';
				exit;
			}
		}
	}
}
