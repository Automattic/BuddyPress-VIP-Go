<?php
/**
 * Integrates BuddyPress into VIP's File Hosting Service.
 * 
 * @package BuddyPress-VIP-Go
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

add_action(
	'bp_init',
	function () {
		/*
		* Tweaks for fetching avatars and cover images -- bp_core_fetch_avatar() and bp_attachments_get_attachment().
		*/
		add_filter( 'bp_core_avatar_folder_dir', '__return_empty_string' );
		add_filter( 'bp_core_fetch_avatar_no_grav', '__return_true' );
		add_filter( 'bp_core_default_avatar_user', 'vipbp_filter_user_avatar_urls', 10, 2 );
		add_filter( 'bp_core_default_avatar_group', 'vipbp_filter_group_avatar_urls', 10, 2 );
		add_filter( 'bp_attachments_pre_get_attachment', 'vipbp_filter_get_cover_image', 10, 2 );

		/*
		* Tweaks for uploading user and group avatars -- bp_core_avatar_handle_upload().
		*/
		add_filter( 'bp_core_pre_avatar_handle_upload', 'vipbp_handle_avatar_upload', 10, 3 );
		add_filter( 'bp_avatar_pre_handle_capture', 'vipbp_handle_avatar_capture', 10, 3 );

		/*
		* Tweaks for uploading cover images -- bp_attachments_cover_image_ajax_upload().
		*/
		add_filter( 'bp_attachments_pre_cover_image_ajax_upload', 'vip_handle_cover_image_upload', 10, 4 );

		/*
		* Tweaks for cropping user and group avatars -- bp_core_avatar_handle_crop().
		*/
		add_filter( 'bp_core_pre_avatar_handle_crop', 'vipbp_handle_avatar_crop', 10, 2 );

		/*
		* Tweaks for deleting avatars and cover images -- bp_core_delete_existing_avatar() and bp_attachments_delete_file().
		*/
		add_filter( 'bp_core_pre_delete_existing_avatar', 'vipbp_delete_existing_avatar', 10, 2 );
		add_filter( 'bp_attachments_pre_delete_file', 'vipbp_delete_cover_image', 10, 2 );

		/*
		* Tweaks for handling temporary directory cleanup.
		*/
		add_filter( 'bp_core_remove_temp_directory', 'vipbp_handle_temp_directory_removal', 10, 1 );

		/*
		* Video upload tracking hooks
		*/
		add_action( 'bb_before_video_upload_handler', 'vipbp_log_video_upload_start' );
		add_action( 'bb_after_video_upload_handler', 'vipbp_log_video_upload_complete' );
		add_action( 'bb_video_upload', 'vipbp_log_video_upload_attachment', 10, 1 );
		add_action( 'bb_before_video_preview_image_by_js', 'vipbp_log_preview_image_start' );
		add_action( 'bb_after_video_preview_image_by_js', 'vipbp_log_preview_image_complete' );
		add_action( 'bp_video_before_background_process_create', 'vipbp_log_thumbnail_generation_start', 10, 1 );
		add_action( 'bp_video_after_background_process_create', 'vipbp_log_thumbnail_generation_complete', 10, 1 );
		add_action( 'bb_video_after_preview_generate', 'vipbp_log_preview_generation_complete' );
		add_action( 'bp_video_after_background_create_thumbnail', 'vipbp_log_thumbnail_creation_complete', 10, 1 );
		add_action( 'bb_try_after_video_background_create_thumbnail', 'vipbp_log_thumbnail_creation_attempt_complete', 10, 1 );

		/**
		 * Tweaks for flushing the cache after moving a video.
		 */
		add_action( 'bp_video_after_save', 'vipbp_flush_cache_after_video_move', 99 );
	} 
);

