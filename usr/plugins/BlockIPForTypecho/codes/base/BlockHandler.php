<?php
namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class BlockHandler
{
    const VERSION = '1.0.0';
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    public static function blockAccess(string $ip, string $reason, object $config): void
    {
        $reasonMap = [
            'blacklisted' => '黑名单拦截',
            'not_whitelisted' => '非白名单访问',
            'sql_injection' => 'SQL注入攻击',
            'xss_attack' => 'XSS攻击',
            'csrf_attack' => 'CSRF攻击',
            'blocked_region' => '地理位置限制',
            'blacklist_rate_limit' => '黑名单访问频率限制'
        ];
        
        $finalReason = isset($reasonMap[$reason]) ? $reasonMap[$reason] : $reason;
        
        Logger::logBlockedAccess($ip, $finalReason);
        
        $customMessage = isset($config->customMessage) && !empty($config->customMessage) ?
            $config->customMessage :
            '抱歉，您的访问被系统安全策略拦截。';
        
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>访问被拦截</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            text-align: center;
            padding: 50px 20px;
            background: #f5f5f5;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 600px;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            color: #e74c3c;
            font-size: 48px;
            margin: 0 0 20px 0;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        .message {
            font-size: 18px;
            line-height: 1.6;
            margin: 20px 0;
            color: #555;
        }
        .details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 14px;
            color: #666;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🛡️</div>
        <h1>403</h1>
        <div class="message">' . htmlspecialchars($customMessage) . '</div>
        <div class="details">
            <strong>拦截原因：</strong>' . htmlspecialchars($finalReason) . '<br>
            <strong>您的IP：</strong>' . htmlspecialchars($ip) . '<br>
            <strong>时间：</strong>' . date('Y-m-d H:i:s') . '
        </div>
    </div>
</body>
</html>';
        
        exit;
    }
}
