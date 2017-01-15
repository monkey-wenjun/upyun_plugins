<?php

/**
 * Created by PhpStorm.
 * User: sh4d0walker
 * Date: 12/7/16
 * Time: 1:12 AM
 * @subpackage Filesystem
 * @uses WP_Filesystem_Base Extends class
 */

require __DIR__ . '/../vendor/upyun.class.php';

class Filesystem_Upyun extends UpYun
{
    //currently ,max filesize allowed by Upyuh form API is 100MiB
    const FORM_API_MAX_CONTENT_LENGTH = 104857600;
    const VERION        = 'ihacklog_20161107';
    const TOKEN_NAME = '_upt';

    private $form_api_secret = '';
    private $anti_leech_token = '';
    private $form_api_content_max_length = 104857600;
    private $form_api_allowed_ext = 'jpg,jpeg,gif,png,doc,pdf,zip,rar,tar.gz,tar.bz2,7z';
    private $form_api_timeout = 300;
    //default anti-leech token timeout is 10 min
    private $anti_leech_timeout = 600;

    public $debug = false;
    public $errors;

    /**
     * Filesystem_Upyun constructor.
     * @param string $bucketname
     * @param string $username
     * @param string $password
     * @param null $endpoint
     * @param int $timeout
     */
    public function __construct($bucketname, $username, $password, $endpoint = NULL, $timeout = 30) {
        $this->errors = new WP_Error();
        $this->check_param ($bucketname, $username, $password, $endpoint, $timeout);
        parent::__construct($bucketname, $username, $password, $endpoint, $timeout);

    }

    /**
     * @param $form_api_params
     */
    public function set_form_api_params($form_api_params) {
        if (!empty($form_api_params['form_api_secret'])) {
            $this->form_api_secret = $form_api_params['form_api_secret'];
        }
        if (!empty($form_api_params['anti_leech_token'])) {
            $this->anti_leech_token = $form_api_params['anti_leech_token'];
        }
        if (!empty($form_api_params['form_api_allowed_ext'])) {
            $this->form_api_allowed_ext = $form_api_params['form_api_allowed_ext'];
        }
        //form_api_content_max_length
        if (!empty($form_api_params['form_api_content_max_length'])) {
            $this->form_api_content_max_length = 1024 * 1024 * $form_api_params['form_api_content_max_length'];
        }
        if (!empty($form_api_params['form_api_timeout'])) {
            $this->form_api_timeout = $form_api_params['form_api_timeout'];
        }
        if (!empty($form_api_params['anti_leech_timeout'])) {
            $this->anti_leech_timeout = $form_api_params['anti_leech_timeout'];
        }
    }


    /**
     * check the needed param
     * @param $bucketname
     * @param $username
     * @param $password
     * @param $endpoint
     * @param $timeout
     */
    public function check_param($bucketname, $username, $password, $endpoint, $timeout) {
        if (empty($endpoint))
        {
            $this->errors->add ( 'empty_api_domain', __ ( 'api_domain is required' ) );
        }

        if (empty ($bucketname))
        {
            $this->errors->add ( 'empty_bucketname', __ ( 'bucketname is required' ) );
        }

        if (empty ($username))
        {
            $this->errors->add ( 'empty_username', __ ( 'username is required' ) );
        }
        if (empty ($password))
        {
            $this->errors->add ( 'empty_password', __ ( 'password is required' ) );
        }
    }

    // -------------------------START  form api -------------------------------- //

    private function get_form_api_secret() {
        return $this->form_api_secret;
    }

