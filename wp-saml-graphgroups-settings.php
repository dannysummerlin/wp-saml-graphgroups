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
			'graph'         => __( 'MS Graph Groups Settings', 'wp-saml-auth' ),
		);
		foreach ( self::$sections as $id => $title ) {
			add_settings_section( $id, $title, null, WP_SAML_Auth_GraphGroups_Options::get_option_name() );
		}
	}
	public static function init_fields() {
		self::$fields = array(
			array(
				'section' => 'graph',
				'uid'     => 'client_id_attribute',
				'type'    => 'text',
				'label'   => __( 'oAuth Client Id', 'wp-saml-auth' ),
				'description' => __( 'oAuth Client.', 'wp-saml-auth' ),
				'default' => 'client_id',
			),
			array(
				'section' => 'graph',
				'uid'     => 'client_secret_attribute',
				'label'   => 'client_secret',
				'type'    => 'text',
				'label'   => __( 'oAuth Client Id', 'wp-saml-auth' ),
				'description' => __( 'oAuth Client.', 'wp-saml-auth' ),
				'default' => 'client_secret',
			),
			array(
				'section' => 'graph',
				'uid'     => 'scope_attribute',
				'label'   => 'scope',
				'type'    => 'text',
				'label'   => __( 'Scope', 'wp-saml-auth' ),
				'description' => __( 'ex. https://graph.microsoft.com/.default', 'wp-saml-auth' ),
				'default' => 'https://graph.microsoft.com/.default',
			),
			array(
				'section' => 'graph',
				'uid'     => 'grant_type_attribute',
				'label'   => 'grant_type',
				'type'    => 'text',
				'label'   => __( 'Grant Type', 'wp-saml-auth' ),
				'description' => __( 'ex. client_credentials', 'wp-saml-auth' ),
				'default' => 'client_credentials',
			),
			array(
				'section' => 'graph',
				'uid'     => 'custom_user_fields_attribute',
				'label'   => 'custom_user_fields',
				'type'    => 'text',
				'label'   => __( 'Custom User Fields', 'wp-saml-auth' ),
				'description' => __( 'Enter one comma-separated pair per line like fieldName,Label', 'wp-saml-auth' ),
				'default' => 'fieldName, Field Label',
			),
		);
	}
	public static function get_fields() {
		self::init_fields();
		return self::$fields;
	}
}