/**
 * Change user avatars' URLs to their locations on VIP Go FHS.
 *
 * By default, BuddyPress iterates through the local file system to find an uploaded avatar
 * for a given user or group. Our filter on `bp_core_avatar_folder_dir()` prevents this happening,
 * and our filter on `bp_core_fetch_avatar_no_grav` will make the code flow to this
 * bp_core_default_avatar_* filter.
 *
 * It's normally used to override the fallback image for Gravatar, but by duplicating some logic, we
 * use it to here to set a custom URL to support VIP Go FHS without any core changes to BuddyPress.
 *
 * @param string $_      Unused.
 * @param array  $params Parameters for fetching the avatar.
 * @return string Avatar URL.
 */
function vipbp_filter_user_avatar_urls( $_, $params ) {
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	$retval = vipbp_filter_avatar_urls(
		$params,
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		get_user_meta( $params['item_id'], 'vipbp-avatars', true ) ?: array()
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return $retval;
}

/**
 * Change group avatars' URLs to their locations on VIP Go FHS.
 *
 * By default, BuddyPress iterates through the local file system to find an uploaded avatar
 * for a given user or group. Our filter on `bp_core_avatar_folder_dir()` prevents this happening,
 * and our filter on `bp_core_fetch_avatar_no_grav` will make the code flow to this
 * bp_core_default_avatar_* filter.
 *
 * It's normally used to override the fallback image for Gravatar, but by duplicating some logic, we
 * use it to here to set a custom URL to support VIP Go FHS without any core changes to BuddyPress.
 *
 * @param string $_      Unused.
 * @param array  $params Parameters for fetching the avatar.
 * @return string Avatar URL.
 */
function vipbp_filter_group_avatar_urls( $_, $params ) {
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	$retval = vipbp_filter_avatar_urls(
		$params,
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		groups_get_groupmeta( $params['item_id'], 'vipbp-group-avatars', true ) ?: array()
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return $retval;
}

/**
 * Change the URL of any kind of avatars to their locations on the VIP Go FHS.
 *
 * Intended as a helper function for vipbp_filter_user_avatar_urls() and
 * vipbp_filter_group_avatar_urls() to avoid duplication.
 *
 * @param array $params Parameters for fetching the avatar.
 * @param array $meta Image meta for cropping.
 * @return string Avatar URL.
 */
function vipbp_filter_avatar_urls( $params, $meta ) {
	$bp       = buddypress();
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}


	/**
	 * If no meta exists, object does not have an avatar.
	 * Return a Gravatar.
	 */

	if ( ! $meta ) {

		// Gravatar type.
		if ( empty( $bp->grav_default->{$params['object']} ) ) {
			$default_grav = 'wavatar';
		} else {
			$default_grav = $bp->grav_default->{$params['object']};
		}

		// Check email address is set.
		if ( empty( $params['email'] ) ) {
			if ( 'user' === $params['object'] ) {
				$params['email'] = bp_core_get_user_email( $params['item_id'] );
			} elseif ( 'group' === $params['object'] || 'blog' === $params['object'] ) {
				$params['email'] = $params['item_id'] . '-' . $params['object'] . '@' . bp_get_root_domain();
			}
		}

		$params['email'] = apply_filters( 'bp_core_gravatar_email', $params['email'], $params['item_id'], $params['object'] );
		$gravatar        = apply_filters( 'bp_gravatar_url', '//www.gravatar.com/avatar/' );
		$gravatar       .= md5( strtolower( $params['email'] ) );

		$gravatar_args = array(
			's' => $params['width'],
		);

		if ( ! empty( $params['force_default'] ) ) {
			$gravatar_args['f'] = 'y';
		}

		if ( ! empty( $params['rating'] ) ) {
			$gravatar_args['r'] = strtolower( $params['rating'] );
		}

		// Only set default image if 'Gravatar Logo' is not requested.
		if ( 'gravatar_default' !== $default_grav ) {
			$gravatar_args['d'] = $default_grav;
		}

		$retval = esc_url(
			add_query_arg(
				rawurlencode_deep( array_filter( $gravatar_args ) ),
				$gravatar
			) 
		);

		if ( $switched ) {
			restore_current_blog();
		}

		return $retval;
	}


	/**
	 * Object has an uploaded avatar.
	 */

	$avatar_args = array(
		// Maybe clamp image width (e.g. if it was uploaded on a narrow display).
		'w'      => $meta['ui_width'],

		// Crop avatar.
		'crop'   => sprintf(
			'%dpx,%dpx,%dpx,%dpx',
			$meta['crop_x'],
			$meta['crop_y'],
			$meta['crop_w'],
			$meta['crop_h']
		),

		// Resize back to bpthumb or bpfull size.
		'resize' => sprintf( '%d,%d', $params['width'], $params['height'] ),

		// Removes EXIF and IPTC data.
		'strip'  => 'info',
	);

	// Only clamp image width if it was uploaded on mobile.
	if ( ! $avatar_args['w'] ) {
		unset( $avatar_args['w'] );
	}

	// Add crop and resizing parameters to the avatar URL.
	$avatar_url = add_query_arg( urlencode_deep( $avatar_args ), $meta['url'] );
	$avatar_url = apply_filters( 'vipbp_filter_avatar_urls', $avatar_url, $params, $meta );
	$avatar_url = set_url_scheme( $avatar_url, $params['scheme'] );

	if ( $switched ) {
		restore_current_blog();
	}

	return $avatar_url;
}

/**
 * Change the URL of any kind of cover image to their locations on the VIP Go FHS.
 *
 * By default, BuddyPress iterates through the local file system to find an uploaded cover iamge
 * for a given user or group. Our filter on `bp_attachments_pre_get_attachment` prevents this
 * happening.
 *
 * @param null|string $value If null is returned, proceed with default behaviour. Otherwise, value returned verbatim.
 * @param array       $args {
 *     Array of arguments.
 *
 *     @type string $object_dir  The object dir (eg: members/groups). Defaults to members.
 *     @type int    $item_id     The object id (eg: a user or a group id). Defaults to current user.
 *     @type string $type        The type of the attachment which is also the subdir where files are saved.
 *                               Defaults to 'cover-image'.
 *     @type string $file        The name of the file.
 * }
 * @return string Cover image URL.
 */
function vipbp_filter_get_cover_image( $value, $args ) {
	$component = '';
	$meta      = array();
	$switched  = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( 'members' === $args['object_dir'] ) {
		$component = 'xprofile';
		$meta      = get_user_meta( $args['item_id'], 'vipbp-user-cover', true );

	} elseif ( 'groups' === $args['object_dir'] ) {
		$component = 'groups';
		if ( bp_is_active( 'groups' ) ) {
			$meta = groups_get_groupmeta( $args['item_id'], 'vipbp-group-cover', true );
		}
	}

	$dimensions = bp_attachments_get_cover_image_dimensions( $component );

	if ( $meta && $dimensions ) {
		$retval = add_query_arg(
			array(
				// Fit the width.
				'w'     => (int) $dimensions['width'],

				// Crop the middle part of the image.
				'crop'  => sprintf( '0,25,%dpx,%dpx', $dimensions['width'], $dimensions['height'] ),

				// Removes EXIF and IPTC data.
				'strip' => 'info',
			),
			$meta['url'] 
		);

	} else {
		$retval = false;
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return $retval;
}

/**
 * Upload avatars to VIP Go FHS. Overrides default behaviour.
 *
 * Permission checks are made upstream in xprofile_screen_change_avatar().
 *
 * @param string $_                 Unused.
 * @param array  $file              Appropriate entry from $_FILES superglobal.
 * @param string $upload_dir_filter Callable function to get uploaded avatar and upload directory info.
 * @return false Shortcircuits bp_core_avatar_handle_upload().
 */
function vipbp_handle_avatar_upload( $_, $file, $upload_dir_filter ) {
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
	}

	$bp                                = buddypress();
	$crop_image_width                  = bp_core_avatar_original_max_width();
	$crop_ui_available_width           = 0;
	$upload_dir_info                   = call_user_func( $upload_dir_filter );
	list( , $avatar_type, $object_id ) = explode( '/', $upload_dir_info['subdir'] );

	if ( isset( $bp->avatar_admin->ui_available_width ) ) {
		$crop_ui_available_width = $bp->avatar_admin->ui_available_width;
	}


	// Handle file upload.
	$uploaded_file = $file['file'];
	$result        = wp_handle_upload(
		$uploaded_file,
		array(
			'action'    => 'wp_handle_upload', // Matches Core's action for uploads, to ensure VIP Go fileservice filters are applied.
			'test_form' => false,
		) 
	);

	if ( ! empty( $result['error'] ) ) {
		/* translators: %s: Error message returned during the upload process. */
		bp_core_add_message( sprintf( __( 'Upload failed! Error was: %s', 'buddypress-vip-go' ), $result['error'] ), 'error' );

		if ( $switched ) {
			restore_current_blog();
		}

		return false;
	}


	// Make sure the image will fit the cropper.
	if ( $crop_ui_available_width < $crop_image_width ) {

		// $crop_image_width has to be larger than the "bpfull" image size.
		if ( $crop_image_width < bp_core_avatar_full_width() ) {
			$crop_image_width = bp_core_avatar_full_width();
		} else {
			$crop_image_width = $crop_ui_available_width;
		}
	}

	// Add query argument to ensure image is not upscaled.
	$result['url'] = add_query_arg( 'w', $crop_image_width, $result['url'] );


	// Set placeholder meta for image crop.
	if ( 'xprofile_avatar_upload_dir' === $upload_dir_filter ) {
		update_user_meta(
			(int) $object_id,
			"vipbp-{$avatar_type}",
			array(
				'crop_w'   => bp_core_avatar_full_width(),
				'crop_h'   => bp_core_avatar_full_height(),
				'crop_x'   => 0,
				'crop_y'   => 0,
				'ui_width' => $crop_image_width,
				'url'      => $result['url'],
			) 
		);

	} elseif ( 'groups_avatar_upload_dir' === $upload_dir_filter ) {
		groups_update_groupmeta(
			(int) $object_id,
			"vipbp-{$avatar_type}",
			array(
				'crop_w'   => bp_core_avatar_full_width(),
				'crop_h'   => bp_core_avatar_full_height(),
				'crop_x'   => 0,
				'crop_y'   => 0,
				'ui_width' => $crop_image_width,
				'url'      => $result['url'],
			) 
		);
	}


	// Re-implement globals and checks that BuddyPress normally does.
	$bp->avatar_admin->image       = new stdClass();
	$bp->avatar_admin->image->dir  = str_replace( bp_core_avatar_url(), '', $result['url'] );
	$bp->avatar_admin->image->file = $result['url'];
	$bp->avatar_admin->image->url  = $result['url'];

	if ( BP_Attachment_Avatar::is_too_small( $bp->avatar_admin->image->file ) ) {
		bp_core_add_message(
			sprintf(
				/* translators: 1: Avatar width. 2: Avatar height. */
				__( 'You have selected an image that is smaller than recommended. For best results, upload a picture larger than %1$d x %2$d pixels.', 'buddypress-vip-go' ),
				bp_core_avatar_full_width(),
				bp_core_avatar_full_height()
			),
			'error'
		);
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return false;
}

/**
 * Upload webcam user avatars to VIP Go FHS. Overrides default behaviour.
 *
 * Permission checks are made upstream in bp_avatar_ajax_set().
 *
 * @param string $_       Unused.
 * @param string $data    Base64 encoded image.
 * @param int    $item_id Item to associate.
 * @return false Shortcircuits bp_avatar_handle_capture().
 */
function vipbp_handle_avatar_capture( $_, $data, $item_id ) {
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
	}

	// Save bytestream to disk.
	$tmp_name = wp_tempnam();
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
	file_put_contents( $tmp_name, $data );

	// Figure out the MIME type.
	$finfo     = finfo_open( FILEINFO_MIME_TYPE );
	$mime_type = finfo_file( $finfo, $tmp_name );
	finfo_close( $finfo );

	$new_tmp_name = str_replace( '.tmp', '.' . array_search( $mime_type, get_allowed_mime_types(), true ), $tmp_name );
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename
	rename( $tmp_name, $new_tmp_name );
	$tmp_name = $new_tmp_name;

	// Fake it as if it were a $_FILES array.
	$file = array(
		'file' => array(
			'name'     => basename( $tmp_name ),
			'tmp_name' => $tmp_name,
			'type'     => $mime_type,
			'size'     => filesize( $tmp_name ),
		),
	);

	// Upload the avatar.
	bp_core_avatar_handle_upload( $file, 'xprofile_avatar_upload_dir' );

	// And crop it.
	bp_core_avatar_handle_crop(
		array(
			'item_id'       => $item_id,
			'original_file' => $tmp_name,
		) 
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return false;
}

/**
 * Upload cover images to VIP Files Service. Overrides default behaviour.
 *
 * This function duplicates significant logic from BuddyPress upstream because
 * `bp_attachments_cover_image_ajax_upload()` could be significantly improved;
 * it does too much. We'll be able to simply this function in the future.
 *
 * Permission checks are made upstream in bp_attachments_cover_image_ajax_upload().
 *
 * @param string $_           Unused.
 * @param array  $args        Array of arguments for the cover image upload.
 * @param array  $needs_reset Stores original value of certain globals we need to revert to later.
 * @param array  $object_data Array containing additional data about the object being processed.
 * @return array Shortcircuits bp_attachments_cover_image_ajax_upload().
 */
function vip_handle_cover_image_upload( $_, $args, $needs_reset, $object_data ) {
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
	}

	$bp = buddypress();

	// Upload file.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$uploaded_file = $_FILES['file'];
	$result        = wp_handle_upload(
		$uploaded_file,
		array(
			'action'    => 'wp_handle_upload', // Matches Core's action for uploads, to ensure VIP fileservice filters are applied.
			'test_form' => false,
		)
	);


	// Reset globals changed in bp_attachments_cover_image_ajax_upload().
	if ( ! empty( $needs_reset ) ) {
		if ( ! empty( $needs_reset['component'] ) ) {
			$bp->{$needs_reset['component']}->{$needs_reset['key']} = $needs_reset['value'];
		} else {
			$bp->{$needs_reset['key']} = $needs_reset['value'];
		}
	}

	if ( ! empty( $result['error'] ) ) {
		return array(
			'result'  => false,
			'message' => sprintf(
				/* translators: %s: Error message returned during the upload process. */
				__( 'Upload Failed! Error was: %s', 'buddypress-vip-go' ),
				$result['error']
			),
			'type'    => 'upload_error',
		);
	}

	// Set meta so we know there's an image.
	if ( 'user' === $args['object'] ) {
		update_user_meta(
			$args['item_id'],
			'vipbp-user-cover',
			array(
				'url' => $result['url'],
			)
		);

	} elseif ( 'group' === $args['object'] ) {
		groups_update_groupmeta(
			$args['item_id'],
			'vipbp-group-cover',
			array(
				'url' => $result['url'],
			) 
		);
	}

	do_action(
		$object_data['component'] . '_cover_image_uploaded',
		(int) $args['item_id'],
		basename( $result['url'] ),
		$result['url'],
		1
	);

	$retval = array(
		'result'        => true,
		'feedback_code' => 1,
		'name'          => basename( $result['url'] ),
		'url'           => $result['url'],
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return $retval;
}

/**
 * Handle avatar crops on the VIP Go environment.
 *
 * Instead of creating a new image, we store the cropping coordinates and later let the
 * Files Service dynamically crop the image on-demand via Photon-like query parameters.
 *
 * Permission checks are made upstream in xprofile_screen_change_avatar().
 *
 * @param string       $_ Unused.
 * @param array|string $args {
 *     Array of function parameters.
 *
 *     @type string      $object        Object type of the item whose avatar you're
 *                                      handling. 'user', 'group', 'blog', or custom.
 *                                      Default: 'user'.
 *     @type string      $avatar_dir    Subdirectory where avatar should be stored.
 *                                      Default: 'avatars'.
 *     @type bool|int    $item_id       ID of the item that the avatar belongs to.
 *     @type bool|string $original_file Relative path to the original avatar file.
 *     @type int         $crop_w        Crop width. Default: the global 'full' avatar width,
 *                                      as retrieved by bp_core_avatar_full_width().
 *     @type int         $crop_h        Crop height. Default: the global 'full' avatar height,
 *                                      as retrieved by bp_core_avatar_full_height().
 *     @type int         $crop_x        The horizontal starting point of the crop. Default: 0.
 *     @type int         $crop_y        The vertical starting point of the crop. Default: 0.
 * }
 * @return false Shortcircuits bp_core_avatar_handle_crop().
 */
function vipbp_handle_avatar_crop( $_, $args ) {
	$cropping_meta = array(
		'crop_w' => (int) $args['crop_w'],
		'crop_h' => (int) $args['crop_h'],
		'crop_x' => (int) $args['crop_x'],
		'crop_y' => (int) $args['crop_y'],
	);

	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( 'user' === $args['object'] ) {
		$meta = get_user_meta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], true );
		$meta = wp_parse_args( $cropping_meta, $meta );
		update_user_meta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], $meta );

	} elseif ( 'group' === $args['object'] ) {
		$meta = groups_get_groupmeta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], true );
		$meta = wp_parse_args( $cropping_meta, $meta );
		groups_update_groupmeta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], $meta );
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return false;
}

