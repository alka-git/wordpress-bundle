<?php

namespace Metabolism\WordpressBundle\Plugin;


/**
 * Class Metabolism\WordpressBundle Framework
 */
class MediaPlugin {

	protected $config, $prevent_recurssion;

	/**
	 * Quickly upload file
	 */
	public static function upload($file='file', $allowed_type = ['image/jpeg', 'image/gif', 'image/png'], $path='/user', $max_size=1048576){

		if( !isset($_FILES[$file]) or empty($_FILES[$file]) )
			return new \WP_Error('empty', 'File '.$file.' is empty');

		$file = $_FILES[$file];

		if ($file['error'] !== UPLOAD_ERR_OK)
			return new \WP_Error('error_upload', 'There was an error uploading your file.');

		if ($file['size'] > $max_size)
			return new \WP_Error('file_size', 'The file is too large');

		$mime_type = mime_content_type($file['tmp_name']);

		if( !in_array($mime_type, $allowed_type) )
			return new \WP_Error('file_format', 'Sorry, this file format is not permitted');

		$name = preg_replace("/[^A-Z0-9._-]/i", "_", basename( $file['name']) );

		$target_file = '/uploads'.$path.'/'.uniqid().'_'.$name;
		$upload_dir = WP_CONTENT_DIR.'/uploads'.$path;

		if( !is_dir($upload_dir) )
			mkdir($upload_dir, 0777, true);

		if( !is_writable($upload_dir) )
			return new \WP_Error('right', 'Upload directory is not writable.');

		if( move_uploaded_file($file['tmp_name'], WP_CONTENT_DIR.$target_file) )
			return ['filename' => $target_file, 'original_filename' => basename( $file['name']), 'type' => $mime_type ];
		else
			return new \WP_Error('move', 'There was an error while writing the file.');
	}


	/**
	 * delete attachment reference on other blog
	 */
	public function updateAttachment($data, $attachment_ID )
	{
		if( $this->prevent_recurssion || !isset($_REQUEST['action']) || $_REQUEST['action'] != 'image-editor')
			return $data;

		$this->prevent_recurssion = true;

		global $wpdb;

		$main_site_id = get_main_network_id();
		$current_site_id = get_current_blog_id();

		$original_attachment_id = $main_site_id == $current_site_id ? $attachment_ID : get_post_meta( $attachment_ID, '_wp_original_attachment_id', true );

		foreach ( get_sites() as $site ) {

			if ( (int) $site->blog_id !== $current_site_id ) {

				switch_to_blog( $site->blog_id );

				if( $main_site_id == $site->blog_id )
				{
					wp_update_attachment_metadata($attachment_ID, $data);
				}
				elseif( $original_attachment_id )
				{
					$results = $wpdb->get_results( "select `post_id` from $wpdb->postmeta where `meta_value` = '$original_attachment_id' AND `meta_key` = '_wp_original_attachment_id'", ARRAY_A );

					if( !empty($results) )
						wp_update_attachment_metadata($results[0]['post_id'], $data);
				}
			}
		}

		restore_current_blog();

		$this->prevent_recurssion = false;

		return $data;
	}


	/**
	 * delete attachment reference on other blog
	 */
	public function deleteAttachment( $attachment_ID )
	{
		if( $this->prevent_recurssion )
			return;

		$this->prevent_recurssion = true;

		global $wpdb;

		$main_site_id = get_main_network_id();
		$current_site_id = get_current_blog_id();

		$original_attachment_id = $main_site_id == $current_site_id ? $attachment_ID : get_post_meta( $attachment_ID, '_wp_original_attachment_id', true );

		foreach ( get_sites() as $site ) {

			if ( (int) $site->blog_id !== $current_site_id ) {

				switch_to_blog( $site->blog_id );

				if( $main_site_id == $site->blog_id )
				{
					wp_delete_attachment($original_attachment_id);
				}
				elseif( $original_attachment_id )
				{
					$results = $wpdb->get_results( "select `post_id` from $wpdb->postmeta where `meta_value` = '$original_attachment_id' AND `meta_key` = '_wp_original_attachment_id'", ARRAY_A );
					if( !empty($results) )
						wp_delete_attachment($results[0]['post_id']);
				}

			}
		}

		restore_current_blog();

		$this->prevent_recurssion = false;
	}


