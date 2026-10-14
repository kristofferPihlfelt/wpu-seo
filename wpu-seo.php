<?php
/**
 * Plugin Name:       WPU SEO
 * Plugin URI:        https://wordpressutveckling.se/bygg-ett-eget-seo-plugin/
 * Description:       Ett litet SEO-plugin: egen titel, metabeskrivning, delningsbild (Open Graph) och noindex per inlägg och sida, plus några globala standardval. Inga analyser.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Pihlfelt & Vänner
 * Author URI:        https://pihlfelt.se
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpu-seo
 */

defined( 'ABSPATH' ) || exit;

const WPU_SEO_FIELDS = array(
	'_wpu_seo_title'       => 'string',
	'_wpu_seo_description' => 'string',
	'_wpu_seo_image'       => 'integer',
	'_wpu_seo_noindex'     => 'boolean',
);

/**
 * Stay silent next to a full SEO plugin so the head never gets two descriptions.
 */
function wpu_seo_other_plugin_active(): bool {
	return defined( 'WPSEO_VERSION' )           // Yoast SEO.
		|| defined( 'RANK_MATH_VERSION' )
		|| defined( 'SEOPRESS_VERSION' )
		|| defined( 'AIOSEO_VERSION' )
		|| defined( 'THE_SEO_FRAMEWORK_VERSION' );
}

/** One global setting; the option is stored as a single array. */
function wpu_seo_setting( string $key ) {
	$defaults = array(
		'default_image'       => 0,
		'default_description' => '',
		'x_account'           => '',
		'post_types'          => null, // null = every public post type.
		'taxonomies'          => null, // null = every public taxonomy with an admin screen.
		'indexnow'            => true,
	);
	$saved = (array) get_option( 'wpu_seo_settings', array() );
	return array_key_exists( $key, $saved ) ? $saved[ $key ] : $defaults[ $key ];
}

/* 1. Fields ---------------------------------------------------------------- */

add_action( 'init', 'wpu_seo_register_meta' );
function wpu_seo_register_meta(): void {
	foreach ( wpu_seo_post_types() as $type ) {
		foreach ( WPU_SEO_FIELDS as $key => $kind ) {
			register_post_meta(
				$type,
				$key,
				array(
					'type'              => $kind,
					'single'            => true,
					'show_in_rest'      => true,
					'default'           => 'integer' === $kind ? 0 : ( 'boolean' === $kind ? false : '' ),
					'sanitize_callback' => 'string' === $kind ? 'sanitize_text_field' : null,
					// Keys start with "_" (hidden from Custom Fields), so REST needs an explicit capability check.
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
		}
	}
}

/** Public post types with the block editor: posts, pages and public custom post types. */
function wpu_seo_available_post_types(): array {
	$types = get_post_types( array( 'public' => true, 'show_in_rest' => true ) );
	unset( $types['attachment'] );
	return array_values( $types );
}

/** The post types that get the SEO panel: the ones chosen on the settings page, or all. */
function wpu_seo_post_types(): array {
	$chosen = wpu_seo_setting( 'post_types' );
	$types  = is_array( $chosen ) ? array_values( array_intersect( wpu_seo_available_post_types(), $chosen ) ) : wpu_seo_available_post_types();
	return (array) apply_filters( 'wpu_seo_post_types', $types );
}

add_action( 'enqueue_block_editor_assets', 'wpu_seo_editor_assets' );
function wpu_seo_editor_assets(): void {
	// One SEO panel is enough; saved values stay and are used again if the other plugin goes.
	if ( wpu_seo_other_plugin_active() ) {
		return;
	}
	$file = __DIR__ . '/editor.js';
	wp_enqueue_script(
		'wpu-seo-editor',
		plugins_url( 'editor.js', __FILE__ ),
		array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-block-editor' ),
		(string) filemtime( $file ),
		true
	);
	wp_localize_script( 'wpu-seo-editor', 'wpuSeo', array( 'types' => wpu_seo_post_types() ) );
}

/* 2. Values with fallbacks ------------------------------------------------- */

/** The post being viewed, or null on archives and the blog index. */
function wpu_seo_post(): ?WP_Post {
	if ( is_singular() ) {
		return get_queried_object() instanceof WP_Post ? get_queried_object() : null;
	}
	// A static front page is a page too.
	if ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
		return get_post( (int) get_option( 'page_on_front' ) );
	}
	// The posts page ("Inlägg") is a page with its own excerpt and fields.
	if ( is_home() && get_option( 'page_for_posts' ) ) {
		return get_post( (int) get_option( 'page_for_posts' ) );
	}
	// WooCommerce's shop is a product archive, but it is edited as a page.
	if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
		return get_post( wc_get_page_id( 'shop' ) );
	}
	return null;
}

