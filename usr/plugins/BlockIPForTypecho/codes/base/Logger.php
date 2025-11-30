<?php

/**
 * 日志管理模块
 * 
 * 负责记录拦截日志、自动拉黑日志和访问日志
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Db;
use Typecho\Request;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Logger 类
 * 
 * 提供日志记录和管理功能
 */
class Logger
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 记录拦截日志
     * 
     * @param string $ip 被拦截的IP地址
     * @param string $reason 拦截原因
     * @return void
     */
    public static function logBlockedAccess(string $ip, string $reason): void
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $request = new Request();
            $currentTime = time();
            
            $db->query($db->insert($prefix . 'blockip_logs')
                ->rows([
                    'ip' => $ip,
                    'reason' => $reason,
                    'url' => substr($request->getRequestUrl(), 0, 500),
                    'user_agent' => substr((string)$request->getAgent(), 0, 500),
                    'created' => $currentTime,
                    'timestamp' => $currentTime
                ]));
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " logBlockedAccess Error: " . $e->getMessage());
        }
    }
    
    /**
     * 记录自动拉黑日志
     * 
     * @param string $ip 被拉黑的IP地址
     * @param string $detectionType 检测类型
     * @return void
     */
    public static function logAutoBlacklist(string $ip, string $detectionType): void
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $request = new Request();
            $currentTime = time();
            
            $db->query($db->insert($prefix . 'blockip_logs')
                ->rows([
                    'ip' => $ip,
                    'reason' => '自动拉黑：' . $detectionType,
                    'url' => substr($request->getRequestUrl(), 0, 500),
                    'user_agent' => substr((string)$request->getAgent(), 0, 500),
                    'created' => $currentTime,
                    'timestamp' => $currentTime
                ]));
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " logAutoBlacklist Error: " . $e->getMessage());
        }
    }
    
    /**
     * 记录最后访问时间
     * 
     * @param string $ip 访问的IP地址
     * @param string $url 访问的URL（可选）
     * @return void
     */
    public static function recordLastAccess(string $ip, string $url = ''): void
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $request = new Request();
            $currentTime = time();
            
            $existingRecord = $db->fetchRow($db->select()
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ?', $ip));
            
            if ($existingRecord) {
                $db->query($db->update($prefix . 'blockip_access_log')
                    ->rows([
                        'url' => substr($url, 0, 500),
                        'user_agent' => substr((string)$request->getAgent(), 0, 500),
                        'last_access' => $currentTime,
                        'timestamp' => $currentTime
                    ])
                    ->where('ip = ?', $ip));
            } else {
                $db->query($db->insert($prefix . 'blockip_access_log')
                    ->rows([
                        'ip' => $ip,
                        'url' => substr($url, 0, 500),
                        'user_agent' => substr((string)$request->getAgent(), 0, 500),
                        'last_access' => $currentTime,
                        'timestamp' => $currentTime
                    ]));
            }
            
            // 清理30天前的旧记录
            $db->query($db->delete($prefix . 'blockip_access_log')
                ->where('last_access < ?', $currentTime - 2592000));
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " recordLastAccess Error: " . $e->getMessage());
        }
    }
}