/**
 * Handle deleting avatars on the VIP Go environment.
 *
 * Permission checks are made upstream in several screen handling functions.
 *
 * @param string       $_ Unused.
 * @param array|string $args {
 *     Array of function parameters.
 *
 *     @type bool|int    $item_id    ID of the item whose avatar you're deleting.
 *                                   Defaults to the current item of type $object.
 *     @type string      $object     Object type of the item whose avatar you're
 *                                   deleting. 'user', 'group', 'blog', or custom.
 *                                   Default: 'user'.
 *     @type bool|string $avatar_dir Subdirectory where avatar is located.
 *                                   Default: false, which falls back on the default location
 *                                   corresponding to the $object.
 * }
 * @return false Shortcircuits bp_core_delete_existing_avatar().
 */
function vipbp_delete_existing_avatar( $_, $args ) {
	$meta     = array();
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( empty( $args['item_id'] ) ) {
		if ( 'user' === $args['object'] ) {
			$args['item_id'] = bp_displayed_user_id();
		} elseif ( 'group' === $args['object'] ) {
			$args['item_id'] = buddypress()->groups->current_group->id;
		}

		$args['item_id'] = apply_filters( 'bp_core_avatar_item_id', $args['item_id'], $args['object'] );

		if ( ! $args['item_id'] ) {
			if ( $switched ) {
				restore_current_blog();
			}

			return false;
		}
	}

	if ( empty( $args['avatar_dir'] ) ) {
		if ( 'user' === $args['object'] ) {
			$args['avatar_dir'] = 'avatars';
		} elseif ( 'group' === $args['object'] ) {
			$args['avatar_dir'] = 'group-avatars';
		} elseif ( 'blog' === $args['object'] ) {
			$args['avatar_dir'] = 'blog-avatars';
		}

		$args['avatar_dir'] = apply_filters( 'bp_core_avatar_dir', $args['avatar_dir'], $args['object'] );

		if ( ! $args['avatar_dir'] ) {
			if ( $switched ) {
				restore_current_blog();
			}

			return false;
		}
	}

	// Remove crop meta.
	if ( 'user' === $args['object'] ) {
		$meta = get_user_meta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], true );
		delete_user_meta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'] );

	} elseif ( 'group' === $args['object'] ) {
		$meta = groups_get_groupmeta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'], true );
		groups_delete_groupmeta( (int) $args['item_id'], 'vipbp-' . $args['avatar_dir'] );
	}

	if ( $meta ) {
		$meta['url'] = str_replace( get_site_url() . '/', '', strtok( $meta['url'], '?' ) );
		wp_delete_file( $meta['url'] );

		do_action( 'bp_core_delete_existing_avatar', $args );
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return false;
}

