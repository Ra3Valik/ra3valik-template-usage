<?php
/**
 * Plugin Name: Template Usage Scanner (Site Editor)
 * Description: Finds unused wp_template items and marks them as "Not in use". Honors hierarchy for page-* and single-*
 * (single, single-{post_type}, single-{post_type}-{ID|slug}). Includes a button to clear all badges and an admin
 * option to set the unused-title prefix.
 * Author: Ra3Valik
 * Author URI: https://github.com/Ra3Valik
 * Version: 1.1.0
 * Update URI: https://github.com/Ra3Valik/ra3valik-template-usage
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ra3valik_puc = PucFactory::buildUpdateChecker(
	'https://github.com/Ra3Valik/ra3valik-template-usage/', // repo URL
	__FILE__,                                                // main plugin file
	'ra3valik-template-usage'                                // plugin slug (= folder name)
);

// Если публикуете релизы с ZIP-активом (как в вашем workflow) — включите:
$ra3valik_puc->getVcsApi()->enableReleaseAssets();

// Если основная ветка не master:
$ra3valik_puc->setBranch('main');

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

/** ====== Config / Constants ====== */
const RA3VALIK_FLAG_META = '_ra3valik_not_in_use';
const RA3VALIK_OPTION_PREFIX = 'ra3valik_unused_prefix';
const RA3VALIK_DEFAULT_PREFIX = 'Not In Use — ';

/** Polyfill for PHP < 8 str_starts_with */
if ( !function_exists( 'ra3valik_starts_with' ) ) {
	function ra3valik_starts_with( $haystack, $needle )
	{
		if ( $needle === '' ) return true;
		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}
}

/** ====== Admin Menu & List Table Column ====== */
add_action( 'admin_menu', function () {
	add_theme_page(
		'Template Usage',
		'Template Usage',
		'manage_options',
		'ra3valik-template-usage',
		'ra3valik_template_usage_page'
	);
} );

add_filter( 'manage_wp_template_posts_columns', function ( $cols ) {
	$cols['ra3valik_in_use'] = 'In use?';
	return $cols;
} );
add_action( 'manage_wp_template_posts_custom_column', function ( $col, $post_id ) {
	if ( $col !== 'ra3valik_in_use' ) return;
	$flag = get_post_meta( $post_id, RA3VALIK_FLAG_META, true );
	echo $flag
		? '<span style="color:#a00;font-weight:600;">Not in use</span>'
		: '<span style="color:#2271b1;">In use</span>';
}, 10, 2 );

