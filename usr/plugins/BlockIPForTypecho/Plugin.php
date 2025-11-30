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
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Db;
use Typecho\Request;
use Typecho\Widget;
use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/ext/Adapter.php';
require_once __DIR__ . '/ext/ip2region/Searcher.class.php';
use ip2region\xdb\Searcher;
use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;

require_once __DIR__ . '/ext/Database.php';
require_once __DIR__ . '/ext/Logger.php';
require_once __DIR__ . '/ext/GeoLocation.php';
require_once __DIR__ . '/ext/IPAccessControl.php';
require_once __DIR__ . '/ext/SecurityDetector.php';
require_once __DIR__ . '/ext/SmartDetector.php';
require_once __DIR__ . '/ext/VisitorStats.php';
require_once __DIR__ . '/ext/BlockHandler.php';
require_once __DIR__ . '/ext/PathHelper.php';
require_once __DIR__ . '/ext/CaptchaHelper.php';
require_once __DIR__ . '/ext/SecurityHelper.php';
require_once __DIR__ . '/ext/AllowAdminIP.php';

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

            return self::PLUGIN_NAME . ' v' . self::VERSION . ' 激活成功！';
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
        echo '<div style="background: #d4edda; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb;">';
        echo '<p style="margin: 0; color: #155724;">插件设置已移至 <a href="' . \Helper::options()->adminUrl . 'extending.php?panel=' . PathHelper::getConsolePanelPath() . '&tab=settings" style="color: #155724; font-weight: bold;">IP防护控制台 → 插件设置</a></p>';
        echo '</div>';

        $mode = new Text('mode', null, 'smart', ' ');
        $mode->input->setAttribute('style', 'display:none');
        $form->addInput($mode);
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