/**
 * Handle deleting cover images on the VIP Go environment.
 *
 * Permission checks are made upstream in several screen handling functions.
 *
 * @param string $_    Unused.
 * @param array  $args Array of arguments for the attachment deletion.
 * @return false Shortcircuits bp_attachments_delete_file().
 */
function vipbp_delete_cover_image( $_, $args ) {
	$meta     = array();
	$switched = false;

	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
		$switched = true;
	}

	if ( 'members' === $args['object_dir'] ) {
		$meta = get_user_meta( $args['item_id'], 'vipbp-user-cover', true );
		delete_user_meta( $args['item_id'], 'vipbp-user-cover' );

	} elseif ( 'groups' === $args['object_dir'] ) {
		$meta = groups_get_groupmeta( $args['item_id'], 'vipbp-group-cover', true );
		groups_delete_groupmeta( $args['item_id'], 'vipbp-group-cover' );
	}

	if ( $meta ) {
		$meta['url'] = str_replace( get_site_url() . '/', '', strtok( $meta['url'], '?' ) );
		wp_delete_file( $meta['url'] );
	}

	if ( $switched ) {
		restore_current_blog();
	}

	return false;
}

/**
 * Handle temporary directory removal in a VIP Go compatible way.
 *
 * Since the VIP File System uses an object store without a true directory structure,
 * we need to handle file cleanup differently. This function:
 * 1. Uses the system /tmp directory for temporary operations
 * 2. Avoids directory traversal operations
 * 3. Uses WordPress's file system functions where possible
 *
 * @param string $directory Directory to remove.
 * @return array Empty array to prevent reset() error.
 */