/** ====== Admin Page ====== */
function ra3valik_template_usage_page()
{
	if ( !current_user_can( 'manage_options' ) ) return;

	$theme = wp_get_theme();
	$theme_id = $theme->get_stylesheet();

	$did_scan = false;
	$did_clear = false;
	$prefix_opt = get_option( RA3VALIK_OPTION_PREFIX, RA3VALIK_DEFAULT_PREFIX );

	// Save settings (prefix)
	if ( isset( $_POST['ra3valik_save_settings'] ) && isset( $_POST['ra3valik_settings_nonce'] ) && wp_verify_nonce( $_POST['ra3valik_settings_nonce'], 'ra3valik_settings' ) ) {
		$new_prefix = isset( $_POST['ra3valik_prefix'] ) ? (string) wp_unslash( $_POST['ra3valik_prefix'] ) : RA3VALIK_DEFAULT_PREFIX;
		$new_prefix = trim( $new_prefix );
		if ( $new_prefix === '' ) $new_prefix = RA3VALIK_DEFAULT_PREFIX;
		update_option( RA3VALIK_OPTION_PREFIX, $new_prefix );
		$prefix_opt = $new_prefix;
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	$prefix_titles = !empty( $_POST['ra3valik_prefix_titles'] );

	if ( isset( $_POST['ra3valik_scan_nonce'] ) && wp_verify_nonce( $_POST['ra3valik_scan_nonce'], 'ra3valik_scan' ) ) {
		$did_scan = true;
		$result = ra3valik_scan_and_mark_templates( $prefix_titles, $prefix_opt );
		echo '<div class="notice notice-success"><p>'
			. 'Templates scanned: <b>' . (int) $result['total'] . '</b>. '
			. 'Detected as in use: <b>' . (int) $result['in_use'] . '</b>. '
			. 'Marked "Not in use": <b>' . (int) $result['not_in_use_marked'] . '</b>.'
			. '</p></div>';
	}

	if ( isset( $_POST['ra3valik_clear_nonce'] ) && wp_verify_nonce( $_POST['ra3valik_clear_nonce'], 'ra3valik_clear' ) ) {
		$did_clear = true;
		$cleared = ra3valik_clear_all_not_in_use( $prefix_opt );
		echo '<div class="notice notice-info"><p>Cleared flags and removed prefixes for: <b>' . (int) $cleared . '</b> templates.</p></div>';
	}

	?>
    <div class="wrap">
        <h1>Template Usage (Site Editor)</h1>
        <p>
            This tool scans <code>wp_template</code> for theme <code><?php echo esc_html( $theme_id ); ?></code>,
            respects explicit bindings via <code>_wp_page_template</code> and WordPress template hierarchy
            (e.g. <code>page-{slug|ID}</code>, <code>single</code>, <code>single-{post_type}</code>, <code>single-{post_type}-{ID|slug}</code>),
            and marks others as “Not in use”.
        </p>

        <h2>Settings</h2>
        <form method="post" style="margin-bottom:18px;">
			<?php wp_nonce_field( 'ra3valik_settings', 'ra3valik_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ra3valik_prefix">Unused title prefix</label></th>
                    <td>
                        <input type="text" id="ra3valik_prefix" name="ra3valik_prefix"
                               value="<?php echo esc_attr( $prefix_opt ); ?>" class="regular-text"/>
                        <p class="description">This prefix will be added to titles of templates considered “Not in
                            use”.</p>
                    </td>
                </tr>
            </table>
            <p>
                <button class="button button-primary" name="ra3valik_save_settings" value="1">Save settings</button>
            </p>
        </form>

        <h2>Actions</h2>
        <form method="post" style="margin-bottom:18px;">
			<?php wp_nonce_field( 'ra3valik_scan', 'ra3valik_scan_nonce' ); ?>
            <label style="display:inline-block;margin:8px 0;">
                <input type="checkbox" name="ra3valik_prefix_titles"
                       value="1" <?php checked( $prefix_titles, true ); ?> />
                Add prefix “<?php echo esc_html( $prefix_opt ); ?>” to titles of unused templates
            </label>
            <p>
                <button class="button button-primary">Scan now</button>
            </p>
        </form>

        <form method="post">
			<?php wp_nonce_field( 'ra3valik_clear', 'ra3valik_clear_nonce' ); ?>
            <p>
                <button class="button">Clear all “Not in use” badges</button>
            </p>
            <p style="color:#666;margin-top:6px;">Removes meta
                <code><?php echo esc_html( RA3VALIK_FLAG_META ); ?></code> and strips the prefix from all <code>wp_template</code>
                titles.</p>
        </form>

		<?php if ( $did_scan || $did_clear ) : ?>
            <hr>
            <h2>Notes</h2>
            <ul>
                <li>Flag is stored in <code><?php echo esc_html( RA3VALIK_FLAG_META ); ?></code> (1/0).</li>
                <li>Core/base slugs are protected (e.g., <code>index</code>, <code>single</code>, <code>page</code>,
                    etc.).
                </li>
                <li><code>single</code> and <code>single-{post_type}</code> are always treated as “in use” if the post
                    type exists. Specific templates <code>single-{post_type}-{ID|slug}</code> are matched to actual
                    posts.
                </li>
            </ul>
		<?php endif; ?>
    </div>
	<?php
}

/** ====== Scanner ====== */
function ra3valik_scan_and_mark_templates( $prefix_titles = false, $title_prefix = RA3VALIK_DEFAULT_PREFIX )
{
	$theme = wp_get_theme();
	$theme_id = $theme->get_stylesheet();

	$protected_slugs = [
		'index', 'home', 'front-page', 'page', 'single', 'archive', 'category', 'tag', 'taxonomy',
		'author', 'date', 'search', '404', 'attachment'
	];

	// 1) Snapshot of all _wp_page_template values for exact matching
	$used_template_values = ra3valik_collect_used_page_templates();

	// 2) Index pages for page-{slug|ID}
	$pages_index = ra3valik_build_pages_index(); // ['by_slug' => [slug=>ID], 'by_id' => [id=>true]]

	$q = new WP_Query( [
		'post_type' => 'wp_template',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'tax_query' => [
			[
				'taxonomy' => 'wp_theme',
				'field' => 'name',
				'terms' => $theme_id,
			],
		],
		'orderby' => 'title',
		'order' => 'ASC',
		'no_found_rows' => true,
	] );

	$total = 0;
	$in_use = 0;
	$not_in_use_marked = 0;

	if ( $q->have_posts() ) {
		while ( $q->have_posts() ) {
			$q->the_post();
			$total++;

			$tpl_id = get_the_ID();
			$slug = get_post_field( 'post_name', $tpl_id );
			$title = get_the_title( $tpl_id );

			// 0) Base protected slugs
			if ( in_array( $slug, $protected_slugs, true ) ) {
				ra3valik_mark_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$in_use++;
				continue;
			}

			// 1) Page hierarchy: page-{slug|ID}
			if ( ra3valik_is_page_like_slug_in_use( $slug, $pages_index ) ) {
				ra3valik_mark_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$in_use++;
				continue;
			}

			// 2) Single hierarchy: single, single-{post_type}, single-{post_type}-{ID|slug}
			if ( ra3valik_is_single_like_slug_in_use( $slug ) ) {
				ra3valik_mark_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$in_use++;
				continue;
			}

			// 3) Taxonomy hierarchy: taxonomy-*, category-*, tag-* (including term-specific templates)
			if ( ra3valik_is_taxonomy_like_slug_in_use( $slug ) ) {
				ra3valik_mark_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$in_use++;
				continue;
			}

			// 4) Exact matches against stored _wp_page_template values
			$candidates = ra3valik_candidate_values_for_template( $theme_id, $slug );
			$found_exact = false;
			foreach ( $candidates as $cand ) {
				if ( isset( $used_template_values[$cand] ) ) {
					$found_exact = true;
					break;
				}
			}

			if ( $found_exact ) {
				ra3valik_mark_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$in_use++;
			} else {
				ra3valik_mark_not_in_use( $tpl_id, $title, $prefix_titles, $title_prefix );
				$not_in_use_marked++;
			}
		}
		wp_reset_postdata();
	}

	return compact( 'total', 'in_use', 'not_in_use_marked' );
}

/** Collect all distinct _wp_page_template values */
function ra3valik_collect_used_page_templates()
{
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = %s",
			'_wp_page_template'
		),
		ARRAY_A
	);
	$map = [];
	if ( $rows ) {
		foreach ( $rows as $r ) {
			$v = (string) $r['meta_value'];
			if ( $v !== '' ) $map[$v] = true;
		}
	}
	return $map;
}

