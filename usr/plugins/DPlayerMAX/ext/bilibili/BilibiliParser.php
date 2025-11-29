<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/WbiSigner.php';

class DPlayerMAX_Bilibili_Parser
{
    const XOR_CODE = 23442827791579;
    const MASK_CODE = 2251799813685247;
    const MAX_AID = 2251799813685248;
    const BASE = 58;
    const TABLE = 'FcwAPNKTMug3GV5Lj7EJnHpWsx4tb8haYeviqBz6rkCy12mUSDQX9RdoZf';

    private static $qualityMap = ['720p' => 64, '360p' => 16];
    private static $qualityDesc = [64 => '720P 高清', 16 => '360P 流畅'];
    private static $cacheTTL = ['video_info' => 86400, 'play_url' => 1800, 'short_url' => 604800];
    private static $cacheDir = null;

    private static function getCacheDir()
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = __DIR__ . '/cache';
            if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0755, true);
        }
        return self::$cacheDir;
    }

    private static function cache($key, $data = null, $type = 'video_info')
    {
        $file = self::getCacheDir() . '/' . md5($key) . '.json';
        if ($data === null) {
            if (!file_exists($file)) return null;
            $d = @json_decode(file_get_contents($file), true);
            if ($d && isset($d['time'], $d['data']) && time() - $d['time'] < (self::$cacheTTL[$type] ?? 7200)) return $d['data'];
            return null;
        }
        @file_put_contents($file, json_encode(['time' => time(), 'type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE));
    }

    private static function headers($referer = 'https://www.bilibili.com/')
    {
        return [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            'Referer: ' . $referer, 'Accept: application/json', 'Accept-Language: zh-CN,zh;q=0.9',
            'Cookie: _uuid=' . DPlayerMAX_Bilibili_WbiSigner::uuid() . '; buvid3=' . DPlayerMAX_Bilibili_WbiSigner::buvid() . '; CURRENT_FNVAL=4048; CURRENT_QUALITY=64',
        ];
    }

    public static function bv2av($bvid)
    {
        if (strlen($bvid) < 12) return 0;
        if (strpos($bvid, 'BV') !== 0) $bvid = 'BV' . $bvid;
        $a = str_split($bvid);
        list($a[3], $a[9]) = [$a[9], $a[3]];
        list($a[4], $a[7]) = [$a[7], $a[4]];
        $tmp = 0;
        foreach (array_slice($a, 3) as $c) {
            $idx = strpos(self::TABLE, $c);
            if ($idx === false) return 0;
            $tmp = $tmp * self::BASE + $idx;
        }
        return ($tmp & self::MASK_CODE) ^ self::XOR_CODE;
    }

    public static function av2bv($avid)
    {
        $b = str_split('BV1000000000');
        $i = 11;
        $tmp = (self::MAX_AID | $avid) ^ self::XOR_CODE;
        while ($tmp > 0) { $b[$i--] = self::TABLE[$tmp % self::BASE]; $tmp = intdiv($tmp, self::BASE); }
        list($b[3], $b[9]) = [$b[9], $b[3]];
        list($b[4], $b[7]) = [$b[7], $b[4]];
        return implode('', $b);
    }

    public static function isBilibiliUrl($url)
    {
        return (bool)preg_match('/bilibili\.com\/video\/(BV|av)|b23\.tv\/|^(BV[a-zA-Z0-9]{10}|av\d+)$/i', $url);
    }

    public static function parseUrl($url)
    {
        $r = ['bvid' => null, 'avid' => null, 'page' => 1, 'time' => 0];
        if (preg_match('/BV([a-zA-Z0-9]{10})/i', $url, $m)) { $r['bvid'] = 'BV' . $m[1]; $r['avid'] = self::bv2av($r['bvid']); }
        elseif (preg_match('/av(\d+)/i', $url, $m)) { $r['avid'] = (int)$m[1]; $r['bvid'] = self::av2bv($r['avid']); }
        elseif (preg_match('/b23\.tv\/([a-zA-Z0-9]+)/i', $url, $m)) {
            $real = self::resolveShort('https://b23.tv/' . $m[1]);
            if ($real) return self::parseUrl($real);
        }
        if (preg_match('/[?&]p=(\d+)/i', $url, $m)) $r['page'] = (int)$m[1];
        if (preg_match('/[?&]t=(\d+)/i', $url, $m)) $r['time'] = (int)$m[1];
        return $r;
    }

    private static function resolveShort($url)
    {
        $cached = self::cache('short_' . $url, null, 'short_url');
        if ($cached) return $cached;
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($redirect) self::cache('short_' . $url, $redirect, 'short_url');
        return $redirect ?: null;
    }

    private static function curl($url, $referer = 'https://www.bilibili.com/')
    {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => self::headers($referer), CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => 'gzip', CURLOPT_FOLLOWLOCATION => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        for ($i = 0; $i < 3; $i++) {
            $res = curl_exec($ch);
            if ($res !== false && curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) break;
            if ($i < 2) usleep(500000 * ($i + 1));
        }
        curl_close($ch);
        return $res ?: false;
    }

    public static function getVideoInfo($bvid)
    {
        $cached = self::cache('info_' . $bvid, null, 'video_info');
        if ($cached) return $cached;
        $res = self::curl('https://api.bilibili.com/x/web-interface/view?bvid=' . $bvid);
        if (!$res) return null;
        $d = json_decode($res, true);
        if (!$d || $d['code'] !== 0) return null;
        $v = $d['data'];
        $info = [
            'bvid' => $v['bvid'], 'avid' => $v['aid'], 'title' => $v['title'], 'desc' => $v['desc'],
            'pic' => str_replace('http://', 'https://', $v['pic']), 'duration' => $v['duration'],
            'owner' => ['mid' => $v['owner']['mid'], 'name' => $v['owner']['name']],
            'cid' => $v['cid'], 'pages' => []
        ];
        foreach ($v['pages'] ?? [] as $p) $info['pages'][] = ['page' => $p['page'], 'cid' => $p['cid'], 'part' => $p['part'], 'duration' => $p['duration']];
        self::cache('info_' . $bvid, $info, 'video_info');
        return $info;
    }

    public static function getPlayUrl($bvid, $cid, $quality = 64)
    {
        $key = "play_{$bvid}_{$cid}_{$quality}";
        $cached = self::cache($key, null, 'play_url');
        if ($cached) return $cached;

        $res = self::curl('https://api.bilibili.com/x/player/playurl?' . http_build_query(['bvid' => $bvid, 'cid' => $cid, 'qn' => $quality, 'fnval' => 1, 'platform' => 'html5', 'high_quality' => 1]), 'https://www.bilibili.com/video/' . $bvid);
        if ($res) {
            $d = json_decode($res, true);
            if ($d && $d['code'] === 0 && isset($d['data']['durl'])) {
                $r = ['quality' => $d['data']['quality'] ?? $quality, 'quality_desc' => self::$qualityDesc[$d['data']['quality'] ?? $quality] ?? '未知', 'format' => 'mp4', 'video_url' => $d['data']['durl'][0]['url'], 'audio_url' => null, 'type' => 'mp4', 'accept_quality' => $d['data']['accept_quality'] ?? [$quality]];
                self::cache($key, $r, 'play_url');
                return $r;
            }
        }

        $params = DPlayerMAX_Bilibili_WbiSigner::sign(['bvid' => $bvid, 'cid' => $cid, 'qn' => $quality, 'fnval' => 16, 'fourk' => 1]);
        $res = self::curl('https://api.bilibili.com/x/player/wbi/playurl?' . http_build_query($params), 'https://www.bilibili.com/video/' . $bvid);
        if (!$res) return null;
        $d = json_decode($res, true);
        if (!$d || $d['code'] !== 0 || !isset($d['data'])) return null;
        $pd = $d['data'];

        $avail = [];
        if (isset($pd['dash']['video'])) foreach ($pd['dash']['video'] as $v) if (!in_array($v['id'], $avail)) $avail[] = $v['id'];
        rsort($avail);
        if (empty($avail)) $avail = $pd['accept_quality'] ?? [];

        $r = ['quality' => $pd['quality'] ?? $quality, 'quality_desc' => self::$qualityDesc[$pd['quality'] ?? $quality] ?? '未知', 'format' => 'dash', 'video_url' => null, 'audio_url' => null, 'accept_quality' => $avail, 'type' => 'dash'];

        if (isset($pd['dash']['video'])) {
            $sel = null;
            foreach ($pd['dash']['video'] as $v) if ($v['id'] == $quality) { $sel = $v; break; }
            if (!$sel) foreach ($pd['dash']['video'] as $v) if ($v['id'] <= $quality && (!$sel || $v['id'] > $sel['id'])) $sel = $v;
            if (!$sel && !empty($pd['dash']['video'])) $sel = end($pd['dash']['video']);
            if ($sel) { $r['video_url'] = $sel['baseUrl'] ?? $sel['base_url']; $r['quality'] = $sel['id']; $r['quality_desc'] = self::$qualityDesc[$sel['id']] ?? '未知'; }
        }
        if (isset($pd['dash']['audio'][0])) $r['audio_url'] = $pd['dash']['audio'][0]['baseUrl'] ?? $pd['dash']['audio'][0]['base_url'];

        if ($r['video_url']) self::cache($key, $r, 'play_url');
        return $r;
    }

    public static function parseQuality($q) { return self::$qualityMap[strtolower(trim($q))] ?? 64; }

    public static function generateIframe($bvid, $page = 1, $opts = [])
    {
        return ['type' => 'iframe', 'src' => 'https://player.bilibili.com/player.html?' . http_build_query(['bvid' => $bvid, 'page' => $page, 'high_quality' => 1, 'danmaku' => 0, 'autoplay' => ($opts['autoplay'] ?? false) ? 1 : 0]), 'bvid' => $bvid, 'page' => $page];
    }

    public static function parse($url, $page = 1, $quality = '720p', $opts = [])
    {
        $info = self::parseUrl($url);
        if (!$info['bvid']) return ['success' => false, 'error' => '无法解析视频链接', 'fallback' => null];

        $video = self::getVideoInfo($info['bvid']);
        $pageNum = $page > 0 ? $page : ($info['page'] > 0 ? $info['page'] : 1);
        if (!$video) return ['success' => false, 'error' => '获取视频信息失败', 'fallback' => self::generateIframe($info['bvid'], $pageNum, $opts)];

        $cid = $video['cid'];
        if ($pageNum > 1 && isset($video['pages'][$pageNum - 1])) $cid = $video['pages'][$pageNum - 1]['cid'];

        $play = self::getPlayUrl($info['bvid'], $cid, self::parseQuality($quality));
        if (!$play || !$play['video_url']) return ['success' => false, 'error' => '获取播放地址失败', 'fallback' => self::generateIframe($info['bvid'], $pageNum, $opts), 'videoInfo' => ['bvid' => $video['bvid'], 'title' => $video['title'], 'pic' => $video['pic']]];

        return ['success' => true, 'bvid' => $video['bvid'], 'avid' => $video['avid'], 'title' => $video['title'], 'pic' => $video['pic'], 'duration' => $video['duration'], 'owner' => $video['owner'], 'page' => $pageNum, 'cid' => $cid, 'quality' => $play['quality'], 'quality_desc' => $play['quality_desc'], 'type' => $play['type'], 'video_url' => $play['video_url'], 'audio_url' => $play['audio_url'], 'accept_quality' => $play['accept_quality'], 'fallback' => self::generateIframe($info['bvid'], $pageNum, $opts)];
    }

    public static function cleanCache($maxAge = 86400)
    {
        $files = glob(self::getCacheDir() . '/*.json') ?: [];
        $now = time();
        $cleaned = 0;
        foreach ($files as $f) {
            $d = @json_decode(file_get_contents($f), true);
            $ttl = self::$cacheTTL[$d['type'] ?? 'video_info'] ?? $maxAge;
            if (($d && isset($d['time']) && $now - $d['time'] > $ttl) || (!$d && $now - filemtime($f) > $maxAge)) { @unlink($f); $cleaned++; }
        }
        return $cleaned;
    }

    public static function getCacheStats()
    {
        $files = glob(self::getCacheDir() . '/*.json') ?: [];
        $size = 0;
        foreach ($files as $f) $size += filesize($f);
        $units = ['B', 'KB', 'MB'];
        $i = 0;
        while ($size >= 1024 && $i < 2) { $size /= 1024; $i++; }
        return ['total' => count($files), 'size' => $size, 'size_human' => round($size, 2) . ' ' . $units[$i]];
    }
}