    public function check_form_api_internal_error() {
        if (!isset($_GET['non-sign'])) {
            return false;
        }
        if( md5("{$_GET['code']}&{$_GET['message']}&{$_GET['url']}&{$_GET['time']}&") == $_GET['non-sign'] )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function check_form_api_return_param() {
        if (!isset($_GET['sign'])) {
            return false;
        }
        if(	md5("{$_GET['code']}&{$_GET['message']}&{$_GET['url']}&{$_GET['time']}&". $this->get_form_api_secret() ) == $_GET['sign'] )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * md5(policy+'&'+表单API验证密匙)
     */
    public function get_form_api_signature($policy) {
        return md5($policy . '&' . $this->get_form_api_secret() );
    }

    public function build_policy($args) {
        $default = array(
            'expire' => $this->form_api_timeout, // 300 s
            'path' => '/{year}/{mon}/{random}{.suffix}', // full relative path
            'allow_file_ext' => $this->form_api_allowed_ext,
            'content_length_range' =>'0,' . $this->form_api_content_max_length, // 10MB( 10485760) 20MB ( 20971520 ),最大为100MB ( 104857600 )
            'return_url' => plugins_url('upload.php', HACKLOG_RA_UPYUN_LOADER),
            'notify_url' => '',
        );
        $args = array_merge($default,$args);
        $policydoc = array(
            "bucket" => $args['bucket'], /// 空间名
            "expiration" => time() + $args['expire'], /// 该次授权过期时间
            "save-key" => $args['path'], /// 命名规则，/2011/12/随机.扩展名
            "allow-file-type" => $args['allow_file_ext'], /// 仅允许上传图片
            "content-length-range" => $args['content_length_range'] , /// 文件在 100K 以下
            "return-url" => $args['return_url'] , /// 回调地址
            "notify-url" =>$args['notify_url'] /// 异步回调地址
        );
        //var_dump($policydoc);
        $policy = base64_encode(json_encode($policydoc));  /// 注意 base64 编码后的 policy字符串中不包含换行符！
        return $policy;
    }

    /**
     * ge_anti_leech_token_sign
     *签名格式：MD5(密匙&过期时间&URI){中间8位}+(过期时间)
     * 发送cookie或通过get传递
     *过期时间格式: UNIX TIME
     * @param string $uri 文件路径，必须以/开头
     * @return void
     */
    public function get_anti_leech_token_sign($uri = '/') {
        $uri = '/' . ltrim($uri,'/');
        $end_time = time() + $this->anti_leech_timeout;
        $token_sign =md5($this->anti_leech_token . '&' .$end_time.'&' . $uri );
        $sign = substr($token_sign, 12,8).$end_time;
        return $sign;
    }

    /**
     * @param string $uri
     * @return string
     */
    public function set_anti_leech_token_sign_uri($uri = '/') {
        $uri = ltrim($uri,'/');
        return $uri . '?' . self::TOKEN_NAME .'='. $this->get_anti_leech_token_sign($uri);
    }

    public function is_url_token_signed($url = '') {
        if(strpos($url, self::TOKEN_NAME) > 0 )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * @param string $uri
     * @param string $cookie_path
     * @param string $cookie_domain
     */
    public function set_anti_leech_token_sign_cookie($uri='/',$cookie_path='/',$cookie_domain='') {
        $uri = ltrim($uri,'/');
        setcookie( self::TOKEN_NAME ,$this->get_anti_leech_token_sign($uri),time() + $this->anti_leech_timeout ,$cookie_path,$cookie_domain);
    }
    // -------------------------END   form api -------------------------------- //

    public function safe_sdk_call($func, $args) {
        try {
            $ret = call_user_func_array([$this, $func], $args);
        } catch (UpYunException $e) {
            $this->errors->add($e->getCode(), $e->getMessage());
            $ret = false;
            if ($this->debug) {
                throw $e;
            }
        }
        return $ret;
    }

    /**
     * @access public
     *
     * @return bool
     */
    public function connect() {
        //test for authentication
        $finfo = $this->is_file('/');
        if ( !$finfo ) {
            return false; //There was an erorr connecting to the server.
        }
        return true;
    }

    /**
     * download the file or save to file handler
     * @param $file
     * @param resource $file_handle
     * @return mixed
     */
    public function get_contents($file, $file_handle = NULL) {
        return $this->safe_sdk_call('readFile', [$file, $file_handle]);
    }

    /**
     * @param $path target path
     * @param $file file content or file handler
     * @param bool $auto_mkdir
     * @param null $opts
     * @return mixed
     */
    public function put_contents($path, $file, $auto_mkdir = true, $opts = NULL) {
        return $this->safe_sdk_call('writeFile', [$path, $file, $auto_mkdir, $opts]);
    }

    /**
     * @access public
     *
     * @param string $source
     * @param string $destination
     * @param bool   $overwrite
     * @return bool
     */
    public function copy($source, $destination, $overwrite = false) {
        $ret = false;
        $target_exists = $this->is_file($destination);
        if (!$overwrite && $target_exists) {
            return true;
        }
        $tmpf = tmpfile();
        if ($tmpf) {
            $this->get_contents($source, $tmpf);
            if ($target_exists) {
                $this->rm($destination);
            }
            $ret = $this->put_contents($destination, $tmpf);
            @unlink($tmpf);
            return $ret;
        }
    }

    /**
     * @access public
     *
     * @param string $source
     * @param string $destination
     * @param bool $overwrite
     * @return bool
     */
    public function move($source, $destination, $overwrite = false) {
        if ($this->copy($source, $destination, $overwrite)) {
            return $this->rm($source);
        }
        return false;
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function rm($file) {
        return $this->safe_sdk_call('delete', [$file]);
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function exists($file) {
        return $this->safe_sdk_call('getFileInfo', [$file]);
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function is_file($file) {
        return $this->exists($file);
    }

    /**
     * @access public
     *
     * @param string $path
     * @return bool
     */
    public function is_dir($path) {
        return $this->exists($path);
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function is_readable($file) {
        return $this->exists($file);
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function is_writable($file) {
        return $this->exists($file);
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function atime($file) {
        return false;
    }

    /**
     * @access public
     *
     * @param string $file
     * @return int
     */
    public function mtime($file) {
        return false;
    }

    /**
     * @access public
     *
     * @param string $file
     * @return int
     */
    public function size($file) {
        return false;
    }

    /**
     * @access public
     *
     * @param string $file
     * @return bool
     */
    public function touch($file) {
        return false;
    }

    /**
     * @param $path
     * @param bool $auto_mkdir
     * @return bool|mixed
     */
    public function mkdir($path, $auto_mkdir = true) {
        return $this->safe_sdk_call('makeDir', [$path, $auto_mkdir]);
    }

    /**
     * @access public
     *
     * @param string $path
     * @param bool $recursive
     * @return bool
     */
    public function rmdir($path, $recursive = false) {
        return $this->delete($path, $recursive);
    }

    public function get_dir_list($path = '/') {
        return $this->safe_sdk_call('getList', [$path]);
    }

    public function get_fileinfo($path) {
        return $this->safe_sdk_call('getFileInfo', [$path]);
    }

    public function get_fold_usage($bucket = '/') {
        return $this->safe_sdk_call('getFolderUsage', [$bucket]);
    }

    public function get_bucket_usage() {
        return $this->safe_sdk_call('getBucketUsage', []);
    }

    public function get_api_domain() {
        return $this->endpoint;
    }
}