/** Build page index for quick page-{slug|ID} checks */
function ra3valik_build_pages_index()
{
	$pages = get_posts( [
		'post_type' => 'page',
		'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
		'posts_per_page' => -1,
		'fields' => 'ids',
		'no_found_rows' => true,
	] );
	$by_slug = [];
	$by_id = [];
	foreach ( $pages as $pid ) {
		$slug = get_post_field( 'post_name', $pid );
		if ( $slug ) $by_slug[$slug] = (int) $pid;
		$by_id[(int) $pid] = true;
	}
	return ['by_slug' => $by_slug, 'by_id' => $by_id];
}

/** Check page-* */
function ra3valik_is_page_like_slug_in_use( $template_slug, array $pages_index )
{
	if ( strpos( $template_slug, 'page-' ) !== 0 ) return false;
	$tail = substr( $template_slug, 5 );

	// page-{ID}
	if ( ctype_digit( $tail ) ) {
		$id = (int) $tail;
		return !empty( $pages_index['by_id'][$id] );
	}

	// page-{slug}
	$slug = sanitize_title( $tail );
	return isset( $pages_index['by_slug'][$slug] );
}

/**
 * Check single-like slugs:
 *  - single                         => always in use
 *  - single-{post_type}            => in use if post type exists
 *  - single-{post_type}-{ID|slug}  => in use if a matching post exists
 */