/** The category, tag or product category being viewed, if it can have SEO fields. */
function wpu_seo_term(): ?WP_Term {
	if ( ! ( is_category() || is_tag() || is_tax() ) ) {
		return null;
	}
	$term = get_queried_object();
	return $term instanceof WP_Term && in_array( $term->taxonomy, wpu_seo_taxonomies(), true ) ? $term : null;
}

function wpu_seo_description(): string {
	$post = wpu_seo_post();
	if ( $post ) {
		$own = (string) get_post_meta( $post->ID, '_wpu_seo_description', true );
		if ( '' !== $own ) {
			return $own;
		}
		if ( has_excerpt( $post ) ) {
			return wp_strip_all_tags( get_the_excerpt( $post ) );
		}
		if ( ! is_front_page() ) {
			// First words of the content; blocks and shortcodes removed.
			$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) );
			return wp_trim_words( $text, 25, '…' );
		}
	}
	$seo_term = wpu_seo_term();
	if ( $seo_term ) {
		$own = (string) get_term_meta( $seo_term->term_id, '_wpu_seo_description', true );
		if ( '' !== $own ) {
			return $own;
		}
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$term = wp_strip_all_tags( (string) term_description() );
		if ( '' !== trim( $term ) ) {
			return trim( $term );
		}
	}
	// Front page with latest posts, archives, and pages with nothing better.
	$default = trim( (string) wpu_seo_setting( 'default_description' ) );
	return '' !== $default ? $default : (string) get_bloginfo( 'description' );
}

/** @return array{0:string,1:int,2:int}|null URL, width, height. */
function wpu_seo_image(): ?array {
	$post = wpu_seo_post();
	$id   = 0;
	if ( $post ) {
		$id = (int) get_post_meta( $post->ID, '_wpu_seo_image', true ) ?: (int) get_post_thumbnail_id( $post );
	}
	// Default from the settings page; themes and plugins can override it (attachment ID or URL).
	$fallback = $id ? 0 : apply_filters( 'wpu_seo_default_image', (int) wpu_seo_setting( 'default_image' ) );
	if ( is_string( $fallback ) && '' !== $fallback ) {
		return array( $fallback, 0, 0 );
	}
	$id = $id ?: (int) $fallback;
	$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
	return $src ? array( $src[0], (int) $src[1], (int) $src[2] ) : null;
}

/* 3. Output ---------------------------------------------------------------- */

add_filter( 'pre_get_document_title', 'wpu_seo_document_title', 20 );
function wpu_seo_document_title( $title ) {
	$post = wpu_seo_post();
	$term = $post ? null : wpu_seo_term();
	$own  = $post ? (string) get_post_meta( $post->ID, '_wpu_seo_title', true ) : '';
	if ( $term ) {
		$own = (string) get_term_meta( $term->term_id, '_wpu_seo_title', true );
	}
	// Used exactly as written; the site name is not appended.
	return '' !== $own && ! wpu_seo_other_plugin_active() ? $own : $title;
}

add_filter( 'wp_robots', 'wpu_seo_robots' );
function wpu_seo_robots( array $robots ): array {
	// Search results are endless thin pages; keep them out of the index.
	if ( is_search() && ! wpu_seo_other_plugin_active() ) {
		$robots['noindex'] = true;
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
		return $robots;
	}
	$post = wpu_seo_post();
	if ( $post && get_post_meta( $post->ID, '_wpu_seo_noindex', true ) && ! wpu_seo_other_plugin_active() ) {
		$robots['noindex'] = true;
		// Links on the page are still followed, unless something else already said nofollow.
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
	}
	return $robots;
}

