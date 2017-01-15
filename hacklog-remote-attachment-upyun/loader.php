<?php
/*
Plugin Name: Hacklog Remote Attachment Upyun
Plugin URI: https://github.com/ihacklog/hacklog-remote-attachment-upyun
Description: WordPress 远程附件上传插件for Upyun(又拍云).Remote attachment support for WordPress.Support Multisite.
Version: 1.5.2
Author: 荒野无灯
Author URI: http://80x86.io/
*/

/**
 * @package Hacklog Remote Attachment Upyun
 * @encoding UTF-8
 * @author 荒野无灯 <HuangYeWuDeng>
 * @link http://ihacklog.com
 * @copyright Copyright (C) 2012 荒野无灯
 * @license http://www.gnu.org/licenses/
 */

/*
 Copyright 2012  荒野无灯

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */
if (! defined ( 'ABSPATH' )) {
	die ( 'What are you doing?' );
}

//the Hacklog Remote Attachment plugin maybe loaded after this plugin,so,we can only remind the user.
function check_hacklog_ra_upyun_compability()
{
if (class_exists ( 'hacklogra' ))
{
	add_action ( 'admin_notices', create_function ( '', 'echo "<div class=\"error\"><p>Error: you have already activated <strong>Hacklog Remote Attachment</strong>,Please deactivate it and then activate this plugin(<strong>Hacklog Remote Attachment Upyun</strong>).</p></div>";' ) );
}
}
add_action('admin_init','check_hacklog_ra_upyun_compability');


define ( 'HACKLOG_RA_UPYUN_LOADER', __FILE__ );
require plugin_dir_path ( __FILE__ ) . '/includes/hacklogra_upyun.class.php';
new hacklogra_upyun ();
