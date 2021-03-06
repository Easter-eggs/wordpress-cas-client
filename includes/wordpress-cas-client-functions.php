<?php
/*
 * Copyright (C) 2014 Bellevue College
 * Copyright (C) 2009 Ioannis C. Yessios
 *
 * This file is part of the WordPress CAS Client
 *
 * The WordPress CAS Client is free software; you can redistribute
 * it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Bellevue College
 * Address: 3000 Landerholm Circle SE
 *          Room N215F
 *          Bellevue WA 98007-6484
 * Phone:   +1 425.564.4201
 */

require_once constant( 'CAS_CLIENT_ROOT' ) . '/includes/generate-password.php';

/**
 * authenticate_cas_user function
 *
 * Authenticate user via CAS
 *
 * @retval string|void The login of the authenticated CAS user
 **/
function authenticate_cas_user() {
	global $wp_cas_ldap_use_options, $cas_configured, $blog_id;

	if ( ! $cas_configured ) {
		$message = __( 'WordPress CAS Client plugin not configured.', 'wpcasldap' );
		wp_die( $message, $message);
	}
	if ( phpCAS::isAuthenticated() ) {
		// CAS was successful

		// Check if cas_redirect URL parameter is present
		if (isset($_GET['cas_redirect']) && !empty($_GET['cas_redirect'])) {
			header('Location: '.urldecode($_GET['cas_redirect']));
			exit();
		}

		return phpCAS::getUser();
	} elseif ( $wp_cas_ldap_use_options['cas_redirect_using_js'] == 'yes' ) {
		// Authenticate the user using Javascript redirection
		$cas_redirect_url = phpCAS :: getServerLoginURL();
		$cas_root_redirect_url = preg_replace('/service=[^\&]+/', 'service=', $cas_redirect_url);
		$pagetitle = __( 'Authentication', 'wpcasldap' );
		$title = __( 'Please wait', 'wpcasldap' );
		$message = __( 'You will be redirected soon to the login page.', 'wpcasldap' );
		$noredirect_message = sprintf(__( "If you aren't automatically redirected, please click on <a href='%s'>this link</a>.", 'wpcasldap' ), $cas_redirect_url);
		$redirect_script = <<<EOF
<script>
if (location.hash) {
    var path = location.pathname;
    if (path.includes('?')) {
        path += '&cas_redirect=';
    }
    else {
        path += '?cas_redirect=';
    }
    window.location = '$cas_root_redirect_url' + encodeURIComponent(location.origin + path + encodeURIComponent(location.href));
}
else {
    window.location = '$cas_root_redirect_url' + encodeURIComponent(location.href);
}
</script>
EOF;
		wp_die("<h1>$title</h1><p>$message</p><p>$noredirect_message</p>$redirect_script", $title);
	} else {
		// Authenticate the user
		phpCAS::forceAuthentication();
		exit();
	}
}

/**
 * Update and authenticated user
 *
 * @retval void
 */
function update_and_auth_user($cas_user, $wordpress_user) {
	global $wp_cas_ldap_use_options;

	// Initialize return $user_data from Wordpress user infos
	$user_data = array (
		'ID'		=> $wordpress_user->ID,
		'user_login'	=> $wordpress_user->user_login,
		'first_name'	=> $wordpress_user->first_name,
		'last_name'	=> $wordpress_user->last_name,
		'user_email'	=> $wordpress_user->user_email,
		'nickname'	=> $wordpress_user->nickname,
		'user_nicename'	=> $wordpress_user->user_nicename,
	);

	// Update user information from ldap
	if ( 'yes' === $wp_cas_ldap_use_options['useldap'] && function_exists( 'ldap_connect' ) ) {
		$existing_user = get_ldap_user( $cas_user );
		if ( $existing_user ) {
			$new_user_data = $existing_user->get_user_data( );
			$new_user_data['ID'] = $wordpress_user->ID;

			// Remove role and password from userdata
			unset( $new_user_data['role'] );
			unset( $new_user_data['user_pass'] );

			$user_id = wp_update_user( $user_data );

			if ( is_wp_error( $user_id ) ) {
				$error_string = $user_id->get_error_message( );
				error_log( 'Update user failed: ' . $error_string );
				echo '<div id="message" class="error"><p>' . $error_string . '</p></div>';
			}
			else {
				$user_data = $new_user_data;
			}
		}
	}

	$user_exists = is_user_member_of_blog( $wordpress_user->ID, $blog_id );
	if ( ! $user_exists ) {
		if ( function_exists( 'add_user_to_blog' ) ) {
			add_user_to_blog( $blog_id, $wordpress_user->ID, $wp_cas_ldap_use_options['userrole'] );
		}
	}

	// the CAS user has a WP account
	wp_set_auth_cookie($wordpress_user->ID);

	// Update user data in session
	$_SESSION['CAS_USER'] = $cas_user;
	$_SESSION['CAS_USER_DATA'] = $user_data;

	return $user_data;
}

