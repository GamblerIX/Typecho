<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_UpdateManager
{
    const REPO = 'GamblerIX/DPlayerMAX';
    const BRANCH = 'main';
    const TIMEOUT = 15;
    const MIRRORS = ['https://raw.githubusercontent.com/', 'https://ghproxy.net/https://raw.githubusercontent.com/', 'https://mirror.ghproxy.com/https://raw.githubusercontent.com/'];
    const RELEASE_MIRRORS = ['https://github.com/', 'https://ghproxy.net/https://github.com/', 'https://mirror.ghproxy.com/https://github.com/'];

    public static function getLocalVersion()
    {
        require_once dirname(__DIR__) . '/Plugin.php';
        return defined('DPlayerMAX_Plugin::VERSION') ? DPlayerMAX_Plugin::VERSION : DPlayerMAX_Plugin::getVersion();
    }

    public static function checkUpdate()
    {
        $local = self::getLocalVersion();
        if (strpos($local, 'ERROR:') === 0) return ['success' => false, 'localVersion' => $local, 'remoteVersion' => null, 'hasUpdate' => false, 'message' => substr($local, 7)];

        $remote = self::fetchRemote();
        if (!$remote['success']) return ['success' => false, 'localVersion' => $local, 'remoteVersion' => null, 'hasUpdate' => false, 'message' => self::errMsg($remote['error'])];

        $cmp = version_compare($remote['version'], $local);
        if ($cmp > 0) return ['success' => true, 'localVersion' => $local, 'remoteVersion' => $remote['version'], 'hasUpdate' => true, 'message' => "发现新版本 {$remote['version']}"];
        if ($cmp === 0) return ['success' => true, 'localVersion' => $local, 'remoteVersion' => $remote['version'], 'hasUpdate' => false, 'message' => "已是最新版本 {$local}"];
        return ['success' => true, 'localVersion' => $local, 'remoteVersion' => $remote['version'], 'hasUpdate' => false, 'message' => "当前版本 {$local} 高于远程 {$remote['version']}"];
    }

    public static function performUpdate($force = false)
    {
        $dir = dirname(__DIR__);
        $tmp = $dir . '/temp_update';
        try {
            if (!$force) { $chk = self::checkUpdate(); if ($chk['success'] && !$chk['hasUpdate']) return ['success' => false, 'message' => '已是最新版本']; }
            $zip = self::download($tmp);
            if (!$zip) { self::cleanup($tmp); return ['success' => false, 'message' => self::errMsg('DOWNLOAD')]; }
            $ext = self::extract($zip, $tmp);
            if (!$ext) { self::cleanup($tmp); return ['success' => false, 'message' => self::errMsg('EXTRACT')]; }
            if (!self::install($ext, $dir)) { self::cleanup($tmp); return ['success' => false, 'message' => self::errMsg('INSTALL')]; }
            self::cleanup($tmp);
            return ['success' => true, 'message' => $force ? '强制更新成功！请刷新' : '更新成功！请刷新'];
        } catch (Exception $e) { self::cleanup($tmp); return ['success' => false, 'message' => self::errMsg('UNKNOWN')]; }
    }

    private static function fetchRemote()
    {
        $path = self::REPO . '/' . self::BRANCH . '/Plugin.php';
        foreach (self::MIRRORS as $m) {
            $c = self::curl($m . $path);
            if ($c && preg_match('/const\s+VERSION\s*=\s*[\'"]([0-9.]+)[\'"]/', $c, $match)) {
                return ['success' => true, 'version' => $match[1], 'error' => null];
            }
        }
        return ['success' => false, 'version' => null, 'error' => 'NETWORK'];
    }

    private static function curl($url, $opts = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $opts['timeout'] ?? self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_USERAGENT => 'DPlayerMAX/2.0', CURLOPT_ENCODING => 'gzip', CURLOPT_HTTPHEADER => $opts['headers'] ?? ['Accept: */*', 'Cache-Control: no-cache']]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($res !== false && $code === 200) ? $res : false;
    }

    private static function download($tmp)
    {
        $path = self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
        $file = $tmp . '/update.zip';
        if (!file_exists($tmp) && !@mkdir($tmp, 0755, true)) return false;
        foreach (self::RELEASE_MIRRORS as $m) {
            $c = self::curl($m . $path, ['timeout' => 60, 'headers' => ['Accept: application/octet-stream']]);
            if ($c && strlen($c) > 1024 && substr($c, 0, 2) === 'PK' && @file_put_contents($file, $c)) return $file;
        }
        return false;
    }

    private static function extract($zip, $dir)
    {
        if (!class_exists('ZipArchive')) return false;
        $z = new ZipArchive();
        if ($z->open($zip) !== true || !$z->extractTo($dir)) { $z->close(); return false; }
        $z->close();
        $ext = $dir . '/DPlayerMAX-' . self::BRANCH;
        return file_exists($ext) ? $ext : false;
    }

    private static function install($src, $dst)
    {
        $skip = ['ext/Updated.php', '.git', '.github', '.gitignore', '.gitattributes'];
        $files = @scandir($src);
        if (!$files) return false;
        foreach (array_diff($files, ['.', '..']) as $f) {
            foreach ($skip as $s) if ($f === $s || strpos($f, $s . '/') === 0) continue 2;
            $s = $src . '/' . $f; $d = $dst . '/' . $f;
            if (is_dir($s)) { if (!self::rcopy($s, $d)) return false; }
            else { if (!@copy($s, $d)) return false; }
        }
        return true;
    }

    private static function rcopy($src, $dst)
    {
        if (!file_exists($src)) return false;
        if (is_file($src)) return @copy($src, $dst);
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) return false;
        $dir = @opendir($src);
        if (!$dir) return false;
        while (($f = readdir($dir)) !== false) { if ($f !== '.' && $f !== '..' && !self::rcopy($src . '/' . $f, $dst . '/' . $f)) { closedir($dir); return false; } }
        closedir($dir);
        return true;
    }

    private static function cleanup($dir)
    {
        if (!file_exists($dir)) return true;
        if (is_file($dir)) return @unlink($dir);
        $files = @scandir($dir);
        if (!$files) return false;
        foreach (array_diff($files, ['.', '..']) as $f) { $p = $dir . '/' . $f; is_dir($p) ? self::cleanup($p) : @unlink($p); }
        return @rmdir($dir);
    }

    private static function errMsg($t)
    {
        $m = ['NETWORK' => '无法连接GitHub', 'DOWNLOAD' => '下载失败', 'EXTRACT' => '解压失败', 'INSTALL' => '安装失败', 'UNKNOWN' => '发生错误'];
        return $m[$t] ?? '检查更新失败';
    }
}
