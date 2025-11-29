<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_Bilibili_WbiSigner
{
    private static $mixinTab = [46,47,18,2,53,8,23,32,15,50,10,31,58,3,45,35,27,43,5,49,33,9,42,19,29,28,14,39,12,38,41,13,37,48,7,16,24,55,40,61,26,17,0,1,60,51,30,4,22,25,54,21,56,59,6,63,57,62,11,36,20,34,44,52];
    private static $keys = null;
    private static $cacheTime = 0;
    private static $fallback = null;
    const TTL = 1800;

    private static function cacheFile() { return __DIR__ . '/cache/wbi_keys.json'; }

    public static function uuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)) . 'infoc';
    }

    public static function buvid()
    {
        return strtoupper(substr(md5(uniqid()),0,8) . substr(md5(time()),0,4) . substr(md5(mt_rand()),0,4));
    }

    private static function loadCache()
    {
        $f = self::cacheFile();
        if (file_exists($f)) {
            $d = @json_decode(file_get_contents($f), true);
            if ($d && isset($d['keys'], $d['time'])) {
                if (time() - $d['time'] < self::TTL) { self::$keys = $d['keys']; self::$cacheTime = $d['time']; return true; }
                self::$fallback = $d['keys'];
            }
        }
        return false;
    }

    private static function saveCache($keys)
    {
        $dir = dirname(self::cacheFile());
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(self::cacheFile(), json_encode(['keys' => $keys, 'time' => time()]));
        self::$keys = $keys;
        self::$cacheTime = time();
        self::$fallback = $keys;
    }

    public static function getWbiKeys()
    {
        if (self::$keys && time() - self::$cacheTime < self::TTL) return self::$keys;
        if (self::loadCache()) return self::$keys;

        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => 'https://api.bilibili.com/x/web-interface/nav', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 Chrome/120.0.0.0', 'Referer: https://www.bilibili.com/', 'Cookie: _uuid=' . self::uuid() . '; buvid3=' . self::buvid()], CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => 'gzip']);
        for ($i = 0; $i < 3; $i++) {
            $res = curl_exec($ch);
            if ($res && curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) break;
            if ($i < 2) usleep(300000 * ($i + 1));
        }
        curl_close($ch);

        if ($res) {
            $d = json_decode($res, true);
            if ($d && isset($d['data']['wbi_img'])) {
                $img = pathinfo(parse_url($d['data']['wbi_img']['img_url'] ?? '', PHP_URL_PATH), PATHINFO_FILENAME);
                $sub = pathinfo(parse_url($d['data']['wbi_img']['sub_url'] ?? '', PHP_URL_PATH), PATHINFO_FILENAME);
                if ($img && $sub) { self::saveCache(['img_key' => $img, 'sub_key' => $sub]); return self::$keys; }
            }
        }
        return self::$fallback;
    }

    public static function getMixinKey($img, $sub)
    {
        $raw = $img . $sub;
        $key = '';
        foreach (self::$mixinTab as $i) if (isset($raw[$i])) $key .= $raw[$i];
        return substr($key, 0, 32);
    }

    public static function sign(array $params)
    {
        $keys = self::getWbiKeys();
        if (!$keys) return $params;
        $mixin = self::getMixinKey($keys['img_key'], $keys['sub_key']);
        $params['wts'] = time();
        ksort($params);
        $filtered = [];
        foreach ($params as $k => $v) $filtered[$k] = preg_replace("/[!'()*]/", '', (string)$v);
        $query = [];
        foreach ($filtered as $k => $v) $query[] = rawurlencode($k) . '=' . rawurlencode($v);
        $filtered['w_rid'] = md5(implode('&', $query) . $mixin);
        return $filtered;
    }

    public static function clearCache()
    {
        self::$keys = null;
        self::$cacheTime = 0;
        $f = self::cacheFile();
        if (file_exists($f)) @unlink($f);
    }

    public static function getCacheStatus()
    {
        $f = self::cacheFile();
        $s = ['has_memory_cache' => self::$keys !== null, 'has_file_cache' => file_exists($f), 'has_fallback' => self::$fallback !== null];
        if ($s['has_file_cache']) {
            $d = @json_decode(file_get_contents($f), true);
            if ($d && isset($d['time'])) $s['file_cache_age'] = time() - $d['time'];
        }
        return $s;
    }
}
