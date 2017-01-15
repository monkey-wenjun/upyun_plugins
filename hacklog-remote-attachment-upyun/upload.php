<?php
/**
 * @package Hacklog Remote Attachment Upyun
 * @encoding UTF-8
 * @author 荒野无灯 <HuangYeWuDeng>
 * @link http://ihacklog.com
 * @copyright Copyright (C) 2012 荒野无灯
 * @license http://www.gnu.org/licenses/
 * @description
 * upload file via UpYun form API.
 */


define( 'IFRAME_REQUEST' , true );

/** Load WordPress Administration Bootstrap */
$bootstrap_file = dirname(__FILE__). '/includes/bootstrap_compatible.php' ;
if (file_exists( $bootstrap_file ))
{
	require $bootstrap_file;
}
else
{
	echo '<p>Failed to load bootstrap.</p>';
	exit;
}


if (!current_user_can('upload_files'))
	wp_die(__('You do not have permission to upload files.'));

wp_enqueue_script('plupload-handlers');
wp_enqueue_script('image-edit');
wp_enqueue_script('set-post-thumbnail' );
wp_enqueue_style('imgareaselect');

@header('Content-Type: ' . get_option('html_type') . '; charset=' . get_option('blog_charset'));

// IDs should be integers
$ID = isset($ID) ? (int) $ID : 0;
$post_id = isset($_REQUEST['post_id'])? (int) $_REQUEST['post_id'] : 0;

// Require an ID for the edit screen
if ( isset($action) && $action == 'edit' && !$ID )
	wp_die(__("You are not allowed to be here"));

if($post_id <= 0)
{
	wp_die(__("You are not allowed to be here"));
}
else
{

	add_filter('media_upload_tabs', 'hacklogra_upyun_upload_tags');
	// upload type: image, video, file, ..?
	if ( isset($_GET['type']) )
		$type = strval($_GET['type']);
	else
		$type = apply_filters('media_upload_default_type', 'file');

	// tab: gallery, library, or type-specific
	if ( isset($_GET['tab']) )
		$tab = strval($_GET['tab']);
	else
		$tab = apply_filters('media_upload_default_tab', 'type');

	$body_id = 'media-upload';

	if( $tab == 'type')
	{
		hacklogra_upyun_media_upload_handler();
	}
	else
	{
		// let the action code decide how to handle the request
		if ( $tab == 'type_url' || !array_key_exists( $tab , media_upload_tabs() ) )
		{
			do_action("media_upload_$type");
		}
		else
		{
			do_action("media_upload_$tab");
		}
	}

}


/**
 * {@internal Missing Short Description}}
 *
 * @since 1.4.0
 *
 * @return unknown
 */
function hacklogra_upyun_media_upload_handler()
{
	global $is_iphone;

	$errors = array();
	$id = 0;

	if ( isset($_GET['code']) && isset($_GET['message']) && isset($_GET['url']) && isset($_GET['time']) )
	{
		$id = hacklogra_upyun::handle_form_api_upload($_REQUEST['post_id'], $post_data = array() );
		unset($_FILES);
		if ( is_wp_error($id) )
		{
			$errors['upload_error'] = $id;
			$id = false;
		}
	}

	if ( !empty($_POST['insertonlybutton']) )
	{
		$src = $_POST['src'];
		if ( !empty($src) && !strpos($src, '://') )
			$src = "http://$src";

		if ( isset( $_POST['media_type'] ) && 'image' != $_POST['media_type'] ) {
			$title = esc_html( stripslashes( $_POST['title'] ) );
			if ( empty( $title ) )
				$title = esc_html( basename( $src ) );

			if ( $title && $src )
				$html = "<a href='" . esc_url($src) . "'>$title</a>";

			$type = 'file';
			if ( ( $ext = preg_replace( '/^.+?\.([^.]+)$/', '$1', $src ) ) && ( $ext_type = wp_ext2type( $ext ) )
				&& ( 'audio' == $ext_type || 'video' == $ext_type ) )
					$type = $ext_type;

			$html = apply_filters( $type . '_send_to_editor_url', $html, esc_url_raw( $src ), $title );
		} else {
			$align = '';
			$alt = esc_attr( stripslashes( $_POST['alt'] ) );
			if ( isset($_POST['align']) ) {
				$align = esc_attr( stripslashes( $_POST['align'] ) );
				$class = " class='align$align'";
			}
			if ( !empty($src) )
				$html = "<img src='" . esc_url($src) . "' alt='$alt'$class />";

			$html = apply_filters( 'image_send_to_editor_url', $html, esc_url_raw( $src ), $alt, $align );
		}

		return media_send_to_editor($html);
	}

	if ( !empty($_POST) ) {
		$return = media_upload_form_handler();

		if ( is_string($return) )
			return $return;
		if ( is_array($return) )
			$errors = $return;
	}

	if ( isset($_POST['save']) ) {
		$errors['upload_notice'] = __('Saved.');
		return media_upload_gallery();
	}

	if ( isset($_GET['tab']) && $_GET['tab'] == 'type_url' ) {
		$type = 'image';
		if ( isset( $_GET['type'] ) && in_array( $_GET['type'], array( 'video', 'audio', 'file' ) ) )
			$type = $_GET['type'];
		return wp_iframe( 'media_upload_type_url_form', $type, $errors, $id );
	}

	if ( $is_iphone )
		return wp_iframe( 'media_upload_type_url_form', 'image', $errors, $id );
	else
		return wp_iframe( array('hacklogra_upyun', 'media_upload_type_form_upyun'), 'file', $errors, $id );
}


