/**
 * TQRI Blog & Testimonials Builder API
 * Paste this at the BOTTOM of your theme's functions.php (do NOT include a new <?php tag)
 * Site: https://thequalitativeresearchinstitute.com/
 *
 * Powers blog-builder.html and testimonials-builder.html, which post
 * drafts to WordPress via the custom tqri/v1 REST routes below.
 */

define( 'TQRI_SECRET_TOKEN', 'tqri-blog-2026-secret' );

// ── Submit Blog Post ──────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	register_rest_route( 'tqri/v1', '/submit-blog', array(
		'methods'             => 'POST',
		'callback'            => 'tqri_submit_blog',
		'permission_callback' => '__return_true',
	) );
} );

function tqri_submit_blog( WP_REST_Request $request ) {
	$token = (string) $request->get_param( 'token' );
	if ( ! hash_equals( TQRI_SECRET_TOKEN, $token ) ) {
		return new WP_Error( 'forbidden', 'Invalid token', array( 'status' => 403 ) );
	}

	$body     = $request->get_json_params();
	$title    = sanitize_text_field( $body['title'] ?? '' );
	$slug     = sanitize_title( $body['slug'] ?? $title );
	$excerpt  = sanitize_textarea_field( $body['excerpt'] ?? '' );
	$sections = $body['sections'] ?? array();

	$content = tqri_build_blog_html( $sections );

	$post_data = array(
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_excerpt' => $excerpt,
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'post',
	);

	// Set categories if provided (supports either category_id or category_ids)
	$category_ids = array();
	if ( ! empty( $body['category_ids'] ) && is_array( $body['category_ids'] ) ) {
		$category_ids = array_map( 'intval', $body['category_ids'] );
	} elseif ( ! empty( $body['category_id'] ) ) {
		$category_ids = array( intval( $body['category_id'] ) );
	}
	if ( ! empty( $category_ids ) ) {
		$post_data['post_category'] = $category_ids;
	}

	// Set tags if provided
	if ( ! empty( $body['tags'] ) && is_array( $body['tags'] ) ) {
		$tag_ids = array();
		foreach ( $body['tags'] as $tag_name ) {
			$tag = get_term_by( 'name', sanitize_text_field( $tag_name ), 'post_tag' );
			if ( $tag ) {
				$tag_ids[] = $tag->term_id;
			} else {
				$new_tag = wp_insert_term( sanitize_text_field( $tag_name ), 'post_tag' );
				if ( ! is_wp_error( $new_tag ) ) {
					$tag_ids[] = $new_tag['term_id'];
				}
			}
		}
		$post_data['tags_input'] = $tag_ids;
	}

	$post_id = wp_insert_post( $post_data );

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	// Set featured image if provided
	if ( ! empty( $body['featured_image_url'] ) ) {
		$attach_id = tqri_url_to_attachment( $body['featured_image_url'] );
		if ( $attach_id ) {
			set_post_thumbnail( $post_id, $attach_id );
		}
	}

	return array(
		'success'  => true,
		'post_id'  => $post_id,
		'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
	);
}

function tqri_build_blog_html( $sections ) {
	$html = '<div class="tqri-blog-content">';
	foreach ( $sections as $s ) {
		$type = $s['type'] ?? '';
		switch ( $type ) {
			case 'intro':
				$html .= '<p class="tqri-intro">' . wp_kses_post( $s['text'] ?? '' ) . '</p>';
				break;
			case 'text':
				if ( ! empty( $s['heading'] ) ) {
					$html .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
				}
				$html .= '<p>' . wp_kses_post( $s['body'] ?? '' ) . '</p>';
				break;
			case 'image':
				$img_url = esc_url( $s['image'] ?? '' );
				$caption = esc_html( $s['caption'] ?? '' );
				if ( $img_url ) {
					$html .= '<figure class="tqri-image">';
					$html .= '<img src="' . $img_url . '" alt="' . $caption . '" style="max-width:100%;height:auto;">';
					if ( $caption ) {
						$html .= '<figcaption>' . $caption . '</figcaption>';
					}
					$html .= '</figure>';
				}
				break;
			case 'pullquote':
				$html .= '<blockquote class="tqri-pullquote">' . wp_kses_post( $s['text'] ?? '' ) . '</blockquote>';
				break;
			case 'subheading':
				$html .= '<h3>' . esc_html( $s['text'] ?? '' ) . '</h3>';
				break;
			case 'photo-grid-2':
				$imgs = $s['images'] ?? array();
				$html .= '<div class="tqri-photo-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:2rem 0;">';
				foreach ( array_slice( $imgs, 0, 2 ) as $img ) {
					$html .= '<img src="' . esc_url( $img ) . '" alt="" style="width:100%;height:300px;object-fit:cover;">';
				}
				$html .= '</div>';
				break;
		}
	}
	$html .= '</div>';
	return $html;
}

