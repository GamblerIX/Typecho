<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_VideoProxy
{
    public static function proxy($url)
    {
        if (empty($url)) {
            http_response_code(400);
            exit('URL required');
        }

        while (ob_get_level()) ob_end_clean();

        $range = $_SERVER['HTTP_RANGE'] ?? null;
        
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.bilibili.com/',
            'Origin: https://www.bilibili.com',
        ];
        if ($range) $headers[] = 'Range: ' . $range;

        $ch = curl_init($url);
        
        $httpCode = 200;
        $contentType = 'video/mp4';
        $contentLength = 0;
        $contentRange = '';

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$httpCode, &$contentType, &$contentLength, &$contentRange) {
                if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $header, $m)) {
                    $httpCode = (int)$m[1];
                }
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, 13));
                }
                if (stripos($header, 'Content-Length:') === 0) {
                    $contentLength = (int)trim(substr($header, 15));
                }
                if (stripos($header, 'Content-Range:') === 0) {
                    $contentRange = trim($header);
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$httpCode, &$contentType, &$contentLength, &$contentRange, &$headersSent) {
                if (!$headersSent) {
                    $headersSent = true;
                    http_response_code($httpCode);
                    header('Content-Type: ' . $contentType);
                    header('Access-Control-Allow-Origin: *');
                    header('Accept-Ranges: bytes');
                    if ($contentLength) header('Content-Length: ' . $contentLength);
                    if ($contentRange) header($contentRange);
                }
                echo $data;
                flush();
                return strlen($data);
            }
        ]);

        $headersSent = false;
        curl_exec($ch);
        
        if (curl_errno($ch)) {
            if (!$headersSent) {
                http_response_code(502);
                echo 'Proxy error: ' . curl_error($ch);
            }
        }
        
        curl_close($ch);
    }
}
