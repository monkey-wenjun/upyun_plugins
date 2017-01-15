# Wordpress-plugin-for-UPYUN

##WordPress插件信息

### Hacklog Remote Attachment Upyun

------------------------------------------------------------

* Contributors: ihacklog
* Donate link: http://ihacklog.com/donate
* Tags: attachment,manager,admin,images,thumbnail,ftp,remote
* Requires at least: 3.3
* Tested up to: 4.6.1
* Stable tag: 1.5.1

------------------------------------------------------------
此插件用于为你的WP博客添加Upyun附件上传支持。
可以与WordPress无缝结合，通过WordPress上传图片和文件到upyun, 支持大文件上传（需要开启表单 API)和防盗链功能。
有任何问题，欢迎大家提交issue:

github:
https://github.com/ihacklog/hacklog-remote-attachment-upyun/issues

Adds remote attachments support for your WordPress blog.

------------------------------------------------------------

### Description
Features: Adds remote attachments support for your WordPress blog.

此插件用于为你的WP博客添加Upyun附件上传支持。

可以与WordPress无缝结合，通过WordPress上传图片和文件到upyun, 支持大文件上传（需要开启表单 API)和防盗链功能。

* 支持上传文件到upyun服务器（图片、其它附件等)
* 支持同步删除（在WP后台媒体管理“删除”附件后，upyun服务器中的文件也随之删除)
* @TODO 增加图片编辑功能
* @TODO 优化防盗链功能

* 1.1.0 增加与水印插件的兼容性，使上传到远程服务器的图片同样可以加上水印
* 1.2.0 增加重复文件检测，避免同名文件被覆盖。更新和完善了帮助信息。
* 1.2.1 修正在后台上传主题或插件时的bug.
* 1.2.7 增加三种http数据发送方式支持远程附件(curl,fsockopen,streams),方便没有curl扩展支持的朋友.
* 1.2.8 增加对xmlrpc支持(支持通过Windows Live Writer 上传图片时自动上传到Upyun服务器)
* 1.2.9 修复Windows Live Writer 上传图片时url不正确的bug
* 1.3.0 修复首次使用插件时，又拍云空间使用量为0时显示“测试连接失败”的bug.增加更详细的错误信息提示。
* 1.4.0 增加form API上传支持，可上传100MB以内大小的文件（又拍云form API目前最大只支持100MB）
* 1.4.2 增加空间TOKEN防盗链功能支持
* 1.4.3 修复同一地方多处调用url时重复签名的bug
* 1.4.4 修复点击Tools区的链接时，提示“没有权限”的BUG.
* 1.4.5 更改url生成方式，避免因插件目录名称不同而导致404错误。增加对WP 3.9.1支持。修改对WP_http的调用方式。
* 1.4.6 修复在高版本WP中打开插件表单API上传页面时报错的bug.修复默认表单API超时时间未设置时导致无法使用表单API上传的bug.
* 1.5.0 编辑图片功能已经支持！
* 1.5.1 fix #18 修复一个warning提示. (thanks to [GeorgeYan](https://github.com/ihacklog/hacklog-remote-attachment-upyun/issues/18))
* 1.5.2 增加wp 4.6.1 支持,使用upyun 最新sdk
* 1.5.3 修复使用form api时一个php警告(form api上传时不应用重复应用本插件的非form api hook). 
        优化代码和修复若干bug. 移除无用的crypt类.此次更新，需要重新填写一次密码.

更多信息请访问[插件主页](http://ihacklog.com/?p=5001 "plugin homepage") 获取关于插件的更多信息，使用技巧等.
[安装指导](http://ihacklog.com/?p=4993 "安装指导")

======================================================================================

For MORE information,please visit the [plugin homepage](http://ihacklog.com/?p=5204 "plugin homepage") for any questions about the plugin.

[installation guide](http://ihacklog.com/?p=4993 "installation guide")

* version 1.1.0 added compatibility with watermark plugins
* version 1.2.0 added duplicated file checking,so that the existed remote files will not be overwrote.
* version 1.2.1 fixed the bug when uploading new theme or plugin this plugin may cause it to fail.

### Installation

1. Upload the whole fold `hacklog-remote-attachment` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin via `Settings` -> `Hacklog Remote Attachment` menu and it's OK now,you can upload attachments(iamges,videos,audio,etc.) to the remote FTP server.
4. If your have moved all your local server files to remote server,then you can `UPDATE THE DATABASE` so that all your attachments URLs will be OK.
You can visit [plugin homepage](http://ihacklog.com/?p=5001 "plugin homepage") for detailed installation guide.

### Screenshots


![截图1](/ihacklog/Wordpress-plugin-for-UPYUN/raw/master/screenshot-1.png "截图1")



![截图2](/ihacklog/Wordpress-plugin-for-UPYUN/raw/master/screenshot-2.png "截图2")



### Frequently Asked Questions
see
[Hacklog Remote Attachment FAQ](http://ihacklog.com/?p=5001 "Hacklog Remote Attachment FAQ")


### Upgrade Notice
#### 1.4.x upgrade to 1.4.2
增加空间TOKEN防盗链功能支持

#### 1.3.0 upgrade to 1.4.0
如果要使用**表单 API 功能**,注意在又拍云空间管理里面开启表单 API功能，并在插件后台选项中更新表单 API密匙.


### Changelog

### 1.4.3
* fixed: the bug that say "you have no permission to do this" when click the
  link in Tools area.

### 1.4.3
* fixed: the bug that duplicated sign the same url.

### 1.4.2
* added: TOKEN based anti-leeching feature support.

#### 1.4.1
* added: HTML5 file info detect.

#### 1.4.0
* added: form API uploading support.

#### 1.3.0
* fixed: the bug that say "Connection failed" when the bucket is empty in the first time use this plugin.
* improved: get verbose error message displayed.

#### 1.2.9
* fixed: Windows Live Writer file uploading bug(url incorrect).

#### 1.2.8
* added: xmlrpc support (when use Windows Live Writer or other client via xmlrpc upload attahcment,the attachment will auto uploaded to remote FTP server )

#### 1.2.7
* added: curl,fsockopen,streams support for http communication.

#### 1.2.6
* added: duplicated thumbnail filename (this things may happen when crop is TRUE)

#### 1.2.5
* changed: use simple xor cypher instead of using blow_fish

#### 1.2.4
* fixe: curl connection timeout will return '',change the message to more detailed one[class UpYun].

#### 1.2.3
* changed: load_textdomain param 3 uses basename(dirname()) instead of plugin_basename
* fixed: trim spaces on options
* improved: Prevent direct access to files
* changed: uses upyun HTTP REST API to create and delete directory,files
* improved: protect your API password with the strong blowfish cypher.
* improved: the plugin settings page can show you the space useage of your remote bucket.

#### 1.2.2
* ported from Hacklog Remote Attachment
