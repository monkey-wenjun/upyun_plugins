<?php
/**
 * 
 * @author cuelog
 * @link http://www.cuelog.com
 * @copyright 2016 cuelog
 */
class UpYunCloud {
	
	/**
	 *
	 * @var 提示信息
	 */
	private $msg = null;
	
	/**
	 * 
	 * @var 上传文件是否为图片
	 */
	private $is_img = true;
	
	/**
	 * 
	 * @var 当前页面
	 */
	private $current_page = '';
	
	/**
	 * 
	 * @var 当前上传的文件
	 */
	private $file = '';
	
	/**
	 *
	 * @var wp_upload_dir 函数的各项值 
	 */
	private $upload_dir = array ();
	
	/**
	 *
	 * @var 又拍云SDK实例
	 */
	private $UPyun = null;
	
	/**
	 *
	 * @var 插件设置参数
	 */
	private $option = array ();
	
	/**
	 * 初始化
	 */
	public function __construct(){
		$this->upload_dir = wp_upload_dir();
		$this->upyun_option_init();
		if($this->option ['is_upyun'] == 'Y') {
			add_filter ( 'wp_get_attachment_url', array( &$this, 'replace_url' ) );
		}
		if(is_admin()){
			add_action ( 'admin_menu', array(&$this, 'upyun_option_menu') );
			add_action ( 'admin_notices', array(&$this, 'check_plugin_connection' ) );
			add_action ( 'wp_ajax_nopriv_upyun_ajax', array( &$this, 'upyun_ajax' ) );
			add_action ( 'wp_ajax_upyun_ajax', array( &$this, 'upyun_ajax') );
			add_filter ( 'content_save_pre', array( &$this, 'save_remote_file' ), 99999 );
			add_filter ( 'wp_handle_upload', array( &$this, 'upload_completed' ), 99999 );
			add_filter ( 'wp_handle_upload_prefilter', array( &$this, 'before_upload' ), 99999 );
			add_filter ( 'wp_update_attachment_metadata', array( &$this, 'start_upload' ), 99999 );
			add_filter ( 'wp_delete_file', array( &$this, 'delete_file_from_upyun' ), 99999 );
			$this->current_page = basename( $_SERVER ['SCRIPT_NAME'] );
			$this->UPyun = new UpYun ( $this->option['bucket_name'], $this->option['admin_username'], $this->option['admin_password'] );
		}
	}

	/**
	 * 获取又拍云错误信息
	 * 
	 * @return Ambigous <boolean, array>
	 */
	private function get_upyun_error() {
		return $this->UPyun->get_error_msg ();
	}
	
	/**
	 * 显示错误信息
	 */
	private function show_msg($state = false) {
		$msg = $this->get_upyun_error ();
		$msg = $msg ? $msg ['error'] : $this->msg;
		$state = $state === false ? 'error' : 'updated';
		if (! empty ( $msg )) {
			echo "<div class='{$state}'><p>{$msg}</p></div>";
		}
	}
	
	/**
	 * 初始化插件参数
	 */
	private function upyun_option_init() {
		$default_option = array (
				'remote_upload_root' => '/',
				'is_normal' => 'Y',
				'is_delete' => 'N',
				'is_upyun' => 'N'
		);
		$this->option = get_option ( 'upyun_option', $default_option );
	}
	/**
	 * 解决上传/下载文件包括中文名问题
	 */
	private function iconv2cn($str, $cn = false) {
		if (! UPYUN_IS_WIN) {
			return $str;
		}
		return $cn === true ? iconv ( 'GBK', 'UTF-8', $str ) : iconv ( 'UTF-8', 'GBK', $str );
	}
	
	/**
	 * 获取又拍云存储服务中的文件存放目录
	 * @param string $dir_file
	 * @return string
	 */
	private function get_remote_upload_path($dir_file){
		return rtrim ( $this->option ['remote_upload_root'] ) . '/' . ltrim( $dir_file, '/' );
	}