/**
 * wp_cas_ldap_now_puser function
 *
 * @param string $new_user_id the username of a user
 */
function wp_cas_ldap_now_puser( $new_user_id ) {
	global $wp_cas_ldap_use_options;
	$user_data = get_new_user_data( $new_user_id );

	if ( ! function_exists( 'wp_insert_user' ) ) {
		include_once ( ABSPATH . WPINC . '/registration.php' );
	}

	$user_id = wp_insert_user( $user_data );
	if ( is_wp_error( $user_id ) ) {
		$error_string = $user_id->get_error_message( );
		error_log( 'Inserting a user in wp failed: ' . $error_string );
		echo '<div id="message" class="error"><p>' . $error_string . '</p></div>';
		return;
	} else {
		// Set CAS user info in session
		$user_data['ID'] = $user_id;
		$_SESSION['CAS_USER'] = $new_user_id;
		$_SESSION['CAS_USER_DATA'] = $user_data;

		wp_set_auth_cookie( $user_id );

		if ( isset( $_GET['redirect_to'] ) ) {
			wp_redirect( preg_match( '/^http/', $_GET['redirect_to'] ) ? $_GET['redirect_to'] : site_url( ) );
			exit( );
		}

		wp_redirect( site_url( '/wp-admin/' ) );
		exit( );
	}
}

/**
 * get_ldap_user function
 *
 * @param string $login User login
 * @return false|WP_CAS_LDAP_User returns WP_CAS_LDAP_User object as long as user is
 *                             found on the ldap server, otherwise false.
 */
