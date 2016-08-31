# upyun_plugins
wordpress upyun cdn plugins

=== Upyun For Wordpress === 

本插件为第三方提供，原作者cuelog，由于该插件长时间未维护，又拍云官网有更新，本人简单优化了下后台的配置界面。

### 视频教程
https://techs.b0.upaiyun.com/videos/cdnpage/wordpress.html
### INSTALL

1.下载插件复制到plugins目录

      cd /www/wp-content/plugins/
      git clone https://github.com/monkey-wenjun/upyun_plugins.git
      mv upyun_plugins upyun
2.登录WordPress后台开启插件      

3.如果启用了 HTTPS ，插件本身是不支持 HTTPS，但是可以通过 [display upload_path and upload_url_path for WordPress3.5](https://wordpress.org/plugins/030-ps-display-upload-path-for-wp35/)  这个插件去修改图片的访问链接，具体是安装完插件后，点击 设置 --> 多媒体-->>设置，找到 “文件的完整 URL 地址为你又拍云的 HTTPS 地址”，例如  ”https://file.b0.upaiyun.com/“ 
