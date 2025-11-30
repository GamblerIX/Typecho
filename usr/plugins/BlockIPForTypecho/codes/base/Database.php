<?php

namespace TypechoPlugin\BlockIPForTypecho;

use Typecho\Db;

/**
 * 数据库管理模块
 * 
 * 负责数据库表的创建、修复和管理
 */
class Database
{
    /**
     * 插件名称
     */
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    /**
     * 创建数据库表
     */
    public static function createTables(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        
        $logsTable = "CREATE TABLE IF NOT EXISTS `{$prefix}blockip_logs` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `reason` varchar(255) NOT NULL,
            `url` varchar(500) DEFAULT NULL,
            `user_agent` varchar(500) DEFAULT NULL,
            `created` int(10) unsigned NOT NULL,
            `timestamp` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ip` (`ip`),
            KEY `idx_created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $accessTable = "CREATE TABLE IF NOT EXISTS `{$prefix}blockip_access_log` (
            `ip` varchar(45) NOT NULL,
            `url` varchar(500) DEFAULT NULL,
            `user_agent` varchar(500) DEFAULT NULL,
            `last_access` int(10) unsigned NOT NULL,
            `timestamp` int(10) unsigned NOT NULL,
            PRIMARY KEY (`ip`),
            KEY `idx_last_access` (`last_access`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $visitorLogTable = "CREATE TABLE IF NOT EXISTS `{$prefix}visitor_log` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `route` varchar(255) NOT NULL,
            `country` varchar(100) DEFAULT NULL,
            `region` varchar(100) DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ip` (`ip`),
            KEY `idx_time` (`time`),
            KEY `idx_country` (`country`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $botListTable = "CREATE TABLE IF NOT EXISTS `{$prefix}visitor_bot_list` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ip` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        try {
            $db->query($logsTable);
            $db->query($accessTable);
            $db->query($visitorLogTable);
            $db->query($botListTable);
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " createTables: " . $e->getMessage());
        }
    }
    
    /**
     * 修复数据库结构
     */
    public static function fixDatabaseSchema(): string
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $messages = [];
            
            try {
                $db->fetchRow($db->select()->from($prefix . 'blockip_logs')->limit(1));
                $messages[] = '拦截日志表结构正常';
            } catch (\Exception $e) {
                try {
                    $db->query("DROP TABLE IF EXISTS `{$prefix}blockip_logs`");
                    self::createTables();
                    $messages[] = '拦截日志表已重建';
                } catch (\Exception $e2) {
                    $messages[] = '拦截日志表修复失败';
                }
            }
            
            try {
                $db->fetchRow($db->select()->from($prefix . 'blockip_access_log')->limit(1));
                $messages[] = '访问日志表结构正常';
            } catch (\Exception $e) {
                try {
                    $db->query("DROP TABLE IF EXISTS `{$prefix}blockip_access_log`");
                    self::createTables();
                    $messages[] = '访问日志表已重建';
                } catch (\Exception $e2) {
                    $messages[] = '访问日志表修复失败';
                }
            }
            
            $indexResult = self::ensureIndexes();
            $messages[] = $indexResult;
            
            return implode('<br/>', $messages);
        } catch (\Exception $e) {
            return '数据库修复失败: ' . $e->getMessage();
        }
    }
    
    /**
     * 检查索引是否存在
     * 
     * @param string $table 表名
     * @param string $indexName 索引名
     * @return bool
     */
    public static function indexExists(string $table, string $indexName): bool
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $fullTable = $prefix . $table;
            
            $escapedIndexName = str_replace("'", "''", $indexName);
            $sql = "SHOW INDEX FROM `{$fullTable}` WHERE Key_name = '{$escapedIndexName}'";
            $result = $db->fetchAll($sql);
            return !empty($result);
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " indexExists Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建索引
     * 
     * @param string $table 表名
     * @param string $indexName 索引名
     * @param array $columns 列名数组
     * @return bool
     */
    public static function createIndex(string $table, string $indexName, array $columns): bool
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $fullTable = $prefix . $table;
            $columnList = implode(', ', array_map(function($col) {
                return "`{$col}`";
            }, $columns));
            
            $sql = "CREATE INDEX `{$indexName}` ON `{$fullTable}` ({$columnList})";
            $db->query($sql);
            
            error_log(self::PLUGIN_NAME . " Created index {$indexName} on {$fullTable}");
            return true;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " createIndex Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 确保所有必需的索引存在
     * 
     * @return string 操作结果消息
     */
    public static function ensureIndexes(): string
    {
        $results = [];
        $created = 0;
        $skipped = 0;
        
        $indexes = [
            ['table' => 'visitor_log', 'name' => 'idx_ip_time', 'columns' => ['ip', 'time']]
        ];
        
        foreach ($indexes as $index) {
            if (self::indexExists($index['table'], $index['name'])) {
                $skipped++;
            } else {
                if (self::createIndex($index['table'], $index['name'], $index['columns'])) {
                    $created++;
                    $results[] = "创建索引 {$index['name']}";
                } else {
                    $results[] = "创建索引 {$index['name']} 失败";
                }
            }
        }
        
        if ($created > 0) {
            return "索引优化完成：创建 {$created} 个新索引";
        } elseif ($skipped > 0) {
            return "索引检查完成：所有索引已存在";
        } else {
            return "索引检查完成";
        }
    }
    
    /**
     * 获取所有插件创建的表名
     * 
     * @return array
     */
    public static function getPluginTables(): array
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        
        return [
            $prefix . 'blockip_logs',
            $prefix . 'blockip_access_log',
            $prefix . 'visitor_log',
            $prefix . 'visitor_bot_list'
        ];
    }
    
    /**
     * 删除所有插件表
     * 
     * @return array 操作结果
     */
    public static function dropAllTables(): array
    {
        $result = [
            'success' => 0,
            'failed' => 0,
            'tables' => [],
            'errors' => []
        ];
        
        try {
            $db = Db::get();
            $tables = self::getPluginTables();
            
            foreach ($tables as $table) {
                try {
                    $db->query("DROP TABLE IF EXISTS `{$table}`");
                    $result['success']++;
                    $result['tables'][] = $table;
                    error_log(self::PLUGIN_NAME . " Dropped table: {$table}");
                } catch (\Exception $e) {
                    $result['failed']++;
                    $result['errors'][$table] = $e->getMessage();
                    error_log(self::PLUGIN_NAME . " Failed to drop table {$table}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $result['errors']['general'] = $e->getMessage();
            error_log(self::PLUGIN_NAME . " dropAllTables Error: " . $e->getMessage());
        }
        
        return $result;
    }
}
