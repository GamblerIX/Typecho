<?php
/**
 * BlockIP For Typecho - 控制台页面
 *
 * 显示详细的日志记录和统计信息
 *
 * @author GamblerIX
 * @link https://github.com/GamblerIX/BlockIPForTypecho
 */

$adminPath = __DIR__ . '/../../../../admin/';

require_once $adminPath . 'common.php';

require_once __DIR__ . '/../Plugin.php';
require_once __DIR__ . '/PathHelper.php';
require_once __DIR__ . '/VisitorStats.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErrorDiagnostic.php';
use TypechoPlugin\BlockIPForTypecho\Plugin;
use TypechoPlugin\BlockIPForTypecho\PathHelper;
use TypechoPlugin\BlockIPForTypecho\VisitorStats;
use TypechoPlugin\BlockIPForTypecho\Database;
use TypechoPlugin\BlockIPForTypecho\ErrorDiagnostic;

$isAjaxRequest = isset($_GET['action']) && $_GET['action'] === 'get_ip_history';
if ($isAjaxRequest) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    @ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        $ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';

        if (empty($ip)) {
            echo json_encode([
                'success' => false,
                'error' => 'IP地址不能为空',
                'error_code' => 'EMPTY_IP'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            echo json_encode([
                'success' => false,
                'error' => '无效的IP地址格式',
                'error_code' => 'INVALID_IP'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = VisitorStats::getIPAccessHistory($ip, 50);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('BlockIPForTypecho Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

        echo json_encode([
            'success' => false,
            'error' => '服务器内部错误，请稍后重试',
            'error_code' => 'INTERNAL_ERROR',
            'debug_message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$request = Typecho_Request::getInstance();
$action = $request->get('action', '');

require_once $adminPath . 'header.php';
require_once $adminPath . 'menu.php';
$allowedTabs = ['security', 'visitors', 'bots', 'audit', 'settings'];
$tab = $request->get('tab', 'security');
if (!in_array($tab, $allowedTabs)) {
    $tab = 'security';
}
$page = max(1, (int)$request->get('page', 1));
$pageSize = 15;

$db = Typecho_Db::get();
$prefix = $db->getPrefix();

$options = Widget_Options::alloc();
$pluginOptions = $options->plugin('BlockIPForTypecho');
$success_message = '';
$error_message = '';
if ($action === 'clear_logs' && $request->isPost()) {
    try {
        $db->query($db->delete($prefix . 'blockip_logs'));
        $success_message = "安全日志已清空";
    } catch (Exception $e) {
        $error_message = "清空失败: " . $e->getMessage();
    }
}
if ($action === 'clear_visitor_logs' && $request->isPost()) {
    try {
        $days = (int)$request->get('days', 0);
        if (VisitorStats::cleanUpOldLogs($days)) {
            $success_message = $days > 0 ? "已清理{$days}天前的访客日志" : "访客日志已清空";
        } else {
            $error_message = "清理失败";
        }
    } catch (Exception $e) {
        $error_message = "清理失败: " . $e->getMessage();
    }
}
if ($action === 'add_bot_ip' && $request->isPost()) {
    try {
        $ip = trim($request->get('bot_ip', ''));
        if (empty($ip)) {
            $error_message = "IP地址不能为空";
        } elseif (VisitorStats::addBotIP($ip)) {
            $success_message = "Bot IP 已添加: {$ip}";
        } else {
            $error_message = "添加失败，IP可能已存在";
        }
    } catch (Exception $e) {
        $error_message = "添加失败: " . $e->getMessage();
    }
}
if ($action === 'remove_bot_ip' && $request->isPost()) {
    try {
        $id = (int)$request->get('id', 0);
        if (VisitorStats::removeBotIP($id)) {
            $success_message = "Bot IP 已删除";
        } else {
            $error_message = "删除失败";
        }
    } catch (Exception $e) {
        $error_message = "删除失败: " . $e->getMessage();
    }
}
if ($action === 'batch_add_bot_ips' && $request->isPost()) {
    try {
        $ipsText = $request->get('ips_text', '');
        
        if (empty(trim($ipsText))) {
            $error_message = "请输入至少一个 IP 地址";
        } else {
            $result = VisitorStats::batchAddBotIPs($ipsText);
            
            if ($result['success'] > 0 || $result['skipped'] > 0) {
                $success_message = $result['message'];
                if (!empty($result['errors'])) {
                    $errorDetails = [];
                    foreach ($result['errors'] as $ip => $reason) {
                        $errorDetails[] = "{$ip}: {$reason}";
                    }
                    $success_message .= "<br/>错误详情：<br/>" . implode('<br/>', $errorDetails);
                }
            } else {
                $error_message = $result['message'];
                if (!empty($result['errors'])) {
                    $errorDetails = [];
                    foreach ($result['errors'] as $ip => $reason) {
                        $errorDetails[] = "{$ip}: {$reason}";
                    }
                    $error_message .= "<br/>" . implode('<br/>', $errorDetails);
                }
            }
        }
    } catch (Exception $e) {
        $error_message = "批量添加失败: " . $e->getMessage();
    }
}
if ($action === 'batch_remove_bot_ips' && $request->isPost()) {
    try {
        $idsParam = $request->get('ids', '');
        
        error_log("BlockIPForTypecho: Batch delete received IDs parameter: " . var_export($idsParam, true));
        
        if (empty($idsParam)) {
            $error_message = "请选择至少一个 Bot IP";
        } else {
            $ids = is_array($idsParam) ? $idsParam : explode(',', $idsParam);
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, function($id) {
                return $id > 0;
            });
            $ids = array_values($ids);
            
            error_log("BlockIPForTypecho: Parsed and filtered IDs: " . implode(',', $ids) . " (count: " . count($ids) . ")");
            
            if (empty($ids)) {
                $error_message = "无效的ID列表";
            } else {
                $result = VisitorStats::batchRemoveBotIPs($ids);
                
                error_log("BlockIPForTypecho: Batch delete result: " . var_export($result, true));
                
                if ($result['success'] > 0) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
            }
        }
    } catch (Exception $e) {
        $error_message = "批量删除失败: " . $e->getMessage();
        error_log("BlockIPForTypecho: Batch delete exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    }
}
if ($action === 'fix_database' && $request->isPost()) {
    try {
        $result = Database::fixDatabaseSchema();
        $success_message = $result;
    } catch (Exception $e) {
        $error_message = "修复数据库失败: " . $e->getMessage();
    }
}
if ($action === 'save_settings' && $request->isPost()) {
    try {
        $settings = [
            'mode' => $request->get('mode', 'smart'),
            'blacklistMode' => $request->get('blacklistMode', 'block'),
            'blacklist' => $request->get('blacklist', ''),
            'whitelist' => $request->get('whitelist', ''),
            'uaWhitelist' => $request->get('uaWhitelist', ''),
            'urlWhitelist' => $request->get('urlWhitelist', ''),
            'accessInterval' => $request->get('accessInterval', '10'),
            'regionMode' => $request->get('regionMode', 'blacklist'),
            'blockedRegions' => $request->get('blockedRegions', ''),
            'sensitiveWords' => $request->get('sensitiveWords', ''),
            'enableSQLProtection' => $request->get('enableSQLProtection') ? ['1'] : [],
            'enableXSSProtection' => $request->get('enableXSSProtection') ? ['1'] : [],
            'enableCSRFProtection' => $request->get('enableCSRFProtection') ? ['1'] : [],
            'customMessage' => $request->get('customMessage', '抱歉，您的访问被系统安全策略拦截。'),
            'debugMode' => $request->get('debugMode', '0'),
            'enableLoginCaptcha' => $request->get('enableLoginCaptcha') ? ['1'] : [],
            'enableVisitorLog' => $request->get('enableVisitorLog') ? ['1'] : [],
            'botKeywords' => $request->get('botKeywords', ''),
            'logRetentionDays' => $request->get('logRetentionDays', '30'),
            'adminWhitelist' => $request->get('adminWhitelist', ''),
            'adminRedirectUrl' => $request->get('adminRedirectUrl', ''),
            'completeUninstall' => $request->get('completeUninstall') ? ['1'] : []
        ];

        $db->query($db->update('table.options')
            ->rows(['value' => serialize($settings)])
            ->where('name = ?', 'plugin:BlockIPForTypecho'));

        $success_message = "设置已保存";
        $pluginOptions = (object)$settings;
    } catch (Exception $e) {
        $error_message = "保存设置失败: " . $e->getMessage();
    }
}

function getSecurityStats($db, $prefix) {
    $stats = [];
    
    try {
        $total = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs'));
        $stats['total'] = $total ? (int)$total->count : 0;
        $today = strtotime('today');
        $todayCount = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->where('created >= ?', $today));
        $stats['today'] = $todayCount ? (int)$todayCount->count : 0;
        $autoBlacklistCount = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->where('reason LIKE ?', '自动拉黑：%'));
        $stats['auto_blacklist'] = $autoBlacklistCount ? (int)$autoBlacklistCount->count : 0;
        $topIPs = $db->fetchAll($db->select('ip, COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->group('ip')
            ->order('count', Typecho_Db::SORT_DESC)
            ->limit(10));
        $stats['top_ips'] = $topIPs ?: [];
        $hourlyStats = [];
        for ($i = 23; $i >= 0; $i--) {
            $hourStart = strtotime("-{$i} hours", strtotime(date('Y-m-d H:00:00')));
            $hourEnd = $hourStart + 3600;
            
            $hourCount = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'blockip_logs')
                ->where('created >= ? AND created < ?', $hourStart, $hourEnd));
            
            $hourlyStats[] = [
                'hour' => date('H:i', $hourStart),
                'count' => $hourCount ? (int)$hourCount->count : 0
            ];
        }
        $stats['hourly'] = $hourlyStats;
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

function getSecurityLogs($db, $prefix, $page, $pageSize) {
    $offset = ($page - 1) * $pageSize;
    
    try {
        $total = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs'));
        $totalCount = $total ? (int)$total->count : 0;
        
        $logs = $db->fetchAll($db->select()
            ->from($prefix . 'blockip_logs')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset));
        
        return [
            'logs' => $logs ?: [],
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $pageSize)
        ];
    } catch (Exception $e) {
        return [
            'logs' => [],
            'total' => 0,
            'total_pages' => 0
        ];
    }
}

function getBlacklistEntries($pluginOptions) {
    $entries = [];
    
    try {
        $blacklistConfig = '';
        if (isset($pluginOptions->blacklist) && !empty($pluginOptions->blacklist)) {
            $blacklistConfig = $pluginOptions->blacklist;
        } elseif (isset($pluginOptions->ips) && !empty($pluginOptions->ips)) {
            $blacklistConfig = $pluginOptions->ips;
        }
        
        if (empty($blacklistConfig)) {
            return $entries;
        }
        
        $lines = explode("\n", $blacklistConfig);
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            $ip = $line;
            $reason = '';
            $time = '';
            
            if (strpos($line, '#') !== false) {
                $parts = explode('#', $line, 2);
                $ip = trim($parts[0]);
                $comment = trim($parts[1]);
                
                if (strpos($comment, '@') !== false) {
                    $commentParts = explode('@', $comment, 2);
                    $reason = trim($commentParts[0]);
                    $time = trim($commentParts[1]);
                } else {
                    $reason = $comment;
                }
            }
            
            $entries[] = [
                'ip' => $ip,
                'reason' => $reason,
                'time' => $time
            ];
        }
    } catch (Exception $e) {
    }
    
    return $entries;
}
$securityStats = [];
$securityLogs = [];
$blacklistEntries = [];
$visitorStats = [];
$visitorLogs = [];
$visitorTrend = [];
$geoDistribution = [];
$botIPs = [];

if ($tab === 'security') {
    $securityStats = getSecurityStats($db, $prefix);
    $securityLogs = getSecurityLogs($db, $prefix, $page, $pageSize);
    $blacklistEntries = getBlacklistEntries($pluginOptions);
} elseif ($tab === 'visitors') {
    $ipSearch = $request->get('ip_search', '');
    $filters = [];
    if (!empty($ipSearch)) {
        $filters['ip'] = $ipSearch;
    }
    $visitorLogs = VisitorStats::getGroupedVisitorLogs($page, $pageSize, $filters);
} elseif ($tab === 'bots') {
    $botIPs = VisitorStats::listBotIPs($page, 20);
} elseif ($tab === 'audit') {
    $visitorStats = VisitorStats::getVisitorStats();
    $visitorTrend = VisitorStats::getTimeBasedStats('hour');
    $geoDistribution = VisitorStats::getGeographicDistribution();
}

?>
<div class="blockip-console">
<style>
        .blockip-console { padding: 20px; }
        
        /* 标签页导航 */
        .tab-navigation { 
            display: flex; 
            gap: 10px; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #e0e0e0;
        }
        .tab-navigation a { 
            padding: 12px 24px; 
            text-decoration: none; 
            color: #666; 
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s;
        }
        .tab-navigation a:hover { 
            color: #467b96; 
            background: #f8f9fa;
        }
        .tab-navigation a.active { 
            color: #467b96; 
            border-bottom-color: #467b96;
            font-weight: 600;
        }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #467b96; }
        .chart-container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .log-table { width: 100%; border-collapse: collapse; background: #fff; }
        .log-table th, .log-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .log-table th { background: #f8f9fa; font-weight: 600; }
        .log-table tr:hover { background: #f8f9fa; }
        .pagination { margin: 20px 0; text-align: center; }
        .pagination a { padding: 8px 12px; margin: 0 4px; background: #467b96; color: #fff; text-decoration: none; border-radius: 4px; }
        .pagination a:hover { background: #356a7f; }
        .pagination .current { padding: 8px 12px; margin: 0 4px; background: #ccc; color: #333; border-radius: 4px; }
        .btn { padding: 10px 20px; background: #467b96; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin-right: 10px; }
        .btn:hover { background: #356a7f; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .message { padding: 15px; margin: 20px 0; border-radius: 4px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input[type="text"], .form-group input[type="number"] { 
            padding: 8px 12px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            width: 300px;
        }
        .search-form { margin-bottom: 20px; }
</style>

<h1>IP防护控制台</h1>
    <div class="tab-navigation">
        <a href="<?php echo PathHelper::getConsolePanelUrl('security'); ?>" class="<?php echo $tab === 'security' ? 'active' : ''; ?>">安全日志</a>
        <a href="<?php echo PathHelper::getConsolePanelUrl('visitors'); ?>" class="<?php echo $tab === 'visitors' ? 'active' : ''; ?>">访客日志</a>
        <a href="<?php echo PathHelper::getConsolePanelUrl('bots'); ?>" class="<?php echo $tab === 'bots' ? 'active' : ''; ?>">机器人管理</a>
        <a href="<?php echo PathHelper::getConsolePanelUrl('audit'); ?>" class="<?php echo $tab === 'audit' ? 'active' : ''; ?>">网站审计</a>
        <a href="<?php echo PathHelper::getConsolePanelUrl('settings'); ?>" class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">插件设置</a>
    </div>
    
    <?php if ($success_message): ?>
        <div class="message success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="message error"><?php echo $error_message; ?></div>
    <?php endif; ?>
    
    <?php if ($tab === 'security'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>今日拦截</h3>
                <div class="number"><?php echo $securityStats['today']; ?></div>
            </div>
            <div class="stat-card">
                <h3>总计拦截</h3>
                <div class="number"><?php echo $securityStats['total']; ?></div>
            </div>
            <div class="stat-card">
                <h3>自动拉黑</h3>
                <div class="number"><?php echo $securityStats['auto_blacklist']; ?></div>
            </div>
            <div class="stat-card">
                <h3>活跃IP</h3>
                <div class="number"><?php echo count($securityStats['top_ips']); ?></div>
            </div>
        </div>
        <div class="chart-container">
            <h2>24小时拦截趋势</h2>
            <canvas id="securityTrendChart" height="80"></canvas>
        </div>
        <div class="chart-container">
            <h2>最活跃IP（Top 10）</h2>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>拦截次数</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($securityStats['top_ips'] as $ipStat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ipStat['ip']); ?></td>
                        <td><?php echo $ipStat['count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="chart-container">
            <h2>拦截日志</h2>
            <div style="margin-bottom: 15px;">
                <form method="post" style="display: inline;" onsubmit="return confirm('确定要清空所有安全日志吗？');">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="btn btn-danger">清空日志</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="fix_database">
                    <button type="submit" class="btn">修复数据库</button>
                </form>
            </div>
            
            <table class="log-table">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>IP地址</th>
                        <th>拦截原因</th>
                        <th>URL</th>
                        <th>User-Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($securityLogs['logs'] as $log): ?>
                    <tr>
                        <td><?php echo date('Y-m-d H:i:s', $log['created']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                        <td><?php echo htmlspecialchars($log['reason']); ?></td>
                        <td title="<?php echo htmlspecialchars($log['url']); ?>">
                            <?php echo htmlspecialchars(substr($log['url'], 0, 50)) . (strlen($log['url']) > 50 ? '...' : ''); ?>
                        </td>
                        <td title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                            <?php echo htmlspecialchars(substr($log['user_agent'], 0, 50)) . (strlen($log['user_agent']) > 50 ? '...' : ''); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- 分页 -->
            <?php if ($securityLogs['total_pages'] > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $securityLogs['total_pages']; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo PathHelper::getConsolePanelUrl('security', ['page' => $i]); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="chart-container">
            <h2>黑名单管理</h2>
            <?php if (count($blacklistEntries) > 0): ?>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>拉黑原因</th>
                        <th>拉黑时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blacklistEntries as $entry): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($entry['ip']); ?></td>
                        <td><?php echo htmlspecialchars($entry['reason']); ?></td>
                        <td><?php echo htmlspecialchars($entry['time']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: #999; text-align: center; padding: 20px;">黑名单为空</p>
            <?php endif; ?>
            <p style="margin-top: 15px; color: #666;">
                <strong>提示：</strong>要管理黑名单，请前往 <a href="<?php echo PathHelper::getConsolePanelUrl('settings'); ?>">插件设置</a>
            </p>
        </div>
        
        <script>
        const securityCtx = document.getElementById('securityTrendChart').getContext('2d');
        new Chart(securityCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($securityStats['hourly'], 'hour')); ?>,
                datasets: [{
                    label: '拦截次数',
                    data: <?php echo json_encode(array_column($securityStats['hourly'], 'count')); ?>,
                    borderColor: '#467b96',
                    backgroundColor: 'rgba(70, 123, 150, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        </script>
        
    <?php elseif ($tab === 'visitors'): ?>
        <div class="chart-container">
            <h2>访客日志</h2>
            <form method="get" class="search-form">
                <input type="hidden" name="panel" value="<?php echo PathHelper::getConsolePanelPath(); ?>">
                <input type="hidden" name="tab" value="visitors">
                <div class="form-group" style="display: inline-block;">
                    <input type="text" name="ip_search" placeholder="搜索IP地址" value="<?php echo htmlspecialchars($request->get('ip_search', '')); ?>">
                    <button type="submit" class="btn">搜索</button>
                    <?php if (!empty($request->get('ip_search'))): ?>
                        <a href="<?php echo PathHelper::getConsolePanelUrl('visitors'); ?>" class="btn">清除</a>
                    <?php endif; ?>
                </div>
            </form>
            <div style="margin-bottom: 15px;">
                <form method="post" style="display: inline;" onsubmit="return confirm('确定要清空所有访客日志吗？');">
                    <input type="hidden" name="action" value="clear_visitor_logs">
                    <input type="hidden" name="days" value="0">
                    <button type="submit" class="btn btn-danger">清空所有日志</button>
                </form>
                <form method="post" style="display: inline;" onsubmit="return confirm('确定要清理30天前的访客日志吗？');">
                    <input type="hidden" name="action" value="clear_visitor_logs">
                    <input type="hidden" name="days" value="30">
                    <button type="submit" class="btn">清理30天前</button>
                </form>
            </div>
            
            <table class="log-table" id="visitorLogsTable">
                <thead>
                    <tr>
                        <th>最新访问时间</th>
                        <th>IP地址</th>
                        <th>最新访问路由</th>
                        <th>地理位置</th>
                        <th>访问次数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($visitorLogs['logs'])): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                            <div style="font-size: 48px; margin-bottom: 10px;">📊</div>
                            <div style="font-size: 16px;">暂无访客日志记录</div>
                            <div style="font-size: 14px; margin-top: 5px;">访客访问网站后，日志将显示在这里</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($visitorLogs['logs'] as $log): ?>
                    <tr class="visitor-row" data-ip="<?php echo htmlspecialchars($log['ip']); ?>">
                        <td><?php echo htmlspecialchars($log['latest_time']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                        <td title="<?php echo htmlspecialchars($log['latest_route']); ?>">
                            <?php echo htmlspecialchars(substr($log['latest_route'], 0, 50)) . (strlen($log['latest_route']) > 50 ? '...' : ''); ?>
                        </td>
                        <td><?php echo htmlspecialchars($log['country'] . ' ' . $log['region'] . ' ' . $log['city']); ?></td>
                        <td><span class="visit-count"><?php echo $log['visit_count']; ?> 次</span></td>
                        <td>
                            <button class="btn btn-small expand-btn" data-ip="<?php echo htmlspecialchars($log['ip']); ?>">
                                <span class="icon">+</span> 查看历史
                            </button>
                        </td>
                    </tr>
                    <tr class="history-row" data-ip="<?php echo htmlspecialchars($log['ip']); ?>" style="display: none;">
                        <td colspan="6">
                            <div class="history-container">
                                <div class="loading">加载中...</div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- 分页 -->
            <?php if ($visitorLogs['total_pages'] > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $visitorLogs['total_pages']; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <?php 
                        $params = ['page' => $i];
                        if (!empty($request->get('ip_search'))) {
                            $params['ip_search'] = $request->get('ip_search');
                        }
                        ?>
                        <a href="<?php echo PathHelper::getConsolePanelUrl('visitors', $params); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        
    <?php elseif ($tab === 'bots'): ?>
        <div class="chart-container">
            <h2>批量添加Bot IP</h2>
            <form method="post">
                <input type="hidden" name="action" value="batch_add_bot_ips">
                <div class="form-group">
                    <label>批量添加 Bot IP（每行一个）</label>
                    <textarea name="ips_text" rows="10" placeholder="每行一个IP地址，支持通配符&#10;例如：&#10;192.168.1.1&#10;192.168.*.*&#10;2001:db8::*" style="width: 100%; max-width: 600px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;"></textarea>
                </div>
                <button type="submit" class="btn">批量添加</button>
                <p style="color: #666; font-size: 14px; margin-top: 10px;">
                    <strong>说明：</strong>支持通配符 * 匹配任意数字。例如：192.168.*.* 可以匹配 192.168.0.1 到 192.168.255.255
                </p>
            </form>
        </div>
        
        <div class="chart-container">
            <h2>Bot IP 列表</h2>
            
            <!-- 批量操作按钮区域 -->
            <div class="batch-actions" id="batchActions" style="display: none; margin-bottom: 15px;">
                <button type="button" id="selectAllBtn" class="btn">全选</button>
                <button type="button" id="deselectAllBtn" class="btn">取消全选</button>
                <button type="button" id="batchDeleteBtn" class="btn btn-danger">批量删除</button>
            </div>
            
            <?php if (count($botIPs['list']) > 0): ?>
            <form method="post" id="batchDeleteForm">
                <input type="hidden" name="action" value="batch_remove_bot_ips">
                <input type="hidden" name="ids" id="batchDeleteIds" value="">
            </form>
            
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="selectAllCheckbox"></th>
                        <th>IP地址</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($botIPs['list'] as $bot): ?>
                    <tr>
                        <td><input type="checkbox" class="bot-checkbox" value="<?php echo $bot['id']; ?>"></td>
                        <td><?php echo htmlspecialchars($bot['ip']); ?></td>
                        <td><?php echo htmlspecialchars($bot['time']); ?></td>
                        <td>
                            <form method="post" style="display: inline;" onsubmit="return confirm('确定要删除这个Bot IP吗？');">
                                <input type="hidden" name="action" value="remove_bot_ip">
                                <input type="hidden" name="id" value="<?php echo $bot['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-small">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- 分页 -->
            <?php if ($botIPs['total_pages'] > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $botIPs['total_pages']; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo PathHelper::getConsolePanelUrl('bots', ['page' => $i]); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <p style="color: #999; text-align: center; padding: 20px;">暂无Bot IP记录</p>
            <?php endif; ?>
        </div>
        
    <?php elseif ($tab === 'audit'): ?>
        <!-- 网站审计标签页 -->
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>今日访问</h3>
                <div class="number"><?php echo isset($visitorStats['today']) ? $visitorStats['today'] : 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>总访问量</h3>
                <div class="number"><?php echo isset($visitorStats['total']) ? $visitorStats['total'] : 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>独立访客</h3>
                <div class="number"><?php echo isset($visitorStats['unique']) ? $visitorStats['unique'] : 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>热门地区</h3>
                <div class="number"><?php echo isset($visitorStats['top_regions']) ? count($visitorStats['top_regions']) : 0; ?></div>
            </div>
        </div>
        
        <!-- 24小时访问趋势图 -->
        <div class="chart-container">
            <h2>24小时访问趋势</h2>
            <canvas id="auditTrendChart" height="80"></canvas>
        </div>
        
        <!-- 地理分布 Top 10 -->
        <div class="chart-container">
            <h2>地理分布（Top 10）</h2>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>国家/地区</th>
                        <th>访问次数</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($geoDistribution)): ?>
                        <?php foreach ($geoDistribution as $geo): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($geo['country']); ?></td>
                            <td><?php echo $geo['count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: #999;">暂无数据</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        // 24小时访问趋势图
        const auditCtx = document.getElementById('auditTrendChart').getContext('2d');
        new Chart(auditCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($visitorTrend, 'time')); ?>,
                datasets: [{
                    label: '访问次数',
                    data: <?php echo json_encode(array_column($visitorTrend, 'count')); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        </script>

    <?php elseif ($tab === 'settings'): ?>
        <?php
        $cfg = [];
        if ($pluginOptions) {
            foreach (['mode', 'blacklistMode', 'blacklist', 'whitelist', 'uaWhitelist', 'urlWhitelist',
                      'accessInterval', 'blockedRegions', 'sensitiveWords', 'customMessage', 'debugMode',
                      'botKeywords', 'logRetentionDays', 'adminWhitelist', 'adminRedirectUrl'] as $key) {
                $cfg[$key] = isset($pluginOptions->$key) ? $pluginOptions->$key : '';
            }
            foreach (['enableSQLProtection', 'enableXSSProtection', 'enableCSRFProtection',
                      'enableLoginCaptcha', 'enableVisitorLog', 'completeUninstall'] as $key) {
                $cfg[$key] = isset($pluginOptions->$key) && in_array('1', (array)$pluginOptions->$key);
            }
        }
        ?>
        <form method="post" class="settings-form">
            <input type="hidden" name="action" value="save_settings">

            <div class="settings-section">
                <h2>基本设置</h2>

                <div class="setting-item">
                    <label>工作模式</label>
                    <select name="mode" class="wide">
                        <option value="blacklist" <?php echo ($cfg['mode'] ?? '') === 'blacklist' ? 'selected' : ''; ?>>黑名单模式（拦截指定IP）</option>
                        <option value="whitelist" <?php echo ($cfg['mode'] ?? '') === 'whitelist' ? 'selected' : ''; ?>>白名单模式（仅允许指定IP）</option>
                        <option value="smart" <?php echo ($cfg['mode'] ?? 'smart') === 'smart' ? 'selected' : ''; ?>>智能模式（自动识别威胁）</option>
                    </select>
                    <p class="desc">选择插件的工作模式</p>
                </div>

                <div class="setting-item">
                    <label>黑名单处理模式</label>
                    <select name="blacklistMode" class="wide">
                        <option value="block" <?php echo ($cfg['blacklistMode'] ?? 'block') === 'block' ? 'selected' : ''; ?>>完全禁止访问</option>
                        <option value="limit" <?php echo ($cfg['blacklistMode'] ?? '') === 'limit' ? 'selected' : ''; ?>>限制访问频率</option>
                    </select>
                </div>

                <div class="setting-item">
                    <label>访问间隔（秒）</label>
                    <input type="number" name="accessInterval" value="<?php echo htmlspecialchars($cfg['accessInterval'] ?? '10'); ?>" min="1">
                    <p class="desc">同一IP两次访问的最小间隔时间</p>
                </div>

                <div class="setting-item">
                    <label>调试模式</label>
                    <select name="debugMode" class="wide">
                        <option value="0" <?php echo ($cfg['debugMode'] ?? '0') === '0' ? 'selected' : ''; ?>>关闭</option>
                        <option value="1" <?php echo ($cfg['debugMode'] ?? '') === '1' ? 'selected' : ''; ?>>开启</option>
                    </select>
                    <p class="desc">开启后会在错误日志中记录详细信息</p>
                </div>
            </div>

            <div class="settings-section">
                <h2>IP 访问控制</h2>

                <div class="setting-item">
                    <label>IP 黑名单</label>
                    <textarea name="blacklist" rows="6"><?php echo htmlspecialchars($cfg['blacklist'] ?? ''); ?></textarea>
                    <p class="desc">每行一个IP或IP段，支持：单个IP、IP范围(1-50)、通配符(*)、CIDR(/24)</p>
                </div>

                <div class="setting-item">
                    <label>IP 白名单</label>
                    <textarea name="whitelist" rows="6"><?php echo htmlspecialchars($cfg['whitelist'] ?? ''); ?></textarea>
                    <p class="desc">白名单IP不受任何限制</p>
                </div>

                <div class="setting-item">
                    <label>User-Agent 白名单</label>
                    <textarea name="uaWhitelist" rows="4"><?php echo htmlspecialchars($cfg['uaWhitelist'] ?? ''); ?></textarea>
                    <p class="desc">每行一个关键词，包含这些关键词的UA不受限制</p>
                </div>

                <div class="setting-item">
                    <label>URL 白名单</label>
                    <textarea name="urlWhitelist" rows="4"><?php echo htmlspecialchars($cfg['urlWhitelist'] ?? ''); ?></textarea>
                    <p class="desc">每行一个URL路径，这些路径不受限制</p>
                </div>

                <div class="setting-item">
                    <label>地区过滤模式</label>
                    <select name="regionMode" class="wide">
                        <option value="blacklist" <?php echo ($cfg['regionMode'] ?? 'blacklist') === 'blacklist' ? 'selected' : ''; ?>>黑名单模式（拦截指定地区）</option>
                        <option value="whitelist" <?php echo ($cfg['regionMode'] ?? '') === 'whitelist' ? 'selected' : ''; ?>>白名单模式（仅允许指定地区）</option>
                    </select>
                </div>

                <div class="setting-item">
                    <label>地区列表</label>
                    <textarea name="blockedRegions" rows="4"><?php echo htmlspecialchars($cfg['blockedRegions'] ?? ''); ?></textarea>
                    <p class="desc">每行一个地区名称（如：美国、日本），根据上方模式决定拦截或允许</p>
                </div>
            </div>

            <div class="settings-section">
                <h2>安全防护</h2>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="enableSQLProtection" value="1" checked disabled>
                        <input type="hidden" name="enableSQLProtection" value="1">
                        启用 SQL 注入防护
                    </label>
                </div>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="enableXSSProtection" value="1" checked disabled>
                        <input type="hidden" name="enableXSSProtection" value="1">
                        启用 XSS 攻击防护
                    </label>
                </div>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="enableCSRFProtection" value="1" checked disabled>
                        <input type="hidden" name="enableCSRFProtection" value="1">
                        启用 CSRF 攻击防护
                    </label>
                </div>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="enableLoginCaptcha" value="1" <?php echo ($cfg['enableLoginCaptcha'] ?? false) ? 'checked' : ''; ?>>
                        启用登录验证码保护
                    </label>
                </div>

                <div class="setting-item">
                    <label>敏感词列表</label>
                    <textarea name="sensitiveWords" rows="4"><?php echo htmlspecialchars($cfg['sensitiveWords'] ?? ''); ?></textarea>
                    <p class="desc">每行一个敏感词，包含这些词的请求将被拦截</p>
                </div>

                <div class="setting-item">
                    <label>自定义拦截提示</label>
                    <textarea name="customMessage" rows="3"><?php echo htmlspecialchars($cfg['customMessage'] ?? '抱歉，您的访问被系统安全策略拦截。'); ?></textarea>
                </div>
            </div>

            <div class="settings-section">
                <h2>后台访问控制</h2>

                <div class="setting-item">
                    <label>后台访问IP白名单</label>
                    <textarea name="adminWhitelist" rows="4"><?php echo htmlspecialchars($cfg['adminWhitelist'] ?? ''); ?></textarea>
                    <p class="desc">每行一个IP或IP段，留空表示允许所有IP访问后台</p>
                </div>

                <div class="setting-item">
                    <label>非白名单IP重定向URL</label>
                    <input type="text" name="adminRedirectUrl" value="<?php echo htmlspecialchars($cfg['adminRedirectUrl'] ?? ''); ?>">
                    <p class="desc">当IP不在白名单时跳转的URL，留空则跳转到网站首页</p>
                </div>
            </div>

            <div class="settings-section">
                <h2>访客日志</h2>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="enableVisitorLog" value="1" checked disabled>
                        <input type="hidden" name="enableVisitorLog" value="1">
                        启用访客日志记录
                    </label>
                    <p class="desc">记录所有访客访问信息（不包括管理后台和机器人）</p>
                </div>

                <div class="setting-item">
                    <label>机器人关键词</label>
                    <textarea name="botKeywords" rows="6"><?php echo htmlspecialchars($cfg['botKeywords'] ?? "baidu=>百度\ngoogle=>谷歌\nsogou=>搜狗\nyoudao=>有道\nsoso=>搜搜\nbing=>必应\nyahoo=>雅虎\n360=>360搜索"); ?></textarea>
                    <p class="desc">每行一个，格式：关键词=>显示名称。用于识别搜索引擎爬虫</p>
                </div>

                <div class="setting-item">
                    <label>日志保留天数</label>
                    <input type="number" name="logRetentionDays" value="<?php echo htmlspecialchars($cfg['logRetentionDays'] ?? '30'); ?>" min="0">
                    <p class="desc">访客日志保留天数，0表示不自动清理</p>
                </div>
            </div>

            <div class="settings-section danger-zone">
                <h2>危险操作</h2>

                <div class="setting-item checkbox-item">
                    <label>
                        <input type="checkbox" name="completeUninstall" value="1" <?php echo ($cfg['completeUninstall'] ?? false) ? 'checked' : ''; ?>>
                        启用完整卸载（禁用插件时删除所有数据库表）
                    </label>
                    <p class="desc warning">警告：启用此选项后，禁用插件时将删除所有数据库表，包括所有日志记录。此操作不可恢复！</p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>

        <style>
        .settings-form { max-width: 800px; }
        .settings-section { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .settings-section h2 { margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #eee; font-size: 18px; color: #333; }
        .setting-item { margin-bottom: 20px; }
        .setting-item:last-child { margin-bottom: 0; }
        .setting-item > label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
        .setting-item select, .setting-item input[type="text"], .setting-item input[type="number"] {
            padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 400px; font-size: 14px;
        }
        .setting-item select.wide { max-width: 800px; height: 50px; }
        .setting-item textarea {
            padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; width: 100%; font-size: 14px; font-family: monospace;
        }
        .setting-item .desc { margin: 8px 0 0 0; font-size: 13px; color: #666; }
        .setting-item .desc.warning { color: #dc3545; font-weight: 500; }
        .checkbox-item > label { display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; }
        .checkbox-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .danger-zone { border: 2px solid #dc3545; }
        .danger-zone h2 { color: #dc3545; }
        .form-actions { margin-top: 20px; }
        .btn-primary { background: #467b96; padding: 12px 30px; font-size: 16px; }
        .btn-primary:hover { background: #356a7f; }
        </style>

    <?php endif; ?>
    
    <script>
    <?php if ($tab === 'visitors'): ?>
    (function() {
        const historyCache = {};
        document.querySelectorAll('.expand-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const ip = this.dataset.ip;
                const historyRow = document.querySelector(`.history-row[data-ip="${ip}"]`);
                const icon = this.querySelector('.icon');
                
                if (historyRow.style.display === 'none') {
                    icon.textContent = '-';
                    this.innerHTML = '<span class="icon">-</span> 收起历史';
                    historyRow.style.display = 'table-row';
                    
                    if (!historyCache[ip]) {
                        loadIPHistory(ip, historyRow);
                    }
                } else {
                    icon.textContent = '+';
                    this.innerHTML = '<span class="icon">+</span> 查看历史';
                    historyRow.style.display = 'none';
                }
            });
        });
        
        function loadIPHistory(ip, container) {
            const historyContainer = container.querySelector('.history-container');
            historyContainer.innerHTML = '<div class="loading" style="text-align: center; padding: 20px; color: #999;">加载中...</div>';
            
            fetch(`?panel=<?php echo PathHelper::getConsolePanelPath(); ?>&action=get_ip_history&ip=${encodeURIComponent(ip)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        historyCache[ip] = data.data;
                        renderHistory(historyContainer, data.data);
                    } else {
                        historyContainer.innerHTML = '<div class="error" style="text-align: center; padding: 20px; color: #e74c3c;">加载失败: ' + (data.error || '未知错误') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('加载历史记录失败:', error);
                    historyContainer.innerHTML = '<div class="error" style="text-align: center; padding: 20px; color: #e74c3c;">网络错误，请重试</div>';
                });
        }
        
        function renderHistory(container, records) {
            if (!records || records.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #999;">暂无历史记录</div>';
                return;
            }
            
            let html = '<div style="padding: 15px; background: #f8f9fa; border-radius: 4px;">';
            html += '<table class="log-table" style="margin: 0;">';
            html += '<thead><tr><th>时间</th><th>访问路由</th><th>地理位置</th></tr></thead>';
            html += '<tbody>';
            
            records.forEach(record => {
                const location = [record.country, record.region, record.city].filter(v => v && v !== 'Unknown').join(' ');
                html += `<tr>
                    <td>${escapeHtml(record.time)}</td>
                    <td title="${escapeHtml(record.route)}">${escapeHtml(truncate(record.route, 60))}</td>
                    <td>${escapeHtml(location || 'Unknown')}</td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function truncate(text, maxLength) {
            return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
        }
    })();
    <?php endif; ?>
    
    <?php if ($tab === 'bots'): ?>
    (function() {
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const botCheckboxes = document.querySelectorAll('.bot-checkbox');
        const batchActions = document.getElementById('batchActions');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const batchDeleteBtn = document.getElementById('batchDeleteBtn');
        
        function updateBatchActions() {
            const checkedCount = document.querySelectorAll('.bot-checkbox:checked').length;
            batchActions.style.display = checkedCount > 0 ? 'block' : 'none';
        }
        
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                botCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateBatchActions();
            });
        }
        
        botCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(botCheckboxes).every(cb => cb.checked);
                const someChecked = Array.from(botCheckboxes).some(cb => cb.checked);
                
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = allChecked;
                    selectAllCheckbox.indeterminate = someChecked && !allChecked;
                }
                
                updateBatchActions();
            });
        });
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                botCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = true;
                }
                updateBatchActions();
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                botCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
                updateBatchActions();
            });
        }
        
        if (batchDeleteBtn) {
            batchDeleteBtn.addEventListener('click', function() {
                const checkedBoxes = document.querySelectorAll('.bot-checkbox:checked');
                const ids = Array.from(checkedBoxes).map(cb => cb.value);
                
                if (ids.length === 0) {
                    alert('请选择至少一个 Bot IP');
                    return;
                }
                
                if (confirm(`确定要删除 ${ids.length} 个 Bot IP 吗？`)) {
                    document.getElementById('batchDeleteIds').value = ids.join(',');
                    document.getElementById('batchDeleteForm').submit();
                }
            });
        }
    })();
    <?php endif; ?>
    </script>
    
    <style>
    .history-row {
        background: #f8f9fa;
    }
    
    .history-container {
        padding: 10px;
    }
    
    .history-container .loading {
        text-align: center;
        padding: 20px;
        color: #999;
    }
    
    .history-container .error {
        text-align: center;
        padding: 20px;
        color: #e74c3c;
    }
    
    .expand-btn {
        cursor: pointer;
        white-space: nowrap;
    }
    
    .expand-btn .icon {
        font-weight: bold;
        font-size: 16px;
    }
    
    .batch-actions {
        padding: 10px;
        background: #f8f9fa;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    
    .bot-checkbox {
        cursor: pointer;
    }
    
    #selectAllCheckbox {
        cursor: pointer;
    }
    
    .visit-count {
        font-weight: 600;
        color: #467b96;
    }
</style>

<script src="<?php echo PathHelper::getAssetUrl('js/chart.min.js'); ?>"></script>

</div>

<?php
require_once $adminPath . 'footer.php';
?>
