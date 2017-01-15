<?php
/**
 * @package Hacklog Remote Attachment Upyun
 * @encoding UTF-8
 * @author 荒野无灯 <HuangYeWuDeng>
 * @link http://ihacklog.com
 * @copyright Copyright (C) 2012 荒野无灯
 * @license http://www.gnu.org/licenses/
 */
if (!defined('ABSPATH')) {
    die('What are you doing?');
}

require __DIR__ . '/../filesystem/upyun.php';

class hacklogra_upyun
{
    const textdomain = 'hacklog-remote-attachment';
    const plugin_name = 'Hacklog Remote Attachment Upyun';
    //the same
    const opt_space = 'hacklogra_remote_filesize';
    //new option name
    const opt_primary = 'hacklogra_upyun_options';
    const version = '1.4.4-upyun-ported-from-1.2.6-origin';
    private static $img_ext = array('jpg', 'jpeg', 'png', 'gif', 'bmp');
    private static $rest_user = 'admin';
    private static $rest_pwd = '4d4173594c77453d';
    private static $rest_server = 'v0.api.upyun.com';
    private static $bucketname = '';
    private static $form_api_secret = '';
    private static $form_api_content_max_length = 100;
    private static $form_api_allowed_ext = 'jpg,jpeg,gif,png,doc,pdf,zip,rar,tar.gz,tar.bz2,7z';
    private static $form_api_timeout = 300;
    private static $anti_leech_token = '';
    private static $anti_leech_timeout = 600;
    private static $rest_port = 80;
    private static $rest_timeout = 30;
    private static $subdir = '';
    private static $rest_remote_path = 'wp-files';
    private static $http_remote_path = 'wp-files';
    private static $remote_url = '';
    private static $remote_baseurl = '';
    private static $local_basepath = '';
    private static $local_path = '';
    private static $local_url = '';
    private static $local_baseurl = '';
    /**
     * @var Filesystem_Upyun
     */
    private static $fs = null;
    private static $is_form_api_upload = false;

    public function __construct()
    {
        self::init();
        //this should always check
        add_action('admin_notices', array(__CLASS__, 'check_rest_connection'));
        //should load before 'admin_menu' hook ... so,use init hook
        add_action('init', array(__CLASS__, 'load_textdomain'));
        //menu
        add_action('admin_menu', array(__CLASS__, 'plugin_menu'));
        //HOOK the upload, use init to support xmlrpc upload
        add_action('init', array(__CLASS__, 'admin_init'));
        //frontend filter,filter on image only
        add_filter('wp_get_attachment_url', array(__CLASS__, 'replace_baseurl'), -999);
        add_action('wp_ajax_hacklogra_upyun_signature', array(__CLASS__, 'return_signature'));
        add_action('media_buttons', array(__CLASS__, 'add_media_button'), 11);
        add_action('plugin_action_links_' . plugin_basename(HACKLOG_RA_UPYUN_LOADER), array(__CLASS__, 'add_plugin_actions'));
        empty(self::$anti_leech_token) || add_filter('the_content', array(__CLASS__, 'sign_post_url'));
        self::setup_rest();
//        self::$fs->debug  = true;
    }

############################## PRIVATE FUNCTIONS ##############################################

    private static function update_options()
    {
        $value = self::get_default_opts();
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!empty($_POST[$key])) {
                $value[$key] = addslashes(trim($_POST[$key]));
            }
        }
        $value['remote_baseurl'] = rtrim($value['remote_baseurl'], '/');
        $value['rest_remote_path'] = rtrim($value['rest_remote_path'], '/');
        $value['http_remote_path'] = rtrim($value['http_remote_path'], '/');
        if (update_option(self::opt_primary, $value))
            return TRUE;
        else
            return FALSE;
    }

    /**
     * get file extension
     * @static
     * @param $path
     * @return mixed
     */
    private static function get_ext($path)
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * to see if a file is an image file.
     * @static
     * @param $path
     * @return bool
     */
    private static function is_image_file($path)
    {
        return in_array(self::get_ext($path), self::$img_ext);
    }

    /**
     * get the default options
     * @static
     * @return array
     */
    private static function get_default_opts()
    {
        return array(
            'rest_user' => self::$rest_user,
            'rest_pwd' => self::$rest_pwd,
            'form_api_secret' => self::$form_api_secret,
            'form_api_content_max_length' => self::$form_api_content_max_length,
            'form_api_allowed_ext' => self::$form_api_allowed_ext,
            'form_api_timeout' => self::$form_api_timeout,
            'anti_leech_token' => self::$anti_leech_token,
            'anti_leech_timeout' => self::$anti_leech_timeout,
            'rest_server' => self::$rest_server,
            'bucketname' => self::$bucketname,
            'rest_port' => self::$rest_port,
            'rest_timeout' => self::$rest_timeout,
            'rest_remote_path' => self::$rest_remote_path,
            'http_remote_path' => self::$http_remote_path,
            'remote_baseurl' => self::$remote_baseurl,
        );
    }

    /**
     * increase the filesize,keep the filesize tracked.
     * @static
     * @param $file
     * @return void
     */
    private static function update_filesize_used($file)
    {
        if (file_exists($file)) {
            $filesize = filesize($file);
            $previous_value = get_option(self::opt_space);
            $to_save = $previous_value + $filesize;
            update_option(self::opt_space, $to_save);
        }
    }

    /**
     * decrease the filesize when a remote file is deleted.
     * @static
     * @param $fs
     * @param $file
     * @return void
     */
    private static function decrease_filesize_used($fs, $file)
    {
        if ($fs->exists($file)) {
            $filesize = $fs->size($file);
            $previous_value = get_option(self::opt_space);
            $to_save = $previous_value - $filesize;
            update_option(self::opt_space, $to_save);
        }
    }

    /**
     * like  wp_handle_upload_error in file.php under wp-admin/includes
     * @param $message
     * @return array
     */
    public static function handle_upload_error($message)
    {
        return array('error' => $message);
    }

    public static function xmlrpc_error($errorString = '')
    {
        return new IXR_Error(500, $errorString);
    }

    /**
     * report upload error
     * @return type
     */
    private static function raise_upload_error()
    {
        $error_str = sprintf('%s:' . __('upload file to remote server failed!', self::textdomain), self::plugin_name);
        if (defined('XMLRPC_REQUEST')) {
            return self::xmlrpc_error($error_str);
        } else {
            return call_user_func(array(__CLASS__, 'handle_upload_error'), $error_str);
        }
    }

    /**
     * report rest connection error
     * @return type
     */
    private static function raise_connection_error()
    {
        $error_message = 'Unknown Error!';
        $all_errors = self::$fs->errors->get_error_messages();
        if (count($all_errors) > 0) {
            $error_message = implode('|', $all_errors);
        }
        $error_message = '<span style="color:#F00;">' . $error_message . '</span>';
        $error_str = sprintf('%s:' . $error_message, self::plugin_name);
        if (defined('XMLRPC_REQUEST')) {
            return self::xmlrpc_error($error_str);
        } else {
            return call_user_func(array(__CLASS__, 'handle_upload_error'), $error_str);
        }
    }

