<?php
namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class PathHelper
{
    private static $pluginRoot = null;
    
    public static function getPluginRoot(): string
    {
        if (self::$pluginRoot === null) {
            self::$pluginRoot = dirname(dirname(__DIR__));
        }
        return self::$pluginRoot;
    }
    
    public static function getAssetPath(string $relativePath): string
    {
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        return self::getPluginRoot() . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }
    
    public static function getAssetUrl(string $relativePath): string
    {
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        $pluginUrl = \Typecho\Common::url('usr/plugins/BlockIPForTypecho', \Typecho\Widget::widget('Widget_Options')->siteUrl);
        return $pluginUrl . '/assets/' . ltrim($relativePath, '/\\');
    }
    
    public static function getConsolePanelUrl(string $tab = 'security', array $params = []): string
    {
        $url = '?panel=' . self::getConsolePanelPath();
        $url .= '&tab=' . urlencode($tab);
        
        foreach ($params as $key => $value) {
            $url .= '&' . urlencode($key) . '=' . urlencode($value);
        }
        
        return $url;
    }
    
    public static function getConsolePanelPath(): string
    {
        return 'BlockIPForTypecho/ext/Console.php';
    }
}
