<?php

/**
 * 访客统计模块
 * 
 * 负责访客日志记录、统计数据查询和机器人识别管理
 * 
 * @package    BlockIPForTypecho
 * @author     GamblerIX
 * @version    1.0.0
 */

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Db;
use Typecho\Request;
use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * VisitorStats 类
 * 
 * 提供访客统计和机器人管理功能
 */
class VisitorStats
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 记录访客访问
     * 
     * @param string $ip 访客IP地址
     * @param string $route 访问路由
     * @return void
     */
    public static function logVisitorAccess(string $ip, string $route): void
    {
        try {
            // 检查是否为管理后台
            if (self::isAdminArea($route)) {
                return;
            }
            
            // 获取 User-Agent
            $request = new Request();
            $userAgent = $request->getAgent();
            
            // 检查是否为机器人
            if (self::isBot($ip, $userAgent)) {
                return;
            }
            
            // 获取地理位置
            $location = self::getVisitorIpLocation($ip);
            
            // 插入数据库
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $db->query($db->insert($prefix . 'visitor_log')
                ->rows([
                    'ip' => $ip,
                    'route' => substr($route, 0, 255),
                    'country' => $location['country'] ?? 'Unknown',
                    'region' => $location['region'] ?? 'Unknown',
                    'city' => $location['city'] ?? 'Unknown'
                ]));
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " logVisitorAccess Error: " . $e->getMessage());
        }
    }
    
    /**
     * 判断是否为机器人
     * 
     * @param string $ip IP地址
     * @param string $userAgent User-Agent字符串
     * @return bool
     */
    public static function isBot(string $ip, string $userAgent): bool
    {
        // 检查 User-Agent 关键词
        $botList = self::getBotsList();
        $userAgentLower = strtolower($userAgent);
        
        foreach ($botList as $keyword => $name) {
            if (strpos($userAgentLower, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        // 检查 Bot IP 列表
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $results = $db->fetchAll($db->select('ip')
                ->from($prefix . 'visitor_bot_list'));
            
            foreach ($results as $row) {
                $pattern = trim($row['ip']);
                if (empty($pattern)) {
                    continue;
                }
                
                // 匹配通配符模式
                if (self::matchBotIPPattern($ip, $pattern)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " isBot DB Error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * 获取机器人关键词列表
     * 
     * @return array
     */
    public static function getBotsList(): array
    {
        static $cache = null;
        
        if ($cache !== null) {
            return $cache;
        }
        
        try {
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin(self::PLUGIN_NAME);
            
            if (!isset($config->botKeywords) || empty($config->botKeywords)) {
                $cache = [];
                return $cache;
            }
            
            $bots = [];
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $config->botKeywords));
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                
                if (strpos($line, '=>') !== false) {
                    list($keyword, $name) = explode('=>', $line, 2);
                    $bots[trim($keyword)] = trim($name);
                } else {
                    $bots[$line] = $line;
                }
            }
            
            $cache = $bots;
            return $cache;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getBotsList Error: " . $e->getMessage());
            $cache = [];
            return $cache;
        }
    }
    
    /**
     * 获取访客统计数据
     * 
     * @return array
     */
    public static function getVisitorStats(): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $stats = [];
            
            // 总访问量
            $total = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'visitor_log'));
            $stats['total'] = $total ? (int)$total->count : 0;
            
            // 独立访客数
            $unique = $db->fetchObject($db->select('COUNT(DISTINCT ip) as count')
                ->from($prefix . 'visitor_log'));
            $stats['unique'] = $unique ? (int)$unique->count : 0;
            
            // 今日访问量
            $today = date('Y-m-d 00:00:00');
            $todayCount = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'visitor_log')
                ->where('time >= ?', $today));
            $stats['today'] = $todayCount ? (int)$todayCount->count : 0;
            
            // 热门地区 (Top 10)
            $topRegions = $db->fetchAll($db->select('country, COUNT(*) as count')
                ->from($prefix . 'visitor_log')
                ->where('country != ?', 'Unknown')
                ->group('country')
                ->order('count', Db::SORT_DESC)
                ->limit(10));
            $stats['top_regions'] = $topRegions ?: [];
            
            return $stats;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getVisitorStats Error: " . $e->getMessage());
            return [
                'total' => 0,
                'unique' => 0,
                'today' => 0,
                'top_regions' => []
            ];
        }
    }

    /**
     * 获取分组的访客日志列表（按IP去重）
     * 
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param array $filters 过滤条件
     * @return array
     */
    public static function getGroupedVisitorLogs(int $page = 1, int $pageSize = 15, array $filters = []): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $offset = ($page - 1) * $pageSize;
            
            // 构建WHERE子句
            $whereClause = '';
            $ipFilter = null;
            
            // IP搜索过滤
            if (!empty($filters['ip'])) {
                $whereClause = 'WHERE ip LIKE ?';
                $ipFilter = '%' . $filters['ip'] . '%';
            }
            
            // 计算总数（去重后的IP数量）
            // 手动转义 IP 过滤值
            if ($ipFilter !== null) {
                $escapedIpFilter = str_replace("'", "''", $ipFilter);
                $countSql = "SELECT COUNT(DISTINCT ip) as count FROM {$prefix}visitor_log WHERE ip LIKE '{$escapedIpFilter}'";
            } else {
                $countSql = "SELECT COUNT(DISTINCT ip) as count FROM {$prefix}visitor_log";
            }
            $countResult = $db->fetchAll($countSql);
            $totalCount = !empty($countResult) ? (int)$countResult[0]['count'] : 0;
            
            // 获取分组数据 - 使用更简单的查询避免数据库连接问题
            if ($ipFilter !== null) {
                $escapedIpFilter = str_replace("'", "''", $ipFilter);
                $whereClause = "WHERE ip LIKE '{$escapedIpFilter}'";
            } else {
                $whereClause = "";
            }
            
            $dataSql = "
                SELECT 
                    vl.ip,
                    MAX(vl.time) as latest_time,
                    COUNT(*) as visit_count,
                    (SELECT route FROM {$prefix}visitor_log vl2 
                     WHERE vl2.ip = vl.ip 
                     ORDER BY time DESC LIMIT 1) as latest_route,
                    (SELECT country FROM {$prefix}visitor_log vl2 
                     WHERE vl2.ip = vl.ip 
                     ORDER BY time DESC LIMIT 1) as country,
                    (SELECT region FROM {$prefix}visitor_log vl2 
                     WHERE vl2.ip = vl.ip 
                     ORDER BY time DESC LIMIT 1) as region,
                    (SELECT city FROM {$prefix}visitor_log vl2 
                     WHERE vl2.ip = vl.ip 
                     ORDER BY time DESC LIMIT 1) as city
                FROM {$prefix}visitor_log vl
                {$whereClause}
                GROUP BY vl.ip
                ORDER BY latest_time DESC
                LIMIT {$pageSize} OFFSET {$offset}
            ";
            
            $logs = $db->fetchAll($dataSql);
            
            return [
                'logs' => $logs ?: [],
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $pageSize)
            ];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getGroupedVisitorLogs Error: " . $e->getMessage());
            error_log(self::PLUGIN_NAME . " getGroupedVisitorLogs Stack: " . $e->getTraceAsString());
            return [
                'logs' => [],
                'total' => 0,
                'total_pages' => 0
            ];
        }
    }
    
    /**
     * 获取访客日志列表
     * 
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param array $filters 过滤条件
     * @return array
     */
    public static function getVisitorLogs(int $page = 1, int $pageSize = 15, array $filters = []): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $offset = ($page - 1) * $pageSize;
            
            // 构建查询
            $selectCount = $db->select('COUNT(*) as count')->from($prefix . 'visitor_log');
            $selectData = $db->select()->from($prefix . 'visitor_log');
            
            // IP搜索过滤
            if (!empty($filters['ip'])) {
                $ipFilter = '%' . $filters['ip'] . '%';
                $selectCount->where('ip LIKE ?', $ipFilter);
                $selectData->where('ip LIKE ?', $ipFilter);
            }
            
            // 时间范围过滤
            if (!empty($filters['start_time'])) {
                $selectCount->where('time >= ?', $filters['start_time']);
                $selectData->where('time >= ?', $filters['start_time']);
            }
            if (!empty($filters['end_time'])) {
                $selectCount->where('time <= ?', $filters['end_time']);
                $selectData->where('time <= ?', $filters['end_time']);
            }
            
            $total = $db->fetchObject($selectCount);
            $totalCount = $total ? (int)$total->count : 0;
            
            $logs = $db->fetchAll($selectData
                ->order('time', Db::SORT_DESC)
                ->limit($pageSize)
                ->offset($offset));
            
            return [
                'logs' => $logs ?: [],
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $pageSize)
            ];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getVisitorLogs Error: " . $e->getMessage());
            return [
                'logs' => [],
                'total' => 0,
                'total_pages' => 0
            ];
        }
    }
    
    /**
     * 获取地理分布统计
     * 
     * @return array
     */
    public static function getGeographicDistribution(): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $distribution = $db->fetchAll($db->select('country, COUNT(*) as count')
                ->from($prefix . 'visitor_log')
                ->where('country != ?', 'Unknown')
                ->group('country')
                ->order('count', Db::SORT_DESC)
                ->limit(10));
            
            return $distribution ?: [];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getGeographicDistribution Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取时间趋势统计
     * 
     * @param string $period 时间周期 (hour/day/week)
     * @return array
     */
    public static function getTimeBasedStats(string $period = 'hour'): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $stats = [];
            
            if ($period === 'hour') {
                // 24小时统计
                for ($i = 23; $i >= 0; $i--) {
                    $hourStart = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
                    $hourEnd = date('Y-m-d H:59:59', strtotime("-{$i} hours"));
                    
                    $count = $db->fetchObject($db->select('COUNT(*) as count')
                        ->from($prefix . 'visitor_log')
                        ->where('time >= ? AND time <= ?', $hourStart, $hourEnd));
                    
                    $stats[] = [
                        'time' => date('H:i', strtotime($hourStart)),
                        'count' => $count ? (int)$count->count : 0
                    ];
                }
            } elseif ($period === 'day') {
                // 7天统计
                for ($i = 6; $i >= 0; $i--) {
                    $dayStart = date('Y-m-d 00:00:00', strtotime("-{$i} days"));
                    $dayEnd = date('Y-m-d 23:59:59', strtotime("-{$i} days"));
                    
                    $count = $db->fetchObject($db->select('COUNT(*) as count')
                        ->from($prefix . 'visitor_log')
                        ->where('time >= ? AND time <= ?', $dayStart, $dayEnd));
                    
                    $stats[] = [
                        'time' => date('m-d', strtotime($dayStart)),
                        'count' => $count ? (int)$count->count : 0
                    ];
                }
            }
            
            return $stats;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getTimeBasedStats Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 清理旧日志
     * 
     * @param int $days 保留天数，0表示清空所有
     * @return bool
     */
    public static function cleanUpOldLogs(int $days): bool
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            if ($days == 0) {
                // 清空所有
                $db->query($db->delete($prefix . 'visitor_log'));
            } else {
                // 删除指定天数前的记录
                $expiryDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $db->query($db->delete($prefix . 'visitor_log')
                    ->where('time < ?', $expiryDate));
            }
            
            return true;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " cleanUpOldLogs Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取指定IP的访问历史记录
     * 
     * @param string $ip IP地址
     * @param int $limit 返回记录数限制
     * @return array
     */
    public static function getIPAccessHistory(string $ip, int $limit = 50): array
    {
        $startTime = microtime(true);
        
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            // 限制最大返回数量
            $limit = min($limit, 100);
            
            // 查询该IP的所有访问记录 - 使用参数化查询
            $records = $db->fetchAll($db->select('id', 'ip', 'route', 'time', 'country', 'region', 'city')
                ->from($prefix . 'visitor_log')
                ->where('ip = ?', $ip)
                ->order('time', Db::SORT_DESC)
                ->limit($limit));
            
            // 统计总数
            $totalResult = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'visitor_log')
                ->where('ip = ?', $ip));
            $total = $totalResult ? (int)$totalResult->count : 0;
            
            // 计算查询时间
            $queryTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // 如果查询时间过长，记录警告
            if ($queryTime > 100) {
                error_log(self::PLUGIN_NAME . " Slow query detected: getIPAccessHistory took {$queryTime}ms for IP {$ip}");
            }
            
            return [
                'success' => true,
                'data' => $records ?: [],
                'total' => $total,
                'query_time' => $queryTime
            ];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getIPAccessHistory Error: " . $e->getMessage());
            error_log(self::PLUGIN_NAME . " getIPAccessHistory Stack: " . $e->getTraceAsString());
            error_log(self::PLUGIN_NAME . " getIPAccessHistory IP: " . $ip);
            
            return [
                'success' => false,
                'data' => [],
                'total' => 0,
                'error' => 'Database query failed: ' . $e->getMessage(),
                'error_code' => 'DB_ERROR'
            ];
        }
    }
    
    // ========== 机器人管理方法 ==========
    
    /**
     * 添加机器人IP
     * 
     * @param string $ip IP地址(支持通配符)
     * @return bool
     */
    public static function addBotIP(string $ip): bool
    {
        try {
            $ip = trim($ip);
            if (empty($ip)) {
                return false;
            }
            
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            // 检查是否已存在
            $exists = $db->fetchRow($db->select()
                ->from($prefix . 'visitor_bot_list')
                ->where('ip = ?', $ip));
            
            if ($exists) {
                return false;
            }
            
            $db->query($db->insert($prefix . 'visitor_bot_list')
                ->rows(['ip' => $ip]));
            
            return true;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " addBotIP Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除机器人IP
     * 
     * @param int $id 记录ID
     * @return bool
     */
    public static function removeBotIP(int $id): bool
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $db->query($db->delete($prefix . 'visitor_bot_list')
                ->where('id = ?', $id));
            
            return true;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " removeBotIP Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 列出机器人IP
     * 
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array
     */
    public static function listBotIPs(int $page = 1, int $pageSize = 20): array
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $offset = ($page - 1) * $pageSize;
            
            $total = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'visitor_bot_list'));
            $totalCount = $total ? (int)$total->count : 0;
            
            $list = $db->fetchAll($db->select()
                ->from($prefix . 'visitor_bot_list')
                ->order('time', Db::SORT_DESC)
                ->limit($pageSize)
                ->offset($offset));
            
            return [
                'list' => $list ?: [],
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $pageSize)
            ];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " listBotIPs Error: " . $e->getMessage());
            return [
                'list' => [],
                'total' => 0,
                'total_pages' => 0
            ];
        }
    }
    
    /**
     * 批量添加机器人IP
     * 
     * @param string $ipsText 多行文本，每行一个IP地址
     * @return array 操作结果
     */
    public static function batchAddBotIPs(string $ipsText): array
    {
        $result = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // 按行分割输入
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $ipsText));
            
            if (empty($lines)) {
                $result['message'] = '请输入至少一个 IP 地址';
                return $result;
            }
            
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            foreach ($lines as $line) {
                $ip = trim($line);
                
                // 跳过空行和注释
                if (empty($ip) || strpos($ip, '#') === 0) {
                    continue;
                }
                
                // 移除行内注释
                if (strpos($ip, '#') !== false) {
                    $ip = trim(substr($ip, 0, strpos($ip, '#')));
                }
                
                // 验证IP格式
                if (!self::validateBotIPFormat($ip)) {
                    $result['failed']++;
                    $result['errors'][$ip] = '无效的IP格式';
                    continue;
                }
                
                // 检查是否已存在
                try {
                    $exists = $db->fetchRow($db->select()
                        ->from($prefix . 'visitor_bot_list')
                        ->where('ip = ?', $ip));
                    
                    if ($exists) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    // 插入数据库
                    $db->query($db->insert($prefix . 'visitor_bot_list')
                        ->rows(['ip' => $ip]));
                    
                    $result['success']++;
                } catch (\Exception $e) {
                    $result['failed']++;
                    $result['errors'][$ip] = '数据库错误: ' . $e->getMessage();
                }
            }
            
            // 生成总结消息
            $messages = [];
            if ($result['success'] > 0) {
                $messages[] = "成功添加 {$result['success']} 个";
            }
            if ($result['skipped'] > 0) {
                $messages[] = "跳过 {$result['skipped']} 个（已存在）";
            }
            if ($result['failed'] > 0) {
                $messages[] = "失败 {$result['failed']} 个";
            }
            
            $result['message'] = empty($messages) ? '没有有效的IP地址' : implode('，', $messages);
            
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " batchAddBotIPs Error: " . $e->getMessage());
            $result['message'] = '批量添加失败: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 批量删除机器人IP
     * 
     * @param array $ids Bot IP记录的ID数组
     * @return array 操作结果
     */
    public static function batchRemoveBotIPs(array $ids): array
    {
        $result = [
            'success' => 0,
            'failed' => 0,
            'message' => '',
            'errors' => []
        ];
        
        try {
            error_log(self::PLUGIN_NAME . " batchRemoveBotIPs called with IDs: " . implode(',', $ids));
            
            if (empty($ids)) {
                $result['message'] = '请选择至少一个 Bot IP';
                error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Empty IDs array");
                return $result;
            }
            
            // 验证ID数组
            $validIds = array_filter($ids, function($id) {
                return is_numeric($id) && $id > 0;
            });
            $validIds = array_values($validIds); // 重新索引
            
            error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Valid IDs after filtering: " . implode(',', $validIds));
            
            if (empty($validIds)) {
                $result['message'] = '无效的ID列表';
                error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: No valid IDs after filtering");
                return $result;
            }
            
            // 验证数据库连接
            $db = Db::get();
            if (!$db) {
                throw new \Exception('数据库连接失败');
            }
            
            $prefix = $db->getPrefix();
            error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Database prefix: {$prefix}");
            
            // 使用传统方式逐个删除，避免可变参数问题
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($validIds as $id) {
                try {
                    error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Attempting to delete ID {$id}");
                    
                    // 先检查记录是否存在
                    $exists = $db->fetchRow($db->select()
                        ->from($prefix . 'visitor_bot_list')
                        ->where('id = ?', $id));
                    
                    if (!$exists) {
                        error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: ID {$id} does not exist");
                        $result['errors'][$id] = '记录不存在';
                        $failedCount++;
                        continue;
                    }
                    
                    // 执行删除
                    $affected = $db->query($db->delete($prefix . 'visitor_bot_list')
                        ->where('id = ?', $id));
                    
                    error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Delete ID {$id} affected rows: {$affected}");
                    
                    if ($affected > 0) {
                        $successCount++;
                    } else {
                        $result['errors'][$id] = '删除失败（未影响任何行）';
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    error_log(self::PLUGIN_NAME . " batchRemoveBotIPs - Failed to delete ID {$id}: {$errorMsg}");
                    $result['errors'][$id] = $errorMsg;
                    $failedCount++;
                }
            }
            
            $result['success'] = $successCount;
            $result['failed'] = $failedCount;
            
            error_log(self::PLUGIN_NAME . " batchRemoveBotIPs: Final result - Success: {$successCount}, Failed: {$failedCount}");
            
            if ($successCount > 0) {
                $result['message'] = "成功删除 {$successCount} 个 Bot IP";
                if ($failedCount > 0) {
                    $result['message'] .= "，失败 {$failedCount} 个";
                }
            } else {
                $result['message'] = '删除失败';
                if (!empty($result['errors'])) {
                    $result['message'] .= '：' . implode('; ', array_map(function($id, $error) {
                        return "ID {$id}: {$error}";
                    }, array_keys($result['errors']), $result['errors']));
                }
            }
            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            error_log(self::PLUGIN_NAME . " batchRemoveBotIPs Error: {$errorMsg} at {$e->getFile()}:{$e->getLine()}");
            $result['failed'] = count($ids);
            $result['message'] = '批量删除失败: ' . $errorMsg;
        }
        
        return $result;
    }
    
    // ========== 私有辅助方法 ==========
    
    /**
     * 验证Bot IP格式
     * 
     * @param string $ip IP地址或模式
     * @return bool
     */
    private static function validateBotIPFormat(string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }
        
        // 检查是否为有效的IPv4地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
        
        // 检查是否为有效的IPv6地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        
        // 检查是否为通配符模式（IPv4）
        if (preg_match('/^(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)$/', $ip)) {
            $parts = explode('.', $ip);
            foreach ($parts as $part) {
                if ($part !== '*' && ($part < 0 || $part > 255)) {
                    return false;
                }
            }
            return true;
        }
        
        // 检查是否为通配符模式（IPv6简化版）
        if (strpos($ip, ':') !== false && strpos($ip, '*') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 匹配机器人IP通配符模式
     * 
     * @param string $ip 要检查的IP
     * @param string $pattern 通配符模式
     * @return bool
     */
    private static function matchBotIPPattern(string $ip, string $pattern): bool
    {
        // 完全匹配
        if ($ip === $pattern) {
            return true;
        }
        
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\*', '.*', $regex);
        $regex = '/^' . $regex . '$/i';
        
        return preg_match($regex, $ip) === 1;
    }
    
    /**
     * 获取访客IP地理位置
     * 
     * @param string $ip IP地址
     * @return array
     */
    private static function getVisitorIpLocation(string $ip): array
    {
        try {
            $location = GeoLocation::lookupIPLocation($ip);
            
            if (empty($location)) {
                return [
                    'country' => 'Unknown',
                    'region' => 'Unknown',
                    'city' => 'Unknown'
                ];
            }
            
            $parts = explode('|', $location);
            
            $parts = array_map(function($part) {
                return ($part === '0' || empty($part)) ? '' : trim($part);
            }, $parts);
            
            $country = $parts[0] ?? 'Unknown';
            $region = $parts[2] ?? 'Unknown';
            $city = $parts[3] ?? 'Unknown';
            
            if (empty($country) && empty($region) && empty($city)) {
                return [
                    'country' => 'Unknown',
                    'region' => 'Unknown',
                    'city' => 'Unknown'
                ];
            }
            
            return [
                'country' => $country ?: 'Unknown',
                'region' => $region ?: 'Unknown',
                'city' => $city ?: 'Unknown'
            ];
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " getVisitorIpLocation Error: " . $e->getMessage());
            return [
                'country' => 'Unknown',
                'region' => 'Unknown',
                'city' => 'Unknown'
            ];
        }
    }
    
    /**
     * 检查是否为管理后台访问
     * 
     * @param string $url URL地址
     * @return bool
     */
    private static function isAdminArea(string $url): bool
    {
        $adminPaths = ['/admin/', 'admin.php', '/action/', 'action/'];
        foreach ($adminPaths as $path) {
            if (strpos($url, $path) !== false) {
                return true;
            }
        }
        return false;
    }
}
