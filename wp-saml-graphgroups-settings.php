<?php
class WP_SAML_Auth_GraphGroups_Settings {
	private static $capability = 'manage_options';
	private static $fields;
	private static $instance;
	private static $menu_slug = 'wp-saml-auth-graphgroups-settings';
	private static $option_group = 'wp-saml-auth-graphgroups-settings-group';
	private static $sections;
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WP_SAML_Auth_GraphGroups_Settings;
			add_action( 'admin_init', array( self::$instance, 'admin_init' ) );
			add_action( 'admin_menu', array( self::$instance, 'admin_menu' ) );
			add_filter(
				'plugin_action_links_' . plugin_basename( dirname( plugin_dir_path( __FILE__ ) ) ) .
					'/wp-saml-auth.php',
				array( self::$instance, 'plugin_settings_link' )
			);
		}
		return self::$instance;
	}
	public static function admin_init() {
		register_setting(
			self::$option_group,
			WP_SAML_Auth_GraphGroups_Options::get_option_name(),
			array( 'sanitize_callback' => array( self::$instance, 'sanitize_callback' ) )
		);
		self::setup_sections();
		self::setup_fields();
	}
	public static function admin_menu() {
		add_options_page(
			__( 'WP SAML Auth Settings', 'wp-saml-auth' ),
			__( 'WP SAML Auth', 'wp-saml-auth' ),
			self::$capability,
			self::$menu_slug,
			array( self::$instance, 'render_page_content' )
		);
	}
	public static function field_callback( $arguments ) {
		$uid   = WP_SAML_Auth_GraphGroups_Options::get_option_name() . '[' . $arguments['uid'] . ']';
		$value = $arguments['value'];
		switch ( $arguments['type'] ) {
			case 'checkbox':
				printf( '<input id="%1$s" name="%1$s" type="checkbox"%2$s>', esc_attr( $uid ), checked( $value, true, false ) );
				break;
			case 'select':
				if ( ! empty( $arguments['choices'] ) && is_array( $arguments['choices'] ) ) {
					$markup = '';
					foreach ( $arguments['choices'] as $key => $label ) {
						$markup .= '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) .
									'</option>';
					}
					printf( '<select name="%1$s" id="%1$s">%2$s</select>', esc_attr( $uid ), $markup );
				}
				break;
			case 'text':
			case 'url':
				printf(
					'<input name="%1$s" type="text" id="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $uid ),
					esc_attr( $value )
				);
				break;
		}
		if ( isset( $arguments['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $arguments['description'] ) );
		}
	}
	public static function render_page_content() {
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'WP SAML Auth Settings', 'wp-saml-auth' ); ?></h2>
			<?php if ( WP_SAML_Auth_GraphGroups_Options::has_settings_filter() ) : ?>
				<p>
				<?php
				// translators: Link to the plugin settings page.
				echo sprintf( __( 'Settings are defined with a filter and unavailable for editing through the backend. <a href="%s">Visit the plugin page</a> for more information.', 'wp-saml-auth' ), 'https://wordpress.org/plugins/wp-saml-auth/' );
				?>
				</p>
			<?php else : ?>
				<p>
				<?php
				// translators: Link to the plugin settings page.
				echo sprintf( __( 'Use the following settings to configure WP SAML Auth with the \'internal\' connection type. <a href="%s">Visit the plugin page</a> for more information.', 'wp-saml-auth' ), 'https://wordpress.org/plugins/wp-saml-auth/' );
				?>
				</p>
				<?php if ( WP_SAML_Auth_GraphGroups_Options::do_required_settings_have_values() ) : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'Settings are actively applied to WP SAML Auth configuration.', 'wp-saml-auth' ); ?></p></div>
				<?php else : ?>
					<div class="notice error"><p><?php esc_html_e( 'Some required settings don\'t have values, so WP SAML Auth isn\'t active.', 'wp-saml-auth' ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="options.php">
					<?php
						settings_fields( self::$option_group );
						do_settings_sections( WP_SAML_Auth_GraphGroups_Options::get_option_name() );
						submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
	public static function plugin_settings_link( $links ) {
		$a = '<a href="' . menu_page_url( self::$menu_slug, false ) . '">' . esc_html__( 'Settings', 'wp-saml-auth' ) . '</a>';
		array_push( $links, $a );
		return $links;
	}
	public static function sanitize_callback( $input ) {
		if ( empty( $input ) || ! is_array( $input ) ) {
			return array();
		}
		foreach ( self::$fields as $field ) {
			$section = self::$sections[ $field['section'] ];
			$uid     = $field['uid'];
			$value   = $input[ $uid ];
			// checkboxes.
			if ( 'checkbox' === $field['type'] ) {
				$input[ $uid ] = isset( $value ) ? true : false;
			}
			// required fields.
			if ( isset( $field['required'] ) && $field['required'] ) {
				if ( empty( $value ) ) {
					$input['connection_type'] = null;
					add_settings_error(
						WP_SAML_Auth_GraphGroups_Options::get_option_name(),
						$uid,
						// translators: Field label.
						sprintf( __( '%s is a required field', 'wp-saml-auth' ), trim( $section . ' ' . $field['label'] ) )
					);
				}
			}
			// text fields.
			if ( 'text' === $field['type'] ) {
				if ( ! empty( $value ) ) {
					$input[ $uid ] = sanitize_text_field( $value );
				}
			}
			// url fields.
			if ( 'url' === $field['type'] ) {
				if ( ! empty( $value ) ) {
					if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
						$input[ $uid ] = esc_url_raw( $value, array( 'http', 'https' ) );
					} else {
						$input['connection_type'] = null;
						$input[ $uid ]            = null;
						add_settings_error(
							WP_SAML_Auth_GraphGroups_Options::get_option_name(),
							$uid,
							// translators: Field label.
							sprintf( __( '%s is not a valid URL.', 'wp-saml-auth' ), trim( $section . ' ' . $field['label'] ) )
						);
					}
				}
			}
			if ( 'x509cert' === $field['uid'] ) {
				if ( ! empty( $value ) ) {
					$value = str_replace( 'ABSPATH', ABSPATH, $value );
					if ( ! file_exists( $value ) ) {
						add_settings_error(
							WP_SAML_Auth_GraphGroups_Options::get_option_name(),
							$uid,
							// translators: Field label.
							sprintf( __( '%s is not a valid certificate path.', 'wp-saml-auth' ), trim( $section . ' ' . $field['label'] ) )
						);
					}
				}
			}
		}
		return $input;
	}
	public static function setup_fields() {
		self::init_fields();
		$options = get_option( WP_SAML_Auth_GraphGroups_Options::get_option_name() );
		foreach ( self::$fields as $field ) {
			if ( ! empty( $options ) && is_array( $options ) && array_key_exists( $field['uid'], $options ) ) {
				$field['value'] = $options[ $field['uid'] ];
			} else {
				$field['value'] = isset( $field['default'] ) ? $field['default'] : null;
			}
			add_settings_field(
				$field['uid'],
				$field['label'],
				array( self::$instance, 'field_callback' ),
				WP_SAML_Auth_GraphGroups_Options::get_option_name(),
				$field['section'],
				$field
			);
		}
	}
	public static function setup_sections() {
		self::$sections = array(
			'general'    => '',
			'sp'         => __( 'Service Provider Settings', 'wp-saml-auth' ),
			'idp'        => __( 'Identity Provider Settings', 'wp-saml-auth' ),
			'attributes' => __( 'Attribute Mappings', 'wp-saml-auth' ),
		);
		foreach ( self::$sections as $id => $title ) {
			add_settings_section( $id, $title, null, WP_SAML_Auth_GraphGroups_Options::get_option_name() );
		}
	}
	public static function init_fields() {
		self::$fields = array(
			// general section.
			array(
				'section'     => 'general',
				'uid'         => 'auto_provision',
				'label'       => __( 'Auto Provision', 'wp-saml-auth' ),
				'type'        => 'checkbox',
				'description' => __( 'If checked, create a new WordPress user upon login. <br>If unchecked, WordPress user will already need to exist in order to log in.', 'wp-saml-auth' ),
				'default'     => 'true',
			),
			// sp section.
			array(
				'section'     => 'sp',
				'uid'         => 'sp_entityId',
				'label'       => __( 'Entity Id (Required)', 'wp-saml-auth' ),
				'type'        => 'text',
				'choices'     => false,
				'description' => __( 'SP (WordPress) entity identifier.', 'wp-saml-auth' ),
				'default'     => 'urn:' . parse_url( home_url(), PHP_URL_HOST ),
				'required'    => true,
			),
			// attributes section.
			array(
				'section' => 'attributes',
				'uid'     => 'user_login_attribute',
				'label'   => 'user_login',
				'type'    => 'text',
				'default' => 'uid',
			),
			array(
				'section' => 'attributes',
				'uid'     => 'user_email_attribute',
				'label'   => 'user_email',
				'type'    => 'text',
				'default' => 'email',
			),
			array(
				'section' => 'attributes',
				'uid'     => 'display_name_attribute',
				'label'   => 'display_name',
				'type'    => 'text',
				'default' => 'display_name',
			),
			array(
				'section' => 'attributes',
				'uid'     => 'first_name_attribute',
				'label'   => 'first_name',
				'type'    => 'text',
				'default' => 'first_name',
			),
			array(
				'section' => 'attributes',
				'uid'     => 'last_name_attribute',
				'label'   => 'last_name',
				'type'    => 'text',
				'default' => 'last_name',
			),
		);
	}
	public static function get_fields() {
		self::init_fields();
		return self::$fields;
	}
}