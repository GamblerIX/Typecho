<?php
/**
 * 错误诊断工具
 * 
 * 用于深度诊断和记录错误信息
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * ErrorDiagnostic 类
 * 
 * 提供错误诊断和日志记录功能
 */
class ErrorDiagnostic
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 清理所有输出缓冲区
     * 
     * @return int 清理的缓冲区数量
     */
    public static function cleanOutputBuffers(): int
    {
        $count = 0;
        while (ob_get_level() > 0) {
            ob_end_clean();
            $count++;
        }
        
        if ($count > 0) {
            error_log(self::PLUGIN_NAME . " Cleaned {$count} output buffer(s)");
        }
        
        return $count;
    }
    
    /**
     * 检查输出缓冲区状态
     * 
     * @return array
     */
    public static function checkOutputBuffers(): array
    {
        $level = ob_get_level();
        $status = ob_get_status(true);
        
        return [
            'level' => $level,
            'status' => $status,
            'has_buffers' => $level > 0
        ];
    }
    
    /**
     * 检查响应头状态
     * 
     * @return array
     */
    public static function checkHeaders(): array
    {
        $file = '';
        $line = 0;
        $sent = headers_sent($file, $line);
        
        return [
            'sent' => $sent,
            'file' => $file,
            'line' => $line,
            'list' => headers_list()
        ];
    }
    
    /**
     * 记录详细错误信息
     * 
     * @param \Exception $e 异常对象
     * @param array $context 上下文信息
     * @return void
     */
    public static function logError(\Exception $e, array $context = []): void
    {
        $errorInfo = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        
        if (!empty($context)) {
            $errorInfo['context'] = $context;
        }
        
        $errorInfo['environment'] = [
            'php_version' => PHP_VERSION,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'output_buffers' => self::checkOutputBuffers(),
            'headers' => self::checkHeaders()
        ];
        
        error_log(self::PLUGIN_NAME . " Error Details: " . json_encode($errorInfo, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 生成诊断报告
     * 
     * @return array
     */
    public static function generateDiagnosticReport(): array
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'extensions' => [
                'pdo' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring')
            ],
            'output_buffers' => self::checkOutputBuffers(),
            'headers' => self::checkHeaders(),
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ]
        ];
        
        return $report;
    }
    
    /**
     * 验证 IP 地址格式
     * 
     * @param string $ip IP地址
     * @return bool
     */
    public static function validateIP(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * 清理环境（用于 AJAX 请求前）
     * 
     * @return void
     */
    public static function cleanEnvironment(): void
    {
        self::cleanOutputBuffers();
        
        @ini_set('display_errors', '0');
        
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    }
}
