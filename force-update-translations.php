<?php
/*
Plugin Name: Force Update Translations
Description: Download WordPress theme/plugin translations and apply them to your site manually even their language pack haven't been released or reviewed on translate.wordpress.org
Author:      Mayo Moriyama
Version:     0.2.2
*/

class Force_Update_Translations {

	private $admin_notices = [];

  /**
   * Constructor.
   */
  function __construct() {

		include 'lib/glotpress/locales.php';

		add_action( 'plugin_action_links',               array( $this, 'plugin_action_links'        ), 10, 2 );
		add_action( 'network_admin_plugin_action_links', array( $this, 'plugin_action_links'        ), 10, 2 );
		add_action( 'pre_current_active_plugins',        array( $this, 'pre_current_active_plugins' ) );

  }
	/**
	 * Add plugin action link.
	 *
	 * @param string $actions
	 * @param string $plugin_file
	 * @return array $actions    File path to get source.
	 */
	function plugin_action_links( $actions, $plugin_file ) {
		$url         = admin_url( 'plugins.php?force_translate=' . $plugin_file );
		$new_actions = array (
			'force_translate' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Update translation', 'force-update-translations' )
			)
		);
		// Check if plugin is on wordpress.org by checking if ID (from Plugin wp.org info) exists in 'response' or 'no_update'
		$on_wporg = false;
		$plugin_state = get_site_transient( 'update_plugins' );
		if ( isset( $plugin_state->response[ $plugin_file ]->id ) || isset( $plugin_state->no_update[ $plugin_file ]->id ) ) {
			$on_wporg = true;
		};
		if ( $on_wporg ) {
			$actions  = array_merge( $actions, $new_actions );
		};
		return $actions;

	}
	/**
	 * Main plugin action.
	 *
	 * @return null
	 */
	function pre_current_active_plugins() {
		if ( !isset( $_GET['force_translate'] ) ) {
			return;
		}

		$plugin_file = $_GET['force_translate'];
		if ( !preg_match("/^([a-zA-Z0-9-_]+)\/([a-zA-Z0-9-_.]+.php)$/", $plugin_file, $plugin_slug) ){
			$this->admin_notices[] = array(
				'status'  => 'error',
				'content' => sprintf(
					/* Translators: %s: parameter */
					esc_html__( 'Invalid parameter: %s', 'force-update-translations' ),
					esc_html( $plugin_file )
				)
			);
			self::admin_notices();
			return;
		}

		foreach ( array( 'po', 'mo' ) as $type ){
			$import = $this->import( 'wp-plugins/'. $plugin_slug[1], get_user_locale(), $type );
			if( is_wp_error( $import ) ) {
				$this->admin_notices[] = array(
					'status'  => 'error',
					'content' => $import->get_error_message()
				);
			}
		} // endforeach;

		if ( empty( $this->admin_notices ) ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false );
			$this->admin_notices[] = array(
				'status'  => 'success',
				'content' => sprintf(
					__( 'Translation files have been exported: %s', 'force-update-translations' ),
					'<b>' . esc_html( $plugin_data['Name'] ) . '</b>' )
			);
		}
		self::admin_notices();

	}
	/**
	 * Import translation file.
	 *
	 * @param string $project   File project
	 * @param string $locale    File locale
	 * @param string $format    File format
	 * @return null|WP_Error    File path to get source.
	 */
	function import( $project_slug, $locale = '', $format = 'mo' ) {

		if ( empty( $locale ) ) {
			$locale = get_user_locale();
		}

		preg_match("/wp-(.*)/", $project_slug, $project_path);

		$source = $this->get_source_path( $project_slug, $locale, $format );
		$target = sprintf(
			'%s-%s.%s',
			$project_path[1],
			$locale,
			$format
		);
		$response = wp_remote_get( $source );

		if ( !is_array( $response )
			|| $response['headers']['content-type'] !== 'application/octet-stream' ) {
			return new WP_Error( 'fdt-source-not-found', sprintf(
				__( 'Cannot get source file: %s', 'force-update-translations' ),
				'<b>' . esc_html( $source ) . '</b>'
			) );
		}
		else {
			file_put_contents( WP_LANG_DIR . '/' . $target, $response['body'] );
			return;
		}
	}
	/**
	 * Generate a file path to get translation file.
	 *
	 * @param string $project   File project
	 * @param string $locale    File locale
	 * @param string $type      File type
	 * @param string $format    File format
	 * @return $path            File path to get source.
	 */
	function get_source_path( $project, $locale, $format = 'mo', $type = 'dev' ) {
		$locale = GP_Locales::by_field( 'wp_locale', $locale );
		$path = sprintf( 'https://translate.wordpress.org/projects/%1$s/%2$s/%3$s/default/export-translations?filters[status]=current_or_waiting_or_fuzzy',
			$project,
			$type,
			$locale->slug
		);
		$path = ( $format == 'po' ) ? $path : $path . '&format=' . $format;
		$path = esc_url_raw( $path );
		return $path;
	}

	/**
	 * Prints admin screen notices.
	 *
	 */
	function admin_notices() {
		if ( empty( $this->admin_notices ) ) {
			return;
		}
		foreach ( $this->admin_notices as $notice ) {
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['status'] ); ?>">
					<p><?php echo $notice['content']; // WPCS: XSS OK. ?></p>
			</div>
			<?php
		}
	}
}

new Force_Update_Translations;