	/**
	 * 开始上传到又拍云存储
	 * @param string $file
	 * @return boolean
	 */
	private function upload_to_upyun($file) {
		$return = true;
		$file_path = $this->iconv2cn ( $this->upload_dir ['basedir'] . '/' . ltrim($file, '/') );
		if( file_exists ( $file_path ) ){
			$local_file = file_get_contents ( $file_path );		
			$res = $this->UPyun->writeFile ( $this->get_remote_upload_path($file), $local_file, true );
			if($res === false){
				$return = $this->get_upyun_error();
			}
			unset ( $local_file );
			if ($this->option ['is_delete'] == 'Y') {
				unlink ( $file_path );
			}
		} else {
			$return = array('error' => '文件不存在');
		}
		return $return;
	}
	
	/**
	 * 安装插件后检查参数设置
	 * @return boolean
	 */
	public function check_plugin_connection() {
		global $hook_suffix;
		if ($hook_suffix == 'plugins.php') {
			$this->UPyun->getFolderUsage ( '/' );
			$msg = $this->get_upyun_error ();
			if ( empty ( $this->option ['binding_url'] ) 
				|| empty ( $this->option ['bucket_name'] ) 
				|| empty ( $this->option ['admin_username'] ) 
				|| empty ( $this->option ['admin_password'] ) ) 
			{
				echo "<div class='error'><p>又拍云插件缺少相关参数，<a href='/wp-admin/options-general.php?page=set_upyun_option'>点击这里进行设置</a></p></div>";
				return false;
			}
		}
	}
	
	/**
	 * 上传附件前检查链接
	 * 
	 * @return Ambigous <boolean, unknown>
	 */
	public function before_upload($file) {
		$res = $this->UPyun->getFolderUsage ( '/' );
		$msg = $this->get_upyun_error ();
		if (! empty($msg) ) {
			return ! $msg ? array ( 'error' => '又拍云存储连接失败，请确认插件参数正确无误' ) : $msg;
		}
		return $file;
	}

	/**
	 * 又拍云参数设置页面
	 */
	public function upyun_option_menu(){
		add_options_page( "又拍云设置", "又拍云设置", 'administrator', 'set_upyun_option', array($this, 'display_upyun_option_page') );
	}
	
	/**
	 * 替换附件的url地址
	 *
	 * @param 上传成功后的文件访问路径 $url        	
	 * @return string
	 */
	public function replace_url($url) {
		return str_replace ( $this->upload_dir ['baseurl'], $this->option ['remote_upload_root_url'], $url );
	}
	
	/**
	 * 新增或编辑附件后，上传到又拍云存储
	 * 
	 * @param 文件参数 $metadata        	
	 * @return array
	 */
	public function start_upload($metadata) {
		if ($this->is_img) {
			if( empty( $metadata ) ){
				$metadata ['file'] = $this->file;
			}
			$files = array ();
			$files [] = basename( $metadata ['file'] );
			if(! empty( $metadata ['sizes'] ) ) {
				$sizes = $metadata ['sizes'];
				foreach ($sizes as $tmb) {
					$files [] = $tmb ['file'];
				}
			}
			set_time_limit ( 0 );
			foreach ( $files as $fs ) {
				$fs = $this->upload_dir ['subdir'] . '/' . $fs;
				$res = $this->upload_to_upyun($fs);
				if($res !== true) {
					return $res;
				}
			}
		}
		return $metadata;
	}
	
	/**
	 * 这里只对非图片的文件做上传处理
	 * @param Array $file
	 * @return Ambigous <Ambigous, boolean, string>|unknown
	 */
	public function upload_completed($attachment) {
		$type = $attachment ['type'];
		$suffix = substr ( $type, 0, strpos ( $type, '/' ) );
		$this->is_img = $suffix == 'image' ? true : false;
		$this->file = str_replace( $this->upload_dir ['baseurl'], '', $attachment ['url']);
		// 特殊情况下始终不删除本地文件，否则造成无法安装主题或插件以及其它上传的功能无法显示附件的问题
		if (! $this->is_img && $this->option ['is_normal'] == 'Y' && ! in_array( $this->current_page, array( 'themes.php', 'update.php' ) )) {
			$res = $this->upload_to_upyun( $this->file );
			if ($res !== true) {
				return $res;
			}
		}
		return $attachment;
	}

	/**
	 * 删除又拍云服务中的文件
	 *
	 * @param 删除的文件 $file
	 * @return string
	 */
	public function delete_file_from_upyun($file) {
		$delete_files = str_replace ( $this->upload_dir ['basedir'] . '/', '', $file );
		$delete_files = $this->get_remote_upload_path( $delete_files );
		$this->UPyun->deleteFile ( $delete_files );
		return $file;
	}
	
