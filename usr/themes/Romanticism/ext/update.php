<?php
/**
 * 主题更新检查接口
 * @version 2.2
 */

header('Content-Type: application/json; charset=utf-8');

// 当前主题版本（更新时只需修改此处）
define('THEME_VERSION', '2.2');

function checkThemeUpdate()
{
    // GitHub 仓库地址（二开项目）
    $api_url = 'https://api.github.com/repos/GamblerIX/Typecho/releases/latest';
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36\r\n",
            'timeout' => 10
        ]
    ];
    $response = @file_get_contents($api_url, false, stream_context_create($opts));
    if ($response === FALSE) {
        return json_encode(["error" => "无法连接到更新服务器"]);
    }

    $data = json_decode($response, true);
    if (!isset($data['tag_name'])) {
        return json_encode(["error" => "无效的更新数据"]);
    }

    $latestVersion = ltrim($data['tag_name'], 'v');

    return json_encode([
        "current_version" => THEME_VERSION,
        "latest_version" => $latestVersion,
        "has_update" => version_compare($latestVersion, THEME_VERSION, '>'),
        "update_url" => $data['html_url'],
        "feature" => $data['body'] ?? '暂无更新说明'
    ]);
}

echo checkThemeUpdate();
?>