	/**
	 * add attachment to other blog by reference
	 */
	public function addAttachment( $attachment_ID )
	{
		if( $this->prevent_recurssion )
			return;

		$this->prevent_recurssion = true;

		$attachment = get_post( $attachment_ID );
		$current_site_id = get_current_blog_id();
		$main_site_id = get_main_network_id();

		$attr = [
			'post_mime_type' => $attachment->post_mime_type,
			'filename'       => $attachment->guid,
			'post_title'     => $attachment->post_title,
			'post_status'    => $attachment->post_status,
			'post_parent'    => 0,
			'post_content'   => $attachment->post_content,
			'guid'           => $attachment->guid,
			'post_date'      => $attachment->post_date
		];

		$file = get_attached_file( $attachment_ID );
		$attachment_metadata = wp_get_attachment_metadata( $attachment_ID );

		if( !$attachment_metadata )
			$attachment_metadata = wp_generate_attachment_metadata( $attachment_ID, $file );

		$original_id = false;

		foreach ( get_sites() as $site ) {

			if ( (int) $site->blog_id !== $current_site_id ) {

				switch_to_blog( $site->blog_id );

				// check if post is allready synced
				$attachment = get_posts(['post_type'=>'attachment', 'meta_key' => '_wp_original_attachment_id', 'meta_value' => $attachment_ID, 'fields'=>'ids']);

				if( !count($attachment) )
				{
					// check if a post with the same file exist
					$attachment = get_posts(['post_type'=>'attachment','fields'=>'ids',
						'meta_query' => [
							'relation' => 'AND',
							[
								'key'     => '_wp_attached_file',
								'value'   => $attachment_metadata['file']
							],
							[
								'key'     => '_wp_original_attachment_id',
								'compare' => 'NOT EXISTS'
							]
						]
					]);

					if( !count($attachment) )
					{
						$inserted_id = wp_insert_attachment( $attr, $file );
						if ( !is_wp_error($inserted_id) )
						{
							wp_update_attachment_metadata( $inserted_id, $attachment_metadata );

							if( $main_site_id != $site->blog_id )
								add_post_meta( $inserted_id, '_wp_original_attachment_id', $attachment_ID );
							else
								$original_id = $inserted_id;
						}
					}
					else
					{
						if( $main_site_id != $site->blog_id )
							add_post_meta( $attachment[0], '_wp_original_attachment_id', $attachment_ID );
						else
							$original_id = $attachment[0];
					}
				}
				else
				{
					if( $main_site_id != $site->blog_id )
						$original_id = $attachment[0];
				}
			}
		}

		restore_current_blog();

		if( $main_site_id != $current_site_id && $original_id )
			add_post_meta( $attachment_ID, '_wp_original_attachment_id', $original_id );

		$this->prevent_recurssion = false;
	}


	/**
	 * add network parameters
	 */
	public function wpmuOptions()
	{
		// Remove generated thumbnails option
		$thumbnails = $this->getThumbnails(true);

		if( count($thumbnails) )
		{
			echo '<h2>Images</h2>';
			echo '<table id="thumbnails" class="form-table"><tbody>';
			echo '<tr>
				<th scope="row">'.__('Generated thumbnails').'</th>
				<td><a class="button button-primary" href="'.get_admin_url().'?clear_all_thumbnails">Remove '.count($thumbnails).' images</a></td>
			</tr>';

			if( $this->config->get('multisite.shared_media') )
			echo '<tr>
				<th scope="row">'.__('Multisite').'</th>
				<td><a class="button button-primary" href="'.get_admin_url().'?syncronize_images">Synchronize images</a></td>
			</tr>';

			echo '</tbody></table>';
		}
	}


	/**
	 * add admin parameters
	 */
	public function adminInit()
	{
		if( isset($_GET['clear_thumbnails']) )
			$this->clearThumbnails();

		if( isset($_GET['clear_all_thumbnails']) )
			$this->clearThumbnails(true);

		if( isset($_GET['syncronize_images']) )
			$this->syncMedia();

		// Remove generated thumbnails option
		add_settings_field('clean_image_thumbnails', __('Generated thumbnails'), function(){

			$thumbnails = $this->getThumbnails();

			if( count($thumbnails) )
				echo '<a class="button button-primary" href="'.get_admin_url().'?clear_thumbnails">'.__('Remove').' '.count($thumbnails).' images</a>';
			else
				echo __('Nothing to remove');

		}, 'media');

	}