function hacklogra_upyun_media_upload_form( $errors = null )
{
	global $type, $tab, $pagenow, $is_IE, $is_opera, $is_iphone;

	if ( $is_iphone )
		return;

	$post_id = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
	$_type = isset($type) ? $type : '';
	$_tab = isset($tab) ? $tab : '';

	$upload_size_unit = $max_upload_size = Filesystem_Upyun::FORM_API_MAX_CONTENT_LENGTH;
	$sizes = array( 'KB', 'MB', 'GB' );

	for ( $u = -1; $upload_size_unit > 1024 && $u < count( $sizes ) - 1; $u++ ) {
		$upload_size_unit /= 1024;
	}

	if ( $u < 0 ) {
		$upload_size_unit = 0;
		$u = 0;
	} else {
		$upload_size_unit = (int) $upload_size_unit;
	}
?>

<div id="media-upload-notice">
<?php
	if(isset($_GET['code']) && isset($_GET['message'])) {
		$errors['upload_notice'] = 'Upyun reply: code '. $_GET['code'] . ', message: '. $_GET['message'];
	}
	if (isset($errors['upload_notice']) )
		echo $errors['upload_notice'];

?></div>
<div id="media-upload-error" style="background-color:#FFFFE0;border-color:#E6DB55;color:#F00;">
<?php
	if (isset($errors['upload_error']) && is_wp_error($errors['upload_error']))
	{
		echo $errors['upload_error']->get_error_message();
		$location_href = plugins_url('upload.php?post_id=' . $post_id .'&TB_iframe=1&width=640&height=451', __FILE__);
		echo '<a href="#" onclick="location.href=\''. $location_href .'\';return false;">Retry</a>';
	}
?>
</div>


<div id="html-upload-ui" class="hide-if-js">
	<p id="async-upload-wrap">
		<label class="screen-reader-text" for="async-upload"><?php _e('Upload'); ?></label>
		<input type="file" name="file" id="async-upload" />
		<?php submit_button( __( 'Upload' ), 'button', 'html-upload', false ); ?>
		<a href="#" onclick="try{top.tb_remove();}catch(e){}; return false;"><?php _e('Cancel'); ?></a>
	</p>
	<div class="clear"></div>
</div>

<div id="fileInfo" style="display:none; margin:10px auto;padding:10px 10px 5px;overflow: hidden;border-radius:10px;-moz-border-radius: 10px;border: 1px solid #ccc;box-shadow: 0 0 5px #ccc;background-image: -moz-linear-gradient(top, #ff9900, #c77801);background-image: -webkit-gradient(linear, left top, left bottom, from(#ff9900), to(#c77801));">
	<ul style="margin:0;">
		<li id="fileName"></li>
		<li id="fileSize"></li>
		<li id="fileType"></li>
	</ul>
</div>


<span class="max-upload-size"><?php printf( __( 'Maximum upload file size: %d%s.' ), esc_html($upload_size_unit), esc_html($sizes[$u]) ); ?></span>
<?php
if ( ($is_IE || $is_opera) && $max_upload_size > 100 * 1024 * 1024 ) { ?>
	<span class="big-file-warning"><?php _e('Your browser has some limitations uploading large files with the multi-file uploader. Please use the browser uploader for files over 100MB.'); ?></span>
<?php }

}

function hacklogra_upyun_upload_tags($_default_tabs)
{
	$_default_tabs['type'] =__('via UpYun Form API', hacklogra_upyun::textdomain );
	return $_default_tabs;
}

?>