	/**
	 * 获取又拍云存储服务中的所有文件地址
	 * @param string $path
	 * @return multitype:string
	 */
	public function get_upyun_list($path = null) {
		$path = is_null ( $path ) ?  $this->option ['remote_upload_root'] : $path;
		$list = $this->UPyun->getList ( $path );
		$files = array ();
		if ($list) {
			foreach ( $list as $k => $ls ) {
				if ($ls ['type'] == 'folder') {
					$res = $this->get_upyun_list ( $path . '/' . $ls ['name'] );
					if ($res) {
						$files = array_merge ( $files, $res );
					}
				} else {
					$files [] = 'http://' . $this->option ['binding_url'] . $path . '/' . $ls ['name'];
				}
			}
		}
		return $files;
	}
	
	/**
	 * 保存远程图片
	 * @param string $content
	 * @return mixed
	 */
	public function save_remote_file( $content ) {
		if (! empty($_POST ['ID']) 
			&& $_POST ['action'] != 'autosave' 
			&& $_POST ['post_status'] == 'publish' 
			&& ( $_POST ['post_type'] == 'page' || $_POST ['post_type'] == 'post' ) ) {
			$home_url = addcslashes($_SERVER ['HTTP_HOST'], './');
			$binding_url = addcslashes($this->option ['binding_url'], './');
			preg_match_all ( '#<img.*?src=[\'|\"](http:\/\/(?!'.$home_url.'|'.$binding_url.').*?\.(?:jpg|jpeg|gif|png|icon|bmp))[\'|\"]#', stripslashes ( $content ), $matches );
			if ( isset( $matches [1] ) ) {
				set_time_limit ( 0 );
				$images = $matches [1];
				$post_id = absint( $_POST ['ID'] );
				foreach ( $images as $image_url ) {
					$image_url = htmlspecialchars_decode ($image_url);
					$file = file_get_contents ( $image_url );
					$basename = basename ( $image_url );
					$name = substr($basename, 0, strrpos($basename, '.'));
					$img_name = md5 ( $name ) . strpbrk( $basename, '.' );
					$res = wp_upload_bits ( $img_name, '', $file );
					$the_file = $res ['file'];
					$filetype = wp_check_filetype ( $the_file );
					$attachment = array (
							'guid' => $this->upload_dir ['baseurl'] . '/' . _wp_relative_upload_path ( $the_file ),
							'post_mime_type' => $filetype ['type'],
							'post_title' => preg_replace ( '/\.[^.]+$/', '', basename ( $the_file ) ),
							'post_content' => '',
							'post_status' => 'inherit' 
					);
					$attach_id = wp_insert_attachment ( $attachment, $the_file, $post_id );
					$attach_data = wp_generate_attachment_metadata ( $attach_id, $the_file );
					wp_update_attachment_metadata ( $attach_id, $attach_data );
					$up_url = $this->replace_url($res ['url'] );
					$content = str_replace ( $image_url, $up_url, $content );
				}
			}
			remove_filter( 'content_save_pre', array( $this, 'save_remote_file' ) );
		}
		return $content;
	}

