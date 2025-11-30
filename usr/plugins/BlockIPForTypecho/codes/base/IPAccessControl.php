<?php

/**
 * IP访问控制模块
 * 
 * 负责IP地址获取、黑白名单管理、IP格式匹配和访问频率控制
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
 * IPAccessControl 类
 * 
 * 提供IP访问控制功能
 */
class IPAccessControl
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 获取真实客户端IP
     * 
     * @param Request $request 请求对象
     * @return string 客户端IP地址
     */
    public static function getRealClientIP(Request $request): string
    {
        $ipHeaders = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $request->getIp();
    }
    
    /**
     * 检查IP是否在黑名单中
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @return bool 是否在黑名单中
     */
    public static function isBlacklisted(string $ip, object $config): bool
    {
        $blacklistConfig = '';
        if (isset($config->blacklist) && !empty($config->blacklist)) {
            $blacklistConfig = $config->blacklist;
        } elseif (isset($config->ips) && !empty($config->ips)) {
            $blacklistConfig = $config->ips;
        }
        
        if (empty($blacklistConfig)) {
            return false;
        }
        
        return self::matchIPRules($ip, $blacklistConfig, $config);
    }
    
    /**
     * 检查IP是否在白名单中
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @return bool 是否在白名单中
     */
    public static function isWhitelisted(string $ip, object $config): bool
    {
        if (!isset($config->whitelist) || empty($config->whitelist)) {
            return false;
        }
        
        return self::matchIPRules($ip, $config->whitelist, $config);
    }
    
    /**
     * 添加IP到黑名单
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @param string $reason 拉黑原因
     * @return void
     */
    public static function addToBlacklist(string $ip, object $config, string $reason): void
    {
        try {
            $db = Db::get();
            $blacklistConfigKey = isset($config->blacklist) ? 'blacklist' : 'ips';
            $currentBlacklist = isset($config->$blacklistConfigKey) ? $config->$blacklistConfigKey : '';
            
            $lines = explode("\n", $currentBlacklist);
            foreach ($lines as $line) {
                if (strpos(trim($line), $ip) === 0) {
                    return;
                }
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $newEntry = "{$ip} # {$reason} @ {$timestamp}";
            $updatedBlacklist = trim($currentBlacklist) . "\n" . $newEntry;
            
            $configResult = $db->fetchObject($db->select('value')
                ->from('table.options')
                ->where('name = ? AND user = 0', 'plugin:' . self::PLUGIN_NAME));
            
            if ($configResult && $configResult->value) {
                $configArray = unserialize($configResult->value);
                $configArray[$blacklistConfigKey] = $updatedBlacklist;
                
                $db->query($db->update('table.options')
                    ->rows(['value' => serialize($configArray)])
                    ->where('name = ? AND user = 0', 'plugin:' . self::PLUGIN_NAME));
            }
            
            Logger::logAutoBlacklist($ip, $reason);
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " addToBlacklist Error: " . $e->getMessage());
        }
    }
    
    /**
     * 匹配IP规则
     * 
     * @param string $ip IP地址
     * @param string $rules 规则字符串（多行）
     * @param object $config 插件配置对象
     * @return bool 是否匹配
     */
    public static function matchIPRules(string $ip, string $rules, object $config): bool
    {
        $lines = explode("\n", $rules);
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            if (self::matchSingleIPRule($ip, $line, $config)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 匹配单条IP规则
     * 
     * @param string $ip IP地址
     * @param string $rule 规则字符串
     * @param object $config 插件配置对象
     * @return bool 是否匹配
     */
    private static function matchSingleIPRule(string $ip, string $rule, object $config): bool
    {
        if (strpos($rule, '#') !== false) {
            $rule = trim(substr($rule, 0, strpos($rule, '#')));
        }
        
        if (empty($rule)) {
            return false;
        }
        
        if ($ip === $rule) {
            return true;
        }
        
        if (strpos($rule, '*') !== false) {
            return self::matchWildcard($ip, $rule);
        }
        
        if (strpos($rule, '/') !== false) {
            return self::matchCIDR($ip, $rule);
        }
        
        if (strpos($rule, '-') !== false) {
            return self::matchIPRange($ip, $rule);
        }
        
        return false;
    }
    
    /**
     * 通配符匹配
     * 
     * @param string $ip IP地址
     * @param string $pattern 通配符模式
     * @return bool 是否匹配
     */
    private static function matchWildcard(string $ip, string $pattern): bool
    {
        if (strpos($ip, ':') !== false || strpos($pattern, ':') !== false) {
            return self::matchIPv6Wildcard($ip, $pattern);
        }
        
        $ipParts = explode('.', $ip);
        $patternParts = explode('.', $pattern);
        
        if (count($ipParts) != 4 || count($patternParts) != 4) {
            return false;
        }
        
        for ($i = 0; $i < 4; $i++) {
            if ($patternParts[$i] === '*') {
                continue;
            }
            
            if ($ipParts[$i] !== $patternParts[$i]) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * IPv6 通配符匹配
     * 
     * @param string $ip IPv6地址
     * @param string $pattern 通配符模式
     * @return bool 是否匹配
     */
    private static function matchIPv6Wildcard(string $ip, string $pattern): bool
    {
        $ip = strtolower($ip);
        $pattern = strtolower($pattern);
        
        $ip = self::expandIPv6($ip);
        $pattern = self::expandIPv6($pattern);
        
        if ($ip === false || $pattern === false) {
            return false;
        }
        
        $ipParts = explode(':', $ip);
        $patternParts = explode(':', $pattern);
        
        if (count($ipParts) != 8 || count($patternParts) != 8) {
            return false;
        }
        
        for ($i = 0; $i < 8; $i++) {
            if ($patternParts[$i] === '*') {
                continue;
            }
            
            if ($ipParts[$i] !== $patternParts[$i]) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 展开 IPv6 地址为完整格式
     * 
     * @param string $ip IPv6地址
     * @return string|false 展开后的地址或false
     */
    private static function expandIPv6(string $ip)
    {
        if (strpos($ip, '::') !== false) {
            $parts = explode('::', $ip);
            $left = $parts[0] ? explode(':', $parts[0]) : [];
            $right = isset($parts[1]) && $parts[1] ? explode(':', $parts[1]) : [];
            
            $missing = 8 - count($left) - count($right);
            $middle = array_fill(0, $missing, '0000');
            
            $all = array_merge($left, $middle, $right);
        } else {
            $all = explode(':', $ip);
        }
        
        if (count($all) != 8) {
            return false;
        }
        
        foreach ($all as &$part) {
            if ($part === '*') {
                continue;
            }
            $part = str_pad($part, 4, '0', STR_PAD_LEFT);
        }
        
        return implode(':', $all);
    }
    
    /**
     * CIDR格式匹配
     * 
     * @param string $ip IP地址
     * @param string $cidr CIDR格式字符串
     * @return bool 是否匹配
     */
    private static function matchCIDR(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        
        list($network, $mask) = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);
            $maskLong = ~((1 << (32 - $mask)) - 1);
            
            return ($ipLong & $maskLong) === ($networkLong & $maskLong);
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::matchIPv6CIDR($ip, $network, (int)$mask);
        }
        
        return false;
    }
    
    /**
     * IPv6 CIDR 匹配
     * 
     * @param string $ip IPv6地址
     * @param string $network 网络地址
     * @param int $mask 掩码位数
     * @return bool 是否匹配
     */
    private static function matchIPv6CIDR(string $ip, string $network, int $mask): bool
    {
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);
        
        if ($ipBin === false || $networkBin === false) {
            return false;
        }
        
        $ipBits = '';
        $networkBits = '';
        
        for ($i = 0; $i < strlen($ipBin); $i++) {
            $ipBits .= str_pad(decbin(ord($ipBin[$i])), 8, '0', STR_PAD_LEFT);
            $networkBits .= str_pad(decbin(ord($networkBin[$i])), 8, '0', STR_PAD_LEFT);
        }
        
        return substr($ipBits, 0, $mask) === substr($networkBits, 0, $mask);
    }
    
    /**
     * IP范围匹配
     * 
     * @param string $ip IP地址
     * @param string $range IP范围字符串
     * @return bool 是否匹配
     */
    private static function matchIPRange(string $ip, string $range): bool
    {
        $ipParts = explode('.', $ip);
        $rangeParts = explode('.', $range);
        
        if (count($ipParts) != 4 || count($rangeParts) != 4) {
            return false;
        }
        
        for ($i = 0; $i < 4; $i++) {
            if (strpos($rangeParts[$i], '-') !== false) {
                list($start, $end) = explode('-', $rangeParts[$i]);
                if ($ipParts[$i] < $start || $ipParts[$i] > $end) {
                    return false;
                }
            } else {
                if ($ipParts[$i] != $rangeParts[$i]) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 检查访问是否过于频繁
     * 
     * @param string $ip IP地址
     * @param object $config 插件配置对象
     * @param bool $isBlacklistCheck 是否为黑名单检查（黑名单检查使用更严格的间隔）
     * @return bool 是否访问过于频繁
     */
    public static function isAccessTooFrequent(string $ip, object $config, bool $isBlacklistCheck = false): bool
    {
        $accessInterval = isset($config->accessInterval) ? (int)$config->accessInterval : 10;
        
        if ($accessInterval <= 0) {
            return false;
        }
        
        if ($isBlacklistCheck) {
            $accessInterval = max(1, $accessInterval * 0.1);
        }
        
        $lastAccess = self::getLastAccessTime($ip);
        
        if ($lastAccess && (time() - $lastAccess) < $accessInterval) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取上次访问时间
     * 
     * @param string $ip IP地址
     * @return int 上次访问时间戳，0表示无记录
     */
    public static function getLastAccessTime(string $ip): int
    {
        static $accessCache = [];
        
        if (isset($accessCache[$ip])) {
            return $accessCache[$ip];
        }
        
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $result = $db->fetchObject($db->select('last_access')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ?', $ip)
                ->limit(1));
            
            $lastAccess = $result ? (int)$result->last_access : 0;
            $accessCache[$ip] = $lastAccess;
            
            return $lastAccess;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * URL白名单检查
     * 
     * @param string $url URL地址
     * @param string $whitelist 白名单配置（正则表达式，每行一个）
     * @return bool 是否在白名单中
     */
    public static function isURLWhitelisted(string $url, string $whitelist): bool
    {
        if (empty($whitelist)) {
            return false;
        }
        
        $lines = explode("\n", $whitelist);
        
        foreach ($lines as $line) {
            $pattern = trim($line);
            
            if (empty($pattern) || substr($pattern, 0, 1) === '#') {
                continue;
            }
            
            if (@preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
}
