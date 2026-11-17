<?php

final class SafeImageUpload
{
    private const TYPES=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

    public static function storeUploaded(array $file,string $directory,string $prefix,int $maxBytes=2097152):string
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new InvalidArgumentException('No valid image was uploaded');
        if((int)($file['size']??0)<1||(int)$file['size']>$maxBytes)throw new InvalidArgumentException('Image is too large');
        $bytes=file_get_contents((string)$file['tmp_name']);
        if($bytes===false)throw new RuntimeException('Could not read uploaded image');
        return self::storeBytes($bytes,$directory,$prefix,$maxBytes);
    }

    public static function storeBytes(string $bytes,string $directory,string $prefix,int $maxBytes=2097152):string
    {
        if($bytes===''||strlen($bytes)>$maxBytes)throw new InvalidArgumentException('Image is too large');
        $image=@getimagesizefromstring($bytes);
        $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->buffer($bytes);
        if(!is_array($image)||!is_string($mime)||!isset(self::TYPES[$mime])||($image['mime']??'')!==$mime)throw new InvalidArgumentException('Image content is invalid');
        if((int)($image[0]??0)<1||(int)($image[1]??0)<1||(int)$image[0]>6000||(int)$image[1]>6000)throw new InvalidArgumentException('Image dimensions are invalid');
        if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new RuntimeException('Could not prepare image storage');
        self::protectDirectory($directory);
        $safePrefix=preg_replace('/[^A-Za-z0-9_-]/','',$prefix)?:'image';
        $filename=$safePrefix.'_'.bin2hex(random_bytes(16)).'.'.self::TYPES[$mime];
        if(file_put_contents($directory.DIRECTORY_SEPARATOR.$filename,$bytes,LOCK_EX)===false)throw new RuntimeException('Could not save image');
        @chmod($directory.DIRECTORY_SEPARATOR.$filename,0640);
        return $filename;
    }

    private static function protectDirectory(string $directory):void
    {
        $rules="Options -Indexes\n<FilesMatch \"\\.(?:php|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n";
        $path=$directory.DIRECTORY_SEPARATOR.'.htaccess';
        if(!is_file($path))@file_put_contents($path,$rules,LOCK_EX);
    }
}