function tqri_url_to_attachment( $url ) {
	global $wpdb;
	$attachment = $wpdb->get_col(
		$wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid='%s';", $url )
	);
	return isset( $attachment[0] ) ? intval( $attachment[0] ) : null;
}

// ── Upload Image ──────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	register_rest_route( 'tqri/v1', '/upload-image', array(
		'methods'             => 'POST',
		'callback'            => 'tqri_upload_image',
		'permission_callback' => '__return_true',
	) );
} );

function tqri_upload_image( WP_REST_Request $request ) {
	$token = (string) $request->get_param( 'token' );
	if ( ! hash_equals( TQRI_SECRET_TOKEN, $token ) ) {
		return new WP_Error( 'forbidden', 'Invalid token', array( 'status' => 403 ) );
	}

	$body      = $request->get_json_params();
	$filename  = sanitize_file_name( $body['filename'] ?? 'upload.jpg' );
	$mime_type = $body['mime_type'] ?? 'image/jpeg';
	$data      = $body['data'] ?? '';

	$allowed_mimes = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);
	if ( ! isset( $allowed_mimes[ $mime_type ] ) ) {
		return new WP_Error( 'invalid_type', 'Only JPEG, PNG, GIF, or WebP images are allowed', array( 'status' => 400 ) );
	}
	// Force the extension to match the declared mime type, ignoring whatever
	// extension the caller sent, so a .php filename can't ride along as an "image".
	$filename = preg_replace( '/\.[^.]+$/', '', $filename ) . '.' . $allowed_mimes[ $mime_type ];

	$decoded = base64_decode( $data, true );
	if ( false === $decoded ) {
		return new WP_Error( 'invalid_data', 'Image data is not valid base64', array( 'status' => 400 ) );
	}

	$finfo       = new finfo( FILEINFO_MIME_TYPE );
	$actual_mime = $finfo->buffer( $decoded );
	if ( $actual_mime !== $mime_type ) {
		return new WP_Error( 'mime_mismatch', 'File content does not match the declared image type', array( 'status' => 400 ) );
	}

	$upload = wp_upload_bits( $filename, null, $decoded );

	if ( $upload['error'] ) {
		return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
	}

	$attach_id = wp_insert_attachment( array(
		'post_mime_type' => $mime_type,
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
		'post_status'    => 'inherit',
	), $upload['file'] );

	require_once( ABSPATH . 'wp-admin/includes/image.php' );
	$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	wp_update_attachment_metadata( $attach_id, $metadata );

	return array(
		'success'   => true,
		'url'       => $upload['url'],
		'attach_id' => $attach_id,
	);
}

// ── Contact Form Submission ─────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	register_rest_route( 'tqri/v1', '/contact-form', array(
		'methods'             => 'POST',
		'callback'            => 'tqri_submit_contact_form',
		'permission_callback' => '__return_true',
	) );
} );

function tqri_submit_contact_form( WP_REST_Request $request ) {
	$token = (string) $request->get_param( 'token' );
	if ( ! hash_equals( TQRI_SECRET_TOKEN, $token ) ) {
		return new WP_Error( 'forbidden', 'Invalid token', array( 'status' => 403 ) );
	}

	$body    = $request->get_json_params();
	$name    = sanitize_text_field( $body['name'] ?? '' );
	$phone   = sanitize_text_field( $body['phone'] ?? '' );
	$company = sanitize_text_field( $body['company'] ?? '' );
	$message = sanitize_textarea_field( $body['message'] ?? '' );

	if ( empty( $name ) || empty( $phone ) || empty( $message ) ) {
		return new WP_Error( 'missing_fields', 'Name, phone, and message are required', array( 'status' => 400 ) );
	}

	$to      = 'hello@thequalitativeresearchinstitute.com';
	$subject = 'New enquiry from ' . $name . ' (TQRI website)';
	$body_text  = "New contact form submission:\n\n";
	$body_text .= "Name: $name\n";
	$body_text .= "Phone: $phone\n";
	$body_text .= "Company / Institution: " . ( $company ?: '—' ) . "\n\n";
	$body_text .= "Message:\n$message\n";

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$sent    = wp_mail( $to, $subject, $body_text, $headers );

	if ( ! $sent ) {
		return new WP_Error( 'mail_failed', 'Could not send email', array( 'status' => 500 ) );
	}

	return array( 'success' => true );
}

// ── CORS for blog/testimonials builder pages ──────────────────────────────────
// Scoped to tqri/v1 routes only — must not touch Elementor's own REST calls,
// which rely on the default cookie/nonce-based CORS handling to work at all.
add_filter( 'rest_pre_serve_request', function ( $value, $result, $request ) {
	if ( 0 === strpos( $request->get_route(), '/tqri/v1/' ) ) {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type' );
	}
	return $value;
}, 15, 3 );
