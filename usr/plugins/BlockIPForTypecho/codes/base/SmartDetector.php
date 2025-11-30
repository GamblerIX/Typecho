<?php

/**
 * 智能检测模块
 * 
 * 负责智能威胁检测，包括访问频率异常检测和多维度威胁评估
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * SmartDetector 类
 * 
 * 提供智能威胁检测功能
 */
class SmartDetector
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 智能威胁检测
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @return array 检测到的威胁原因数组
     */
    public static function isSmartBlocked(string $ip, object $config): array
    {
        $reasons = [];
        
        if (self::checkFrequencyAnomaly($ip, $config)) {
            $reasons[] = '频率异常';
        }
        
        $uaAnomaly = SecurityDetector::checkUserAgentAnomaly($config);
        if ($uaAnomaly) {
            $reasons[] = 'UA异常';
        }
        
        $refererAnomaly = SecurityDetector::checkRefererAnomaly();
        if ($refererAnomaly) {
            $reasons[] = '来源异常';
        }
        
        return $reasons;
    }
    
    /**
     * 检测访问频率异常
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @return bool 是否检测到频率异常
     */
    public static function checkFrequencyAnomaly(string $ip, object $config): bool
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $currentTime = time();
            
            // 检查1秒内不同URL访问数（阈值5）
            $count1s = $db->fetchObject($db->select('COUNT(DISTINCT url) as count')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 1));
            
            if ($count1s && $count1s->count >= 5) {
                if (isset($config->debugMode) && $config->debugMode) {
                    error_log(self::PLUGIN_NAME . " Debug: IP {$ip} 1秒内访问了 {$count1s->count} 个不同URL");
                }
                return true;
            }
            
            // 检查5秒内访问次数（阈值10）
            $count5s = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 5));
            
            if ($count5s && $count5s->count >= 10) {
                if (isset($config->debugMode) && $config->debugMode) {
                    error_log(self::PLUGIN_NAME . " Debug: IP {$ip} 5秒内访问了 {$count5s->count} 次");
                }
                return true;
            }
            
            // 检查10秒内访问次数（阈值20）
            $count10s = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 10));
            
            if ($count10s && $count10s->count >= 20) {
                if (isset($config->debugMode) && $config->debugMode) {
                    error_log(self::PLUGIN_NAME . " Debug: IP {$ip} 10秒内访问了 {$count10s->count} 次");
                }
                return true;
            }
            
            // 检查60秒内访问次数（阈值60）
            $count60s = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 60));
            
            if ($count60s && $count60s->count >= 60) {
                if (isset($config->debugMode) && $config->debugMode) {
                    error_log(self::PLUGIN_NAME . " Debug: IP {$ip} 60秒内访问了 {$count60s->count} 次");
                }
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
