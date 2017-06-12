<?php
/*
Plugin Name: Fetch Remote Url
Description: Fetch a remote URL using wpcom_vip_file_get_contents 
Version: 1.0.0
License: GNU General Public License v2 or later
License URI: LICENSE
Author: Brandon Jones
Author URI: http://www.brandonsj.me/
*/

if( ! defined( 'FRU_VER' ) ) {
	define( 'FRU_VER', '1.0.0' );
}

class Fetch_Remote_Url {

	/**
	 * Fetch a remote URL and cache the result for a certain period of time.
	 *
	 * This function originally used file_get_contents(), hence the function name.
	 * While it no longer does, it still operates the same as the basic PHP function.
	 *
	 * We strongly recommend not using a $timeout value of more than 3 seconds as this
	 * function makes blocking requests (stops page generation and waits for the response).
	 *
	 * The $extra_args are:
	 *  * obey_cache_control_header: uses the "cache-control" "max-age" value if greater than $cache_time.
	 *  * http_api_args: see http://codex.wordpress.org/Function_API/wp_remote_get
	 *
	 * @link http://lobby.vip.wordpress.com/best-practices/fetching-remote-data/ Fetching Remote Data
	 * @param string $url URL to fetch
	 * @param int $timeout Optional. The timeout limit in seconds; valid values are 1-10. Defaults to 3.
	 * @param int $cache_time Optional. The minimum cache time in seconds. Valid values are >= 60. Defaults to 900.
	 * @param array $extra_args Optional. Advanced arguments: "obey_cache_control_header" and "http_api_args".
	 * @return string The remote file's contents (cached)
	 */
	public function fru_wpcom_vip_file_get_contents( $url, $timeout = 3, $cache_time = 900, $extra_args = array() ) {
		global $blog_id;

		$extra_args_defaults = array(
			'obey_cache_control_header' => true, // Uses the "cache-control" "max-age" value if greater than $cache_time
			'http_api_args' => array(), // See http://codex.wordpress.org/Function_API/wp_remote_get
		);

		$extra_args = wp_parse_args( $extra_args, $extra_args_defaults );

		$cache_key       = md5( serialize( array_merge( $extra_args, array( 'url' => $url ) ) ) );
		$backup_key      = $cache_key . '_backup';
		$disable_get_key = $cache_key . '_disable';
		$cache_group     = 'wpcom_vip_file_get_contents';

		// Temporary legacy keys to prevent mass cache misses during our key switch
		$old_cache_key       = md5( $url );
		$old_backup_key      = 'backup:' . $old_cache_key;
		$old_disable_get_key = 'disable:' . $old_cache_key;

		// Let's see if we have an existing cache already
		// Empty strings are okay, false means no cache
		if ( false !== $cache = wp_cache_get( $cache_key, $cache_group) )
			return $cache;

		// Legacy
		if ( false !== $cache = wp_cache_get( $old_cache_key, $cache_group) )
			return $cache;

		// The timeout can be 1 to 10 seconds, we strongly recommend no more than 3 seconds
		$timeout = min( 10, max( 1, (int) $timeout ) );

		if ( $timeout > 3 && ! is_admin() )
			_doing_it_wrong( __FUNCTION__, 'Using a timeout value of over 3 seconds is strongly discouraged because users have to wait for the remote request to finish before the rest of their page loads.', null );

		$server_up = true;
		$response = false;
		$content = false;

		// Check to see if previous attempts have failed
		if ( false !== wp_cache_get( $disable_get_key, $cache_group ) ) {
			$server_up = false;
		}
		// Legacy
		elseif ( false !== wp_cache_get( $old_disable_get_key, $cache_group ) ) {
			$server_up = false;
		}
		// Otherwise make the remote request
		else {
			$http_api_args = (array) $extra_args['http_api_args'];
			$http_api_args['timeout'] = $timeout;
			$response = wp_remote_get( $url, $http_api_args );
		}

		// Was the request successful?
		if ( $server_up && ! is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
			$content = wp_remote_retrieve_body( $response );

			$cache_header = wp_remote_retrieve_header( $response, 'cache-control' );
			if ( is_array( $cache_header ) )
				$cache_header = array_shift( $cache_header );

			// Obey the cache time header unless an arg is passed saying not to
			if ( $extra_args['obey_cache_control_header'] && $cache_header ) {
				$cache_header = trim( $cache_header );
				// When multiple cache-control directives are returned, they are comma separated
				foreach ( explode( ',', $cache_header ) as $cache_control ) {
					// In this scenario, only look for the max-age directive
					if( 'max-age' == substr( trim( $cache_control ), 0, 7 ) )
						// Note the array_pad() call prevents 'undefined offset' notices when explode() returns less than 2 results
						list( $cache_header_type, $cache_header_time ) = array_pad( explode( '=', trim( $cache_control ), 2 ), 2, null );
				}
				// If the max-age directive was found and had a value set that is greater than our cache time
				if ( isset( $cache_header_type ) && isset( $cache_header_time ) && $cache_header_time > $cache_time )
					$cache_time = (int) $cache_header_time; // Casting to an int will strip "must-revalidate", etc.
			}

			// The cache time shouldn't be less than a minute
			// Please try and keep this as high as possible though
			// It'll make your site faster if you do
			$cache_time = (int) $cache_time;
			if ( $cache_time < 60 )
				$cache_time = 60;

			// Cache the result
			wp_cache_add( $cache_key, $content, $cache_group, $cache_time );

			// Additionally cache the result with no expiry as a backup content source
			wp_cache_add( $backup_key, $content, $cache_group );

			// So we can hook in other places and do stuff
			do_action( 'wpcom_vip_remote_request_success', $url, $response );
		}
		// Okay, it wasn't successful. Perhaps we have a backup result from earlier.
		elseif ( $content = wp_cache_get( $backup_key, $cache_group ) ) {
			// If a remote request failed, log why it did
			if ( ! defined( 'WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING' ) || ! WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING ) {
				if ( $response && ! is_wp_error( $response ) ) {
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response['headers'] ) . ' ' . maybe_serialize( $response['response'] ) );
				} elseif ( $response ) { // is WP_Error object
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response ) );
				}
			}
		}
		// Legacy
		elseif ( $content = wp_cache_get( $old_backup_key, $cache_group ) ) {
			// If a remote request failed, log why it did
			if ( ! defined( 'WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING' ) || ! WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING ) {
				if ( $response && ! is_wp_error( $response ) ) {
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response['headers'] ) . ' ' . maybe_serialize( $response['response'] ) );
				} elseif ( $response ) { // is WP_Error object
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response ) );
				}
			}
		}
		// We were unable to fetch any content, so don't try again for another 60 seconds
		elseif ( $response ) {
			wp_cache_add( $disable_get_key, 1, $cache_group, 60 );

			// If a remote request failed, log why it did
			if ( ! defined( 'WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING' ) || ! WPCOM_VIP_DISABLE_REMOTE_REQUEST_ERROR_REPORTING ) {
				if ( $response && ! is_wp_error( $response ) ) {
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response['headers'] ) . ' ' . maybe_serialize( $response['response'] ) );
				} elseif ( $response ) { // is WP_Error object
					error_log( "wpcom_vip_file_get_contents: Blog ID {$blog_id}: Failure for $url and the result was: " . maybe_serialize( $response ) );
				}
			}
			// So we can hook in other places and do stuff
			do_action( 'wpcom_vip_remote_request_error', $url, $response );
		}

		return $content;
	}
		
   /**
	 * Return json cached data
	 *
	 * @return array json $data
	 */
	public function fru_get_json( $url ) {
		$response = $this->fru_wpcom_vip_file_get_contents( $url, 3, 900 );

		if (  $response == ! 200 ) {
			 return false;
		}

		$data = json_decode( $response, true );

		// Are the results in an array?
		if ( ! is_array( $data ) ) {
			 return false;
		}

		return $data;
	}

// End class	
}

$Fetch_Remote_Url = new Fetch_Remote_Url();