	/**
	 * Remove all thumbnails
	 */
	private function getThumbnails($all=false)
	{
		$folder = wp_upload_dir();
		$folder = $folder['basedir'];

		if( is_multisite() && get_current_blog_id() != 1 && !$this->config->get('multisite.shared_media') && !$all )
			$folder = $folder. '/sites/' . get_current_blog_id() . '/';

		$file_list = [];

		if( is_dir($folder) )
		{
			$dir = new \RecursiveDirectoryIterator($folder);
			$ite = new \RecursiveIteratorIterator($dir);
			$files = new \RegexIterator($ite, '/(?!.*150x150).*-[0-9]+x[0-9]+(-c-default|-c-center)?\.[a-z]{3,4}$/', \RegexIterator::GET_MATCH);
			$file_list = [];

			foreach($files as $file)
				$file_list[] = $file[0];
		}

		return $file_list;
	}


	/**
	 * Remove all thumbnails
	 */
	private function clearThumbnails($all=false)
	{
		if ( current_user_can('administrator') && (!$all || is_super_admin()) )
		{
			$thumbnails = $this->getThumbnails($all);

			foreach($thumbnails as $file)
				unlink($file);
		}

		clearstatcache();

		wp_redirect( get_admin_url(null, $all?'network/settings.php':'options-media.php') );
	}


	/**
	 * Synchronize media across multisite instance
	 */
	private function syncMedia()
	{
		if ( current_user_can('administrator') && is_super_admin() )
		{
			set_time_limit(0);
			
			$main_site_id = get_main_network_id();

			global $wpdb;

			switch_to_blog( $main_site_id );
			$results = $wpdb->delete( $wpdb->postmeta, ['meta_key' => '_wp_original_attachment_id']);
			restore_current_blog();

			$network_site_url = trim(network_site_url(), '/');

			foreach ( get_sites() as $site ) {

				switch_to_blog( $site->blog_id );

				//clean guid
				$home_url = get_home_url();
				$wpdb->query("UPDATE $wpdb->posts SET `guid` = REPLACE(guid, '$network_site_url$home_url', '$network_site_url') WHERE `guid` LIKE '$network_site_url$home_url%'");
				$wpdb->query("UPDATE $wpdb->posts SET `guid` = REPLACE(guid, '$home_url', '$network_site_url') WHERE `guid` LIKE '$home_url%' and `post_type`='attachment'");

				$original_attachment_ids = get_posts(['post_type'=>'attachment', 'meta_key' => '_wp_original_attachment_id', 'meta_compare' => 'NOT EXISTS', 'posts_per_page' => -1, 'fields'=>'ids']);

				foreach ($original_attachment_ids as $original_attachment_id)
					$this->addAttachment($original_attachment_id);
			}

			restore_current_blog();
		}

		wp_redirect( get_admin_url(null, 'network/settings.php') );
	}

	
	/**
	 * Redefine upload dir
	 * @see Menu
	 */
	public function uploadDir($dirs)
	{
		$dirs['baseurl'] = str_replace($dirs['relative'],'/uploads', $dirs['baseurl']);
		$dirs['basedir'] = str_replace($dirs['relative'],'/uploads', $dirs['basedir']);

		$dirs['url']  = str_replace($dirs['relative'],'/uploads', $dirs['url']);
		$dirs['path'] = str_replace($dirs['relative'],'/uploads', $dirs['path']);

		$dirs['relative'] = '/uploads';

		return $dirs;
	}

	public static function add_relative_upload_dir_key( $arr )
	{
		$arr['url'] = str_replace('edition/../', '', $arr['url']);
		$arr['baseurl'] = str_replace('edition/../', '', $arr['baseurl']);
		$arr['relative'] = str_replace(get_home_url(), '', $arr['baseurl']);

		return $arr;
	}


	public function __construct($config)
	{
		$this->config = $config;

		add_filter('upload_dir', [$this, 'add_relative_upload_dir_key'], 10, 2);

		if( $this->config->get('multisite.shared_media') and is_multisite() )
			add_filter( 'upload_dir', [$this, 'uploadDir'], 11 );

		if( is_admin() )
		{
			add_action( 'admin_init', [$this, 'adminInit'] );
			add_action( 'wpmu_options', [$this, 'wpmuOptions'] );

			// Replicate media on network
			if( $this->config->get('multisite.shared_media') and is_multisite() )
			{
				add_action( 'add_attachment', [$this, 'addAttachment']);
				add_action( 'delete_attachment', [$this, 'deleteAttachment']);
				add_filter( 'wp_update_attachment_metadata', [$this, 'updateAttachment'], 10, 2);
				add_filter( 'wpmu_delete_blog_upload_dir', '__return_false' );
			}
		}
	}
}