// Pages marked noindex should not be listed in the sitemap either.
add_filter( 'wp_sitemaps_posts_query_args', 'wpu_seo_sitemap_exclude' );
function wpu_seo_sitemap_exclude( array $args ): array {
	if ( wpu_seo_other_plugin_active() ) {
		return $args;
	}
	$indexable = array(
		'relation' => 'OR',
		array( 'key' => '_wpu_seo_noindex', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_wpu_seo_noindex', 'value' => '1', 'compare' => '!=' ),
	);
	// Keep any meta_query another plugin already added; both must match.
	$args['meta_query'] = empty( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		? $indexable
		: array( 'relation' => 'AND', $args['meta_query'], $indexable );
	return $args;
}

add_action( 'wp_head', 'wpu_seo_head', 1 );
function wpu_seo_head(): void {
	if ( wpu_seo_other_plugin_active() || is_404() || is_search() ) {
		return;
	}
	$post        = wpu_seo_post();
	$description = wpu_seo_description();
	$title       = wp_get_document_title();
	$url         = $post ? get_permalink( $post ) : home_url( user_trailingslashit( $GLOBALS['wp']->request ?? '' ) );
	if ( is_category() || is_tax() ) {
		$url = get_term_link( get_queried_object() );
	} elseif ( is_front_page() ) {
		$url = home_url( '/' );
	}
	$image = wpu_seo_image();

	$tags = array(
		array( 'name', 'description', $description ),
		array( 'property', 'og:locale', get_locale() ),
		array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
		array( 'property', 'og:type', $post && is_singular( 'post' ) ? 'article' : 'website' ),
		array( 'property', 'og:title', $title ),
		array( 'property', 'og:description', $description ),
		array( 'property', 'og:url', is_string( $url ) ? $url : '' ),
	);
	if ( $image ) {
		$tags[] = array( 'property', 'og:image', $image[0] );
		if ( $image[1] ) {
			$tags[] = array( 'property', 'og:image:width', (string) $image[1] );
			$tags[] = array( 'property', 'og:image:height', (string) $image[2] );
		}
	}
	if ( $post && is_singular( 'post' ) ) {
		$tags[] = array( 'property', 'article:published_time', get_post_time( 'c', true, $post ) );
		$tags[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
	}
	// X (Twitter) reads og:* for the rest; it only needs the card size.
	$tags[] = array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' );
	$x = ltrim( trim( (string) wpu_seo_setting( 'x_account' ) ), '@' );
	if ( '' !== $x ) {
		$tags[] = array( 'name', 'twitter:site', '@' . $x );
	}

	echo "\n<!-- WPU SEO -->\n";
	// WordPress prints rel=canonical only on single posts and pages; archives get theirs here.
	if ( ! is_singular() && ( is_home() || is_category() || is_tag() || is_tax() || is_author() ) && is_string( $url ) && '' !== $url ) {
		$paged = (int) get_query_var( 'paged' );
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $paged > 1 ? get_pagenum_link( $paged ) : $url ) );
	}
	foreach ( $tags as [ $attr, $key, $value ] ) {
		if ( '' === (string) $value ) {
			continue;
		}
		printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
	}
}

/* 4. Settings page (Inställningar → SEO) ----------------------------------- */

add_action( 'admin_menu', 'wpu_seo_admin_menu' );
function wpu_seo_admin_menu(): void {
	add_options_page( 'SEO', 'SEO', 'manage_options', 'wpu-seo', 'wpu_seo_settings_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpu_seo_action_links' );
function wpu_seo_action_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wpu-seo' ) ) . '">' . esc_html__( 'Inställningar', 'wpu-seo' ) . '</a>' );
	return $links;
}

add_action( 'admin_init', 'wpu_seo_register_setting' );
function wpu_seo_register_setting(): void {
	register_setting(
		'wpu_seo',
		'wpu_seo_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wpu_seo_sanitize_settings',
			'default'           => array(),
		)
	);
}

