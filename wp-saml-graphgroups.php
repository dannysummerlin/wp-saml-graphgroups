<?php
# Plugin Name: WP SAML Graph Groups
# Plugin URI:  https://summerlin.co/wp_saml_graphgroups
# Description: This add-on to the WP SAML plugin syncs nested group memberships from Azure/MS Graph
# Version:     20221111
# Author:      Danny Summerlin
# Author URI:  https://www.summerlin.co/
# License:     GPL2
# License URI: https://www.gnu.org/licenses/gpl-2.0.html

/**
 * Reject authentication if $attributes doesn't include the authorized group.
 */
if(is_plugin_active('wp-saml-auth/wp-saml-auth.php')) {
	class WP_SAML_GraphGroups {
		private static $tenantId;
		private static $instance;

		public static function get_option( $option_name ) { return apply_filters( 'wp_saml_graphgroups_option', null, $option_name ); }
		private function downcaseArray(&$a, $i) { $a = strtolower($a); }
		private function curl_call($url, $method = 'post', $data = NULL, array $options = array()) {
			$defaults = array(
				CURLOPT_HEADER => 0,
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_TIMEOUT => 4
			);
			if($method === 'post') {
				$defaults[CURLOPT_POSTFIELDS] = $data;
				$defaults[CURLOPT_POST] = 1;
			} elseif ($method === 'get') {
				$defaults[CURLOPT_URL] = $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($data);
			}
			$ch = curl_init();
			curl_setopt_array($ch, ($options + $defaults));
			if( ! $result = curl_exec($ch)) { trigger_error(curl_error($ch)); }
			curl_close($ch);
			return $result;	
		}
		function curl_post($url, $post = NULL, array $options = array()) { return curl_call($url, 'post', $post, $options); }
		function curl_get($url, array $get = NULL, array $options = array()) { return curl_call($url, 'get', $get, $options); }

		function getAzureGroups($groupLink) {
			try {
			// Get Azure Token for MS Graph
				if ( false === ( $azureToken = get_transient( 'azureToken' ) ) ) {
					$tokenString = curl_post('https://login.microsoftonline.com/'.$this->tenantId.'/oauth2/v2.0/token', http_build_query(array(
						'client_id' => get_option('client_id'),
						'client_secret' => get_option('client_secret'),
						'scope' => get_option('scope'), # https://graph.microsoft.com/.default
						'grant_type' => get_option('grant_type') # client_credentials
					)));
					if($tokenString !== '') {
						$tokenResponse = json_decode($tokenString);
						$azureToken = $tokenResponse->access_token;
						set_transient( 'azureToken', $azureToken, $tokenResponse->expires_in);
					}
				}
			// with token, get all groups
				if($azureToken) {
					if ( false === ( $azureGroups = get_transient( 'azureGroups' ) ) ) {
						$rawAzureGroupsResponse = curl_get('https://graph.microsoft.com/v1.0/groups?$select=id,displayName,onPremisesSamAccountName&$top=999&$filter=onPremisesSyncEnabled%20eq%20true', null, array(
							CURLOPT_HTTPHEADER => array(
								"Authorization: Bearer ".urlencode($azureToken),
								"Content-Type: application/json"
							)
						));
						$rawAzureGroups = json_decode($rawAzureGroupsResponse);
						$azureGroups = $rawAzureGroups->value;
						set_transient( 'azureGroups', $azureGroups, 12 * HOUR_IN_SECONDS);
					}
			// get users groups
					$url = str_replace("https://graph.windows.net", "https://graph.microsoft.com/v1.0", $groupLink);
					$url = str_replace('Objects', 'Groups', $url);
					$rawUserGroups = json_decode(curl_post($url, json_encode(array('securityEnabledOnly'=>true)), array(
						CURLOPT_HTTPHEADER => array(
							"Authorization: Bearer ".urlencode($azureToken),
							"Content-Type: application/json"
						)
					)));
					$groupIds = $rawUserGroups->value;
					$userGroups = array();
					$groupIdCount = count($groupIds);
					for($i=0;$i<$groupIdCount;$i++) {
						$g = $groupIds[$i];
						$group = array_filter($azureGroups, function($k) use ($g) {
							return ($k->id === $g);
						});
						if(count($group))
							array_push($userGroups, array_pop($group)->onPremisesSamAccountName);
					}
					return $userGroups;
				}
			} catch (Exception $e) {
				echo 'getAzGroups - Caught exception: ',  $e->getMessage(), "\n";
				wp_die();
			}
		}

		/**
		 * Update user attributes after a user has logged in via SAML.
		 * (from the plugin vendor themselves)
		 */
		add_action( 'wp_saml_auth_new_user_authenticated', function( $new_user, $attributes ) {
			updateSSOUser($new_user, $attributes);
		}, 10, 2);
		add_action( 'wp_saml_auth_existing_user_authenticated', function( $existing_user, $attributes ) {
			updateSSOUser($existing_user, $attributes);
		}, 10, 2 );

		function updateSSOUser($user_info, $attributes) {
			$user_args = array('ID' => $user_info->ID);
			try {
				if(is_array($attributes['http://schemas.microsoft.com/claims/groups.link']) && count($attributes['http://schemas.microsoft.com/claims/groups.link'])) {
					$azGroups = getAzureGroups($attributes['http://schemas.microsoft.com/claims/groups.link'][0]);
					if(!empty($azGroups) && count($azGroups))
						mapGroupsToRoles($user_info, $azGroups);
				} else {
					mapGroupsToRoles($user_info, $attributes['http://schemas.microsoft.com/ws/2008/06/identity/claims/groups']);
				}
			} catch (Exception $e) {
				echo 'wp_saml_auth_existing_user_authenticated - Caught exception: ',  $e->getMessage(), "\n";
				wp_die();
			}
			$attributeTypes = array( 'display_name', 'first_name', 'last_name'); // dropped user_email because it appears to be incorrect right now
			foreach ( $attributeTypes as $type ) {
				$attribute          = \WP_SAML_Auth::get_option( "{$type}_attribute" );
				$user_args[ $type ] = ! empty( $attributes[ $attribute ][0] ) ? $attributes[ $attribute ][0] : '';
			}
			$custom_attributes = wp_get_user_contact_methods();
			foreach($custom_attributes as $attr => $label) {
				$user_args[ $attr ] = ! empty( $attributes[ $attr ][0] ) ? $attributes[ $attr ][0] : '';
			}
			wp_update_user( $user_args );
		}

		function mapGroupsToRoles($user, $rolesActiveDirectory) {
			$rolesActiveDirectory = $rolesActiveDirectory ?? array();
			$rolesActiveDirectory[] = 'subscriber';
			try {
				array_walk($rolesActiveDirectory, 'downcaseArray');
				$rolesWordPress = $user->roles;
				$rolesAdd = array_diff($rolesActiveDirectory, $rolesWordPress);
				$rolesRemove = array_diff($rolesWordPress, $rolesActiveDirectory);

				foreach($rolesAdd as $adRole) {
					$wpRole = get_role($adRole);
					if(isset($wpRole)) {
						$user->add_role($adRole);
					} // add else create role and add AFTER we clean up AD roles on older staff
				}
				foreach($rolesRemove as $wpRole) { 
					$user->remove_role($wpRole);
				}
				wp_cache_delete($user->ID, 'users');
				wp_cache_delete($user->user_login, 'userlogins');
			} catch (Exception $e) {
				echo 'mapGroupsToRoles - Caught exception: ',  $e->getMessage(), "\n";
				wp_die();
			}
		};
		function ssoRedirect( $url, $request, $user ) {
			if(isset($_REQUEST) && isset($_REQUEST['RelayState']) && !stristr($_REQUEST['RelayState'], 'login.php')) {
				wp_redirect($_REQUEST['RelayState']);
				die;
			}
			return $url;
		} add_filter( 'login_redirect', 'ssoRedirect', 10, 3 );

		// adding custom user profile fields
		function getCustomUserFields( $methods, $user ) {
			// has to be identical to the attribute coming over from Office 365 in order to sync
			try {
				$customUserFields = get_option('custom_user_fields');
				$csv = array_map('str_getcsv', explode("\n",$customUserFields));
				foreach ($customUserFields as $field) {
					$methods[trim($field[0])] = trim($field[1]);
				}
			} catch {
				// output debug
			}
			return $methods;
		} add_filter( 'user_contactmethods', 'getCustomUserFields', 10, 2 );
		function init() {
			$wpSAMLSettings = new WP_SAML_Auth_Settings;
			$wpSAMLOptions  = get_option( $wpSAMLSettings->get_option_name() );
			self::$tenantId = preg_match('[\w-]{36}', $wpSAMLOptions['sp_entityId'])[0];
		}
		public function action_init() {
			// add_action( 'login_head', array( $this, 'action_login_head' ) );
			// add_action( 'login_message', array( $this, 'action_login_message' ) );
			// add_action( 'wp_logout', array( $this, 'action_wp_logout' ) );
			// add_filter( 'login_body_class', array( $this, 'filter_login_body_class' ) );
			// add_filter( 'authenticate', array( $this, 'filter_authenticate' ), 21, 3 ); // after wp_authenticate_username_password runs.
			// add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
		}
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new WP_SAML_GraphGroups;
				add_action( 'init', array( self::$instance, 'action_init' ) );
			}
			return self::$instance;
		}
	}
}
?>