function get_ldap_user( $login ) {
	global $wp_cas_ldap_use_options;
	$ds = ldap_connect( $wp_cas_ldap_use_options['ldaphost'], $wp_cas_ldap_use_options['ldapport'] );
	//Can't connect to LDAP.
	if ( ! $ds ) {
		error_log('Error in contacting the LDAP server: ' . $wp_cas_ldap_use_options['ldaphost'] . ':' . $wp_cas_ldap_use_options['ldapport']);
	} else {
		// Make sure the protocol is set to version 3
		if ( ! ldap_set_option( $ds, LDAP_OPT_PROTOCOL_VERSION, 3 ) ) {
			error_log( 'Failed to set LDAP protocol to version 3.' );
		} else {
			// Do not allow referrals, per MS recommendation
			if ( ! ldap_set_option( $ds, LDAP_OPT_REFERRALS, 0 ) ) {
				error_log( 'Failed to set LDAP Referrals to False.' );
			} else {
				// Get LDAP service account DN/password
				$ldap_bind_dn = $wp_cas_ldap_use_options['ldapbinddn'];
				$ldap_bind_pwd = $wp_cas_ldap_use_options['ldapbindpwd'];
				if (strlen($ldap_bind_pwd) > 0)
					$ldap_bind_pwd = wp_cas_ldap_settings :: decrypt($ldap_bind_pwd);

				$bind = ldap_bind( $ds, $ldap_bind_dn, $ldap_bind_pwd );

				//Check to make sure we're bound.
				if ( ! $bind ) {
					error_log( 'LDAP Bind failed with Service Account' );
				} else {
					// Compose LDAP filter string from user login
					$filterstr = '('.$wp_cas_ldap_use_options['ldap_map_login_attr'] . '=' . $login.')';
					if ($wp_cas_ldap_use_options['ldap_users_filter']) {
						$optional_filterstr = $wp_cas_ldap_use_options['ldap_users_filter'];
						if ($optional_filterstr[0] != '(')
							$optional_filterstr = "($optional_filterstr)";
						$filterstr = "(&".$optional_filterstr.$filterstr.")";
					}
					$search = ldap_search(
						$ds,
						($wp_cas_ldap_use_options['ldap_users_basedn']?$wp_cas_ldap_use_options['ldap_users_basedn']:$wp_cas_ldap_use_options['ldapbasedn']),
						$filterstr,
						array(
							$wp_cas_ldap_use_options['ldap_map_login_attr'],
							$wp_cas_ldap_use_options['ldap_map_email_attr'],
							$wp_cas_ldap_use_options['ldap_map_alt_email_attr'],
							$wp_cas_ldap_use_options['ldap_map_first_name_attr'],
							$wp_cas_ldap_use_options['ldap_map_last_name_attr'],
							$wp_cas_ldap_use_options['ldap_map_role_attr'],
							$wp_cas_ldap_use_options['ldap_map_affiliations_attr'],
							$wp_cas_ldap_use_options['ldap_map_nickname_attr'],
							$wp_cas_ldap_use_options['ldap_map_nicename_attr'],
						),
						0
					);
					if($search) {
						$count = ldap_count_entries( $ds, $search);
						if ($count == 1) {
							$entry = ldap_first_entry( $ds, $search );
							$user = new WP_CAS_LDAP_User(
								ldap_get_dn( $ds, $entry),
								ldap_get_attributes( $ds, $entry)
							);
							ldap_free_result($search);

							// Get user's groups (if configured)
							if ($wp_cas_ldap_use_options['ldap_groups_filter']) {

								// Generate user's group LDAP filter from user's DN and data
								$filterstr = $wp_cas_ldap_use_options['ldap_groups_filter'];
								$user_data = $user -> get_user_data();
								if (!preg_match_all("|\{([^\}]+)\}|", $filterstr, $matches, PREG_PATTERN_ORDER)) {
									error_log( "Fail to compose user's groups LDAP filter : no keyword to substitute" );
								}
								else {
									for($i=0;$i<count($matches[0]);$i++) {
                		$keyword = $matches[0][$i];
                		$info = $matches[1][$i];
										if ($info == 'user_dn')
											$value = $user -> get_user_dn();
										elseif (isset($user_data[$info]))
											$value = $user_data[$info];
										else {
											error_log( "Fail to compose user's groups LDAP filter : unknown keyword '$keyword'" );
											return $user;
										}
                    $filterstr = str_replace($keyword, $value, $filterstr);
        					}

									// Lookup for user's groups in LDAP
									$basedn = ($wp_cas_ldap_use_options['ldap_groups_basedn']?$wp_cas_ldap_use_options['ldap_groups_basedn']:$wp_cas_ldap_use_options['ldapbasedn']);
									$search = ldap_search(
										$ds,
										$basedn,
										$filterstr
									);
									if(!$search) {
										ldap_get_option($ds, LDAP_OPT_DIAGNOSTIC_MESSAGE, $details);
										error_log( "Fail to retreive user's groups with filter '$filterstr' on $basedn : ".ldap_error($ds).($details?", details : $details":"") );
										return $user;
									}
									$data = ldap_get_entries($ds, $search);
									$user_groups = array();
									for($i=0; $i < $data['count']; $i++) {
										$user_groups[] = $data[$i]['dn'];
									}
									ldap_free_result($search);
									$user -> set_user_groups($user_groups);
								}
								return $user;
							}
						}
						else {
							error_log("Duplicated users found in LDAP for login '$login'.");
						}
					}
					else {
						error_log("User not found in LDAP for login '$login'.");
					}
				}
			}
		}
		ldap_close( $ds );
	}
	return false;
}

/**
 * get_new_user_data function
 *
 * @param string $cas_user the username of a user
 * @return array returns new user data
 */
function get_new_user_data($cas_user) {
	global $wp_cas_ldap_use_options;

	if ( 'yes' === $wp_cas_ldap_use_options['useldap'] && function_exists( 'ldap_connect' ) ) {
		$ldap_user = get_ldap_user( $cas_user );

		if ( $ldap_user ) {
			$user_data = $ldap_user->get_user_data();
			if ($user_data)
				return $user_data;
		}
		error_log( 'User not found on LDAP Server: ' . $cas_user );
	}

	return array (
		'user_login' => $cas_user,
		'user_email' => $cas_user . '@' . $wp_cas_ldap_use_options['email_suffix'],
		'role' => $wp_cas_ldap_use_options['userrole'],
		'user_pass' => generate_password( 32, 64 ),
	);
}
