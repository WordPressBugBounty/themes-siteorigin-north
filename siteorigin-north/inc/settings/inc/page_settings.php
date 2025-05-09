<?php

/**
 * A basic settings class used to add settings metaboxes to pages.
 *
 * Class SiteOrigin_Settings_Page_Settings
 */
class SiteOrigin_Settings_Page_Settings {
	private $meta;

	public function __construct() {
		$this->meta = array();

		add_action( 'init', array( $this, 'add_page_settings_support' ) );

		// All the meta box stuff
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10 );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_css' ) );

		// Page Builder integration
		add_action( 'siteorigin_panels_create_home_page', array( $this, 'panels_save_home_page' ) );

		if ( is_admin() || is_customize_preview() ) {
			// Initialize Page Settings Customizer if we're in the admin.
			SiteOrigin_Settings_Page_Settings_Customizer::single();
		}
	}

	/**
	 * Get the singular instance
	 *
	 * @return SiteOrigin_Settings_Page_Settings
	 */
	public static function single() {
		static $single;

		if ( empty( $single ) ) {
			$single = new self();
		}

		return $single;
	}

	/**
	 * Get a settings value
	 *
	 * @return null
	 */
	public static function get( $key = false, $default = null ) {
		$single = self::single();

		static $type = false;
		static $id = false;

		if ( ( apply_filters( 'siteorigin_page_settings_get_query_bypass', false ) || is_main_query() ) && $type === false && $id === false ) {
			list( $type, $id ) = self::get_current_page();
		}

		// If we're unable to detect a valid type, or id, return the default.
		if ( is_array( $type ) || is_array( $id ) ) {
			return $default;
		}

		if ( empty( $single->meta[ $type . '_' . $id ] ) ) {
			$single->meta[ $type . '_' . $id ] = $single->get_settings_values( $type, $id );
		}


		if ( empty( $key ) ) {
			$value = $single->meta[ $type . '_' . $id ];
		} else {
			$value = isset( $single->meta[ $type . '_' . $id ][ $key ] ) ? $single->meta[ $type . '_' . $id ][ $key ] : $default;
		}

		return apply_filters(
			'siteorigin_page_setting_get_' . $key,
			$value,
			$default
		);
	}

	public function get_settings( $type, $id ) {
		return apply_filters( 'siteorigin_page_settings', array(), $type, $id );
	}

	public function add_page_settings_support() {
		add_post_type_support( 'page', 'so-page-settings' );
		add_post_type_support( 'post', 'so-page-settings' );

		if ( post_type_exists( 'jetpack-portfolio' ) ) {
			add_post_type_support( 'jetpack-portfolio', 'so-page-settings' );
		}
	}

	public function get_settings_defaults( $type, $id ) {
		return apply_filters( 'siteorigin_page_settings_defaults', array(), $type, $id );
	}

	public static function get_current_page() {
		global $wp_query;

		if ( $wp_query->is_home() ) {
			$type = 'template';
			$id = 'home';
		} elseif ( $wp_query->is_search() ) {
			$type = 'template';
			$id = 'search';
		} elseif ( $wp_query->is_404() ) {
			$type = 'template';
			$id = '404';
		} elseif ( $wp_query->is_date() ) {
			$type = 'template';
			$id = 'date';
		} elseif ( $wp_query->is_post_type_archive() ) {
			$type = 'archive';
			$id = $wp_query->get( 'post_type' );
		} else {
			$object = get_queried_object();

			if ( ! empty( $object ) ) {
				switch( get_class( $object ) ) {
					case 'WP_Term':
						$type = 'taxonomy';
						$id = $object->taxonomy;
						break;

					case 'WP_Post':
						$type = 'post';
						$id = $object->ID;
						break;

					case 'WP_User':
						$type = 'template';
						$id = 'author';
						break;
				}
			} else {
				$type = 'template';
				$id = 'default';
			}
		}

		return array( $type, $id );
	}

	/**
	 * Get the settings post meta and add the default values.
	 *
	 * @return array|mixed
	 */
	public function get_settings_values( $type, $id ) {
		$defaults = $this->get_settings_defaults( $type, $id );
		$values = false;

		// If $type or $id is an array, we can't detect values.
		if ( ! is_array( $type ) && ! is_array( $id ) ) {
			switch( $type ) {
				case 'archive':
				case 'template':
				case 'taxonomy':
					$values = get_theme_mod( 'page_settings_' . $type . '_' . $id );
					break;

				case 'post':
				default:
					$values = get_post_meta( $id, 'siteorigin_page_settings', true );
					break;
			}
		}

		if ( empty( $values ) ) {
			$values = array();
		}
		$values = apply_filters( 'siteorigin_page_settings_values', $values, $type, $id );

		return wp_parse_args( $values, $defaults );
	}

	/**
	 * Add the meta box.
	 */
	public function add_meta_box( $post_type ) {
		// Don't add meta box to WooCommerce Shop page.
		$screen = get_current_screen();

		if (
			class_exists( 'WooCommerce' ) &&
			! empty( $screen ) && $screen->id == 'page' &&
			isset( $_GET['post'] ) &&
			get_option( 'woocommerce_shop_page_id' ) == $_GET['post']
		) {
			return;
		}

		if ( ! empty( $post_type ) && post_type_supports( $post_type, 'so-page-settings' ) ) {
			add_meta_box(
				'siteorigin_page_settings',
				__( 'Page settings', 'siteorigin-north' ),
				array( $this, 'display_post_meta_box' ),
				$post_type,
				'side'
			);
		}
	}

	/**
	 * Display the Meta Box
	 */
	public function display_post_meta_box( $post ) {
		$settings = $this->get_settings( $post->post_type, $post->ID );
		$values = $this->get_settings_values( $post->post_type, $post->ID );

		wp_enqueue_style( 'siteorigin-settings-metabox' );

		do_action( 'siteorigin_settings_before_page_settings_meta_box', $post );

		foreach ( $settings as $id => $field ) {
			if ( empty( $values[$id] ) ) {
				$values[$id] = false;
			}

			?><p><label for="so-page-settings-<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $field['label'] ); ?></strong></label></p><?php

			switch( $field['type'] ) {
				case 'select':
					?>
					<select name="so_page_settings[<?php echo esc_attr( $id ); ?>]" id="so-page-settings-<?php echo esc_attr( $id ); ?>">
						<?php foreach ( $field['options'] as $v => $n ) { ?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $values[$id], $v ); ?>><?php echo esc_html( $n ); ?></option>
						<?php } ?>
					</select>
					<?php

					break;

				case 'checkbox':
					?>
					<label><input type="checkbox" name="so_page_settings[<?php echo esc_attr( $id ); ?>]" <?php checked( $values[$id] ); ?> /><?php echo esc_html( $field['checkbox_label'] ); ?></label>
					<?php
					break;

				case 'text':
				default:
					?><input type="text" name="so_page_settings[<?php echo esc_attr( $id ); ?>]" id="so-page-settings-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $values[$id] ); ?>" /><?php
					break;
			}

			if ( ! empty( $field['description'] ) ) {
				?><p class="description"><?php echo esc_html( $field['description'] ); ?></p><?php
			}
		}

		wp_nonce_field( 'save_page_settings', '_so_page_settings_nonce' );

		do_action( 'siteorigin_settings_after_page_settings_meta_box', $post );
	}

	/**
	 * Save settings
	 */
	public function save_post( $post_id, $post ) {
		if ( !current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['_so_page_settings_nonce'] ) || !wp_verify_nonce( $_POST['_so_page_settings_nonce'], 'save_page_settings' ) ) {
			return;
		}

		if ( empty( $_POST['so_page_settings'] ) ) {
			return;
		}

		$settings = stripslashes_deep( $_POST['so_page_settings'] );
		$post_type = ! wp_is_post_revision( $post_id ) ? $post->post_type : get_post_type( $post->post_parent );

		foreach ( $this->get_settings( $post_type, $post_id ) as $id => $field ) {
			switch( $field['type'] ) {
				case 'select':
					if (
						isset( $settings[ $id ] ) &&
						! in_array( $settings[ $id ], array_keys( $field['options'] ) )
					) {
						$settings[ $id ] = isset( $field['default'] ) ? $field['default'] : null;
					}
					break;

				case 'checkbox':
					$settings[$id] = !empty( $settings[$id] );
					break;

				case 'text':
				default:
					$settings[$id] = sanitize_text_field( $settings[$id] );
					break;
			}
		}

		update_post_meta( $post_id, 'siteorigin_page_settings', $settings );
	}

	public function register_css() {
		wp_enqueue_style(
			'siteorigin-settings-metabox',
			get_template_directory_uri() . '/inc/settings/css/page-settings.css',
			array(),
			SITEORIGIN_THEME_VERSION
		);
	}

	public function panels_save_home_page( $post_id ) {
		$settings = $this->get_settings_values( 'post', $post_id );
		$settings = apply_filters( 'siteorigin_page_settings_panels_home_defaults', $settings );
		update_post_meta( $post_id, 'siteorigin_page_settings', $settings );
	}
}
SiteOrigin_Settings_Page_Settings::single();

function siteorigin_page_setting( $setting = false, $default = false ) {
	return SiteOrigin_Settings_Page_Settings::single()->get( $setting, $default );
}