	/**
	 * 本地-又拍云上传/下载ajax操作
	 */
	public function upyun_ajax(){
		if (isset ( $_GET ['do'] )) {
			// 查询所有附件数量
			if ($_GET ['do'] == 'get_local_count') {
				global $wpdb;
				$sql = "select SUM(a.c) from ( 
								( SELECT COUNT(meta_id) as c FROM {$wpdb->postmeta} 
									WHERE meta_key = '_wp_attached_file' 
									AND meta_value REGEXP '[0-9]{4}/[0-9]{2}/.*\\\\.[^jpg|jpeg|png|gif]' 
								 )
								union all 
								(SELECT COUNT(meta_id) as c FROM {$wpdb->postmeta}
									WHERE meta_key = '_wp_attachment_metadata'
									AND meta_value LIKE '%width%height%'
								) 
						) AS a";
				
				if($this->option ['is_normal'] == 'N'){
					$sql = "SELECT COUNT(meta_id) as c FROM {$wpdb->postmeta}
									WHERE meta_key = '_wp_attachment_metadata'
									AND meta_value LIKE '%width%'";
				} 
				$count = $wpdb->get_var($sql);
				die ( strval($count) );
			} elseif ($_GET ['do'] == 'upload') {
				// 获取meta_id后查询下一个附件并上传
				if (isset ( $_GET ['meta_id'] )) {
					global $wpdb;
					$meta_id = absint( $_GET ['meta_id'] );
					// 普通服务类型的话获取所有格式附件
					if($this->option ['is_normal'] == 'Y'){
						$sql = "select * from ( 
										( SELECT * FROM {$wpdb->postmeta} 
											WHERE meta_key = '_wp_attached_file' 
											AND meta_value REGEXP '[0-9]{4}/[0-9]{2}/.*\\\\.[^jpg|jpeg|png|gif]'
										 ) 
										union all 
										(SELECT * FROM {$wpdb->postmeta} 
											WHERE meta_key = '_wp_attachment_metadata'
											AND meta_value LIKE '%width%height%'
										)
								) AS a
								WHERE a.meta_id > {$meta_id}
								ORDER BY a.meta_id ASC LIMIT 1";
					}else{
						// 只获取图片附件
						$sql = "SELECT * FROM {$wpdb->postmeta}
										WHERE meta_id > {$meta_id}
										AND meta_key = '_wp_attachment_metadata'
										AND meta_value LIKE '%width%height%'
										ORDER BY meta_id ASC LIMIT 1";
					}
					$attachment = $wpdb->get_results($sql, ARRAY_A);
					if($attachment){
						$attachment = $attachment [0];
						$meta_id = $attachment ['meta_id'];
						$files = array();
						$meta_value = unserialize($attachment['meta_value']);
												
						if($meta_value){
							$files [] = $meta_value ['file'];
							if(! empty ( $meta_value ['sizes'] ) ) {
								$yyyyM = substr($meta_value ['file'], 0, strrpos( $meta_value ['file'], '/' ) + 1);
								foreach($meta_value ['sizes'] as $size){
									$files [] = $yyyyM . $size ['file'];
								}
							}
						}else{
							$files [] = $attachment['meta_value'];
						}
						$err_list = array();
						$err = '';
						foreach($files as $file) {
							$exist = $this->UPyun->getFileInfo($this->get_remote_upload_path($file));
							if(!$exist){
								$res = $this->upload_to_upyun($file);
								if ($res !== true) {
									$err_list [] = $file;
									$err .= "{$res ['error']}: {$this->upload_dir ['baseurl']}/{$file}\r\n";
								}
							}
						}
						$info ['meta_id'] = $meta_id;
						if( !empty( $err ) ){
							$info ['error_list'] = $err_list;
							$info ['error_count'] = count($err_list);
							$info ['error'] = $err;
						}
						die(json_encode($info));
					}
				}
				die('0');
			} elseif($_GET['do'] == 'upload_again') {
				// 尝试上传失败文件
				$res = $this->upload_to_upyun($_GET['file']);
				if ($res !== true) {
					die("{$res ['error']}: {$this->upload_dir ['baseurl']}/{$_GET['file']}\r\n");
				}
				die('1');
			} elseif ($_GET ['do'] == 'get_upyun_list') {
				// 获取又拍云所有文件
				$list = $this->get_upyun_list ();
				$count = count ( $list );
				$res = array (
						'count' => $count,
						'url' => $list 
				);
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'download') {
				// 下载又拍云所有文件
				if (isset ( $_GET ['file_path'] )) {
					$file = str_replace ( $this->option ['remote_upload_root_url'], '', $_GET ['file_path'] );
					$local = str_replace ( $this->option ['remote_upload_root_url'], $this->upload_dir ['basedir'], $_GET ['file_path'] );
					$local = mb_convert_encoding ( $local, 'GBK' );
					$local_url = $this->upload_dir ['baseurl'] . $file;
					if ( file_exists ( $this->iconv2cn( $local ) ) ) {
						$msg = '【取消下载，文件已经存在】：' . $local_url;
					} else {
						$file_dir = $this->upload_dir ['basedir'] . substr ( $file, 0, strrpos ( $file, '/' ) );
						if (! is_dir ( $file_dir )) {
							if( ! mkdir ( $file_dir, 0755, true ) ) {
								die ( '【Error】 >> 创建目录失败，请确定是否有足够的权限：' . $file_dir );
							}
						}
						$fp = fopen ( $local, 'wb' );
						$res = $this->UPyun->readFile ( $this->option ['remote_upload_root'] . $file, $fp );
						$msg = $res === false ? '【Error】 >> 下载失败：' . $_GET ['file_path'] : '下载成功 >> ' . $local_url;
						fclose ( $fp );
					}
					die ( $msg );
				}
			} elseif ($_GET ['do'] == 'delete') {
				// 删除又拍云储存中的文件
				if(! empty ( $_GET ['files'] ) ) {
					$files = explode( "\n", $_GET['files'] );
					$count = count($files);
					$err = array();
					foreach ($files as $fs) {
						$dfs = str_replace('http://', '', $fs);
						$dfs = str_replace($this->option ['binding_url'], '', $dfs);
						$res = $this->UPyun->deleteFile ( $dfs );
						if($res === false) {
							$err[] = $fs;
						}
					}
					if(count($err) == 0) {
						die('成功删除'.$count.'个附件');
					}else {
						$err = implode("<br />", $err);
						die($err."<p>这些文件删除失败，可能不存在</p>");
					}
				}
				die('没有文件可以删除');
			}
		}
	}
	
	/**
	 * 参数设置页面
	 */
	public function display_upyun_option_page() {
		if (isset ( $_POST ['submit'] )) {
			if (! empty ( $_POST ['action'] )) {
				if (empty ( $this->option ['binding_url'] ) || empty ( $this->option ['bucket_name'] )) {
					$this->msg = '取消操作，你还没有设置又拍云服务绑定的域名或服务名';
					$this->show_msg ();
				} else {
					global $wpdb;
					$upyun_url = $this->option ['remote_upload_root_url'];
					$local_url = $this->upload_dir ['baseurl'];
					if ($_POST ['action'] == 'to_upyun') {
						$this->option ['is_upyun'] = 'Y';
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$local_url}','{$upyun_url}')";
					} elseif ($_POST ['action'] == 'to_local') {
						$this->option ['is_upyun'] = 'N';
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$upyun_url}','{$local_url}')";
					}
					update_option ( 'upyun_option', $this->option );
					$num_rows = $wpdb->query ( $sql );
					$this->msg = "共有 {$num_rows} 篇文章替换";
					$this->show_msg ( true );
				}
			} else {
				// 绑定域名
				$this->option ['binding_url'] = str_replace ( 'http://', '', trim ( $_POST ['binding_url'], ' /' ) );
				// 服务名
				$this->option ['bucket_name'] = trim ( $_POST ['bucket_name'] );
				// 用户名
				$this->option ['admin_username'] = trim ( $_POST ['admin_usernmae'] );
				// 密码
				if (! empty ( $_POST ['admin_password'] )) {
					$this->option ['admin_password'] = $_POST ['admin_password'];
				}
				// 根目录
				$remote_upload_root = trim ( $_POST ['remote_upload_root'], ' /' );
				$this->option ['remote_upload_root'] = empty ( $remote_upload_root ) ? '/' : '/' . $remote_upload_root;
				// 文件根目录访问url
				$this->option ['remote_upload_root_url'] = 'http://' . $this->option ['binding_url'] . rtrim( $this->option ['remote_upload_root'], '/' );
				// 服务类型
				$this->option ['is_normal'] = $_POST ['is_normal'] == 'Y' ? 'Y' : 'N';
				// 是否上传后删除本地文件
				$this->option ['is_delete'] = $_POST ['is_delete'] == 'Y' ? 'Y' : 'N';
				$res = update_option ( 'upyun_option', $this->option );
				$this->UPyun = new UpYun ( $this->option['bucket_name'], $this->option['admin_username'], $this->option['admin_password']);
				$this->msg = $res == false ? '没有做任何修改' : '保存成功';
				$this->show_msg ( true );
			}
		}
		$size_res = $this->UPyun->getFolderUsage ( '/' );
		if ($size_res === false) {
			$upyun_size = '<label style="color:red;">连接失败，无法获取服务使用情况</label>';
		} else {
			$upyun_size = number_format ( $size_res / 1024 / 1024, 2 ) . ' MB';
		}