function vipbp_handle_temp_directory_removal( $directory ) {
	error_log( sprintf(
		'[VIPBP Video] Temp directory removal handler called. Directory: %s',
		$directory
	) );

	if ( empty( $directory ) ) {
		error_log( '[VIPBP Video] Empty directory provided to removal handler' );
		return array();
	}

	// Handle vip:// protocol URLs
	if ( 0 === strpos( $directory, 'vip://' ) ) {
		$original_directory = $directory;
		$directory = str_replace( 'vip://', '', $directory );
		error_log( sprintf(
			'[VIPBP Video] Converted vip:// URL. Original: %s, New: %s',
			$original_directory,
			$directory
		) );
	}

	// Get the system temp directory
	$tmp_dir = get_temp_dir();
	
	// If the directory is in /tmp, we can use normal file operations
	if ( 0 === strpos( $directory, $tmp_dir ) ) {
		error_log( sprintf(
			'[VIPBP Video] Directory is in /tmp. Using normal file operations. Path: %s',
			$directory
		) );
		
		// Use WordPress's file system functions
		global $wp_filesystem;
		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			$creds = request_filesystem_credentials( site_url() );
			wp_filesystem( $creds );
		}

		if ( is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			$result = $wp_filesystem->delete( $directory, true );
			error_log( sprintf(
				'[VIPBP Video] WP_Filesystem delete result: %s',
				$result ? 'success' : 'failed'
			) );
		} else {
			error_log( '[VIPBP Video] WP_Filesystem not available for /tmp operation' );
		}
	} else {
		// For files in the VIP File System, we need to handle them differently
		// since we can't do directory operations
		$upload_dir = wp_upload_dir();
		$basedir = $upload_dir['basedir'];
		
		error_log( sprintf(
			'[VIPBP Video] Directory is in VIP File System. Basedir: %s, Directory: %s',
			$basedir,
			$directory
		) );
		
		// If the directory is within uploads, we need to handle it file by file
		if ( 0 === strpos( $directory, $basedir ) ) {
			// Get the relative path from uploads
			$relative_path = substr( $directory, strlen( $basedir ) );
			
			error_log( sprintf(
				'[VIPBP Video] Directory is within uploads. Relative path: %s',
				$relative_path
			) );
			
			// Use WordPress's file system functions to delete files
			global $wp_filesystem;
			if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
				$creds = request_filesystem_credentials( site_url() );
				wp_filesystem( $creds );
			}

			if ( is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
				$result = $wp_filesystem->delete( $directory, true );
				error_log( sprintf(
					'[VIPBP Video] WP_Filesystem delete result for VIP File System: %s',
					$result ? 'success' : 'failed'
				) );
			} else {
				error_log( '[VIPBP Video] WP_Filesystem not available for VIP File System operation' );
			}
		} else {
			error_log( sprintf(
				'[VIPBP Video] Directory is not within uploads. Directory: %s',
				$directory
			) );
		}
	}

	error_log( '[VIPBP Video] Temp directory removal handler completed' );
	return array();
}

