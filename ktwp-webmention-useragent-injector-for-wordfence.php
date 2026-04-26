<?php
/**
 * Plugin Name: Kupietools Webmention UserAgent Injector For WordFence
 * Plugin URI: https://michaelkupietz.com/plugins/ktwp-webmention-useragent-infjector-for-wordfence
 * Description: Prevents WordPress playlist shortcode from repeating when it reaches the end
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       webmention-ua-injector
 */


defined( 'ABSPATH' ) || exit;

// =============================================================================
// CONFIGURATION — edit these two constants to match your setup.
// =============================================================================

/**
 * Request path of the webmention endpoint, relative to the site root.
 *
 * The value is treated as the start of a regex, so '/webmention' will
 * also match '/webmention/' and '/webmention?target=…'.
 *
 * Examples:
 *   '/webmention'
 *   '/wp-json/webmention/1.0/endpoint'
 */
define( 'WMUAI_ENDPOINT', '/wp-json/webmention/1.0/endpoint' );

/**
 * User-Agent string to inject when a qualifying POST request has none.
 */
define( 'WMUAI_USER_AGENT', 'WebmentionUserAgent-Injected/1.0' );

// =============================================================================
// INTERNALS — no need to edit anything below this line.
// =============================================================================

/** The label placed in the BEGIN / END .htaccess marker comments. */
define( 'WMUAI_MARKER', 'Webmention UserAgent Injector for WordFence' );

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Load wp-admin/includes/file.php when not already available.
 *
 * get_home_path() and insert_with_markers() live there and are only
 * auto-loaded during wp-admin requests.
 */
function wmuai_load_file_helpers(): void {
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
}

/**
 * Build the lines that go between the BEGIN / END markers in .htaccess.
 *
 * The block uses Apache 2.4's <If> expression directive together with
 * mod_headers to check three conditions simultaneously:
 *
 *   1. The HTTP method is POST.
 *   2. The incoming User-Agent header is absent or empty.
 *   3. The request URI begins with the configured endpoint path.
 *
 * When all three match, Apache sets the User-Agent header before the
 * request reaches PHP / WordPress.
 *
 * @return string[]
 */
function wmuai_htaccess_lines(): array {
    // '#' is used as the regex delimiter below, so escape any literal '#'
    // that might appear in the endpoint string.
    $endpoint = str_replace( '#', '\\#', WMUAI_ENDPOINT );

    // Strip double-quotes from the UA string to prevent breaking the
    // quoted RequestHeader directive value.
    $ua = str_replace( '"', '', WMUAI_USER_AGENT );

    return [
        '# DO NOT EDIT THIS BLOCK.',
        '# Requires Apache 2.4+ with mod_headers enabled.',
        '# Injects a User-Agent for POST requests to the webmention endpoint when none is present.',
        '<IfModule mod_headers.c>',
        "  <If \"%{REQUEST_METHOD} == 'POST' && %{HTTP_USER_AGENT} == '' && %{REQUEST_URI} =~ m#{$endpoint}#\">",
        "    RequestHeader set User-Agent \"{$ua}\"",
        '  </If>',
        '</IfModule>',
    ];
}

/**
 * Write (or update) the plugin's rule block inside .htaccess.
 *
 * insert_with_markers() prepends the block to the file when the marker
 * does not yet exist, placing it before WordPress's own rewrite rules.
 *
 * @return bool True on success, false when the file is not writable.
 */
function wmuai_write_htaccess(): bool {
    wmuai_load_file_helpers();

    $path = get_home_path() . '.htaccess';

    $writable = ( file_exists( $path ) && is_writable( $path ) )
             || ( ! file_exists( $path ) && is_writable( dirname( $path ) ) );

    if ( ! $writable ) {
        return false;
    }

    return (bool) insert_with_markers( $path, WMUAI_MARKER, wmuai_htaccess_lines() );
}

/**
 * Remove the plugin's rule block from .htaccess.
 *
 * Passing an empty array to insert_with_markers() strips the entire
 * BEGIN … END section from the file.
 *
 * @return bool True on success, or when the file does not exist.
 */
function wmuai_remove_htaccess(): bool {
    wmuai_load_file_helpers();

    $path = get_home_path() . '.htaccess';

    if ( ! file_exists( $path ) ) {
        return true; // Nothing to remove.
    }

    return (bool) insert_with_markers( $path, WMUAI_MARKER, [] );
}

// -----------------------------------------------------------------------------
// Lifecycle hooks
// -----------------------------------------------------------------------------

/**
 * Activation callback: write the .htaccess block.
 * Stores a flag if the write fails so the admin notice can surface it.
 */
function wmuai_on_activate(): void {
    if ( wmuai_write_htaccess() ) {
        delete_option( 'wmuai_write_failed' );
    } else {
        update_option( 'wmuai_write_failed', true );
    }
}
register_activation_hook( __FILE__, 'wmuai_on_activate' );

/**
 * Deactivation: remove the block immediately.
 * An inactive plugin has no handler in PHP, so the rule serves no purpose.
 */
register_deactivation_hook( __FILE__, 'wmuai_remove_htaccess' );

/**
 * Uninstall (plugin deleted via WP admin): remove the block.
 *
 * IMPORTANT: register_uninstall_hook() requires a plain function name or a
 * static class method — closures and anonymous functions will silently not
 * fire. The named function wmuai_remove_htaccess() above satisfies this.
 */
register_uninstall_hook( __FILE__, 'wmuai_remove_htaccess' );

// -----------------------------------------------------------------------------
// Admin notice
// -----------------------------------------------------------------------------

/**
 * Display a persistent error notice whenever the .htaccess rule block is
 * absent while the plugin is active.
 *
 * This covers both an initial write failure and cases where .htaccess was
 * regenerated or overwritten by another process after activation.
 */
function wmuai_admin_notices(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wmuai_load_file_helpers();

    $path    = get_home_path() . '.htaccess';
    $content = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

    // Rules are present — nothing to report.
    if ( false !== strpos( $content, '# BEGIN ' . WMUAI_MARKER ) ) {
        return;
    }

    $message = sprintf(
        '<strong>Webmention UA Injector:</strong> The .htaccess rule block is missing from '
        . '<code>%s</code>. Ensure the file is writable and re-activate the plugin, '
        . 'or add the rules manually.',
        esc_html( $path )
    );

    echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'wmuai_admin_notices' );