function ra3valik_is_single_like_slug_in_use( $template_slug )
{
	if ( !ra3valik_starts_with( $template_slug, 'single' ) ) return false;

	$parts = explode( '-', $template_slug );
	// 'single'
	if ( count( $parts ) === 1 ) {
		return true; // always considered in use globally
	}

	// 'single-{post_type}' or 'single-{post_type}-{id|slug}'
	$post_type = $parts[1] ?? '';
	if ( $post_type === '' ) return true;

	if ( !post_type_exists( $post_type ) ) {
		// If post type isn't registered, treat as not in use
		return false;
	}

	// Exactly 'single-{post_type}'
	if ( count( $parts ) === 2 ) {
		return true; // applies globally for that CPT
	}

	// 'single-{post_type}-{id|slug}' (remaining parts joined with '-')
	$tail = implode( '-', array_slice( $parts, 2 ) );
	if ( $tail === '' ) return true;

	// Numeric ID?
	if ( ctype_digit( $tail ) ) {
		$post = get_post( (int) $tail );
		return $post && $post->post_type === $post_type;
	}

	// Otherwise treat it as {slug}
	$query = get_posts( [
		'name' => sanitize_title( $tail ),
		'post_type' => $post_type,
		'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
		'posts_per_page' => 1,
		'fields' => 'ids',
		'no_found_rows' => true,
	] );
	return !empty( $query );
}

