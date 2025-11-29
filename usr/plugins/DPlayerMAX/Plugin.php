<?php
/**
 * DPlayerMAX - 强大的视频播放器插件，支持B站视频解析
 *
 * @package DPlayerMAX
 * @author GamblerIX
 * @version 2.3.0
 * @link https://github.com/GamblerIX/DPlayerMAX
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_Plugin implements Typecho_Plugin_Interface
{
    const VERSION = '2.3.0';

    public static function init()
    {
        if (isset($_GET['dplayermax_api'])) {
            self::handleApiRequest();
        }
        if (isset($_POST['dplayermax_action']) && isset($_GET['config']) && strpos($_SERVER['REQUEST_URI'], 'DPlayerMAX') !== false) {
            self::handleAjaxRequest();
        }
    }

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = [__CLASS__, 'replacePlayer'];
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = [__CLASS__, 'replacePlayer'];
        Typecho_Plugin::factory('Widget_Archive')->header = [__CLASS__, 'playerHeader'];
        Typecho_Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'playerFooter'];
        Typecho_Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'addEditorButton'];
        Typecho_Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'addEditorButton'];

        $cacheDir = __DIR__ . '/ext/bilibili/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

        return _t('插件已激活 (v' . self::VERSION . ')，请进入设置页面进行配置');
    }

    public static function deactivate() {}

    public static function playerHeader()
    {
        require_once __DIR__ . '/ext/PlayerRenderer.php';
        DPlayerMAX_PlayerRenderer::renderHeader();
    }

    public static function playerFooter()
    {
        require_once __DIR__ . '/ext/PlayerRenderer.php';
        DPlayerMAX_PlayerRenderer::renderFooter();
    }

    public static function replacePlayer($text, $widget, $last)
    {
        $text = empty($last) ? $text : $last;
        if ($widget instanceof Widget_Archive) {
            require_once __DIR__ . '/ext/ShortcodeParser.php';
            $pattern = DPlayerMAX_ShortcodeParser::getRegex(['dplayer']);
            $text = preg_replace_callback("/$pattern/", [__CLASS__, 'parseCallback'], $text);
        }
        return $text;
    }

    public static function parseCallback($matches)
    {
        if ($matches[1] == '[' && $matches[6] == ']') return substr($matches[0], 1, -1);

        require_once __DIR__ . '/ext/ShortcodeParser.php';
        require_once __DIR__ . '/ext/PlayerRenderer.php';

        return DPlayerMAX_PlayerRenderer::parsePlayer(
            DPlayerMAX_ShortcodeParser::parseAtts(htmlspecialchars_decode($matches[3]))
        );
    }

    public static function addEditorButton()
    {
        echo '<script src="' . \Utils\Helper::options()->pluginUrl . '/DPlayerMAX/assets/editor.js"></script>';
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        if (isset($_POST['dplayermax_action'])) self::handleAjaxRequest();

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'theme', null, '#FADFA3', _t('主题颜色'), _t('播放器主题色，如 #FADFA3')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'hls', ['1' => _t('开启'), '0' => _t('关闭')], '1', _t('HLS支持'), _t('播放m3u8格式')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'flv', ['1' => _t('开启'), '0' => _t('关闭')], '1', _t('FLV支持'), _t('播放flv格式')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'lazy_load', ['1' => _t('开启'), '0' => _t('关闭')], '1', _t('懒加载'), _t('进入视口时加载')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bilibili', ['1' => _t('开启'), '0' => _t('关闭')], '1', _t('外链解析'), _t('直接播放外链视频')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'bilibili_quality', ['720p' => '720P 高清', '360p' => '360P 流畅'], '720p',
            _t('默认清晰度'), _t('访客可自行切换')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bilibili_fallback', ['1' => _t('开启'), '0' => _t('关闭')], '1',
            _t('备用播放器'), _t('解析失败时使用官方播放器')
        ));

        echo self::renderInfoPanels();
    }

    private static function renderInfoPanels()
    {
        require_once __DIR__ . '/ext/bilibili/BilibiliParser.php';
        require_once __DIR__ . '/ext/bilibili/WbiSigner.php';

        $stats = DPlayerMAX_Bilibili_Parser::getCacheStats();
        $wbi = DPlayerMAX_Bilibili_WbiSigner::getCacheStatus();

        $html = '<div style="margin-top:30px;padding:20px;background:#f9f9f9;border:1px solid #e8e8e8;border-radius:8px">';
        $html .= '<h4 style="margin:0 0 15px;font-size:14px;color:#333">缓存管理</h4>';
        $html .= '<div style="font-size:13px;color:#666;line-height:1.8;margin-bottom:12px">';
        $html .= '缓存: <b>' . $stats['total'] . '</b> 个 (' . $stats['size_human'] . ') | ';
        $html .= 'WBI: ' . ($wbi['has_file_cache'] ? '<span style="color:#52c41a">已缓存</span>' : '<span style="color:#999">无</span>');
        $html .= '</div>';
        $html .= '<button type="button" name="clear_expired" onclick="DPlayerMAXCache(\'clear_expired\')" style="padding:6px 12px;font-size:12px;background:#d9d9d9;border:none;border-radius:4px;cursor:pointer;margin-right:8px">清理过期</button>';
        $html .= '<button type="button" name="clear_all" onclick="DPlayerMAXCache(\'clear_all\')" style="padding:6px 12px;font-size:12px;background:#ff4d4f;color:#fff;border:none;border-radius:4px;cursor:pointer">全部清理</button>';
        $html .= '<div id="dplayermax-cache-msg" style="font-size:12px;margin-top:10px"></div>';

        $html .= '<h4 style="margin:20px 0 10px;padding-top:15px;border-top:1px solid #e0e0e0;font-size:14px;color:#333">使用说明</h4>';
        $html .= '<div style="font-size:13px;color:#666;line-height:2">';
        $html .= '<code>[dplayer url="视频地址"]</code> 普通视频<br>';
        $html .= '<code>[dplayer url="外链地址"]</code> 外链视频<br>';
        $html .= '<code>[dplayer url="外链地址" mode="iframe"]</code> 官方播放器';
        $html .= '</div>';

        $html .= '<h4 style="margin:20px 0 10px;padding-top:15px;border-top:1px solid #e0e0e0;font-size:14px;color:#333">插件更新</h4>';
        require_once __DIR__ . '/ext/UpdateUI.php';
        $html .= DPlayerMAX_UpdateUI::render();
        $html .= '</div>';

        $html .= '<script>function DPlayerMAXCache(a){var m=document.getElementById("dplayermax-cache-msg");m.innerHTML="处理中...";var x=new XMLHttpRequest();x.open("POST",location.href,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.onload=function(){try{var d=JSON.parse(x.responseText);m.innerHTML="<span style=\"color:"+(d.success?"#52c41a":"#ff4d4f")+"\">"+d.message+"</span>";if(d.success)setTimeout(function(){location.reload()},1000)}catch(e){m.innerHTML="<span style=\"color:#ff4d4f\">操作失败</span>"}};x.send("dplayermax_action="+a)}</script>';

        return $html;
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    private static function handleAjaxRequest()
    {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');

        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('administrator', true)) {
            self::sendJson(['success' => false, 'message' => '权限不足']);
        }

        $action = $_POST['dplayermax_action'] ?? '';

        try {
            switch ($action) {
                case 'check': $result = self::checkUpdate(); break;
                case 'perform': $result = self::performUpdate(); break;
                case 'force': $result = self::performUpdate(true); break;
                case 'clear_expired': $result = self::clearExpiredCache(); break;
                case 'clear_all': $result = self::clearAllCache(); break;
                default: $result = ['success' => false, 'message' => '无效操作'];
            }
            self::sendJson($result);
        } catch (Exception $e) {
            self::sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private static function handleApiRequest()
    {
        $action = $_GET['dplayermax_api'] ?? '';

        if ($action === 'video') {
            self::apiProxyVideo();
            return;
        }

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');

        try {
            switch ($action) {
                case 'parse': $result = self::apiParseBilibili(); break;
                case 'info': $result = self::apiGetVideoInfo(); break;
                default: $result = ['success' => false, 'error' => 'Invalid action'];
            }
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function apiProxyVideo()
    {
        $url = $_GET['url'] ?? '';
        if (empty($url)) {
            http_response_code(400);
            exit('URL required');
        }
        $url = base64_decode($url);
        if (!$url || (strpos($url, 'bilivideo.com') === false && strpos($url, 'akamaized.net') === false)) {
            http_response_code(400);
            exit('Invalid URL');
        }

        require_once __DIR__ . '/ext/VideoProxy.php';
        DPlayerMAX_VideoProxy::proxy($url);
        exit;
    }

    private static function apiParseBilibili()
    {
        $url = $_GET['url'] ?? '';
        if (empty($url)) return ['success' => false, 'error' => 'URL required'];

        require_once __DIR__ . '/ext/bilibili/BilibiliParser.php';
        $result = DPlayerMAX_Bilibili_Parser::parse($url, (int)($_GET['page'] ?? 1), $_GET['quality'] ?? '720p');

        if ($result['success']) unset($result['owner']['mid']);
        return $result;
    }

    private static function apiGetVideoInfo()
    {
        $bvid = $_GET['bvid'] ?? '';
        if (empty($bvid)) return ['success' => false, 'error' => 'BVID required'];

        require_once __DIR__ . '/ext/bilibili/BilibiliParser.php';
        $info = DPlayerMAX_Bilibili_Parser::getVideoInfo($bvid);

        return $info ? ['success' => true, 'data' => $info] : ['success' => false, 'error' => 'Failed'];
    }

    private static function clearExpiredCache()
    {
        require_once __DIR__ . '/ext/bilibili/BilibiliParser.php';
        return ['success' => true, 'message' => '已清理 ' . DPlayerMAX_Bilibili_Parser::cleanCache() . ' 个过期缓存'];
    }

    private static function clearAllCache()
    {
        $files = glob(__DIR__ . '/ext/bilibili/cache/*.json') ?: [];
        $cleaned = 0;
        foreach ($files as $file) if (@unlink($file)) $cleaned++;

        require_once __DIR__ . '/ext/bilibili/WbiSigner.php';
        DPlayerMAX_Bilibili_WbiSigner::clearCache();

        return ['success' => true, 'message' => '已清理 ' . $cleaned . ' 个缓存'];
    }

    public static function checkUpdate()
    {
        try {
            require_once __DIR__ . '/ext/Updated.php';
            return class_exists('DPlayerMAX_UpdateManager') ? DPlayerMAX_UpdateManager::checkUpdate() : ['success' => false, 'message' => '更新组件加载失败'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '检查失败: ' . $e->getMessage()];
        }
    }

    public static function performUpdate($force = false)
    {
        try {
            require_once __DIR__ . '/ext/Updated.php';
            return class_exists('DPlayerMAX_UpdateManager') ? DPlayerMAX_UpdateManager::performUpdate($force) : ['success' => false, 'message' => '更新组件加载失败'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '更新失败: ' . $e->getMessage()];
        }
    }

    private static function sendJson($data)
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function getVersion() { return self::VERSION; }
}

DPlayerMAX_Plugin::init();
