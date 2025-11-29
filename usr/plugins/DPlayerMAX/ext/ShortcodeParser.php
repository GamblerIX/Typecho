<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_ShortcodeParser
{
    private static $biliPatterns = [
        '/bilibili\.com\/video\/(BV[a-zA-Z0-9]+)/i',
        '/bilibili\.com\/video\/av(\d+)/i',
        '/b23\.tv\/([a-zA-Z0-9]+)/i'
    ];

    public static function parseAtts($text)
    {
        $atts = [];
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);

        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) $atts[strtolower($m[1])] = stripcslashes($m[2]);
                elseif (!empty($m[3])) $atts[strtolower($m[3])] = stripcslashes($m[4]);
                elseif (!empty($m[5])) $atts[strtolower($m[5])] = stripcslashes($m[6]);
                elseif (isset($m[7]) && strlen($m[7])) $atts[] = stripcslashes($m[7]);
                elseif (isset($m[8])) $atts[] = stripcslashes($m[8]);
            }
            foreach ($atts as &$v) {
                if (is_string($v) && strpos($v, '<') !== false && !preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $v)) {
                    $v = '';
                }
            }
        } else {
            $atts = ltrim($text);
        }
        return $atts;
    }

    public static function getRegex($tags)
    {
        $t = join('|', array_map('preg_quote', $tags));
        return '\\[(\\[?)(' . $t . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
    }

    public static function isBilibiliUrl($url)
    {
        if (empty($url)) return false;
        foreach (self::$biliPatterns as $p) if (preg_match($p, $url)) return true;
        return false;
    }

    public static function extractBilibiliId($url)
    {
        if (empty($url)) return null;
        if (preg_match('/BV([a-zA-Z0-9]{10})/i', $url, $m)) return ['type' => 'bvid', 'id' => 'BV' . $m[1]];
        if (preg_match('/av(\d+)/i', $url, $m)) return ['type' => 'avid', 'id' => (int)$m[1]];
        if (preg_match('/b23\.tv\/([a-zA-Z0-9]+)/i', $url, $m)) return ['type' => 'short', 'id' => $m[1]];
        return null;
    }

    public static function parseBilibiliParams($url)
    {
        $params = ['page' => 1, 'time' => 0];
        if (preg_match('/[?&]p=(\d+)/i', $url, $m)) $params['page'] = (int)$m[1];
        if (preg_match('/[?&]t=(\d+)/i', $url, $m)) $params['time'] = (int)$m[1];
        return $params;
    }
}
