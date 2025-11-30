<?php
namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Widget;
use Utils\Helper;
use Widget\ActionInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/PathHelper.php';

class CaptchaAction extends Widget implements ActionInterface
{
    public function render()
    {
        Helper::security()->protect();
        session_start();
        
        $captcha = $this->generateCaptcha();
        $_SESSION['blockip_captcha'] = $captcha['code'];
        
        header('Content-type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        imagepng($captcha['image']);
        imagedestroy($captcha['image']);
        exit;
    }
    
    private function generateCaptcha(): array
    {
        $width = 100;
        $height = 30;
        $image = imagecreatetruecolor($width, $height);
        
        imagealphablending($image, false);
        imagesavealpha($image, true);
        
        $background_color = imagecolorallocatealpha($image, 255, 255, 255, 127);
        imagefill($image, 0, 0, $background_color);
        
        for ($i = 0; $i < 100; $i++) {
            $noise_color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));
            imagesetpixel($image, rand(0, $width), rand(0, $height), $noise_color);
        }
        
        $code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4);
        $x = 10;
        
        for ($i = 0; $i < strlen($code); $i++) {
            $text_color = imagecolorallocate($image, rand(0, 255), rand(0, 255), rand(0, 255));
            $angle = rand(-15, 15);
            $y = rand(20, 25);
            
            $temp_image = imagecreatetruecolor(20, 30);
            imagealphablending($temp_image, false);
            imagesavealpha($temp_image, true);
            imagefill($temp_image, 0, 0, $background_color);
            
            $fontPath = PathHelper::getAssetPath('assets/captcha-font.ttf');
            
            if (!file_exists($fontPath)) {
                error_log(Plugin::PLUGIN_NAME . " Captcha font file not found: {$fontPath}");
                error_log(Plugin::PLUGIN_NAME . " Expected location: " . PathHelper::getAssetPath('assets/captcha-font.ttf'));
                imagestring($temp_image, 5, 0, 5, $code[$i], $text_color);
            } else {
                imagettftext($temp_image, 20, $angle, 0, $y, $text_color, $fontPath, $code[$i]);
            }
            
            imagecopy($image, $temp_image, $x, 0, 0, 0, 20, 30);
            $x += 25;
            imagedestroy($temp_image);
        }
        
        return ['image' => $image, 'code' => $code];
    }
    
    public function action()
    {
    }
}
