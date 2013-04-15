<?php
/*
Plugin Name:    WP Facebook Open Graph protocol
Plugin URI:     http://wordpress.org/extend/plugins/wp-facebook-open-graph-protocol/
Description:    Adds proper Facebook Open Graph Meta tags and values to your site so when links are shared it looks awesome! Works on Google + and Linkedin too!
Version:        2.1
Author:         Chuck Reynolds
Author URI:     http://chuckreynolds.us
License:        GPL v3
License URI:    http://www.gnu.org/licenses/gpl-3.0.html
Text Domain:    wpfbogp
Domain Path:    /languages/
*/
/*
	Copyright 2011 WordPress Facebook Open Graph protocol plugin (email: chuck@rynoweb.com)

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, version 3 of the License.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see http://www.gnu.org/licenses/gpl-3.0.html
*/

class WPFBOGP {

	const VERSION = '2.1';

	public function __construct() {
		// Check to see if any warnings should be shown to admins
		$this->admin_warnings();

		// Jetpack used to force it in, seems to have stopped but just for good measure
		remove_action( 'wp_head', array( $this, 'jetpack_og_tags' ) );

		// Add the OGP namespace to the <html> tag.
		add_filter( 'language_attributes', array( $this, 'ogpprefix' ) );

		// Start the output buffer early, and end it late
		add_action( 'init', array( $this, 'buffer' ), 0 );
		add_action( 'wp_head', array( $this, 'build_head' ), 9999 );

		// Include and build the admin pages
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );

		// Remove some default filters on the_excerpt()
		add_action( 'after_setup_theme', array( $this, 'fix_excerpts' ) );

		// Add helpful settings link to the plugins listing page
		add_filter( 'plugin_action_links', array( $this, 'add_settings_link' ), 10, 2 );