function ra3valik_is_taxonomy_like_slug_in_use( $template_slug )
{
	// Handle built-ins: category, tag (post_tag), and generic taxonomy slugs
	if ( strpos( $template_slug, 'taxonomy' ) === 0 ) {
		// taxonomy | taxonomy-{taxonomy} | taxonomy-{taxonomy}-{term}
		$parts = explode( '-', $template_slug, 3 ); // limit to keep term intact (may contain dashes)
		// 'taxonomy' alone – considered globally applicable (already protected, but keep true)
		if ( count( $parts ) === 1 ) {
			return true;
		}
		$taxonomy = $parts[1] ?? '';
		if ( $taxonomy === '' || !taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		// 'taxonomy-{taxonomy}'
		if ( count( $parts ) === 2 ) {
			return true;
		}
		// 'taxonomy-{taxonomy}-{term}' where {term} can be ID or slug
		$term_part = $parts[2];
		if ( $term_part === '' ) return true;

		// Numeric ID?
		if ( ctype_digit( $term_part ) ) {
			$term = get_term( (int) $term_part, $taxonomy );
			return ( $term && !is_wp_error( $term ) );
		}

		// Otherwise, treat as slug
		$term = get_term_by( 'slug', sanitize_title( $term_part ), $taxonomy );
		return ( $term && !is_wp_error( $term ) );
	}

	if ( strpos( $template_slug, 'category' ) === 0 ) {
		// category | category-{term}
		$parts = explode( '-', $template_slug, 2 );
		if ( count( $parts ) === 1 ) {
			return true; // global category template
		}
		$term_part = $parts[1];
		if ( $term_part === '' ) return true;

		if ( ctype_digit( $term_part ) ) {
			$term = get_term( (int) $term_part, 'category' );
			return ( $term && !is_wp_error( $term ) );
		}
		$term = get_term_by( 'slug', sanitize_title( $term_part ), 'category' );
		return ( $term && !is_wp_error( $term ) );
	}

	if ( strpos( $template_slug, 'tag' ) === 0 ) {
		// tag | tag-{term}
		$parts = explode( '-', $template_slug, 2 );
		if ( count( $parts ) === 1 ) {
			return true; // global tag template
		}
		$term_part = $parts[1];
		if ( $term_part === '' ) return true;

		if ( ctype_digit( $term_part ) ) {
			$term = get_term( (int) $term_part, 'post_tag' );
			return ( $term && !is_wp_error( $term ) );
		}
		$term = get_term_by( 'slug', sanitize_title( $term_part ), 'post_tag' );
		return ( $term && !is_wp_error( $term ) );
	}

	return false;
}


/** Candidate values that can appear in _wp_page_template */
function ra3valik_candidate_values_for_template( $theme_id, $slug )
{
	return [
		$theme_id . '//' . $slug,
		$theme_id . '//templates/' . $slug,
		$slug, // fallback for migrations/imports
	];
}

/** Helpers to mark status */
function ra3valik_mark_in_use( $tpl_id, $title, $strip_prefix, $title_prefix )
{
	update_post_meta( $tpl_id, RA3VALIK_FLAG_META, 0 );
	if ( $strip_prefix ) {
		$patterns = [
			'/^' . preg_quote( $title_prefix, '/' ) . '\s*/u',
			'/^' . preg_quote( RA3VALIK_DEFAULT_PREFIX, '/' ) . '\s*/u',
		];
		$new = preg_replace( $patterns, '', $title, 1, $count );
		if ( $count > 0 ) {
			wp_update_post( ['ID' => $tpl_id, 'post_title' => $new] );
		}
	}
}

function ra3valik_mark_not_in_use( $tpl_id, $title, $add_prefix, $title_prefix )
{
	update_post_meta( $tpl_id, RA3VALIK_FLAG_META, 1 );
	if ( $add_prefix && !ra3valik_starts_with( $title, $title_prefix ) ) {
		wp_update_post( [
			'ID' => $tpl_id,
			'post_title' => $title_prefix . $title,
		] );
	}
}

/** Clear all badges: remove meta + strip prefix from titles */
function ra3valik_clear_all_not_in_use( $title_prefix )
{
	$theme = wp_get_theme();
	$theme_id = $theme->get_stylesheet();

	$q = new WP_Query( [
		'post_type' => 'wp_template',
		'post_status' => ['publish', 'draft'],
		'posts_per_page' => -1,
		'tax_query' => [
			[
				'taxonomy' => 'wp_theme',
				'field' => 'name',
				'terms' => $theme_id,
			],
		],
		'no_found_rows' => true,
		'fields' => 'ids',
	] );
	$count = 0;
	if ( $q->have_posts() ) {
		foreach ( $q->posts as $pid ) {
			delete_post_meta( $pid, RA3VALIK_FLAG_META );
			$title = get_the_title( $pid );

			$patterns = [
				'/^' . preg_quote( $title_prefix, '/' ) . '\s*/u',
				'/^' . preg_quote( RA3VALIK_DEFAULT_PREFIX, '/' ) . '\s*/u',
			];
			$new = preg_replace( $patterns, '', $title, 1, $changed );
			if ( $changed > 0 ) {
				wp_update_post( ['ID' => $pid, 'post_title' => $new] );
			}
			$count++;
		}
	}
	wp_reset_postdata();
	return $count;
}
