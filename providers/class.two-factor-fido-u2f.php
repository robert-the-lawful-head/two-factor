<?php

class Two_Factor_FIDO_U2F extends Two_Factor_Provider {

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function get_label() {
		return _x( 'FIDO U2F', 'Provider Label', 'two-factor' );
	}

	function authentication_page( $user ) {}
	function validate_authentication( $user ) {}
	function is_available_for_user( $user ) {}

}