function wpu_seo_sanitize_settings( $in ): array {
	$in    = is_array( $in ) ? $in : array();
	$types = array_map( 'sanitize_key', (array) ( $in['post_types'] ?? array() ) );
	return array(
		'default_image'       => absint( $in['default_image'] ?? 0 ),
		'default_description' => sanitize_textarea_field( (string) ( $in['default_description'] ?? '' ) ),
		'x_account'           => sanitize_text_field( ltrim( (string) ( $in['x_account'] ?? '' ), '@' ) ),
		'post_types'          => array_values( array_intersect( wpu_seo_available_post_types(), $types ) ),
		'indexnow'            => ! empty( $in['indexnow'] ),
		'taxonomies'          => array_values( array_intersect( wpu_seo_available_taxonomies(), array_map( 'sanitize_key', (array) ( $in['taxonomies'] ?? array() ) ) ) ),
	);
}

add_action( 'admin_enqueue_scripts', 'wpu_seo_settings_assets' );
function wpu_seo_settings_assets( string $hook ): void {
	if ( 'settings_page_wpu-seo' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'media-editor', wpu_seo_media_picker_js() );
}

/** Tiny media picker for the default image; no separate file needed. */
function wpu_seo_media_picker_js(): string {
	return <<<'JS'
document.addEventListener( 'click', function ( e ) {
	var b = e.target.closest( '[data-wpu-seo-bild]' );
	if ( ! b ) return;
	e.preventDefault();
	var field = document.getElementById( 'wpu-seo-default-image' );
	var preview = document.getElementById( 'wpu-seo-default-image-preview' );
	if ( b.dataset.wpuSeoBild === 'ta-bort' ) {
		field.value = '';
		preview.innerHTML = '';
		return;
	}
	var frame = wp.media( { title: 'Standardbild för delning', library: { type: 'image' }, multiple: false } );
	frame.on( 'select', function () {
		var a = frame.state().get( 'selection' ).first().toJSON();
		var img = document.createElement( 'img' );
		img.src = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
		img.style.cssText = 'max-width:300px;height:auto';
		field.value = a.id;
		preview.replaceChildren( img );
	} );
	frame.open();
} );
JS;
}

