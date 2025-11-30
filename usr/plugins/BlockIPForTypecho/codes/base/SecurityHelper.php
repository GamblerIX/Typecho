<?php
namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class SecurityHelper
{
    public static function isAdminArea(string $url): bool
    {
        $adminPaths = ['/admin/', 'admin.php', '/action/', 'action/'];
        
        foreach ($adminPaths as $path) {
            if (strpos($url, $path) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
