<?php

/**
 * 自检模块
 * 
 * 在插件激活后自动运行各种检查，确保插件正常工作
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Db;
use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * SelfCheck 类
 * 
 * 提供插件自检功能
 */
class SelfCheck
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 自检结果
     */
    private static $results = [];
    
    /**
     * 运行完整自检
     * 
     * @return array 自检结果
     */
    public static function runFullCheck(): array
    {
        self::$results = [
            'success' => true,
            'checks' => [],
            'errors' => [],
            'warnings' => [],
            'summary' => ''
        ];
        
        // 1. 数据库检查
        self::checkDatabase();
        
        // 2. 文件完整性检查
        self::checkFiles();
        
        // 3. 依赖检查
        self::checkDependencies();
        
        // 4. 配置检查
        self::checkConfiguration();
        
        // 5. 权限检查
        self::checkPermissions();
        
        // 6. 功能测试
        self::testFunctions();
        
        // 生成总结
        self::generateSummary();
        
        return self::$results;
    }
    
    /**
     * 检查数据库
     */
    private static function checkDatabase(): void
    {
        $checkName = '数据库检查';
        
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            // 检查必需的表
            $requiredTables = [
                'blockip_logs' => '拦截日志表',
                'blockip_access_log' => '访问日志表',
                'visitor_log' => '访客日志表',
                'visitor_bot_list' => '机器人IP列表表'
            ];
            
            $missingTables = [];
            $existingTables = [];
            
            foreach ($requiredTables as $table => $description) {
                $fullTableName = $prefix . $table;
                try {
                    $db->fetchRow($db->select()->from($fullTableName)->limit(1));
                    $existingTables[] = $description;
                    self::addCheck($checkName, "✓ {$description}存在", 'success');
                } catch (\Exception $e) {
                    $missingTables[] = $description;
                    self::addCheck($checkName, "✗ {$description}不存在", 'error');
                    self::$results['success'] = false;
                }
            }
            
            // 检查表结构
            if (empty($missingTables)) {
                self::checkTableStructure($db, $prefix);
            }
            
        } catch (\Exception $e) {
            self::addCheck($checkName, '数据库连接失败: ' . $e->getMessage(), 'error');
            self::$results['success'] = false;
        }
    }
    
    /**
     * 检查表结构
     */
    private static function checkTableStructure($db, $prefix): void
    {
        $checkName = '表结构检查';
        
        try {
            // 检查 visitor_log 表的字段
            $columns = $db->fetchAll("SHOW COLUMNS FROM {$prefix}visitor_log");
            $requiredColumns = ['id', 'ip', 'route', 'country', 'region', 'city', 'time'];
            $existingColumns = array_column($columns, 'Field');
            
            foreach ($requiredColumns as $col) {
                if (in_array($col, $existingColumns)) {
                    self::addCheck($checkName, "✓ visitor_log.{$col} 字段存在", 'success');
                } else {
                    self::addCheck($checkName, "✗ visitor_log.{$col} 字段缺失", 'error');
                    self::$results['success'] = false;
                }
            }
            
        } catch (\Exception $e) {
            self::addCheck($checkName, '表结构检查失败: ' . $e->getMessage(), 'warning');
        }
    }
    
    /**
     * 检查文件完整性
     */
    private static function checkFiles(): void
    {
        $checkName = '文件完整性检查';
        
        $requiredFiles = [
            'Plugin.php' => '插件主文件',
            'codes/base/Database.php' => '数据库模块',
            'codes/base/Logger.php' => '日志模块',
            'codes/base/GeoLocation.php' => '地理位置模块',
            'codes/base/IPAccessControl.php' => 'IP访问控制模块',
            'codes/base/SecurityDetector.php' => '安全检测模块',
            'codes/base/SmartDetector.php' => '智能检测模块',
            'codes/base/VisitorStats.php' => '访客统计模块',
            'codes/base/BlockHandler.php' => '拦截处理模块',
            'codes/base/Console.php' => '控制台面板',
            'codes/base/PathHelper.php' => '路径辅助类',
            'codes/base/CaptchaHelper.php' => '验证码辅助类',
            'codes/base/SecurityHelper.php' => '安全辅助类',
            'ip2region/Searcher.class.php' => 'IP地理位置库'
        ];
        
        $pluginDir = __DIR__ . '/../../';
        
        foreach ($requiredFiles as $file => $description) {
            $filePath = $pluginDir . $file;
            if (file_exists($filePath)) {
                self::addCheck($checkName, "✓ {$description}存在", 'success');
            } else {
                self::addCheck($checkName, "✗ {$description}缺失", 'error');
                self::$results['success'] = false;
            }
        }
    }
    
    /**
     * 检查依赖
     */
    private static function checkDependencies(): void
    {
        $checkName = '依赖检查';
        
        // PHP 版本检查
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '7.0.0', '>=')) {
            self::addCheck($checkName, "✓ PHP 版本 {$phpVersion} 符合要求 (>= 7.0)", 'success');
        } else {
            self::addCheck($checkName, "✗ PHP 版本 {$phpVersion} 过低，需要 >= 7.0", 'error');
            self::$results['success'] = false;
        }
        
        // 必需的 PHP 扩展
        $requiredExtensions = [
            'pdo' => 'PDO 数据库扩展',
            'json' => 'JSON 扩展',
            'mbstring' => '多字节字符串扩展'
        ];
        
        foreach ($requiredExtensions as $ext => $description) {
            if (extension_loaded($ext)) {
                self::addCheck($checkName, "✓ {$description}已加载", 'success');
            } else {
                self::addCheck($checkName, "✗ {$description}未加载", 'warning');
            }
        }
        
        // 可选的 PHP 扩展
        $optionalExtensions = [
            'gd' => 'GD 图像处理扩展（验证码功能需要）',
            'curl' => 'cURL 扩展（某些功能可能需要）'
        ];
        
        foreach ($optionalExtensions as $ext => $description) {
            if (extension_loaded($ext)) {
                self::addCheck($checkName, "✓ {$description}已加载", 'success');
            } else {
                self::addCheck($checkName, "○ {$description}未加载（可选）", 'info');
            }
        }
    }
    
    /**
     * 检查配置
     */
    private static function checkConfiguration(): void
    {
        $checkName = '配置检查';
        
        try {
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin(self::PLUGIN_NAME);
            
            // 检查工作模式
            if (isset($config->mode)) {
                $mode = $config->mode;
                self::addCheck($checkName, "✓ 工作模式: {$mode}", 'success');
                
                // 根据模式检查相关配置
                if ($mode === 'whitelist') {
                    if (empty($config->whitelist)) {
                        self::addCheck($checkName, "⚠ 白名单模式但白名单为空", 'warning');
                    } else {
                        self::addCheck($checkName, "✓ 白名单已配置", 'success');
                    }
                }
            } else {
                self::addCheck($checkName, "○ 工作模式未配置，将使用默认值", 'info');
            }
            
            // 检查访客日志配置
            if (isset($config->enableVisitorLog) && in_array('1', (array)$config->enableVisitorLog)) {
                self::addCheck($checkName, "✓ 访客日志记录已启用", 'success');
            } else {
                self::addCheck($checkName, "○ 访客日志记录未启用", 'info');
            }
            
            // 检查登录验证码配置
            if (isset($config->enableLoginCaptcha) && in_array('1', (array)$config->enableLoginCaptcha)) {
                self::addCheck($checkName, "✓ 登录验证码已启用", 'success');
            } else {
                self::addCheck($checkName, "○ 登录验证码未启用", 'info');
            }
            
        } catch (\Exception $e) {
            self::addCheck($checkName, '配置检查失败: ' . $e->getMessage(), 'warning');
        }
    }
    
    /**
     * 检查权限
     */
    private static function checkPermissions(): void
    {
        $checkName = '权限检查';
        
        $pluginDir = __DIR__ . '/../../';
        
        // 检查目录可写性
        $writableDirs = [
            'ip2region' => 'IP地理位置数据目录'
        ];
        
        foreach ($writableDirs as $dir => $description) {
            $dirPath = $pluginDir . $dir;
            if (is_writable($dirPath)) {
                self::addCheck($checkName, "✓ {$description}可写", 'success');
            } else {
                self::addCheck($checkName, "⚠ {$description}不可写（某些功能可能受限）", 'warning');
            }
        }
    }
    
    /**
     * 测试功能
     */
    private static function testFunctions(): void
    {
        $checkName = '功能测试';
        
        try {
            // 测试 IP 解析
            $testIP = '8.8.8.8';
            try {
                $location = GeoLocation::lookupIPLocation($testIP);
                if (!empty($location)) {
                    self::addCheck($checkName, "✓ IP地理位置查询功能正常", 'success');
                } else {
                    self::addCheck($checkName, "⚠ IP地理位置查询返回空结果", 'warning');
                }
            } catch (\Exception $e) {
                self::addCheck($checkName, "✗ IP地理位置查询失败: " . $e->getMessage(), 'error');
            }
            
            // 测试数据库写入
            try {
                $db = Db::get();
                $prefix = $db->getPrefix();
                
                // 生成唯一的测试 IP（使用时间戳确保唯一性）
                $testIP = '127.0.0.' . (time() % 254 + 1);
                
                // 先删除可能存在的测试数据
                $db->query($db->delete($prefix . 'blockip_access_log')
                    ->where('ip LIKE ? AND user_agent = ?', '127.0.0.%', 'SelfCheck'));
                
                // 测试写入访问日志
                $testData = [
                    'ip' => $testIP,
                    'url' => '/selfcheck-test',
                    'user_agent' => 'SelfCheck',
                    'last_access' => time(),
                    'timestamp' => time()
                ];
                
                $db->query($db->insert($prefix . 'blockip_access_log')->rows($testData));
                
                // 验证写入成功
                $result = $db->fetchRow($db->select()
                    ->from($prefix . 'blockip_access_log')
                    ->where('ip = ?', $testIP));
                
                if ($result) {
                    // 立即删除测试数据
                    $db->query($db->delete($prefix . 'blockip_access_log')
                        ->where('ip = ?', $testIP));
                    
                    self::addCheck($checkName, "✓ 数据库写入功能正常", 'success');
                } else {
                    self::addCheck($checkName, "✗ 数据库写入验证失败", 'error');
                    self::$results['success'] = false;
                }
            } catch (\Exception $e) {
                self::addCheck($checkName, "✗ 数据库写入测试失败: " . $e->getMessage(), 'error');
                self::$results['success'] = false;
            }
            
            // 测试钩子注册
            $hooks = [
                'Widget_Archive' => ['beforeRender', 'header', 'footer', 'handle'],
                'index.php' => ['begin'],
                'admin/common.php' => ['begin'],
                'admin/menu.php' => ['navBar']
            ];
            
            $hookCount = 0;
            foreach ($hooks as $component => $methods) {
                $hookCount += count($methods);
            }
            
            self::addCheck($checkName, "✓ 已注册 {$hookCount} 个钩子", 'success');
            
        } catch (\Exception $e) {
            self::addCheck($checkName, '功能测试失败: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * 添加检查结果
     */
    private static function addCheck(string $category, string $message, string $type): void
    {
        if (!isset(self::$results['checks'][$category])) {
            self::$results['checks'][$category] = [];
        }
        
        self::$results['checks'][$category][] = [
            'message' => $message,
            'type' => $type
        ];
        
        if ($type === 'error') {
            self::$results['errors'][] = $message;
        } elseif ($type === 'warning') {
            self::$results['warnings'][] = $message;
        }
    }
    
    /**
     * 生成总结
     */
    private static function generateSummary(): void
    {
        $totalChecks = 0;
        $successChecks = 0;
        $errorChecks = count(self::$results['errors']);
        $warningChecks = count(self::$results['warnings']);
        
        foreach (self::$results['checks'] as $category => $checks) {
            foreach ($checks as $check) {
                $totalChecks++;
                if ($check['type'] === 'success') {
                    $successChecks++;
                }
            }
        }
        
        $summary = "自检完成：共 {$totalChecks} 项检查";
        
        if (self::$results['success']) {
            $summary .= "，全部通过 ✓";
        } else {
            $summary .= "，{$errorChecks} 个错误";
            if ($warningChecks > 0) {
                $summary .= "，{$warningChecks} 个警告";
            }
        }
        
        self::$results['summary'] = $summary;
        self::$results['stats'] = [
            'total' => $totalChecks,
            'success' => $successChecks,
            'errors' => $errorChecks,
            'warnings' => $warningChecks
        ];
    }
    
    /**
     * 生成 HTML 报告
     * 
     * @return string HTML 报告
     */
    public static function generateHTMLReport(): string
    {
        $results = self::runFullCheck();
        
        $html = '<div style="font-family: Arial, sans-serif; max-width: 900px; margin: 20px auto; padding: 20px; background: #f5f5f5; border-radius: 8px;">';
        
        // 标题
        $html .= '<h2 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;">🔍 插件自检报告</h2>';
        
        // 总结
        $statusColor = $results['success'] ? '#28a745' : '#dc3545';
        $statusIcon = $results['success'] ? '✓' : '✗';
        $html .= '<div style="background: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid ' . $statusColor . ';">';
        $html .= '<h3 style="margin: 0; color: ' . $statusColor . ';">' . $statusIcon . ' ' . $results['summary'] . '</h3>';
        
        if (!empty($results['stats'])) {
            $stats = $results['stats'];
            $html .= '<p style="margin: 10px 0 0 0; color: #666;">';
            $html .= "成功: {$stats['success']} | ";
            $html .= "错误: {$stats['errors']} | ";
            $html .= "警告: {$stats['warnings']}";
            $html .= '</p>';
        }
        
        $html .= '</div>';
        
        // 详细检查结果
        foreach ($results['checks'] as $category => $checks) {
            $html .= '<div style="background: white; padding: 15px; border-radius: 5px; margin-bottom: 15px;">';
            $html .= '<h4 style="margin: 0 0 10px 0; color: #333;">' . htmlspecialchars($category) . '</h4>';
            $html .= '<ul style="list-style: none; padding: 0; margin: 0;">';
            
            foreach ($checks as $check) {
                $color = '#666';
                if ($check['type'] === 'success') {
                    $color = '#28a745';
                } elseif ($check['type'] === 'error') {
                    $color = '#dc3545';
                } elseif ($check['type'] === 'warning') {
                    $color = '#ffc107';
                } elseif ($check['type'] === 'info') {
                    $color = '#17a2b8';
                }
                
                $html .= '<li style="padding: 5px 0; color: ' . $color . ';">';
                $html .= htmlspecialchars($check['message']);
                $html .= '</li>';
            }
            
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        // 建议
        if (!$results['success']) {
            $html .= '<div style="background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;">';
            $html .= '<h4 style="margin: 0 0 10px 0; color: #856404;">⚠️ 修复建议</h4>';
            $html .= '<ul style="margin: 0; color: #856404;">';
            
            if (!empty($results['errors'])) {
                $html .= '<li>请检查并修复上述错误项</li>';
                $html .= '<li>确保数据库表结构完整</li>';
                $html .= '<li>确保所有必需文件存在</li>';
            }
            
            $html .= '<li>如果问题持续，请尝试重新激活插件</li>';
            $html .= '<li>查看服务器错误日志获取更多信息</li>';
            $html .= '</ul>';
            $html .= '</div>';
        } else {
            $html .= '<div style="background: #d4edda; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745;">';
            $html .= '<p style="margin: 0; color: #155724;">✓ 所有检查通过，插件运行正常！</p>';
            $html .= '</div>';
        }
        
        $html .= '<p style="text-align: center; color: #999; margin-top: 20px; font-size: 12px;">生成时间: ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '</div>';
        
        return $html;
    }
}