function wpu_seo_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$image  = (int) wpu_seo_setting( 'default_image' );
	$chosen = wpu_seo_setting( 'post_types' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SEO', 'wpu-seo' ); ?></h1>
		<?php if ( wpu_seo_other_plugin_active() ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Ett annat SEO-plugin är aktivt. WPU SEO skriver då inte ut något, så att sidorna inte får dubbla taggar. Inställningarna här gäller först när det andra pluginet är avaktiverat.', 'wpu-seo' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Standardvärden som används när ett inlägg eller en sida inte har egna. Titel, beskrivning och bild per inlägg fyller du i panelen "SEO" i redigeraren.', 'wpu-seo' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpu_seo' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Standardbild för delning', 'wpu-seo' ); ?></th>
					<td>
						<input type="hidden" id="wpu-seo-default-image" name="wpu_seo_settings[default_image]" value="<?php echo esc_attr( $image ?: '' ); ?>">
						<div id="wpu-seo-default-image-preview"><?php echo $image ? wp_get_attachment_image( $image, 'medium', false, array( 'style' => 'max-width:300px;height:auto' ) ) : ''; ?></div>
						<p><button type="button" class="button" data-wpu-seo-bild="valj"><?php esc_html_e( 'Välj bild', 'wpu-seo' ); ?></button>
						<button type="button" class="button-link button-link-delete" data-wpu-seo-bild="ta-bort"><?php esc_html_e( 'Ta bort', 'wpu-seo' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'Visas när startsidan, en kategori eller en sida utan utvald bild delas. Bäst 1200 × 630 px.', 'wpu-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpu-seo-default-description"><?php esc_html_e( 'Standardbeskrivning', 'wpu-seo' ); ?></label></th>
					<td>
						<textarea id="wpu-seo-default-description" class="large-text" rows="3" name="wpu_seo_settings[default_description]"><?php echo esc_textarea( (string) wpu_seo_setting( 'default_description' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Används där inget bättre finns, till exempel en startsida som visar senaste inläggen. Tomt = sajtens slogan under Inställningar → Allmänt.', 'wpu-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpu-seo-x"><?php esc_html_e( 'Konto på X', 'wpu-seo' ); ?></label></th>
					<td>
						<input id="wpu-seo-x" type="text" class="regular-text" name="wpu_seo_settings[x_account]" value="<?php echo esc_attr( (string) wpu_seo_setting( 'x_account' ) ); ?>" placeholder="foretaget">
						<p class="description"><?php esc_html_e( 'Valfritt. Skrivs ut som twitter:site. Utan @.', 'wpu-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SEO-panelen visas för', 'wpu-seo' ); ?></th>
					<td>
						<fieldset>
						<?php foreach ( wpu_seo_available_post_types() as $type ) : ?>
							<?php $obj = get_post_type_object( $type ); ?>
							<label style="display:block;margin-bottom:4px"><input type="checkbox" name="wpu_seo_settings[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( ! is_array( $chosen ) || in_array( $type, $chosen, true ) ); ?>> <?php echo esc_html( $obj ? $obj->labels->name : $type ); ?></label>
						<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SEO-fält för', 'wpu-seo' ); ?></th>
					<td>
						<fieldset>
						<?php $chosen_tax = wpu_seo_setting( 'taxonomies' ); ?>
						<?php foreach ( wpu_seo_available_taxonomies() as $tax ) : ?>
							<?php $obj = get_taxonomy( $tax ); ?>
							<label style="display:block;margin-bottom:4px"><input type="checkbox" name="wpu_seo_settings[taxonomies][]" value="<?php echo esc_attr( $tax ); ?>" <?php checked( ! is_array( $chosen_tax ) || in_array( $tax, $chosen_tax, true ) ); ?>> <?php echo esc_html( $obj ? $obj->labels->name : $tax ); ?></label>
						<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Ger titel och beskrivning när du redigerar en kategori. Tom beskrivning = kategorins egen beskrivning.', 'wpu-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IndexNow', 'wpu-seo' ); ?></th>
					<td>
						<label><input type="checkbox" name="wpu_seo_settings[indexnow]" value="1" <?php checked( (bool) wpu_seo_setting( 'indexnow' ) ); ?>> <?php esc_html_e( 'Meddela Bing m.fl. direkt när något publiceras eller uppdateras', 'wpu-seo' ); ?></label>
						<p class="description"><?php echo esc_html( sprintf( /* translators: 1: key file URL, 2: last result */ __( 'Nyckelfil: %1$s. Senast: %2$s', 'wpu-seo' ), home_url( '/' . wpu_seo_indexnow_key() . '.txt' ), (string) get_option( 'wpu_seo_indexnow_senast', __( 'inget skickat ännu', 'wpu-seo' ) ) ) ); ?></p>
						<?php if ( 'production' !== wp_get_environment_type() ) : ?>
							<p class="description"><?php esc_html_e( 'Skickas bara i produktion (WP_ENVIRONMENT_TYPE = production).', 'wpu-seo' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* 5. Categories and other taxonomies --------------------------------------- */

/** Public taxonomies with an admin screen: categories, product categories, brands … */
function wpu_seo_available_taxonomies(): array {
	$taxonomies = get_taxonomies( array( 'public' => true, 'show_ui' => true ) );
	unset( $taxonomies['post_format'] );
	return array_values( $taxonomies );
}

function wpu_seo_taxonomies(): array {
	$chosen = wpu_seo_setting( 'taxonomies' );
	$all    = wpu_seo_available_taxonomies();
	return (array) apply_filters( 'wpu_seo_taxonomies', is_array( $chosen ) ? array_values( array_intersect( $all, $chosen ) ) : $all );
}

// Runs late so taxonomies registered by WooCommerce and others exist.
add_action( 'init', 'wpu_seo_term_hooks', 99 );
function wpu_seo_term_hooks(): void {
	if ( wpu_seo_other_plugin_active() ) {
		return;
	}
	foreach ( wpu_seo_taxonomies() as $tax ) {
		add_action( "{$tax}_edit_form_fields", 'wpu_seo_term_fields' );
		add_action( "edited_{$tax}", 'wpu_seo_save_term' );
	}
}

function wpu_seo_term_fields( WP_Term $term ): void {
	wp_nonce_field( 'wpu_seo_term', 'wpu_seo_term_nonce' );
	$title       = (string) get_term_meta( $term->term_id, '_wpu_seo_title', true );
	$description = (string) get_term_meta( $term->term_id, '_wpu_seo_description', true );
	?>
	<tr class="form-field">
		<th scope="row"><label for="wpu-seo-term-title"><?php esc_html_e( 'SEO-titel', 'wpu-seo' ); ?></label></th>
		<td>
			<input id="wpu-seo-term-title" name="wpu_seo_term[title]" type="text" value="<?php echo esc_attr( $title ); ?>">
			<p class="description"><?php esc_html_e( 'Tomt = namnet och sajtens namn. Runt 60 tecken visas i Google.', 'wpu-seo' ); ?></p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="wpu-seo-term-description"><?php esc_html_e( 'Metabeskrivning', 'wpu-seo' ); ?></label></th>
		<td>
			<textarea id="wpu-seo-term-description" name="wpu_seo_term[description]" rows="3"><?php echo esc_textarea( $description ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Tomt = beskrivningen ovan. Runt 155 tecken visas i Google.', 'wpu-seo' ); ?></p>
		</td>
	</tr>
	<?php
}

function wpu_seo_save_term( int $term_id ): void {
	if ( ! isset( $_POST['wpu_seo_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpu_seo_term_nonce'] ) ), 'wpu_seo_term' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$in = isset( $_POST['wpu_seo_term'] ) ? (array) wp_unslash( $_POST['wpu_seo_term'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
	foreach ( array( 'title' => '_wpu_seo_title', 'description' => '_wpu_seo_description' ) as $field => $key ) {
		$value = 'description' === $field ? sanitize_textarea_field( (string) ( $in[ $field ] ?? '' ) ) : sanitize_text_field( (string) ( $in[ $field ] ?? '' ) );
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}
}

/* 6. IndexNow ------------------------------------------------------------------
 * Tells Bing, Yandex, Seznam and Naver right away when a page is published or updated
 * (https://www.indexnow.org). Google does not use IndexNow; it reads the sitemap.
 * The key is proven by a text file at /<key>.txt, served here without a real file.
 */

function wpu_seo_indexnow_key(): string {
	$key = (string) get_option( 'wpu_seo_indexnow_key', '' );
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( 'wpu_seo_indexnow_key', $key, false );
	}
	return $key;
}

add_action( 'parse_request', 'wpu_seo_indexnow_key_file', 0 );
function wpu_seo_indexnow_key_file(): void {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$key  = (string) get_option( 'wpu_seo_indexnow_key', '' );
	if ( '' !== $key && '/' . $key . '.txt' === $path ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}

// Publishing (also scheduled posts going live) and updates of published content.
add_action( 'transition_post_status', 'wpu_seo_indexnow_queue', 20, 3 );
function wpu_seo_indexnow_queue( string $new, string $old, WP_Post $post ): void {
	if ( 'publish' !== $new || ! wpu_seo_setting( 'indexnow' ) || wpu_seo_other_plugin_active() ) {
		return;
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! is_post_type_viewable( $post->post_type ) || get_post_meta( $post->ID, '_wpu_seo_noindex', true ) ) {
		return;
	}
	$url = (string) get_permalink( $post );
	// Sent from cron a moment later, so publishing never waits for the search engines.
	if ( $url && ! wp_next_scheduled( 'wpu_seo_indexnow_send', array( $url ) ) ) {
		wp_schedule_single_event( time() + 30, 'wpu_seo_indexnow_send', array( $url ) );
	}
}

add_action( 'wpu_seo_indexnow_send', 'wpu_seo_indexnow_send' );
function wpu_seo_indexnow_send( string $url ): void {
	if ( 'production' !== wp_get_environment_type() ) {
		return; // Never announce local or staging addresses.
	}
	$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$key      = wpu_seo_indexnow_key();
	$response = wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode(
				array(
					'host'        => $host,
					'key'         => $key,
					'keyLocation' => home_url( '/' . $key . '.txt' ),
					'urlList'     => array( $url ),
				)
			),
		)
	);
	// 200 and 202 both mean accepted.
	$status = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
	update_option( 'wpu_seo_indexnow_senast', gmdate( 'Y-m-d H:i' ) . ' UTC, ' . $url . ' → ' . $status, false );
}
