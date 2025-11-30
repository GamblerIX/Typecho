<?php

/**
 * 地理位置模块
 * 
 * 负责IP地理位置查询和地区拦截检查
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use ip2region\xdb\Searcher;
use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/PathHelper.php';

/**
 * GeoLocation 类
 * 
 * 提供IP地理位置查询功能，支持IPv4和IPv6
 */
class GeoLocation
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * ip2region IPv4 搜索器实例
     */
    private static $geoSearcherV4 = null;
    
    /**
     * ip2region IPv6 搜索器实例
     */
    private static $geoSearcherV6 = null;
    
    /**
     * 地理位置查询缓存
     */
    private static $geoCache = [];
    
    /**
     * 查询IP地理位置
     * 
     * @param string $ip IP地址
     * @return string|null 地理位置信息，格式: 国家|区域|省份|城市|ISP
     */
    public static function lookupIPLocation(string $ip): ?string
    {
        if (isset(self::$geoCache[$ip])) {
            return self::$geoCache[$ip];
        }
        
        $version = self::getIPVersion($ip);
        if ($version === null) {
            error_log(self::PLUGIN_NAME . " Invalid IP address: {$ip}");
            return null;
        }
        
        if (!self::initGeoLocation($version)) {
            return null;
        }
        
        try {
            $searcher = ($version === 4) ? self::$geoSearcherV4 : self::$geoSearcherV6;
            $region = $searcher->search($ip);
            
            if ($region) {
                self::$geoCache[$ip] = $region;
                return $region;
            }
            
            return null;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " lookupIPLocation Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查IP是否在被禁地区
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @return bool 是否在被禁地区
     */
    public static function isBlockedRegion(string $ip, object $config): bool
    {
        if (!isset($config->blockedRegions) || empty($config->blockedRegions)) {
            return false;
        }
        
        $location = self::lookupIPLocation($ip);
        
        if (!$location) {
            return false;
        }
        
        $blockedRegions = explode("\n", $config->blockedRegions);
        
        foreach ($blockedRegions as $region) {
            $region = trim($region);
            
            if (empty($region) || strpos($region, '#') === 0) {
                continue;
            }
            
            if (stripos($location, $region) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检测IP版本
     * 
     * @param string $ip IP地址
     * @return int|null 4表示IPv4，6表示IPv6，null表示无效IP
     */
    private static function getIPVersion(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 4;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 6;
        }
        return null;
    }
    
    /**
     * 初始化地理位置搜索器
     * 
     * @param int $version IP版本（4或6）
     * @return bool 初始化是否成功
     */
    private static function initGeoLocation(int $version): bool
    {
        if ($version === 4) {
            if (self::$geoSearcherV4 !== null) {
                return true;
            }
            
            try {
                $dbPath = PathHelper::getAssetPath('ip2region/ip2region_v4.xdb');
                
                if (!file_exists($dbPath)) {
                    error_log(self::PLUGIN_NAME . " IPv4 database not found: {$dbPath}");
                    error_log(self::PLUGIN_NAME . " Expected location: " . PathHelper::getAssetPath('ip2region/ip2region_v4.xdb'));
                    return false;
                }
                
                self::$geoSearcherV4 = Searcher::newWithFileOnly(IPv4::default(), $dbPath);
                return true;
            } catch (\Exception $e) {
                error_log(self::PLUGIN_NAME . " initGeoLocation IPv4 Error: " . $e->getMessage());
                return false;
            }
        } elseif ($version === 6) {
            if (self::$geoSearcherV6 !== null) {
                return true;
            }
            
            try {
                $dbPath = PathHelper::getAssetPath('ip2region/ip2region_v6.xdb');
                
                if (!file_exists($dbPath)) {
                    error_log(self::PLUGIN_NAME . " IPv6 database not found: {$dbPath}");
                    error_log(self::PLUGIN_NAME . " Expected location: " . PathHelper::getAssetPath('ip2region/ip2region_v6.xdb'));
                    return false;
                }
                
                self::$geoSearcherV6 = Searcher::newWithFileOnly(IPv6::default(), $dbPath);
                return true;
            } catch (\Exception $e) {
                error_log(self::PLUGIN_NAME . " initGeoLocation IPv6 Error: " . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
}