/**
 * Log the start of a video upload operation.
 */
function vipbp_log_video_upload_start() {
	error_log( '[VIPBP Video] Starting video upload' );
}

/**
 * Log the completion of a video upload operation.
 */
function vipbp_log_video_upload_complete() {
	error_log( '[VIPBP Video] Completed video upload' );
}

/**
 * Log video upload attachment details.
 *
 * @param mixed $attachment The attachment object.
 */
function vipbp_log_video_upload_attachment( $attachment ) {
	error_log( sprintf(
		'[VIPBP Video] Video upload attachment created. ID: %d, Title: %s',
		$attachment->ID,
		$attachment->post_title
	) );
}

/**
 * Log the start of a preview image operation.
 */
function vipbp_log_preview_image_start() {
	error_log( '[VIPBP Video] Starting preview image processing' );
}

/**
 * Log the completion of a preview image operation.
 */
function vipbp_log_preview_image_complete() {
	error_log( '[VIPBP Video] Completed preview image processing' );
}

/**
 * Log the start of thumbnail generation.
 *
 * @param BP_Video $video The video object.
 */
function vipbp_log_thumbnail_generation_start( $video ) {
	error_log( sprintf(
		'[VIPBP Video] Starting thumbnail generation for video ID: %d',
		$video->id
	) );
}