		// Adds a debug menu item to the admin bar
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_link' ), 1000 );
	}

	/**
	 * Add OGP namespace per ogp.me schema
	 *
	 * @param  string $output
	 * @return string
	 */
	public function ogpprefix( $output ) {
		return $output.' prefix="og: http://ogp.me/ns#"';
	}

	/**
	 * Finds the first image in the post content if post thumbnails aren't
	 * available.
	 *
	 * @return array
	 */
	public function find_images() {
		global $post, $posts;

		// Grab content and match first image
		$content = $post->post_content;
		$output = preg_match_all( '/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $content, $matches );

		// Make sure there was an image that was found, otherwise return false
		if ( $output === FALSE ) {
			return false;
		}

		$images = array();
		foreach ( $matches[1] as $match ) {
			// If the image path is relative, add the site url to the beginning
			if ( ! preg_match( '/^https?:\/\//', $match ) ) {
				// Remove any starting slash with ltrim() and add one to the end of site_url()
				$match = site_url( '/' ) . ltrim( $match, '/' );
			}
			$images[] = $match;
		}

		return $images;
	}

	/**
	 * Parses the output for the title or falls back to some sensible defaults.
	 *
	 * @param  string $content
	 * @return string
	 */
	public function get_title( $content ) {
		global $post;

		$title = preg_match( '/<title>(.*)<\/title>/', $content, $title_matches );
		if ( $title !== FALSE && count( $title_matches ) == 2 ) {
			$title = $title_matches[1];
		} elseif ( is_home() || is_front_page() ) {
			$title = get_bloginfo( 'name' );
		} else {
			$title = the_title_attribute( 'echo=0' );
		}

		return $title;
	}

	/**
	 * Parses the output for the description for falls back to some sensible
	 * defaults.
	 *
	 * @param  string $content
	 * @return string
	 */
	public function get_description( $content ) {
		global $post;

		$description = preg_match( '/<meta name="description" content="(.*)"/', $content, $description_matches );
		if ( $description !== FALSE && count( $description_matches ) == 2 ) {
			$description = $description_matches[1];
		} elseif ( is_singular() ) {
			// Use any custom excerpt before simply truncating the content,
			// but ignore the front page.
			if ( has_excerpt( $post->ID ) ) {
				$description = strip_tags( get_the_excerpt( $post->ID ) );
			} else {
				$description = str_replace( "\r\n", ' ' , substr( strip_tags( strip_shortcodes( $post->post_content ) ), 0, 160 ) );
			}
		} else {
			// Default to the blog description
			$description = get_bloginfo( 'description' );
		}

		return $description;
	}

	/**
	 * Starts the output buffer at the very beginning of wp_head().
	 *
	 * @return void
	 */
	public function buffer() {
		ob_start();
	}

	/**
	 * The heart of the plugin, which outputs the entire meta tag output
	 * to the wp_head() action.
	 *
	 * @return string
	 */
	public function build_head() {
		global $post;
		$options = get_option( 'wpfbogp' );

		// Get the output buffer contents, which will include all previous
		// output before our plugin is called (hopefully last in the stack).
		$content = ob_get_contents();

		// Immediately flush the buffer so no output is lost.
		ob_end_flush();

		// check to see if you've filled out one of the required fields and announce if not
		if ( ( ! isset( $options['wpfbogp_admin_ids'] ) || empty( $options['wpfbogp_admin_ids'] ) ) && ( ! isset( $options['wpfbogp_app_id'] ) || empty( $options['wpfbogp_app_id'] ) ) ) {
			echo "\n<!-- Facebook Open Graph protocol plugin NEEDS an admin or app ID to work, please visit the plugin settings page! -->\n";
			return;
		}

		echo "\n<!-- WordPress Facebook Open Graph protocol plugin (WPFBOGP v".self::VERSION.") http://rynoweb.com/wordpress-plugins/ -->\n";

		// If there are admin ID(s) to output, we must split them up and
		// output them as an array.
		if ( isset( $options['wpfbogp_admin_ids'] ) && ! empty( $options['wpfbogp_admin_ids'] ) ) {
			// Remove spaces around commas for consistent exploding
			$admins = explode( ',', preg_replace( '/\s*,\s*/', ',', $options['wpfbogp_admin_ids'] ) );

			// Allow the array of admins to be filtered
			$admins = apply_filters( 'wpfbogp_admin_ids', $admins );

			// Output a meta tag for each admin ID
			foreach ( $admins as $admin ) {
				echo '<meta property="fb:admins" content="' . esc_attr( $admin ) . '" />' . "\n";
			}
		}

		// If an application ID is being used, output it
		if ( isset( $options['wpfbogp_app_id'] ) && ! empty( $options['wpfbogp_app_id'] ) ) {
			echo '<meta property="fb:app_id" content="' . esc_attr( apply_filters( 'wpfbogp_app_id', $options['wpfbogp_app_id'] ) ) . '" />' . "\n";
		}

		// do url stuff
		if ( is_home() || is_front_page() ) {
			$url = get_bloginfo( 'url' );
		} else {
			$url = 'http' . ( is_ssl() ? 's' : '' ) . "://".$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}
		echo '<meta property="og:url" content="' . esc_url( apply_filters( 'wpfbogp_url', $url ) ) . '" />' . "\n";

		// First we will attempt to match the content of the <title> tags,
		// to capture any changes that were done by an SEO plugin. If that
		// fails, then we fall back to the blog name or the post name.
		$title = $this->get_title( $content );
		echo '<meta property="og:title" content="' . esc_attr( apply_filters( 'wpfbogp_title', $title ) ) . '" />' . "\n";

		// Site title
		echo '<meta property="og:site_name" content="' . esc_attr( apply_filters( 'wpfbogp_site_name', get_bloginfo( 'name' ) ) ) . '" />' . "\n";

		// We follow the same flow as titles, where we try to match any
		// existing description before moving onto the fallbacks.
		$description = $this->get_description( $content );
		echo '<meta property="og:description" content="' . esc_attr( apply_filters( 'wpfbogp_description', $description ) ) . '" />' . "\n";

		// do ogp type
		if ( is_home() ) {
			$type = 'blog';
		} elseif ( is_single() ) {
			$type = 'article';
		} else {
			$type = 'website';
		}
		echo '<meta property="og:type" content="' . esc_attr( apply_filters( 'wpfbpogp_type', $type ) ) . '" />' . "\n";

		// Find/output any images for use in the OGP tags
		$wpfbogp_images = array();

		// First check for a fallback image
		if ( isset( $options['wpfbogp_fallback_img'] ) && $options['wpfbogp_fallback_img'] != '' ) {
			$fallback = '<meta property="og:image" content="' . esc_url( apply_filters( 'wpfbogp_image', $options['wpfbogp_fallback_img'] ) ) . '" />' . "\n";
		} else {
			$fallback = false;
		}

		// Always output the fallback image first
		echo $fallback;

		// If the fallback isn't forced, we can now output more images
		if ( $options['wpfbogp_force_fallback'] != 1 ) {
			// Make sure we are on a single page, not an archive or index
			if ( is_singular() ) {
				// Find featured thumbnail of the current post/page
				if ( function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post->ID ) ) {
					$thumbnail_src = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'medium' );
					$wpfbogp_images[] = $thumbnail_src[0]; // Add to images array
				}

				// Find any images in post/page content and put into current array
				if ( $this->find_images() !== false ) {
					$wpfbogp_images = array_merge( $wpfbogp_images, $this->find_images() );
				}
			}

			// Make sure there were images passed as an array and loop through/output each
			if ( ! empty( $wpfbogp_images ) && is_array( $wpfbogp_images ) ) {
				foreach ( $wpfbogp_images as $image ) {
					echo '<meta property="og:image" content="' . esc_url( apply_filters( 'wpfbogp_image', $image ) ) . '" />' . "\n";
				}
			}
		}

		// No images were available
		if ( empty( $wpfbogp_images ) && ! $fallback ) {
			echo "<!-- There is not an image here as you haven't set a default image in the plugin settings! -->\n";
		}

		// do locale // make lower case cause facebook freaks out and shits parser mismatched metadata warning
		echo '<meta property="og:locale" content="' . strtolower( esc_attr( get_locale() ) ) . '" />' . "\n";
		echo "<!-- // end wpfbogp -->\n";
	}

	/**
	 * Initializes the plugin including settings and localization.
	 *
	 * @return void
	 */
	public function init() {
		// Register settings and sanitization callback
		register_setting( 'wpfbogp_options', 'wpfbogp', array( $this, 'validate' ) );

		// Load localization
		load_plugin_textdomain( 'wpfbogp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Add admin page to the WordPress menu
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page( __( 'Facebook Open Graph protocol plugin', 'wpfbogp' ), __( 'Facebook OGP', 'wpfbogp' ), 'manage_options', 'wpfbogp', array( $this, 'buildpage' ) );
	}

	/**
	 * Admin page output
	 *
	 * @return void
	 */
	public function buildpage() {
		load_plugin_textdomain( 'wpfbogp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		?>
		<div class="wrap">
			<h2><?php printf( _x( 'Facebook Open Graph protocol plugin %s', 'Headline + Version', 'wpfbogp' ), '<em>v' . self::VERSION . '</em>' ) ?></h2>
			<div id="poststuff" class="metabox-holder has-right-sidebar">
				<div id="side-info-column" class="inner-sidebar">
					<div class="meta-box-sortables">
						<div id="about" class="postbox">
							<h3 class="hndle" id="about-sidebar"><?php _e( 'About the Plugin:', 'wpfbogp' ) ?></h3>
							<div class="inside">
								<p><?php printf( __( 'Talk to %s on twitter or please fill out the %s for bugs or feature requests.', 'wpfbogp' ), '<a href="http://twitter.com/chuckreynolds" target="_blank">@ChuckReynolds</a>', '<a href="http://rynoweb.com/wordpress-plugins/" target="_blank">' . __( 'plugin support form', 'wpfbogp' ) . '</a>' ) ?></p>
								<p>
									<strong><?php _e( 'Having problems?', 'wpfbogp' ); ?></strong><br>
									<?php printf( __( 'If you are experiencing issues with the correct information appearing on Facebook, please run the URL through the <a href="%s">Facebook debugger</a> to check for errors.', 'wpfbogp' ), 'https://developers.facebook.com/tools/debug' ); ?>
								</p>
								<p><strong><?php _e( 'Enjoy the plugin?', 'wpfbogp' ) ?></strong><br />
								<?php printf( __( '%s and consider donating.', 'wpfbogp' ), '<a href="http://twitter.com/?status=I\'m using @chuckreynolds\'s WordPress Facebook Open Graph plugin - check it out! http://rynoweb.com/wordpress-plugins/" target="_blank">' . __( 'Tweet about it', 'wpfbogp' ) . '</a>' ) ?></p>
								<p><?php _e( '<strong>Donate:</strong> A lot of hard work goes into building plugins - support your open source developers. Include your twitter username and I\'ll send you a shout out for your generosity. Thank you!', 'wpfbogp' ) ?><br />
								<form action="https://www.paypal.com/cgi-bin/webscr" method="post">
								<input type="hidden" name="cmd" value="_s-xclick">
								<input type="hidden" name="hosted_button_id" value="GWGGBTBJTJMPW">
								<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
								<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
								</form></p>
							</div>
						</div>
					</div>

					<div class="meta-box-sortables">
						<div id="ogp-info" class="postbox">
							<h3 class="hndle" id="about-sidebar"><?php _e( 'Relevant Information:', 'wpfbogp' ) ?></h3>
							<div class="inside">
								<p><a href="http://ogp.me" target="_blank"><?php _e( 'The Open Graph Protocol', 'wpfbogp' ) ?></a><br />
								<a href="https://developers.facebook.com/docs/opengraph/" target="_blank"><?php _e( 'Facebook Open Graph Docs', 'wpfbogp' ) ?></a><br />
								<a href="https://developers.facebook.com/docs/insights/" target="_blank"><?php _e( 'Insights: Domain vs App vs Page', 'wpfbogp' ) ?></a><br />
								<a href="https://developers.facebook.com/docs/reference/plugins/like/" target="_blank"><?php _e( 'How To Add a Like Button', 'wpfbogp' ) ?></a></p>
							</div>
						</div>
					</div>
				</div> <!-- // #side-info-column .inner-sidebar -->

				<div id="post-body" class="has-sidebar">
					<div id="post-body-content" class="has-sidebar-content">
						<div id="normal-sortables" class="meta-box-sortables">
							<div id="wpfbogp-options" class="postbox">
								<div class="inside">

				<form method="post" action="options.php">
					<?php settings_fields( 'wpfbogp_options' ); ?>
					<?php $options = get_option( 'wpfbogp' ); ?>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e( 'Facebook User Account ID:', 'wpfbogp' ) ?></th>
						<td><input type="text" name="wpfbogp[wpfbogp_admin_ids]" value="<?php echo $options['wpfbogp_admin_ids']; ?>" class="regular-text" /><br />
							<?php _e( "For personal sites use your Facebook User ID here. <em>(You can enter multiple by separating each with a comma)</em>, if you want to receive Insights about the Like Buttons. The meta values will not display in your site until you've completed this box.", 'wpfbogp' ) ?><br />
							<?php _e( '<strong>Find your ID</strong> by going to the URL like this:', 'wpfbogp' ) ?> http://graph.facebook.com/yourusername</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e( 'Facebook Application ID:', 'wpfbogp' ) ?></th>
						<td><input type="text" name="wpfbogp[wpfbogp_app_id]" value="<?php echo $options['wpfbogp_app_id']; ?>" class="regular-text" /><br />
							<?php printf( __( 'For business and/or brand sites use Insights on an App ID as to not associate it with a particular person. You can use this with or without the User ID field. Create an app and use the "App ID": %s.', 'wpfbogp' ), '<a href="https://www.facebook.com/developers/apps.php" target="_blank">' . __( 'Create FB App', 'wpfbogp' ) . '</a>' ) ?></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e( 'Default Image URL to use:', 'wpfbogp' ) ?></th>
						<td><input type="text" name="wpfbogp[wpfbogp_fallback_img]" value="<?php echo $options['wpfbogp_fallback_img']; ?>" class="large-text" /><br />
							<?php _e( "Full URL including http:// to the default image to use if your posts/pages don't have a featured image or an image in the content. <strong>The image is recommended to be 200px by 200px</strong>.", 'wpfbogp' ) ?><br />
							<?php printf( __( 'You can use the WordPress %s if you wish, just copy the location of the image and put it here.', 'wpfbogp' ), '<a href="media-new.php">' . __( 'media uploader', 'wpfbogp' ) . '</a>' ) ?></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e( 'Force Fallback Image as Default', 'wpfbogp' ) ?></th>
						<td><input type="checkbox" name="wpfbogp[wpfbogp_force_fallback]" value="1" <?php if ( $options['wpfbogp_force_fallback'] == 1 ) echo 'checked="checked"'; ?> /> <?php _e( 'Use this if you want to use the Default Image for everything instead of looking for featured/content images.', 'wpfbogp' ) ?></label></td>
					</tr>
				</table>

				<input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ) ?>" />
				</form>
				<br class="clear" />
							</div>
						</div>
					</div>
				</div>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize and validate user input from the settings pages.
	 *
	 * @param  array $input
	 * @return array
	 */
	public function validate( $input ) {
		$input['wpfbogp_fallback_img'] = wp_filter_nohtml_kses( $input['wpfbogp_fallback_img'] );
		$input['wpfbogp_force_fallback'] = ( isset( $input['wpfbogp_force_fallback'] ) && $input['wpfbogp_force_fallback'] == 1 )  ? 1 : 0;

		if ( ! empty( $input['wpfbogp_admin_ids'] ) AND ! preg_match( '/^[\d\s,]*$/', $input['wpfbogp_admin_ids'] ) ) {
			add_settings_error( 'wpfbogp_options', 'invalid-admin-ids', __( 'You have entered invalid admin ID(s). Please check you have entered the correct IDs.', 'wpfbogp' ) );
		} else {
			$input['wpfbogp_admin_ids'] = wp_filter_nohtml_kses( $input['wpfbogp_admin_ids'] );
		}

		if ( ! empty( $input['wpfbogp_app_id'] ) AND ! preg_match( '/^\d+$/', $input['wpfbogp_app_id'] ) ) {
			add_settings_error( 'wpfbogp_options', 'invalid-app-ids', __( 'You have entered an invalid application ID. Please check you have entered the correct ID.', 'wpfbogp' ) );
		} else {
			$input['wpfbogp_app_id'] = wp_filter_nohtml_kses( $input['wpfbogp_app_id'] );
		}

		return $input;
	}

	/**
	 * Run admin notices on activation or if settings not set.
	 *
	 * @return void
	 */
	public function admin_warnings() {
		global $wpfbogp_admins;

		// Notices should be only displayed for admin users
		$wpfbogp_data = get_option( 'wpfbogp' );

		if ( empty( $wpfbogp_data['wpfbogp_admin_ids'] ) && empty( $wpfbogp_data['wpfbogp_app_id'] ) ) {
			add_action( 'admin_notices', array( $this, 'almost_ready' ) );
		}
	}

	/**
	 * Displays the "almost ready" message for admins. Check for admin is done
	 * in this function because admin_warnings() is called too early.
	 *
	 * @return string
	 */
	public function almost_ready() {
		// The notice should only be displayed if both ID fields are empty
		if ( current_user_can( 'manage_options' ) ) {
			echo "<div id='wpfbogp-warning' class='updated fade'><p><strong>" . __( 'WP FB OGP is almost ready.', 'wpfbogp' ) . "</strong> " . sprintf( __( 'You must %s for it to work.', 'wpfbogp' ), '<a href="options-general.php?page=wpfbogp">' . __( 'enter your Facebook User ID or App ID', 'wpfbogp' ) . '</a>' ) . "</p></div>";
		}
	}

	/**
	 * Twentyten and Twentyeleven add crap to the excerpt so lets check for that and remove
	 *
	 * @return  void
	 */
	public function fix_excerpts() {
		remove_filter( 'get_the_excerpt', 'twentyten_custom_excerpt_more' );
		remove_filter( 'get_the_excerpt', 'twentyeleven_custom_excerpt_more' );
	}

	/**
	 * Add settings link to plugins list
	 */
	public function add_settings_link( $links, $file ) {
		static $this_plugin;
		if ( !$this_plugin ) $this_plugin = plugin_basename( __FILE__ );
		if ( $file == $this_plugin ) {
			$settings_link = '<a href="options-general.php?page=wpfbogp">'. __( 'Settings', 'wpfbogp' ) . '</a>';
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	/**
	 * Adds a menu item to the admin bar to easily debug the page using the
	 * Facebook URL debugger.
	 *
	 * @return void
	 */
	public function admin_bar_link() {
		global $wp_admin_bar, $wpdb, $wp;

		if ( is_admin() || ! is_super_admin() || ! is_admin_bar_showing() )
			return;

		$current_url = add_query_arg( $wp->query_string, '', home_url( $wp->request ) );
		$wp_admin_bar->add_menu( array( 'id' => 'wpfbogp_debug', 'title' => __( 'OGP Debug', 'wpfbogp' ), 'href' => 'https://developers.facebook.com/tools/debug?q='.$current_url ) );
	}

}

// Start the plugin
$wpfbogp = new WPFBOGP;

/**
 * Lets offer an actual clean uninstall and remove the options from the DB.
 */
if ( function_exists( 'register_uninstall_hook' ) ) {
	register_uninstall_hook( __FILE__, 'wpfbogp_uninstall_hook' );
}

/**
 * Simply deletes the wpfbogp option from the database.
 *
 * @return void
 */
function wpfbogp_uninstall_hook() {
	delete_option( 'wpfbogp' );
}