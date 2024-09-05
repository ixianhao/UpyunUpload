<?php
/**
 * 又拍云图片上传插件
 * 
 * @package UpyunUpload
 * @author ixianhao
 * @version 1.2.3
 * @link https://ixianhao.com
 */
class UpyunUpload_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('UpyunUpload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('UpyunUpload_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('UpyunUpload_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('UpyunUpload_Plugin', 'attachmentHandle');

        return _t('插件已经激活，请配置又拍云参数');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件已被禁用');
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $bucket = new Typecho_Widget_Helper_Form_Element_Text(
            'bucket', null, '', 
            _t('空间名称'), 
            _t('请填写又拍云空间名称')
        );
        $form->addInput($bucket->addRule('required', _t('必须填写空间名称')));
        
        $operator = new Typecho_Widget_Helper_Form_Element_Text(
            'operator', null, '', 
            _t('操作员'), 
            _t('请填写又拍云操作员名称')
        );
        $form->addInput($operator->addRule('required', _t('必须填写操作员名称')));
        
        $password = new Typecho_Widget_Helper_Form_Element_Password(
            'password', null, '', 
            _t('操作员密码'), 
            _t('请填写又拍云操作员密码')
        );
        $form->addInput($password->addRule('required', _t('必须填写操作员密码')));
        
        $domain = new Typecho_Widget_Helper_Form_Element_Text(
            'domain', null, '', 
            _t('空间域名'), 
            _t('请填写又拍云空间绑定的域名，如https://yourname.b0.upaiyun.com')
        );
        $form->addInput($domain->addRule('required', _t('必须填写空间域名'))
                               ->addRule('url', _t('请填写正确的URL')));

        $uploadDir = new Typecho_Widget_Helper_Form_Element_Text(
            'uploadDir', null, 'xug_cc_tupian', 
            _t('上传目录'), 
            _t('请填写文件上传的目录，如xug_cc_tupian')
        );
        $form->addInput($uploadDir);
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}
    
    /**
     * 上传文件处理函数
     * 
     * @access public
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }
        
        $ext = self::getExtension($file['name']);
        
        if (in_array($ext, array('gif', 'jpg', 'jpeg', 'png', 'bmp', 'tiff' , 'webp' , 'avif'))) {
            return self::uploadToUpyun($file);
        } else {
            return self::uploadToLocal($file);
        }
    }

    /**
     * 上传文件到又拍云
     * 
     * @access private
     * @param array $file 上传的文件
     * @return mixed
     */
    private static function uploadToUpyun($file)
    {
        self::log("开始上传文件到又拍云: " . $file['name']);
        
        $options = Typecho_Widget::widget('Widget_Options')->plugin('UpyunUpload');
        
        if (empty($options->bucket) || empty($options->operator) || empty($options->password) || empty($options->domain)) {
            self::log("上传失败：又拍云配置信息不完整");
            return false;
        }
        
        $ext = self::getExtension($file['name']);
        $uploadDir = $options->uploadDir ? trim($options->uploadDir, '/') . '/' : '';
        $filePath = '/' . $uploadDir . 'uploads/' . date('Y/m/') . uniqid() . '.' . $ext;
        
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $uri = "/{$options->bucket}{$filePath}";
        
        $fileContent = file_get_contents($file['tmp_name']);
        $fileSize = strlen($fileContent);
        
        self::log("File size: " . $fileSize . " bytes");
        
        $method = 'PUT';
        $passwordMd5 = md5($options->password);
        $stringToSign = "{$method}&{$uri}&{$date}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $passwordMd5, true));
        
        self::log("签名详情: StringToSign = {$stringToSign}");
        self::log("密码MD5: {$passwordMd5}");
        self::log("生成的签名: {$signature}");
        
        $url = "http://v0.api.upyun.com{$uri}";
        $headers = array(
            'Authorization: UPYUN ' . $options->operator . ':' . $signature,
            'Date: ' . $date,
            'Content-Type: ' . $file['type'],
            'Content-Length: ' . $fileSize,
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        self::log("上传请求详情: URL = {$url}, Headers = " . print_r($headers, true));
        
        if ($httpCode == 200) {
            self::log("文件上传成功: {$file['name']} -> {$filePath}");
            return array(
                'name' => $file['name'],
                'path' => $filePath,
                'size' => $fileSize,
                'type' => $ext,
                'mime' => $file['type'],
                'parameters' => array('upyun' => true)
            );
        } else {
            self::log("上传到又拍云失败: HTTP Code {$httpCode}, Response: {$response}, Error: {$error}");
            return false;
        }
    }


    /**
     * 上传文件到本地
     * 
     * @access private
     * @param array $file 上传的文件
     * @return mixed
     */
    private static function uploadToLocal($file)
    {
        self::log("开始上传非图片文件到本地: " . $file['name']);
        
        $options = Typecho_Widget::widget('Widget_Options');
        $date = new Typecho_Date($options->gmtTime);
        
        $path = Typecho_Common::url(defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads',
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__);
        
        $path = $path . '/' . $date->year . '/' . $date->month;
        
        if (!isset($file['tmp_name'])) {
            return false;
        }
        
        if (!@is_dir($path)) {
            if (!@mkdir($path, 0755, true)) {
                return false;
            }
        }
        
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . self::getExtension($file['name']);
        $path = $path . '/' . $fileName;
        
        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } else if (isset($file['bytes'])) {
            if (!file_put_contents($path, $file['bytes'])) {
                return false;
            }
        } else {
            return false;
        }
        
        self::log("非图片文件上传成功: {$file['name']} -> {$path}");
        
        return array(
            'name' => $file['name'],
            'path' => (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads') . '/' . $date->year . '/' . $date->month . '/' . $fileName,
            'size' => $file['size'],
            'type' => $file['type'],
            'mime' => Typecho_Common::mimeContentType($path)
        );
    }

    /**
     * 修改文件处理函数
     * 
     * @access public
     * @param array $content 旧的文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        
        $ext = self::getExtension($file['name']);
        
        if (in_array($ext, array('gif', 'jpg', 'jpeg', 'png', 'bmp', 'tiff' , 'webp' , 'avif'))) {
            if (isset($content['attachment']->parameters['upyun'])) {
                self::deleteUpyunFile($content['attachment']->path);
            } else {
                @unlink(Typecho_Common::url($content['attachment']->path, __TYPECHO_ROOT_DIR__));
            }
            return self::uploadToUpyun($file);
        } else {
            if (isset($content['attachment']->parameters['upyun'])) {
                self::deleteUpyunFile($content['attachment']->path);
            } else {
                @unlink(Typecho_Common::url($content['attachment']->path, __TYPECHO_ROOT_DIR__));
            }
            return self::uploadToLocal($file);
        }
    }
    
    /**
     * 删除文件
     * 
     * @access public
     * @param array $content 文件相关信息
     * @return boolean
     */
    public static function deleteHandle($content)
    {
        if (isset($content['attachment']->parameters['upyun'])) {
            return self::deleteUpyunFile($content['attachment']->path);
        } else {
            return @unlink(Typecho_Common::url($content['attachment']->path, __TYPECHO_ROOT_DIR__));
        }
    }
    
    /**
     * 获取实际文件数据
     * 
     * @access public
     * @param array $content
     * @return string
     */
    public static function attachmentHandle($content)
    {
        if (isset($content['attachment']->parameters['upyun'])) {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('UpyunUpload');
            return Typecho_Common::url($content['attachment']->path, $options->domain);
        } else {
            return Typecho_Common::url($content['attachment']->path, Typecho_Widget::widget('Widget_Options')->siteUrl);
        }
    }

    /**
     * 获取文件扩展名
     * 
     * @access private
     * @param string $fileName
     * @return string
     */
    private static function getExtension($fileName)
    {
        $info = pathinfo($fileName);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 写入日志
     *
     * @access private
     * @param string $message 日志信息
     * @return void
     */
    private static function log($message)
    {
        if (defined('__TYPECHO_ROOT_DIR__')) {
            $log = __TYPECHO_ROOT_DIR__ . '/usr/plugins/UpyunUpload/upload.log';
            $dir = dirname($log);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (is_writable($dir)) {
                file_put_contents($log, date('Y-m-d H:i:s') . " {$message}\n", FILE_APPEND);
            }
        }
    }

    /**
     * 从又拍云删除文件
     * 
     * @access private
     * @param string $path 文件路径
     * @return boolean
     */
    private static function deleteUpyunFile($path)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('UpyunUpload');
        
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $uri = "/{$options->bucket}{$path}";
        $method = 'DELETE';
        
        $passwordMd5 = md5($options->password);
        $stringToSign = "{$method}&{$uri}&{$date}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $passwordMd5, true));
        
        $url = "http://v0.api.upyun.com{$uri}";
        $headers = array(
            'Authorization: UPYUN ' . $options->operator . ':' . $signature,
            'Date: ' . $date
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode == 200;
    }
}