############################## PUBLIC FUNCTIONS ##############################################
    /**
     * init
     * @static
     * @return void
     */

    public static function init()
    {
        register_activation_hook(HACKLOG_RA_UPYUN_LOADER, array(__CLASS__, 'my_activation'));
        register_deactivation_hook(HACKLOG_RA_UPYUN_LOADER, array(__CLASS__, 'my_deactivation'));
        $opts = get_option(self::opt_primary);
        self::$rest_user = $opts['rest_user'];
        self::$rest_pwd = $opts['rest_pwd'];
        self::$form_api_secret = $opts['form_api_secret'];
        self::$form_api_allowed_ext = $opts['form_api_allowed_ext'];
        self::$form_api_content_max_length = $opts['form_api_content_max_length'];
        self::$form_api_timeout = $opts['form_api_timeout'];
        self::$anti_leech_token = $opts['anti_leech_token'];
        self::$anti_leech_timeout = $opts['anti_leech_timeout'];
        self::$rest_server = $opts['rest_server'];
        self::$bucketname = $opts['bucketname'];
        self::$rest_port = $opts['rest_port'];
        self::$rest_timeout = $opts['rest_timeout'] > 0 ? $opts['rest_timeout'] : 30;

        $opts['rest_remote_path'] = rtrim($opts['rest_remote_path'], '/');
        $opts['http_remote_path'] = rtrim($opts['http_remote_path'], '/');
        $opts['remote_baseurl'] = rtrim($opts['remote_baseurl'], '/');
        $upload_dir = wp_upload_dir();
        //be aware of / in the end
        self::$local_basepath = $upload_dir['basedir'];
        self::$local_path = $upload_dir['path'];
        self::$local_baseurl = $upload_dir['baseurl'];
        self::$local_url = $upload_dir['url'];
        self::$subdir = $upload_dir['subdir'];
        //if the post publish date was different from the media upload date,the time should take from the database.
        if (get_option('uploads_use_yearmonth_folders') && isset($_REQUEST['post_id'])) {
            $post_id = (int)$_REQUEST['post_id'];
            if ($post = get_post($post_id)) {
                if (substr($post->post_date, 0, 4) > 0) {
                    $time = $post->post_date;
                    $y = substr($time, 0, 4);
                    $m = substr($time, 5, 2);
                    $subdir = "/$y/$m";
                    self::$subdir = $subdir;
                }
            }
        }
        //后面不带 /
        self::$rest_remote_path = $opts['rest_remote_path'] == '.' ? '' : $opts['rest_remote_path'];
        self::$http_remote_path = $opts['http_remote_path'] == '.' ? '' : $opts['http_remote_path'];
        //此baseurl与options里面的不同！
        self::$remote_baseurl = '.' == self::$http_remote_path ? $opts['remote_baseurl'] :
            $opts['remote_baseurl'] . '/' . self::$http_remote_path;
        self::$remote_url = self::$remote_baseurl . self::$subdir;
    }

    public static function handle_form_api_upload($post_id, $post_data = array())
    {
        if (!self::setup_rest()) {
            return new WP_Error('hacklogra_internal_error', 'failed to contruct Upyun class');
        }
        self::$is_form_api_upload = true;
        //check_admin_referer('media-form');
        // Upload File button was clicked
        if (self::$fs->check_form_api_internal_error()) {
            $id = new WP_Error('upyun_internal_error', __('Upyun internal Error!'));
        } else
            if ($_GET['code'] == '200') {
                if (self::$fs->check_form_api_return_param()) {
                    $url = self::$remote_baseurl . ltrim($_GET['url'], '/');
                    //error_log(var_export(array(self::$remote_baseurl ,$_GET['url']),true));
                    $filename = basename($_GET['url']);
                    $ft = wp_check_filetype($filename, null);
                    $type = $ft['type'];
                    $file = str_replace('/' . self::$http_remote_path . '/', '', $_GET['url']);
                    $name_parts = pathinfo($filename);
                    $name = trim(substr($filename, 0, -(1 + strlen($name_parts['extension']))));
                    $title = $name;
                    $content = '';
                    // Construct the attachment array
                    $attachment = array_merge(array(
                        'post_mime_type' => $type,
                        'guid' => $url,
                        'post_parent' => $post_id,
                        'post_title' => $title,
                        'post_content' => $content,
                    ), $post_data);

                    // This should never be set as it would then overwrite an existing attachment.
                    if (isset($attachment['ID']))
                        unset($attachment['ID']);

                    // Save the data
                    $id = wp_insert_attachment($attachment, $file, $post_id);
                    if (!is_wp_error($id)) {
                        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
                    }

                }
            } else {
                $id = new WP_Error('upyun_upload_error', $_GET['message']);
            }
        return $id;
    }


    public static function return_signature()
    {
        if (!self::setup_rest()) {
            die(json_encode(array('error' => 'yes', 'message' => 'failed to contruct Upyun class')));
        }
        $post_id = $_POST['post_id'];
        $filename = basename($_POST['file']);
        $unique_filename = self::unique_filename(self::$rest_remote_path . self::$subdir, $filename);
        $policy = self::$fs->build_policy(
            array('path' => '/' . self::$rest_remote_path . self::$subdir . '/' . $unique_filename,
                'return_url' => plugins_url('upload.php?post_id=' . $post_id, HACKLOG_RA_UPYUN_LOADER),
                'bucket' => self::$bucketname,
            ));
        $signature = self::$fs->get_form_api_signature($policy);
        header("Content-Type: text/json;Charset=UTF-8");
        die(json_encode(array('policy' => $policy, 'signature' => $signature, 'error' => 'none')));
    }

    public static function add_media_button($editor_id = 'content')
    {
        global $post_ID;
        $url = plugins_url("upload.php?post_id={$post_ID}&TB_iframe=1&width=640&height=451&tab=upyun", HACKLOG_RA_UPYUN_LOADER);
        $admin_icon = plugins_url('images/upyun_icon.png', HACKLOG_RA_UPYUN_LOADER);
        if (is_ssl()) {
            $url = str_replace('http://', 'https://', $url);
        }
        $alt = __('Upload local file to Upyun server', self::textdomain);
        $img = '<img src="' . esc_url($admin_icon) . '" width="15" height="15" alt="' . esc_attr($alt) . '" />';

        echo '<a href="' . esc_url($url) . '" class="thickbox hacklogra-upyun-button" id="' . esc_attr($editor_id) . '-hacklogra-upyun" title="' . esc_attr__('Hacklog Remote Attachment Upyun', self::textdomain) . '" onclick="return false;">' . $img . '</a>';
    }

    public static function media_upload_type_form_upyun($type = 'file', $errors = null, $id = null)
    {

        $post_id = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
        if (!self::connect_remote_server()) {
            return self::raise_connection_error();
        }

        media_upload_header();

        $upyun_form_action_url = 'http://' . self::$fs->get_api_domain() . '/' . self::$bucketname . '/';
        if (isset($_GET['code']) && isset($_GET['message']) && isset($_GET['url']) && isset($_GET['time']) && isset($_GET['sign'])) {
            $form_action_url = plugins_url('upload.php?post_id=' . $post_id . '&TB_iframe=1&width=640&height=451', HACKLOG_RA_UPYUN_LOADER);
        } else {
            $form_action_url = $upyun_form_action_url;
        }

        $form_class = 'media-upload-form type-form validate';

        //if ( get_user_setting('uploader') )
        $form_class .= ' html-uploader';
        ?>

        <form enctype="multipart/form-data" method="post" action="<?php echo esc_attr($form_action_url); ?>"
              class="<?php echo $form_class; ?>" id="<?php echo $type; ?>-form">
            <?php submit_button('', 'hidden', 'save', false); ?>
            <input type="hidden" name="post_id" id="post_id" value="<?php echo (int)$post_id; ?>"/>
            <?php wp_nonce_field('media-form'); ?>

            <input type="hidden" id="policy" name="policy" value="">
            <input type="hidden" id="signature" name="signature" value="">
            <h3 class="media-title"><?php _e('Add media files from your computer'); ?></h3>
            <p style="border:3px dotted #ccc;padding:5px;">
                <?php _e('I advise you to upload images via click WordPress original add media button.', self::textdomain); ?>
                <br/>
                <?php _e('This page was designed for uploading big files to UpYun Server.', self::textdomain); ?>
            </p>
            <?php hacklogra_upyun_media_upload_form($errors); ?>

            <script type="text/javascript">
                //<![CDATA[
                jQuery(function ($) {
                    var preloaded = $(".media-item.preloaded");
                    if (preloaded.length > 0) {
                        preloaded.each(function () {
                            prepareMediaItem({id: this.id.replace(/[^0-9]/g, '')}, '');
                        });
                    }
                    updateMediaForm();
                });
                //]]>
            </script>

            <div id="media-items">
                <?php
                if ($id) {
                    if (!is_wp_error($id)) {
                        add_filter('attachment_fields_to_edit', 'media_post_single_attachment_fields_to_edit', 10, 2);
                        echo get_media_items($id, $errors);
                    } else {
                        echo '<div id="media-upload-error">' . esc_html($id->get_error_message()) . '</div></div>';
                        exit;
                    }
                }
                ?></div>

            <p class="savebutton ml-submit">
                <?php submit_button(__('Save all changes'), 'button', 'save', false); ?>
            </p>
        </form>
        <script type="text/javascript">
            jQuery(function ($) {
                $('#html-upload').attr('disabled', true);
                var set_upyun_form_api_action_url = function () {
                    $('#file-form').attr('action', '<?php echo $upyun_form_action_url;?>');
                };

                var set_fileinfo = function () {
                    var file = $('#async-upload')[0].files[0];
                    if (file == undefined) {
                        return false;
                    }
                    var fileSize = 0;

                    if (file.size > 1024 * 1024)
                        fileSize = (Math.round(file.size * 100 / (1024 * 1024)) / 100).toString() + ' MB';
                    else
                        fileSize = (Math.round(file.size * 100 / 1024) / 100).toString() + ' KB';

                    document.getElementById('fileInfo').style.display = 'block';
                    document.getElementById('fileName').innerHTML = '<strong>Name</strong>: ' + file.name;
                    document.getElementById('fileSize').innerHTML = '<strong>Size</strong>: ' + fileSize;
                    document.getElementById('fileType').innerHTML = '<strong>Type</strong>: ' + file.type;
                    return file.name;
                };
                $('#async-upload').change(function () {
                        var filename = $('#async-upload').val();
                        if (filename == '') {
                            alert('Please choose a file!');
                            $('#html-upload').attr('disabled', true);
                            return false;
                        }
                        file_basename = set_fileinfo();
                        filename = !file_basename ? file_basename : filename;
                        $.ajax(
                            {
                                url: ajaxurl,
                                type: 'post',
                                data: {
                                    'action': 'hacklogra_upyun_signature',
                                    'post_id': '<?php echo $post_id;?>',
                                    'file': filename,
                                    '_wpnonce': $('#_wpnonce').val()
                                },
                                dataType: 'json',
                                async: false,
                                cache: false,
                                timeout: 5 * 1000,
                                success: function (data, textStatus) {
                                    if (data.error == 'yes') {
                                        alert('Connection eror!');
                                        return false;
                                    }
                                    //alert( 'policy: ' + data.policy + 'signature: ' + data.signature );
                                    $('#policy').val(data.policy);
                                    $('#signature').val(data.signature);
                                    set_upyun_form_api_action_url();
                                    $('#html-upload').attr('disabled', false);
                                    return true;
                                },
                                error: function (jqXHR, textStatus, errorThrown) {
                                    alert('error hook called. textStatus: ' + textStatus + "\n" + 'errorThrown: ' + errorThrown);
                                    $('#html-upload').attr('disabled', true);
                                    return false;
                                }
                            }
                        );
                        return false;
                    }
                );
            });
        </script>
        <?php
    }


    /**
     * do the stuff once the plugin is installed
     * @static
     * @return void
     */
    public static function my_activation()
    {
        add_option(self::opt_space, 0);
        $opt_primary = self::get_default_opts();
        add_option(self::opt_primary, $opt_primary);
    }

    /**
     * do cleaning stuff when the plugin is deactivated.
     * @static
     * @return void
     */
    public static function my_deactivation()
    {
        //delete_option(self::opt_space);
        //delete_option(self::opt_primary);
    }

    private static function get_opt($key, $defaut = '')
    {
        $opts = get_option(self::opt_primary);
        return isset($opts[$key]) ? $opts[$key] : $defaut;
    }

    private static function sign_url($url)
    {
        if ('.' !== self::$http_remote_path) {
            $baseurl = str_replace('/' . self::$http_remote_path, '', self::$remote_baseurl);
        }
        $file_path = substr($url, strlen($baseurl));
        $signed_url = $baseurl . '/' . self::$fs->set_anti_leech_token_sign_uri($file_path);
        return $signed_url;
    }


    public static function sign_post_url($content)
    {
        if (!empty(self::$anti_leech_token) && preg_match_all("@" . self::$remote_baseurl . "[^'\"\[]+@i", $content, $matches)) {
            if (isset($matches[0]) && count($matches[0]) > 0) {
                $urls = $matches[0];
                //strip the duplicaated urls
                $urls = array_unique($urls);
                self::setup_rest();
                foreach ($urls as $url) {
                    //the determine should be here ,not sign_url function
                    if (self::$fs->is_url_token_signed($url)) {
                        continue;
                    }
                    $signed_url = self::sign_url($url);
                    $content = str_replace($url, $signed_url, $content);
                }
            }
        }
        //var_dump($content);
        return $content;
    }

    public static function sign_attachment_url($url)
    {
        if (strcasecmp(substr($url, 0, strlen(self::$remote_baseurl)), self::$remote_baseurl) == 0) {
            self::setup_rest();
            $url = self::sign_url($url);
        }
        return $url;
    }

    /**
     * humanize file size.
     * @static
     * @param $bytes
     * @return string
     */
    public static function human_size($bytes)
    {
        $types = array('B', 'KB', 'MB', 'GB', 'TB');
        for ($i = 0; $bytes >= 1024 && $i < (count($types) - 1); $bytes /= 1024, $i++)
            ;
        return (round($bytes, 2) . " " . $types[$i]);
    }

    /**
     * load the textdomain on init
     * @static
     * @return void
     */
    public static function load_textdomain()
    {
        load_plugin_textdomain(self::textdomain, false, basename(dirname(HACKLOG_RA_UPYUN_LOADER)) . '/languages/');
    }

    /**
     * @since 1.2.1
     * note that admin_menu runs before admin_init
     */
    public static function admin_init()
    {
        //DO NOT HOOK the update or upgrade page for that they may upload zip file.
        $current_page = basename($_SERVER['SCRIPT_FILENAME']);
        switch ($current_page) {
            //	wp-admin/update.php?action=upload-plugin
            //	wp-admin/update.php?action=upload-theme
            case 'update.php':
                //update-core.php?action=do-core-reinstall
            case 'update-core.php':
                //JUST DO NOTHING ,SKIP.
                break;
            default:
                add_filter('wp_handle_upload', array(__CLASS__, 'upload_and_send'));
                add_filter('media_send_to_editor', array(__CLASS__, 'replace_attachurl'), -999);
                add_filter('wp_calculate_image_srcset', array(__CLASS__, 'replace_attachurl_srcset'), -999, 5);
                add_filter('attachment_link', array(__CLASS__, 'replace_baseurl'), -999);
                //生成缩略图后立即上传生成的文件并删除本地文件,this must after watermark generate
                add_filter('wp_update_attachment_metadata', array(__CLASS__, 'upload_images'), 999);
                //删除远程附件
                add_action('wp_delete_file', array(__CLASS__, 'delete_remote_file'));
                break;
        }
    }

    /**
     * set up rest connection
     * @static
     * @param $args
     * @return bool
     */
    public static function setup_rest($args = null)
    {
        //if object not inited.
        if (null == self::$fs) {
            $credentials = [
                'api_domain' => self::$rest_server,
                'timeout' => self::$rest_timeout,
                'bucketname' => self::$bucketname,
                'username' => self::$rest_user,
                'password' => self::$rest_pwd,
            ];
            $form_api_params = [
                'form_api_secret' => self::$form_api_secret,
                'form_api_allowed_ext' => self::$form_api_allowed_ext,
                'form_api_content_max_length' => self::$form_api_content_max_length,
                'form_api_timeout' => self::$form_api_timeout,
                'anti_leech_token' => self::$anti_leech_token,
                'anti_leech_timeout' => self::$anti_leech_timeout,
            ];
            if (null != $args) {
                $credentials = array_merge($credentials, $args);
            }
            self::$fs = new Filesystem_Upyun($credentials['bucketname'], $credentials['username'], $credentials['password'], $credentials['api_domain'], $credentials['timeout']);
            if (!self::$fs) {
                return false;
            }
            self::$fs->set_form_api_params($form_api_params);
        }
        return true;
    }

    /**
     * do connecting to server.DO NOT call this on any page that not needed!
     * if can not connect to remote server successfully,the plugin will refuse to work
     * @static
     * @return void
     */
    public static function connect_remote_server($args = null)
    {
        self::setup_rest();
        //just call the setup and test the authentication
        return self::$fs->connect();
    }

    /**
     * notice the user to setup the plugin options
     * MUST run after self::init();
     */
    public static function check_rest_connection()
    {
        $current_page = basename($_SERVER['SCRIPT_FILENAME']);
        if ('plugins.php' == $current_page) {
            if (!self::connect_remote_server()) {
                $error = self::raise_connection_error();
                $redirect_msg = sprintf(__('Click <a href="%s">here</a> to setup the plugin options.', self::textdomain), admin_url('options-general.php?page=' . md5(HACKLOG_RA_UPYUN_LOADER)));
                echo '<div class="error"><p><strong>' . $error['error'] . '<br />' . $redirect_msg . '</strong></p></div>';
            }
        }
    }

    /**
     * the hook is in function get_attachment_link()
     * @static
     * @param $html
     * @return mixed
     */
    public static function replace_attachurl($html)
    {
        $html = str_replace(self::$local_url, self::$remote_url, $html);
        return $html;
    }

    /**
     * @param $sources
     * @param $size_array
     * @param $image_src
     * @param $image_meta
     * @param $attachment_id
     * @return mixed
     */
    public static function replace_attachurl_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        $local_url = self::$local_url;
        // using the same logic as WP
        global $wp_version;

        if (version_compare($wp_version, "4.5", '>=') && is_ssl() && 'https' !== substr($local_url, 0, 5) && parse_url($local_url, PHP_URL_HOST) === $_SERVER['HTTP_HOST']) {
            $local_url = set_url_scheme($local_url, 'https');
        }
        foreach ((array)$sources as $index => $source) {
            $sources[$index]['url'] = str_replace($local_url, self::$remote_url, $source['url']);
        }
        return $sources;
    }

    /**
     * the hook is in function media_send_to_editor
     * @static
     * @param $html
     * @return mixed
     */
    public static function replace_baseurl($url)
    {
        $url = str_replace(self::$local_baseurl, self::$remote_baseurl, $url);
        !empty(self::$anti_leech_token) && !is_admin() && self::setup_rest() && $url = self::sign_url($url);
        return $url;
    }

    /**
     * handle orig image file and other files.
     * @static
     * @param $file
     * @return array|mixed
     */
    public static function upload_and_send($file)
    {
        if (self::$is_form_api_upload) {
            return $file;
        }
        /**
         * 泥马， xmlrpc mw_newMediaObject 方法中的 wp_handle_upload HOOK  file 参数仅为文件名！
         */
        if (defined('XMLRPC_REQUEST')) {
            $file['file'] = self::$local_path . '/' . $file['file'];
        }
        if (!self::connect_remote_server()) {
            //failed ,delete the orig file
            file_exists($file['file']) && unlink($file['file']);
            return self::raise_connection_error();
        }
        $upload_error_handler = 'wp_handle_upload_error';

        $local_basename = basename($file['file']);
        $local_basename_unique = self::unique_filename(self::$rest_remote_path . self::$subdir, $local_basename);
        /**
         * since we can not detect wether remote file is duplicated or not.
         * if remote already has the file,then first rename local filename to target.after this,the file uploaded to remote
         * server should not overwrote the existed file.
         */
        if ($local_basename_unique != $local_basename) {
            $local_full_filename = dirname($file['file']) . '/' . $local_basename_unique;
            @rename($file['file'], $local_full_filename);
            $file['file'] = $local_full_filename;
        }
        $localfile = $file['file'];
        //file path on remote server
        $remotefile = self::$rest_remote_path . self::$subdir . '/' . $local_basename_unique;
        $remote_subdir = dirname($remotefile);
        $remote_subdir = str_replace('\\', '/', $remote_subdir);

        $file['url'] = str_replace(self::$local_url, self::$remote_url, $file['url']);
        //如果是图片，此处不处理，因为要与水印插件兼容的原因　
        if (self::is_image_file($file['file'])) {
            //对xmlrpc 这里又给它还原
            if (defined('XMLRPC_REQUEST')) {
                $file['file'] = basename($file['file']);
            }
            return $file;
        }
        $content = file_get_contents($localfile);
        //        return array('error'=> $remotefile);
        if (!self::$fs->put_contents($remotefile, $content, true)) {
            $error_str = sprintf('%s:' . __('upload file to remote server failed!', self::textdomain), self::plugin_name);
            if (defined('XMLRPC_REQUEST')) {
                return self::xmlrpc_error($error_str);
            } else {
                return call_user_func($upload_error_handler, $file, $error_str);
            }
        }
        unset($content);
        //uploaded successfully
        self::update_filesize_used($localfile);
        //delete the local file
        file_exists($file['file']) && unlink($file['file']);
        //对于非图片文件，且为xmlrpc的情况，还原file参数
        if (defined('XMLRPC_REQUEST')) {
            $file['file'] = basename($file['file']);
        }
        return $file;
    }

    /**
     * 上传缩略图到远程服务器并删除本地服务器文件
     * @static
     * @param $metadata from function wp_generate_attachment_metadata
     * @return array
     */
    public static function upload_images($metadata)
    {
        if (self::$is_form_api_upload) {
            return $metadata;
        }
        if (!self::is_image_file($metadata['file'])) {
            return $metadata;
        }

        if (!self::connect_remote_server()) {
            return self::raise_connection_error();
        }

        //deal with fullsize image file
        if (!self::upload_file($metadata['file'])) {
            return self::raise_upload_error();
        }


        if (isset($metadata['sizes']) && count($metadata['sizes']) > 0) {
            //there may be duplicated filenames,so ....
            $unique_images = array();
            foreach ($metadata['sizes'] as $image_size => $image_item) {
                $unique_images[] = $image_item['file'];
            }
            $unique_images = array_unique($unique_images);
            foreach ($unique_images as $image_filename) {
                $relative_filepath = dirname($metadata['file']) . '/' . $image_filename;
                if (!self::upload_file($relative_filepath)) {
                    return self::raise_upload_error();
                }
            }
        }
        return $metadata;
    }

    /**
     * upload single file to remote  rest  server, used by upload_images
     * @param type $relative_path the path relative to upload basedir
     * @return type
     */
    private static function upload_file($relative_path)
    {
        $local_filepath = self::$local_basepath . DIRECTORY_SEPARATOR . $relative_path;
        $local_basename = basename($local_filepath);
        $remotefile = self::$rest_remote_path . self::$subdir . '/' . $local_basename;
        $file_data = file_get_contents($local_filepath);
        if (!self::$fs->put_contents($remotefile, $file_data, true)) {
            return FALSE;
        } else {
            //更新占用空间
            self::update_filesize_used($local_filepath);
            @unlink($local_filepath);
            unset($file_data);
            return TRUE;
        }
    }

    /**
     * Get a filename that is sanitized and unique for the given directory.
     * @uses self::$fs ,make sure the rest connection is available when you use this method!
     * @since 1.2.0
     * @param string $dir the remote dir
     * @param string $filename the base filename
     * @param mixed $unique_filename_callback Callback.
     * @return string New filename, if given wasn't unique.
     */
    private static function unique_filename($dir, $filename)
    {
        // sanitize the file name before we begin processing
        $filename = sanitize_file_name($filename);

        // separate the filename into a name and extension
        $info = pathinfo($filename);
        $ext = !empty($info['extension']) ? '.' . $info['extension'] : '';
        $name = basename($filename, $ext);

        // edge case: if file is named '.ext', treat as an empty name
        if ($name === $ext)
            $name = '';

        // Increment the file number until we have a unique file to save in $dir. Use callback if supplied.
        $number = '';

        // change '.ext' to lower case
        if ($ext && strtolower($ext) != $ext) {
            $ext2 = strtolower($ext);
            $filename2 = preg_replace('|' . preg_quote($ext) . '$|', $ext2, $filename);

            // check for both lower and upper case extension or image sub-sizes may be overwritten
            while (self::$fs->is_file($dir . "/$filename") || self::$fs->is_file($dir . "/$filename2")) {
                $new_number = $number + 1;
                $filename = str_replace("$number$ext", "$new_number$ext", $filename);
                $filename2 = str_replace("$number$ext2", "$new_number$ext2", $filename2);
                $number = $new_number;
            }
            return $filename2;
        }

        while (self::$fs->is_file($dir . "/$filename")) {
            if ('' == "$number$ext")
                $filename = $filename . ++$number . $ext;
            else
                $filename = str_replace("$number$ext", ++$number . $ext, $filename);
        }

        return $filename;
    }

    /**
     * 删除远程服务器上的单个文件
     * @static
     * @param $file
     * @return void
     */
    public static function delete_remote_file($file)
    {
        $file = str_replace(self::$local_basepath, self::$http_remote_path, $file);
        if (strpos($file, self::$http_remote_path) !== 0) {
            $file = self::$rest_remote_path . '/' . $file;
        }

        self::setup_rest();
        self::decrease_filesize_used(self::$fs, $file);
        self::$fs->rm($file);
        return '';
    }

    /**
     * @see wp-admin/includes/scree.php Class Screen
     *  add_contextual_help is deprecated
     * method to find current_screen:
     * function check_current_screen() {
     * if( !is_admin() ) return;
     * global $current_screen;
     * var_dump( $current_screen );
     * }
     * add_action( 'admin_notices', 'check_current_screen' );
     * @return void
     */
    public static function add_my_contextual_help()
    {
        //WP_Screen id:  'settings_page_hacklog-remote-attachment/loader'
        $identifier = md5(HACKLOG_RA_UPYUN_LOADER);
        $current_screen_id = 'settings_page_' . $identifier;
        $text = '<p><h2>' . __('Explanation of some Options', self::textdomain) . '</h2></p>' .
            '<p>' . __('<strong>Remote base URL</strong> is the URL to your bucket root path.', self::textdomain) . '</p>' .
            '<p>' . __('<strong>rest Remote path</strong> is the relative path to your bucket main directory.Use "<strong>/</strong>" for bucket main(root) directory.You can use sub-directory Like <strong>wp-files</strong>', self::textdomain) . '</p>' .
            '<p>' . __('<strong>HTTP Remote path</strong> is the relative path to your HTTP main directory.Use "<strong>/</strong>" for HTTP main(root) directory.You can use sub-directory Like <strong>wp-files</strong>', self::textdomain) . '</p>' .
            '<p><strong>' . __('For more information:', self::textdomain) . '</strong> ' . __('Please visit the <a href="https://github.com/ihacklog/hacklog-remote-attachment-upyun" target="_blank">Plugin Home Page</a> and <a href="http://80x86.io/post/hacklog-remote-attachment.html" target="_blank">Hacklog Remote Attachment</a> home page.', self::textdomain) . '</p>';
        $args = array(
            'title' => sprintf(__("%s Help", self::textdomain), self::plugin_name),
            'id' => $current_screen_id,
            'content' => $text,
            'callback' => false,
        );
        $current_screen = get_current_screen();
        $current_screen->add_help_tab($args);
    }

    /**
     * add menu page
     * @see http://codex.wordpress.org/Function_Reference/add_options_page
     * @static
     * @return void
     */
    public static function plugin_menu()
    {
        $identifier = md5(HACKLOG_RA_UPYUN_LOADER);
        $option_page = add_options_page(__('Hacklog Remote Attachment Upyun Options', self::textdomain), __('Remote Attachment Upyun', self::textdomain), 'manage_options', $identifier, array(__CLASS__, 'plugin_options')
        );
//		 Adds my help tab when my admin page loads
        add_action('load-' . $option_page, array(__CLASS__, 'add_my_contextual_help'));
    }

    public static function show_message($message, $type = 'e')
    {
        if (empty($message))
            return;
        $font_color = 'e' == $type ? '#FF0000' : '#4e9a06';
        $html = '<!-- Last Action --><div class="updated fade"><p>';
        $html .= "<span style='color:{$font_color};'>" . $message . '</span>';
        $html .= '</p></div>';
        echo $html;
    }

    /**
     * option page
     * @static
     * @return void
     */
    public static function plugin_options()
    {
        $msg = '';
        $error = '';

        //update options
        if (isset($_POST['submit'])) {
            if (self::update_options()) {
                $msg = __('Options updated.', self::textdomain);
            } else {
                $error = __('Nothing changed.', self::textdomain);
            }
            $credentials = array(
                'api_domain' => trim($_POST['rest_server']),
                'bucketname' => trim($_POST['bucketname']),
                'username' => trim($_POST['rest_user']),
                'password' => !empty($_POST['rest_pwd']) ? trim($_POST['rest_pwd']) : self::$rest_pwd,
                'form_api_secret' => !empty($_POST['form_api_secret']) ? trim($_POST['form_api_secret']) : self::$form_api_secret,
                'timeout' => trim($_POST['rest_timeout']),
                'ssl' => FALSE,
            );
            if (self::connect_remote_server($credentials)) {
                $msg .= __('Connected and Authenticated successfully.', self::textdomain);
            } else {
                $error_arr = self::raise_connection_error();
                $error .= $error_arr['error'];
            }
        }

        //tools
        if (isset($_GET['hacklog_do'])) {
            global $wpdb;
            switch ($_GET['hacklog_do']) {
                case 'replace_old_post_attach_url':
                    $orig_url = self::$local_baseurl;
                    $new_url = self::$remote_baseurl;
                    $sql = "UPDATE $wpdb->posts set post_content=replace(post_content,'$orig_url','$new_url')";
                    break;
                case 'recovery_post_attach_url':
                    $orig_url = self::$remote_baseurl;
                    $new_url = self::$local_baseurl;
                    $sql = "UPDATE $wpdb->posts set post_content=replace(post_content,'$orig_url','$new_url')";
                    break;
            }
            if (($num_rows = $wpdb->query($sql)) > 0) {
                $msg = sprintf('%d ' . __('posts has been updated.', self::textdomain), $num_rows);
                $msg .= sprintf('%1$s <blockquote><code>%2$s</code></blockquote> ', __('The following SQL statement was executeed:', self::textdomain), $sql);
            } else {
                $error = __('no posts been updated.', self::textdomain);
            }
        }
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2> <?php _e('Hacklog Remote Attachment Upyun Options', self::textdomain) ?></h2>
            <?php
            self::show_message($msg, 'm');
            self::show_message($error, 'e');
            ?>
            <form name="form1" method="post"
                  action="<?php echo admin_url('options-general.php?page=' . md5(HACKLOG_RA_UPYUN_LOADER)); ?>">
                <table width="100%" cellpadding="5" class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="rest_server"><?php _e('REST API server', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <select id="rest_server" name="rest_server">
                                <?php foreach (['v0.api.upyun.com' => 'auto', 'v1.api.upyun.com' => 'telecom', 'v2.api.upyun.com' => 'unicom', 'v3.api.upyun.com' => 'other'] as $the_server => $server_desc): ?>
                                    <option
                                        value="<?php echo $the_server; ?>" <?php selected($the_server, self::get_opt('rest_server'), true); ?>>
                                        <?php echo $server_desc; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span
                                class="description"><?php echo sprintf(__('the IP or domain name of remote file server.', self::textdomain)); ?></span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rest_port"><?php _e('REST API server port', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="rest_port" type="text" class="small-text" size="60" id="rest_port"
                                   value="<?php echo self::get_opt('rest_port'); ?>"/>
                            <span
                                class="description"><?php _e('the listenning port of remote rest server.Generally it is 80.', self::textdomain) ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="bucketname"><?php _e('bucketname', self::textdomain) ?>:</label>
                        </th>
                        <td>
                            <input name="bucketname" type="text" class="regular-text" size="60" id="bucketname"
                                   value="<?php echo self::get_opt('bucketname'); ?>"/>
                            <span
                                class="description"><?php _e('the bucketname you want to store your files to.', self::textdomain) ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rest_user"><?php _e('REST API username', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="rest_user" type="text" class="regular-text" size="60" id="rest_user"
                                   value="<?php echo self::get_opt('rest_user'); ?>"/>
                            <span class="description"><?php _e('the REST API username.', self::textdomain) ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rest_pwd"><?php _e('REST API password', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="rest_pwd" type="password" class="regular-text" size="60" id="rest_pwd"
                                   value=""/>
                            <span
                                class="description"><?php _e('the API user \'s password.will not be displayed here since filled and updated.', self::textdomain) ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="form_api_secret"><?php _e('form api secret', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="form_api_secret" type="password" class="regular-text" size="60"
                                   id="form_api_secret"
                                   value="<?php echo self::get_opt('form_api_secret'); ?>"/>
                            <span
                                class="description"><?php _e('the form API secret.Be aware that if you want to use the <strong>form API features</strong>,you MUST enable this in your Upyun dashboard.', self::textdomain) ?></span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label
                                for="form_api_timeout"><?php _e('form API timeout(s)', self::textdomain) ?>:</label>
                        </th>
                        <td>
                            <input name="form_api_timeout" type="text" class="small-text" size="30"
                                   id="form_api_timeout"
                                   value="<?php echo self::get_opt('form_api_timeout'); ?>"/>
                            <span
                                class="description"><?php _e('form API authorization timeout.the max authorized time (calculated in seconds) when upload file via form API.It depends on your computer\'s network condition.', self::textdomain); ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label
                                for="form_api_content_max_length"><?php _e('form API content max length(MiB)', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="form_api_content_max_length" type="text" class="small-text" size="30"
                                   id="form_api_content_max_length"
                                   value="<?php echo self::get_opt('form_api_content_max_length'); ?>"/>
                            <span
                                class="description"><?php echo sprintf(__('the max file size (calculated in MiB) when upload file via form API.Currently,Upyun \'s limitation is %d MiB', self::textdomain), 100); ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label
                                for="form_api_allowed_ext"><?php _e('form API allowd ext', self::textdomain) ?>:</label>
                        </th>
                        <td>
                            <input name="form_api_allowed_ext" type="text" class="regular-text" size="30"
                                   id="form_api_allowed_ext"
                                   value="<?php echo self::get_opt('form_api_allowed_ext'); ?>"/>
                            <span
                                class="description"><?php _e('form API allowed file extension.For example: <strong>jpg,jpeg,gif,png,doc,pdf,zip,rar,tar.gz,tar.bz2,7z</strong>', self::textdomain); ?></span>
                        </td>
                    </tr>
                    <!-- anti-leech -->

                    <tr valign="top">
                        <th scope="row"><label
                                for="anti_leech_token"><?php _e('anti leech token key', self::textdomain) ?>:</label>
                        </th>
                        <td>
                            <input name="anti_leech_token" type="text" class="regular-text" size="60"
                                   id="anti_leech_token"
                                   value="<?php echo self::get_opt('anti_leech_token'); ?>"/>
                            <span
                                class="description"><?php _e('the anti leech token key your set in upyun panel', self::textdomain) ?></span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label
                                for="anti_leech_timeout"><?php _e('anti leech timeout(s)', self::textdomain) ?>:</label>
                        </th>
                        <td>
                            <input name="anti_leech_timeout" type="text" class="small-text" size="30"
                                   id="anti_leech_timeout"
                                   value="<?php echo self::get_opt('anti_leech_timeout'); ?>"/>
                            <span class="description"><?php _e('anti leech timeout', self::textdomain); ?></span>
                        </td>
                    </tr>


                    <tr valign="top">
                        <th scope="row"><label for="rest_timeout"><?php _e('rest timeout(s)', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="rest_timeout" type="text" class="small-text" size="30" id="rest_timeout"
                                   value="<?php echo self::get_opt('rest_timeout'); ?>"/>
                            <span class="description"><?php _e('rest connection timeout.', self::textdomain); ?></span>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="remote_baseurl"><?php _e('Remote base URL', self::textdomain) ?>
                                :</label></th>
                        <td>
                            <input name="remote_baseurl" type="text" class="regular-text" size="60" id="remote_baseurl"
                                   value="<?php echo self::get_opt('remote_baseurl'); ?>"/>
                            <span
                                class="description"><?php _e('Remote base URL,the URL to your bucket root path.for example: <strong>http://xxx.b0.upaiyun.com</strong>.', self::textdomain); ?></span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rest_remote_path"><?php _e('rest Remote path', self::textdomain); ?>
                                :</label></th>
                        <td>
                            <input name="rest_remote_path" type="text" class="regular-text" size="60"
                                   id="rest_remote_path"
                                   value="<?php echo self::get_opt('rest_remote_path'); ?>"/>
                            <span
                                class="description"><?php _e('the relative path to your bucket main directory.Use "<strong>/</strong>" for rest main(root) directory.You can use sub-directory Like <strong>wp-files</strong>', self::textdomain); ?></span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="http_remote_path"><?php _e('HTTP Remote path', self::textdomain); ?>
                                :</label></th>
                        <td>
                            <input name="http_remote_path" type="text" class="regular-text" size="60"
                                   id="http_remote_path"
                                   value="<?php echo self::get_opt('http_remote_path'); ?>"/>
                            <span
                                class="description"><?php _e('the relative path to your HTTP main directory.Use "<strong>/</strong>" for HTTP main(root) directory.You can use sub-directory Like <strong>wp-files</strong>', self::textdomain); ?></span>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button-primary" name="submit"
                           value="<?php _e('Save Options', self::textdomain); ?> &raquo;"/>
                </p>
            </form>
        </div>
        <div class="wrap">
            <hr/>
            <h2> <?php _e('Hacklog Remote Attachment UpYun Status', self::textdomain); ?></h2>

            <p style="color:#999999;font-size:14px;">
                <?php _e('Space used on remote server:', self::textdomain); ?>
                <?php
                if (self::setup_rest()) {
                    $total_size = self::$fs->get_bucket_usage();
                    if (get_option(self::opt_space) != $total_size) {
                        update_option(self::opt_space, $total_size);
                    }
                    echo '<span style="color:#4e9a06;font-size:14px;">' . self::human_size($total_size) . '</span>';
                } else {
                    echo '<span style="color:#FF0000;">';
                    _e('Authentication failed OR Failed to connect to remote server!', self::textdomain);
                    echo '</span>';
                }
                ?>
            </p>
            <ul style="color:#999999;font-size:14px;">
                <li>
                    Upyun SDK Version: <span style="color:#4e9a06;font-size:14px;"><?php echo self::$fs->version();?></span>
                </li>
            </ul>
            <hr/>
            <h2>Tools</h2>

            <p style="color:#f00;font-size:14px;"><strong><?php _e('warning:', self::textdomain); ?></strong>
                <?php _e("if you haven't moved all your attachments OR dont't know what below means,please <strong>DO NOT</strong> click the link below!", self::textdomain); ?>
            </p>

            <h3><?php _e('Move', self::textdomain); ?></h3>

            <p style="color:#4e9a06;font-size:14px;">
                <?php _e('if you have moved all your attachments to the remote server,then you can click', self::textdomain); ?>
                <a onclick="return confirm('<?php _e('Are your sure to do this?Make sure you have backuped your database tables.', self::textdomain); ?>');"
                   href="<?php echo admin_url('options-general.php?page=' . md5(HACKLOG_RA_UPYUN_LOADER)); ?>&hacklog_do=replace_old_post_attach_url"><strong><?php _e('here', self::textdomain); ?></strong></a><?php _e(' to update the database.', self::textdomain); ?>
            </p>

            <h3><?php _e('Recovery', self::textdomain); ?></h3>

            <p style="color:#4e9a06;font-size:14px;">
                <?php _e('if you have moved all your attachments from the remote server to local server,then you can click', self::textdomain); ?>
                <a onclick="return confirm('<?php _e('Are your sure to do this?Make sure you have backuped your database tables.', self::textdomain); ?>');"
                   href="<?php echo admin_url('options-general.php?page=' . md5(HACKLOG_RA_UPYUN_LOADER)); ?>&hacklog_do=recovery_post_attach_url"><strong><?php _e('here', self::textdomain); ?></strong></a><?php _e(' to update the database.', self::textdomain); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add "Check update" action on installed plugin list
     * @param type $links
     * @return array
     */
    public static function add_plugin_actions($links)
    {
        array_unshift($links, '<a target="_blank" href="https://github.com/ihacklog/hacklog-remote-attachment-upyun">' . __('Check Update', self::textdomain) . '</a>');
        return $links;
    }

}
