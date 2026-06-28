<?php
/**
 * Standardized social-share links that wire into Turf's existing
 * data-turf-click tracking automatically (js/clicks.js already listens for
 * that attribute anywhere in the document) - so a theme can drop in
 * turf_social_share_links() and get "social-share-facebook" etc. on the
 * existing Klikken admin page with no extra wiring.
 */

function turf_social_share_networks() {
	return apply_filters( 'turf_social_share_networks', array(
		'facebook' => array(
			'label' => __( 'Facebook', 'turf-stats' ),
			'url'   => 'https://www.facebook.com/sharer/sharer.php?u=%1$s',
		),
		'twitter'  => array(
			'label' => __( 'X (Twitter)', 'turf-stats' ),
			'url'   => 'https://twitter.com/intent/tweet?url=%1$s&text=%2$s',
		),
		'whatsapp' => array(
			'label' => __( 'WhatsApp', 'turf-stats' ),
			// "+" rather than a literal "%20" for the space - sprintf parses a
			// literal "%2" in the template as its own (malformed) format
			// specifier, not literal text.
			'url'   => 'https://wa.me/?text=%2$s+%1$s',
		),
		'linkedin' => array(
			'label' => __( 'LinkedIn', 'turf-stats' ),
			'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url=%1$s',
		),
		'email'    => array(
			'label' => __( 'E-mail', 'turf-stats' ),
			'url'   => 'mailto:?subject=%2$s&body=%1$s',
		),
	) );
}

/**
 * Echoes a row of share links for $post_id (defaults to the current post in
 * the loop), each pre-wired with data-turf-click="social-share-<network>".
 *
 * @param int|null    $post_id  Post to share. Defaults to get_the_ID().
 * @param string[]|null $networks Which network keys to show, defaults to all of turf_social_share_networks().
 */
function turf_social_share_links( $post_id = null, $networks = null ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id ) {
		return;
	}

	$url   = rawurlencode( get_permalink( $post_id ) );
	$title = rawurlencode( get_the_title( $post_id ) );
	$all   = turf_social_share_networks();
	$keys  = $networks ?: array_keys( $all );
	?>
	<div class="turf-social-share">
		<?php foreach ( $keys as $key ) : ?>
			<?php if ( ! isset( $all[ $key ] ) ) : continue; endif; ?>
			<a
				href="<?php echo esc_url( sprintf( $all[ $key ]['url'], $url, $title ) ); ?>"
				class="turf-social-share__link turf-social-share__link--<?php echo esc_attr( $key ); ?>"
				data-turf-click="social-share-<?php echo esc_attr( $key ); ?>"
				target="_blank"
				rel="noopener noreferrer"
			>
				<?php echo esc_html( $all[ $key ]['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}