/**
 * Log the completion of thumbnail generation.
 *
 * @param BP_Video $video The video object.
 */
function vipbp_log_thumbnail_generation_complete( $video ) {
	error_log( sprintf(
		'[VIPBP Video] Completed thumbnail generation for video ID: %d',
		$video->id
	) );
}

/**
 * Log the completion of preview generation.
 */
function vipbp_log_preview_generation_complete() {
	error_log( '[VIPBP Video] Completed preview generation' );
}

/**
 * Log the completion of thumbnail creation.
 *
 * @param BP_Video $video The video object.
 */
function vipbp_log_thumbnail_creation_complete( $video ) {
	error_log( sprintf(
		'[VIPBP Video] Completed thumbnail creation for video ID: %d',
		$video->id
	) );
}

/**
 * Log the completion of thumbnail creation attempt.
 *
 * @param BP_Video $video The video object.
 */
function vipbp_log_thumbnail_creation_attempt_complete( $video ) {
	error_log( sprintf(
		'[VIPBP Video] Completed thumbnail creation attempt for video ID: %d',
		$video->id
	) );
}

/**
 * Flush the cache for the video after it has been moved to a album.
 *
 * This function resets the BuddyPress media incrementor to ensure that
 * any cached media data is invalidated after a video is moved.
 */
function vipbp_flush_cache_after_video_move() {
	// Flush the cache for the video after it has been moved.
	bp_core_reset_incrementor( 'bp_media' );
}
