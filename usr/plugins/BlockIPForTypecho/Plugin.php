<?php

/**
 * 提供全面的网站安全防护，多层次安全防御机制。
 *
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 * @link       https://github.com/GamblerIX/BlockIPForTypecho
 * @license    EPL-2.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Db;
use Typecho\Request;
use Typecho\Widget;
use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/codes/base/Adapter.php';
require_once __DIR__ . '/ip2region/Searcher.class.php';
use ip2region\xdb\Searcher;
use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;

require_once __DIR__ . '/codes/base/Database.php';
require_once __DIR__ . '/codes/base/Logger.php';
require_once __DIR__ . '/codes/base/GeoLocation.php';
require_once __DIR__ . '/codes/base/IPAccessControl.php';
require_once __DIR__ . '/codes/base/SecurityDetector.php';
require_once __DIR__ . '/codes/base/SmartDetector.php';
require_once __DIR__ . '/codes/base/VisitorStats.php';
require_once __DIR__ . '/codes/base/BlockHandler.php';
require_once __DIR__ . '/codes/base/PathHelper.php';
require_once __DIR__ . '/codes/base/CaptchaHelper.php';
require_once __DIR__ . '/codes/base/SecurityHelper.php';
require_once __DIR__ . '/codes/base/SelfCheck.php';
require_once __DIR__ . '/codes/base/AllowAdminIP.php';

class Plugin implements PluginInterface
{
    const VERSION = '1.0.0';
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    public static function activate()
    {
        try {
            TypechoPlugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('Widget_Archive')->header = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('Widget_Archive')->footer = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('Widget_Archive')->handle = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('index.php')->begin = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('admin/common.php')->begin = [__CLASS__, 'checkIPAccess'];
            TypechoPlugin::factory('admin/login.php')->begin = [__CLASS__, 'hookLoginValidation'];
            TypechoPlugin::factory('admin/footer.php')->end = [__CLASS__, 'injectCaptchaUI'];
            TypechoPlugin::factory('admin/menu.php')->navBar = [__CLASS__, 'navBar'];
            
            \Helper::addPanel(1, PathHelper::getConsolePanelPath(), 'IP防护控制台', 'IP防护控制台', 'administrator');
            \Helper::addRoute('blockip-captcha', '/blockip-captcha', 
                'TypechoPlugin\\BlockIPForTypecho\\CaptchaAction', 'render');
            
            Database::createTables();
            Database::ensureIndexes();
            
            $selfCheckResults = SelfCheck::runFullCheck();
            $message = self::PLUGIN_NAME . ' v' . self::VERSION . ' 激活成功！';
            
            if (!$selfCheckResults['success']) {
                $errorCount = count($selfCheckResults['errors']);
                $warningCount = count($selfCheckResults['warnings']);
                $message .= "<br/><br/><strong>⚠️ 自检发现 {$errorCount} 个错误";
                if ($warningCount > 0) {
                    $message .= "，{$warningCount} 个警告";
                }
                $message .= "</strong><br/>";
                $message .= '<a href="' . \Helper::options()->adminUrl . 'extending.php?panel=' . PathHelper::getConsolePanelPath() . '&tab=selfcheck" style="color: #dc3545;">查看详细自检报告 →</a>';
            } else {
                $message .= '<br/><span style="color: #28a745;">✓ 自检通过，所有功能正常</span>';
            }
            
            return $message;
        } catch (\Exception $e) {
            throw new \Typecho\Plugin\Exception('插件激活失败: ' . $e->getMessage() . ' (文件: ' . $e->getFile() . ', 行: ' . $e->getLine() . ')');
        }
    }
    
    public static function deactivate()
    {
        \Helper::removePanel(1, PathHelper::getConsolePanelPath());
        \Helper::removeRoute('blockip-captcha');
        
        try {
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin(self::PLUGIN_NAME);
            
            if (isset($config->completeUninstall) && $config->completeUninstall) {
                $result = Database::dropAllTables();
                
                $message = self::PLUGIN_NAME . ' 已禁用并完成数据清理。';
                if ($result['success'] > 0) {
                    $message .= "<br/>已删除 {$result['success']} 个数据库表。";
                }
                if ($result['failed'] > 0) {
                    $message .= "<br/>⚠️ {$result['failed']} 个表删除失败。";
                }
                
                return $message;
            }
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " deactivate Error: " . $e->getMessage());
        }
        
        return self::PLUGIN_NAME . ' 已禁用（数据已保留）。';
    }
    
    public static function config(Form $form)
    {
        $mode = new Select(
            'mode',
            [
                'blacklist' => '黑名单模式（拦截指定IP）',
                'whitelist' => '白名单模式（仅允许指定IP）',
                'smart' => '智能模式（自动识别威胁）'
            ],
            'smart',
            '工作模式',
            '选择插件的工作模式'
        );
        $form->addInput($mode);
        
        $blacklistMode = new Select(
            'blacklistMode',
            [
                'block' => '完全禁止访问',
                'limit' => '限制访问频率'
            ],
            'block',
            '黑名单处理模式'
        );
        $form->addInput($blacklistMode);
        
        $blacklist = new Textarea(
            'blacklist',
            null,
            '',
            'IP黑名单',
            '每行一个IP或IP段，支持：单个IP、IP范围(1-50)、通配符(*)、CIDR(/24)'
        );
        $form->addInput($blacklist);
        
        $whitelist = new Textarea(
            'whitelist',
            null,
            '',
            'IP白名单'
        );
        $form->addInput($whitelist);
        
        $uaWhitelist = new Textarea(
            'uaWhitelist',
            null,
            '',
            'User-Agent白名单'
        );
        $form->addInput($uaWhitelist);
        
        $urlWhitelist = new Textarea(
            'urlWhitelist',
            null,
            '',
            'URL白名单'
        );
        $form->addInput($urlWhitelist);
        
        $accessInterval = new Text(
            'accessInterval',
            null,
            '10',
            '访问间隔（秒）'
        );
        $form->addInput($accessInterval);
        
        $blockedRegions = new Textarea(
            'blockedRegions',
            null,
            '',
            '被禁地区'
        );
        $form->addInput($blockedRegions);
        
        $sensitiveWords = new Textarea(
            'sensitiveWords',
            null,
            '',
            '敏感词列表'
        );
        $form->addInput($sensitiveWords);
        
        $enableSQLProtection = new Checkbox(
            'enableSQLProtection',
            ['1' => '启用SQL注入防护'],
            ['1']
        );
        $form->addInput($enableSQLProtection);
        
        $enableXSSProtection = new Checkbox(
            'enableXSSProtection',
            ['1' => '启用XSS攻击防护'],
            ['1']
        );
        $form->addInput($enableXSSProtection);
        
        $enableCSRFProtection = new Checkbox(
            'enableCSRFProtection',
            ['1' => '启用CSRF攻击防护'],
            ['1']
        );
        $form->addInput($enableCSRFProtection);
        
        $customMessage = new Textarea(
            'customMessage',
            null,
            '抱歉，您的访问被系统安全策略拦截。',
            '自定义拦截提示'
        );
        $form->addInput($customMessage);
        
        $debugMode = new Select(
            'debugMode',
            [
                '0' => '关闭',
                '1' => '开启'
            ],
            '0',
            '调试模式'
        );
        $form->addInput($debugMode);
        
        $enableLoginCaptcha = new Checkbox(
            'enableLoginCaptcha',
            ['1' => '启用登录验证码保护'],
            []
        );
        $form->addInput($enableLoginCaptcha);
        
        $enableVisitorLog = new Checkbox(
            'enableVisitorLog',
            ['1' => '启用访客日志记录'],
            ['1'],
            '访客日志',
            '记录所有访客访问信息（不包括管理后台和机器人）'
        );
        $form->addInput($enableVisitorLog);
        
        $botKeywords = new Textarea(
            'botKeywords',
            null,
            "baidu=>百度\ngoogle=>谷歌\nsogou=>搜狗\nyoudao=>有道\nsoso=>搜搜\nbing=>必应\nyahoo=>雅虎\n360=>360搜索",
            '机器人关键词',
            '每行一个，格式: 关键词=>显示名称。用于识别搜索引擎爬虫，这些访问不会被记录到访客日志'
        );
        $form->addInput($botKeywords);
        
        $logRetentionDays = new Text(
            'logRetentionDays',
            null,
            '30',
            '日志保留天数',
            '访客日志保留天数，0表示不自动清理'
        );
        $form->addInput($logRetentionDays);
        
        $adminWhitelist = new Textarea(
            'adminWhitelist',
            null,
            '',
            '后台访问IP白名单',
            '每行一个IP或IP段，支持：单个IP、IP范围(1-50)、通配符(*)、CIDR(/24)。留空表示允许所有IP访问后台。'
        );
        $form->addInput($adminWhitelist);
        
        $adminRedirectUrl = new Text(
            'adminRedirectUrl',
            null,
            '',
            '非白名单IP重定向URL',
            '当IP不在白名单时跳转的URL地址，留空则跳转到网站首页'
        );
        $form->addInput($adminRedirectUrl);
        
        $completeUninstall = new Checkbox(
            'completeUninstall',
            ['1' => '启用完整卸载（禁用插件时删除所有数据库表）'],
            [],
            '完整卸载',
            '<strong style="color: #dc3545;">⚠️ 警告：启用此选项后，禁用插件时将删除所有数据库表，包括所有日志记录。此操作不可恢复！</strong><br/>如果您只是临时禁用插件，请不要启用此选项。'
        );
        $form->addInput($completeUninstall);
    }
    
    public static function personalConfig(Form $form)
    {
    }
    
    public static function navBar($items = []): array
    {
        if (!is_array($items)) {
            $items = [];
        }
        
        try {
            $items['IP防护控制台'] = 'extending.php?panel=' . PathHelper::getConsolePanelPath();
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " navBar Error: " . $e->getMessage());
        }
        
        return $items;
    }
    
    public static function hookLoginValidation(): void
    {
        try {
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin(self::PLUGIN_NAME);
            
            if (!isset($config->enableLoginCaptcha) || 
                !in_array('1', (array)$config->enableLoginCaptcha)) {
                return;
            }
            
            $request = new \Typecho\Request();
            $pathinfo = $request->getPathInfo();
            
            if (preg_match("#/action/login#", $pathinfo)) {
                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }
                
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    error_log(self::PLUGIN_NAME . " Session not available, captcha validation skipped");
                    return;
                }
                
                $captchaInput = $request->get('captcha', '');
                $captchaInput = preg_replace('/[^a-zA-Z0-9]/', '', $captchaInput);
                
                if (!isset($_SESSION['blockip_captcha'])) {
                    unset($_SESSION['blockip_captcha']);
                    \Widget\Notice::alloc()->set(_t('验证码已过期，请重新登录'), 'error');
                    \Typecho\Response::getInstance()->goBack();
                }
                
                if (empty($captchaInput)) {
                    unset($_SESSION['blockip_captcha']);
                    \Widget\Notice::alloc()->set(_t('请输入验证码'), 'error');
                    \Typecho\Response::getInstance()->goBack();
                }
                
                if (strtolower($captchaInput) != strtolower($_SESSION['blockip_captcha'])) {
                    unset($_SESSION['blockip_captcha']);
                    \Widget\Notice::alloc()->set(_t('验证码错误'), 'error');
                    \Typecho\Response::getInstance()->goBack();
                }
                
                unset($_SESSION['blockip_captcha']);
            }
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " hookLoginValidation Error: " . $e->getMessage());
        }
    }
    
    public static function injectCaptchaUI(): void
    {
        try {
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin(self::PLUGIN_NAME);
            
            if (!isset($config->enableLoginCaptcha) || 
                !in_array('1', (array)$config->enableLoginCaptcha)) {
                return;
            }
            
            $request = new \Typecho\Request();
            $requestUri = $request->getRequestUri();
            $loginPath = Common::url('login.php', 
                defined('__TYPECHO_ADMIN_DIR__') ? __TYPECHO_ADMIN_DIR__ : '/admin/');
            
            if (stripos($requestUri, $loginPath) !== 0) {
                return;
            }
            
            $captchaUrl = \Helper::security()->getIndex('blockip-captcha');
            echo CaptchaHelper::getCaptchaUICode($captchaUrl);
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " injectCaptchaUI Error: " . $e->getMessage());
        }
    }
    
    public static function checkIPAccess(): void
    {
        try {
            static $checked = false;
            if ($checked) {
                return;
            }
            $checked = true;
            
            $request = new Request();
            $clientIP = IPAccessControl::getRealClientIP($request);
            
            try {
                $options = Widget::widget('Widget_Options');
                $config = $options->plugin(self::PLUGIN_NAME);
            } catch (\Exception $e) {
                $config = new \stdClass();
            }
            
            $mode = isset($config->mode) ? $config->mode : 'smart';
            $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;
            
            if ($debugMode) {
                error_log(self::PLUGIN_NAME . " Debug: 检查IP {$clientIP}, 模式: {$mode}");
            }
            
            $requestUrl = $request->getRequestUrl();
            if (isset($config->enableVisitorLog) && in_array('1', (array)$config->enableVisitorLog)) {
                try {
                    if (!SecurityHelper::isAdminArea($requestUrl)) {
                        $userAgent = $request->getAgent();
                        if (!VisitorStats::isBot($clientIP, $userAgent)) {
                            VisitorStats::logVisitorAccess($clientIP, $requestUrl);
                        }
                    }
                } catch (\Exception $e) {
                    error_log(self::PLUGIN_NAME . " logVisitorAccess Error: " . $e->getMessage());
                }
            }
            
            if (SecurityHelper::isAdminArea($requestUrl)) {
                AllowAdminIP::checkAdminAccess($clientIP, $config);
                Logger::recordLastAccess($clientIP, $requestUrl);
                return;
            }
            
            if (IPAccessControl::isWhitelisted($clientIP, $config)) {
                Logger::recordLastAccess($clientIP, $requestUrl);
                return;
            }
            
            if (isset($config->urlWhitelist) && !empty($config->urlWhitelist)) {
                if (IPAccessControl::isURLWhitelisted($requestUrl, $config->urlWhitelist)) {
                    Logger::recordLastAccess($clientIP, $requestUrl);
                    return;
                }
            }
            
            if (isset($config->enableSQLProtection) && in_array('1', (array)$config->enableSQLProtection)) {
                if (SecurityDetector::detectSQLInjection()) {
                    BlockHandler::blockAccess($clientIP, 'sql_injection', $config);
                    return;
                }
            }
            
            if (isset($config->enableXSSProtection) && in_array('1', (array)$config->enableXSSProtection)) {
                if (SecurityDetector::detectXSS()) {
                    BlockHandler::blockAccess($clientIP, 'xss_attack', $config);
                    return;
                }
            }
            
            if (isset($config->enableCSRFProtection) && in_array('1', (array)$config->enableCSRFProtection)) {
                if (SecurityDetector::detectCSRF()) {
                    BlockHandler::blockAccess($clientIP, 'csrf_attack', $config);
                    return;
                }
            }
            
            if (isset($config->blockedRegions) && !empty($config->blockedRegions)) {
                if (GeoLocation::isBlockedRegion($clientIP, $config)) {
                    BlockHandler::blockAccess($clientIP, 'blocked_region', $config);
                    return;
                }
            }
            
            if (IPAccessControl::isBlacklisted($clientIP, $config)) {
                $blacklistMode = isset($config->blacklistMode) ? $config->blacklistMode : 'block';
                
                if ($blacklistMode === 'block') {
                    BlockHandler::blockAccess($clientIP, 'blacklisted', $config);
                    return;
                } else {
                    if (IPAccessControl::isAccessTooFrequent($clientIP, $config, true)) {
                        BlockHandler::blockAccess($clientIP, 'blacklist_rate_limit', $config);
                        return;
                    }
                }
            }
            
            if ($mode === 'smart') {
                $smartBlockReasons = SmartDetector::isSmartBlocked($clientIP, $config);
                if (!empty($smartBlockReasons)) {
                    $combinedReason = implode(', ', $smartBlockReasons);
                    IPAccessControl::addToBlacklist($clientIP, $config, $combinedReason);
                    BlockHandler::blockAccess($clientIP, '智能检测：' . $combinedReason, $config);
                    return;
                }
            } elseif ($mode === 'whitelist') {
                if (empty($config->whitelist) || trim($config->whitelist) === '') {
                    error_log(self::PLUGIN_NAME . " Warning: 白名单模式但白名单为空");
                } else {
                    BlockHandler::blockAccess($clientIP, 'not_whitelisted', $config);
                    return;
                }
            }
            
            Logger::recordLastAccess($clientIP, $requestUrl);
            
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " Error: " . $e->getMessage());
        }
    }
}
