<?php

/**
 * 安全检测模块
 * 
 * 负责SQL注入、XSS、CSRF等攻击检测，以及User-Agent和Referer异常检测
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Request;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * SecurityDetector 类
 * 
 * 提供各种安全威胁检测功能
 */
class SecurityDetector
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * SQL注入检测
     * 
     * @return bool 是否检测到SQL注入
     */
    public static function detectSQLInjection(): bool
    {
        $sqlPatterns = [
            '/union.*select/i',
            '/select.*from/i',
            '/insert.*into/i',
            '/delete.*from/i',
            '/update.*set/i',
            '/drop.*table/i',
            '/sleep\s*\(/i',
            '/benchmark\s*\(/i'
        ];
        
        foreach ($_GET as $value) {
            if (is_string($value)) {
                foreach ($sqlPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        return true;
                    }
                }
            }
        }
        
        foreach ($_POST as $value) {
            if (is_string($value)) {
                foreach ($sqlPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * XSS攻击检测
     * 
     * @return bool 是否检测到XSS攻击
     */
    public static function detectXSS(): bool
    {
        $xssPatterns = [
            '/<script[^>]*>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/eval\s*\(/i'
        ];
        
        $allParams = array_merge($_GET, $_POST, $_COOKIE);
        
        foreach ($allParams as $value) {
            if (is_string($value)) {
                foreach ($xssPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * CSRF攻击检测
     * 
     * @return bool 是否检测到CSRF攻击
     */
    public static function detectCSRF(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
            
            if (!empty($referer) && !empty($host)) {
                if (strpos($referer, $host) === false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查User-Agent是否异常
     * 
     * @param object|null $config 插件配置对象
     * @return string|bool 异常原因字符串，或false表示正常
     */
    public static function checkUserAgentAnomaly(?object $config = null)
    {
        $request = new Request();
        $userAgent = $request->getAgent();
        
        if (empty($userAgent)) {
            return 'UA为空';
        }
        
        if ($config && isset($config->uaWhitelist) && !empty($config->uaWhitelist)) {
            if (self::isUserAgentWhitelisted($userAgent, $config->uaWhitelist)) {
                return false;
            }
        }
        
        $maliciousUAs = [
            'sqlmap', 'nmap', 'nikto', 'wpscan', 'acunetix',
            'python-requests', 'go-http-client', 'curl/', 'wget/'
        ];
        
        $knownGoodBots = [
            'googlebot', 'bingbot', 'baiduspider', 'yandexbot'
        ];
        
        foreach ($knownGoodBots as $goodBot) {
            if (stripos($userAgent, $goodBot) !== false) {
                return false;
            }
        }
        
        foreach ($maliciousUAs as $uaPattern) {
            if (stripos($userAgent, $uaPattern) !== false) {
                return 'UA异常';
            }
        }
        
        return false;
    }
    
    /**
     * 检查User-Agent是否在白名单中
     * 
     * @param string $userAgent User-Agent字符串
     * @param string $whitelist 白名单配置（每行一个）
     * @return bool 是否在白名单中
     */
    public static function isUserAgentWhitelisted(string $userAgent, string $whitelist): bool
    {
        if (empty($whitelist) || empty($userAgent)) {
            return false;
        }
        
        $lines = explode("\n", $whitelist);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || substr($line, 0, 1) === '#') {
                continue;
            }
            
            if (strcasecmp($userAgent, $line) === 0) {
                return true;
            }
            
            if (stripos($userAgent, $line) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查Referer是否异常
     * 
     * @return string|bool 异常原因字符串，或false表示正常
     */
    public static function checkRefererAnomaly()
    {
        $request = new Request();
        $referer = $request->getReferer();
        
        if (empty($referer)) {
            return false;
        }
        
        $suspiciousReferers = [
            'casino', 'poker', 'gambling', 'adult', 'sex'
        ];
        
        foreach ($suspiciousReferers as $refPattern) {
            if (stripos($referer, $refPattern) !== false) {
                return '来源异常';
            }
        }
        
        return false;
    }
    
    /**
     * 敏感词检测
     * 
     * @param string $content 待检测内容
     * @param string $wordsConfig 敏感词配置（每行一个）
     * @return bool 是否包含敏感词
     */
    public static function detectSensitiveWords(string $content, string $wordsConfig): bool
    {
        if (empty($wordsConfig) || empty($content)) {
            return false;
        }
        
        // 解析敏感词配置
        $lines = explode("\n", $wordsConfig);
        
        foreach ($lines as $line) {
            $word = trim($line);
            
            if (empty($word) || substr($word, 0, 1) === '#') {
                continue;
            }
            
            if (stripos($content, $word) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