?>
<div class="wrap">
<?php screen_icon(); ?>
<h2>又拍云插件设置</h2>
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<table class="form-table">
			<tr valign="top">
				<th scope="row">域名绑定:</th>
				<td>
					<input name="binding_url" placeholder="例如：xxx.b0.upaiyun.com" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['binding_url']; ?>" /> <span class="description">又拍云提供的的默认域名或者已经绑定又拍云的域名</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">服务名称:</th>
				<td><input name="bucket_name" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['bucket_name']; ?>" /> <span class="description">创建的服务名称</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">操作员:</th>
				<td><input name="admin_usernmae" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['admin_username']; ?>" /> <span class="description">操作员用户名</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">操作员密码:</th>
				<td><input name="admin_password" type="password" class="regular-text" size="100" id="rest_server" value="<?php echo $size_res === false ? $this->option['admin_password'] : null; ?>" /> <span class="description">操作员密码（连接成功后不显示）</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">文件存放的根目录:</th>
				<td><input name="remote_upload_root" placeholder="例如：/wp-file" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['remote_upload_root']; ?>" /> <span class="description">文件存放在又拍云服务的根目录，默认根目录  "/"，例如："/wp-file"，文件将存放在此目录中</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">创建的服务类型:</th>
				<td>
					<p>
						<label><input type="radio" name="is_normal" value="Y" <?php echo $this->option['is_normal'] == 'Y' ? 'checked="checked"' : null; ?> />UPYUN存储 </label> &nbsp; 
					</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">上传后是否删除附件文件:</th>
				<td>
					<p>
						<label><input type="radio" name="is_delete" value="Y" <?php echo $this->option['is_delete'] == 'Y' ? 'checked="checked"' : null; ?> /> 是 </label> &nbsp; 
						<label><input type="radio" name="is_delete" value="N" <?php echo $this->option['is_delete'] == 'N' ? 'checked="checked"' : null; ?> /> 否 &nbsp; 强烈建议此选项为<b>否</b>，可以在紧要关头时关闭又拍云服务来恢复本地访问</label>
					</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">目前服务使用量:</th>
				<td><strong style="color: #f60; font-size:14px;"><?php echo $upyun_size;?></strong></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php if($size_res !== false) { ?> 
	<hr />
	<?php screen_icon(); ?>
	<h2>启用/关闭 又拍云服务</h2>
	<p>PS: 启用服务前，请确保本地附件已经全部上传到又拍云储存中(可使用下面的小工具上传)</p>
	<?php if($this->option ['is_upyun'] == 'N'){ ?>
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="启用又拍云服务" />
		<input type="hidden" name="action" value="to_upyun" />
	</form>
	<?php } else { ?>
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="关闭又拍云服务" />
		<input type="hidden" name="action" value="to_local" />
	</form>
	<?php }?>
	<br />
	<hr />
	<?php screen_icon(); ?>
	<h2>将本地所有附件上传到又拍云存储</h2>
	<p>PS1: 此操不会删除本地服务器上的附件，如果又拍云中有同名的附件，不会被覆盖</p>
	<p>PS2: 请确保附件都上传完毕后（也可以通过FTP工具），再启用又拍云存储服务</p>
	<p><input type="button" class="button-primary" id="upload_check" value="检查本地服务器附件列表" /></p>
	<p id="loading" style="display:none;"></p>
	<div id="upload_action" style="display:none;">
		<p><span style="color: red;">媒体库中共有附件：<strong id="image_count">0</strong> 个</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="upload_btn" value="开始上传" /></p>
		<p id="upload_state" style="display:none;"><span style="color: red;">正在上传第：<strong id="now_number">1</strong> 个</span></p>
		<p id="upload_error" style="display:none;"><span style="color: red;">上传失败：<strong id="error_number">0</strong> 个</span></p>
		<p id="upload_result" style="display:none;color: red;"></p>
		<p id="upload_again" style="display:none;"><input type="button" class="button-primary" id="upload_again_btn" value="尝试重新上传错误列表中的文件" /></p>
		<div>
			<textarea id="upload_reslut_list" style="width: 100%; height: 300px; display:none;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<hr />
	<?php screen_icon(); ?>
	<h2>恢复附件的本地访问，附件地址恢复为本地访问</h2>
	<p>PS1: 如果本地服务器中有同名的附件，不会被覆盖</p>
	<p>PS2: 传输过程中请不要关闭页面，如果附件很多，等待所有附件下载完成</p>
	<p><input type="button" class="button-primary" id="download_check" value="查看又拍云文件列表" /></p>
	<p id="downloading" style="display:none;"></p>
	<div id="download_action" style="display:none;">
		<p><span style="color: red;">又拍云存储服务下共计文件：<strong id="download_image_count">0</strong> 个</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="download_btn" value="开始下载" /></p>
		<p id="download_state" style="display:none;"><span style="color: red;">正在下载第：<strong id="download_now_number">1</strong> 个</span></p>
		<p id="download_error" style="display:none;"><span style="color: red;">下载失败：<strong id="download_error_number">0</strong> 个</span></p>
		<p id="download_result" style="display:none;color: red;"></p>
		<div>
			<textarea id="download_result_list" style="width: 100%; height: 300px;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<hr />
	<?php screen_icon(); ?>
	<h2>删除又拍云储存中的附件</h2>
	<p>PS: 每行一个( 链接为又拍云的附件，例如：http://xxx.b0.upaiyun.com/wp-file/logo.png )</p>
	<div>
		<textarea id="delete_files" style="width: 100%; height: 150px; " ></textarea>
	</div>
	<p id="delete_result" style="color:red;display:none;"></p>
	<input type="submit" class="button-primary" id="btn_delete" value="开始删除" />
	<div style="padding: 30px 10px 0;text-align: right;"><b>By :</b> <a href="https://www.cuelog.com" target="_blank">cuelog.com</a></div>
<?php }?>
	
	<script type="text/javascript">
	jQuery(function($){

		// 删除文件
		$('#btn_delete').click(function(){
			var delete_files = $('#delete_files').val();
			if(delete_files == ''){
				alert('要删除啥？');
				return false;
			}
			$('#btn_delete').attr('disabled','disabled').val('删除中，稍等...');
			$.get('/wp-admin/admin-ajax.php',{'action': 'upyun_ajax', 'do': 'delete', 'files': delete_files}, function(data){
				if(data){
					$('#delete_result').html(data).slideDown();
				}else{
					$('#delete_result').html('服务器没有响应').slideDown();
				}
				$('#btn_delete').removeAttr('disabled').val('开始删除');
			}, 'HTML');
		});
		
		var textarea = $('#upload_reslut_list');
		var error_list = '', 
			now_number = 0, 
			error_number = 0, 
			error_count = 0, 
			start_meta_id = 0, 
			upload_error_list = new Array();
		
		function _regain(meta_id) {
			return function() {
				start_upload(meta_id);
			};
		}
		// 上传开始
		function start_upload(meta_id) {
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'upyun_ajax', 'do': 'upload', 'meta_id': meta_id},
				error: function() {
					if(error_count == 240) {
						start_meta_id = meta_id;
						$('#upload_result').html('上传过程出错，重新尝试了40分钟，服务器宕机吗？ 请不要关闭页面，请检查服务器链接后再恢复上传').fadeIn('fast');
						$('#upload_btn').removeAttr('disabled').val('恢复上传');
						error_count = 0;
					} else {
						setTimeout(_regain(meta_id),10000);
						error_count += 1;
						$('#upload_result').html('上传出错，服务器返回数据失败，进行第 '+error_count+' 次尝试，请稍等...').fadeIn('fast');
						return false;
					}
				},
				success: function(data) {
					if(data == '0') {
						$('#upload_btn').removeAttr('disabled').val('开始上传');
						$('#upload_state').hide();
						now_number = 0;
						$('#now_number').text(1);
						if(error_number == 0){
							$('#upload_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;"  /> 所有附件上传成功！').fadeIn('fast');
						}else {
							$('#upload_result').html('上传完毕').fadeIn('fast');
							$('#upload_again').fadeIn();
						}
					}else if (data.error_list) {
						for(var i in data.error_list) {
							upload_error_list.push(data.error_list[i]);
						}
						$('#upload_reslut_list').slideDown('fast');
						$('#upload_error').slideDown('fast');
						$('#error_number').text(error_number += data.error_list.length);
						textarea.val(data.error + textarea.val());
						start_upload(data.meta_id);
						$('#now_number').text(now_number += 1);
					}else if(data.meta_id > 0){
						start_upload(data.meta_id);
						$('#now_number').text(now_number += 1);
					}
				}
			});
		}

		// 错误列表续传
		$('#upload_again_btn').click(function(){
			textarea.val('');
			var btn = $(this);
			btn.attr('disabled', 'disabled').val('请稍等...');
			for (var i in upload_error_list) {
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'upyun_ajax', 'do': 'upload_again', 'file': upload_error_list[i]},
					success: function(data){
						if(data != '1'){
							textarea.val(data + textarea.val());
						} else {
							upload_error_list.splice(i,1);
							$('#error_number').text(error_number -= 1);
						}
					},
					complete: function() {
						if(error_number == 0 ) {
							$('#upload_again,#upload_error,#upload_reslut_list').hide();
							$('#upload_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;"  /> 所有附件上传成功！').fadeIn('fast');
						}
						if(i == upload_error_list.length - 1){
							btn.removeAttr('disabled').val('多次尝试后无法解决请检查文件');
						}
					}
				});
			}
		});
		

		// 检查本地媒体库数量
		$('#upload_check').click(function(){
			$('#upload_action,#upload_error,#upload_result,#upload_state,#upload_reslut_list').hide();
			textarea.val(null);
			var upload_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'upyun_ajax', 'do': 'get_local_count'},
				error: function(){
					alert('获取附件列表失败，可能是服务器超时了');
				},
				beforeSend: function(){
					upload_check.attr('disabled','disabled');
					$('#loading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					upload_check.removeAttr('disabled');
					if(data > 0){
						$('#loading').hide();
						$('#upload_action').fadeIn('fast');
						$('#upload_btn').removeAttr('disabled');
						$('#image_count').text(data);
					}else{
						$('#loading').html('没有找到任何附件');
					}
				}
			});
		});

		// 开始上传
		$('#upload_btn').click(function(){
			$('#upload_result').hide();
			$('#upload_state').slideDown('fast');
			$(this).attr('disabled','disabled').val('上传过程中请勿关闭页面...');
			textarea.val('');
			start_upload(start_meta_id);
		});

		// 获取又拍云储存的所有文件列表
		var down_list = null;
		var down_textarea = $('#download_result_list');
		$('#download_check').click(function(){
			$('#download_action,#download_error,#download_result,#download_state').hide();
			down_textarea.val(null);
			var download_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'upyun_ajax', 'do': 'get_upyun_list'},
				timeout: 30000,
				error: function(){
					alert('获取文件列表失败，如果文件数量是万量级别，请用FTP工具下载');
				},
				beforeSend: function(){
					download_check.attr('disabled','disabled');
					$('#downloading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					download_check.removeAttr('disabled');
					if(data && data.count > 0){
						$('#downloading').hide();
						$('#download_action').fadeIn('fast');
						$('#download_btn').removeAttr('disabled');
						$('#download_image_count').text(data.count);
						down_list = data;
						for(var i in data.url){
							down_textarea.val(data.url[i] + "\r\n" + down_textarea.val());
						}
					}else{
						$('#downloading').html('没有找到任何文件');
					}
				}
			});
		});


		// 开始下载
		$('#download_btn').click(function(){
			if(down_list.count == 0){
				alert('又拍云服务没有文件');
				return false;
			}
			error_list = '';
			var btn = $(this);
			var download_state = $('#download_state');
			$('#download_result').hide();
			download_state.slideDown('fast');
			btn.attr('disabled','disabled').val('下载过程中请勿关闭页面...');
			down_textarea.val('');
			var download_now_number = 0, download_error_number = 0;
			for(var i in down_list.url){
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'upyun_ajax', 'do': 'download', 'file_path': down_list.url[i]},
					error: function(){
						down_textarea.val('【Error】 下载失败，请手动下载 >> '+down_list.url[i]);
					},
					success: function(data){
						$('#download_now_number').text(download_now_number += 1);
						if(data.indexOf('Error') > 0){
							error_list =  data + "\r\n" + error_list;
							$('#download_error').slideDown('fast');
							$('#download_error_number').text(download_error_number += 1);
						}
						down_textarea.val(data + "\r\n" + down_textarea.val());
					},
					complete: function(){
						if(download_now_number == down_list.count){
							btn.removeAttr('disabled').val('开始下载');
							$('#download_state').hide();
							if(download_error_number == 0){
								$('#download_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;" /> 所有文件下载成功！').fadeIn('fast');
							}else{
								down_textarea.val(error_list);
								error_list = '';
							}
						}
					}
				});
			}
		});

	});
	</script>
</div>
<?php 
	}
}
?>
