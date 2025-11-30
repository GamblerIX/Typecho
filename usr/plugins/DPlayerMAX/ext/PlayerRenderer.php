<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_PlayerRenderer
{
    private static $playerCount = 0;

    public static function renderHeader()
    {
        $url = \Utils\Helper::options()->pluginUrl . '/DPlayerMAX';
        echo '<link rel="stylesheet" href="' . $url . '/assets/DPlayer.css" />' . "\n";
        echo '<style>
.dplayer-lazy{background:#000;position:relative;aspect-ratio:16/9}
.dplayer-lazy::before{content:"";position:absolute;top:50%;left:50%;width:40px;height:40px;margin:-20px;border:3px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:dplayer-spin 1s linear infinite}
@keyframes dplayer-spin{to{transform:rotate(360deg)}}
.dplayer-poster{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;cursor:pointer;z-index:100}
.dplayer-play-btn{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:60px;height:60px;background:rgba(0,0,0,.6);border-radius:50%;cursor:pointer;transition:transform .2s;z-index:101}
.dplayer-play-btn:hover{transform:translate(-50%,-50%) scale(1.1)}
.dplayer-play-btn::after{content:"";position:absolute;top:50%;left:55%;transform:translate(-50%,-50%);border:solid transparent;border-width:12px 0 12px 20px;border-left-color:#fff}
.bilibili-player-container{position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;background:#000}
.bilibili-iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:none}
.dplayer .dplayer-icons-right .dplayer-quality.dplayer-icon{width:auto;height:38px;padding:0;margin:0;display:inline-flex;align-items:center;vertical-align:top;box-sizing:border-box}
.dplayer .dplayer-quality-btn{padding:0 8px;height:38px;line-height:38px;font-size:12px;color:#fff;background:transparent;border:none;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center}
.dplayer .dplayer-quality-btn:hover{color:#00a1d6}
.dplayer .dplayer-quality-btn.loading{opacity:.6;pointer-events:none}
.dplayer .dplayer-quality-list{position:absolute;bottom:42px;left:50%;transform:translateX(-50%);padding:5px 0;background:rgba(21,21,21,.9);border-radius:4px;display:none;min-width:80px;z-index:10000;box-shadow:0 2px 8px rgba(0,0,0,.5)}
.dplayer .dplayer-quality-list.show{display:block}
.dplayer .dplayer-quality-item{padding:8px 15px;font-size:12px;color:#fff;cursor:pointer;white-space:nowrap;text-align:center;transition:background .2s;position:relative;top:-4px}
.dplayer .dplayer-quality-item:hover{background:rgba(255,255,255,.15)}
.dplayer .dplayer-quality-item.active{color:#00a1d6}
</style>' . "\n";
    }

    public static function renderFooter()
    {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        $url = \Utils\Helper::options()->pluginUrl . '/DPlayerMAX';
        $cfg = \Utils\Helper::options()->plugin('DPlayerMAX');

        if (!empty($cfg->hls)) echo '<script src="' . $url . '/assets/hls.js"></script>' . "\n";
        if (!empty($cfg->flv)) echo '<script src="' . $url . '/assets/flv.js"></script>' . "\n";

        echo '<script src="' . $url . '/assets/DPlayer.js"></script>' . "\n";
        echo '<script>window.DPLAYERMAX_API="' . rtrim(\Utils\Helper::options()->index, '/') . '/?dplayermax_api=";</script>' . "\n";
        echo self::renderInitScript();
    }

    private static function renderInitScript()
    {
        return <<<'JS'
<script>
(function(){
var players={},observer=null,playerId=0,initLock={},
qualityOpts=[{id:64,name:'720P',key:'720p'},{id:16,name:'360P',key:'360p'}];

function initPlayer(el){
    if(el.dataset.initialized)return;
    var lockId=el.dataset.playerId||('l_'+Date.now()+'_'+Math.random());
    if(initLock[lockId])return;
    initLock[lockId]=true;
    el.dataset.initialized='1';
    var id='dp_'+(++playerId);
    el.dataset.playerId=id;
    el.classList.remove('dplayer-lazy');
    var btn=el.querySelector('.dplayer-play-btn');
    if(btn)btn.remove();
    try{
        var cfg=JSON.parse(el.dataset.config);
        cfg.container=el;cfg.mutex=true;cfg.autoplay=false;
        var autoplay=cfg._autoplay||false;delete cfg._autoplay;
        if(cfg.bilibili&&cfg.bilibili.bvid){
            fetchBilibiliUrl(cfg.bilibili.bvid,cfg.bilibili.page||1,cfg.bilibili.quality||64,function(url){
                if(url)cfg.video.url=url;
                createPlayer(el,id,cfg,autoplay);
            });
        }else{
            createPlayer(el,id,cfg,autoplay);
        }
    }catch(e){console.error('DPlayer error:',e);if(el.dataset.fallback)degradeToIframe(el)}
    finally{delete initLock[lockId]}
}

function fetchBilibiliUrl(bvid,page,quality,cb){
    var qk=quality===16?'360p':'720p';
    fetch(window.DPLAYERMAX_API+'parse&url='+encodeURIComponent(bvid)+'&page='+page+'&quality='+qk)
    .then(function(r){return r.json()})
    .then(function(d){cb(d.success&&d.video_url?d.video_url:null)})
    .catch(function(){cb(null)});
}

function createPlayer(el,id,cfg,autoplay){
    try{
        var posterSrc=el.querySelector('.dplayer-poster');
        posterSrc=posterSrc?posterSrc.src:null;
        var p=new DPlayer(cfg);
        if(posterSrc){var img=document.createElement('img');img.className='dplayer-poster';img.src=posterSrc;img.referrerPolicy='no-referrer';img.onclick=function(){this.remove();p.play()};el.appendChild(img)}
        var v=el.querySelector('video');
        if(v&&cfg.bilibili)v.removeAttribute('crossorigin');
        players[id]={instance:p,config:cfg,element:el,bilibiliInfo:cfg.bilibili||null,ready:false};
        p.on('error',function(){var ps=el.querySelector('.dplayer-poster');if(ps)ps.remove();if(el.dataset.fallback)degradeToIframe(el)});
        p.on('loadedmetadata',function(){var d=players[id];if(d&&!d.ready){d.ready=true;if(autoplay)p.play()}});
        p.on('play',function(){var ps=el.querySelector('.dplayer-poster');if(ps)ps.remove()});
        if(cfg.bilibili&&el.dataset.qualities)waitForBar(el,id,0);
    }catch(e){console.error('DPlayer error:',e);if(el.dataset.fallback)degradeToIframe(el)}
}

function waitForBar(el,pid,n){
    if(n>20)return;
    var r=el.querySelector('.dplayer-controller .dplayer-icons.dplayer-icons-right');
    if(r&&r.children.length>0)addQuality(el,pid);
    else setTimeout(function(){waitForBar(el,pid,n+1)},100);
}

function addQuality(el,pid){
    if(el.querySelector('.dplayer-quality'))return;
    var qs=[];try{qs=JSON.parse(el.dataset.qualities)}catch(e){return}
    var pd=players[pid];if(!pd||!pd.bilibiliInfo)return;
    var cr=el.querySelector('.dplayer-controller .dplayer-icons.dplayer-icons-right');if(!cr)return;
    var cq=pd.bilibiliInfo.quality,qc=document.createElement('div');
    qc.className='dplayer-quality dplayer-icon';
    var cn='清晰度';for(var i=0;i<qualityOpts.length;i++)if(qualityOpts[i].id===cq){cn=qualityOpts[i].name;break}
    var btn=document.createElement('button');btn.className='dplayer-quality-btn';btn.type='button';btn.textContent=cn;
    var list=document.createElement('div');list.className='dplayer-quality-list';
    btn.onclick=function(e){e.preventDefault();e.stopPropagation();var o=list.classList.contains('show');closeAll();if(!o)list.classList.add('show')};
    qualityOpts.forEach(function(opt){
        var item=document.createElement('div');item.className='dplayer-quality-item';item.textContent=opt.name;
        if(opt.id===cq)item.classList.add('active');
        if(qs.indexOf(opt.id)!==-1){
            item.onclick=function(e){e.preventDefault();e.stopPropagation();if(opt.id!==pd.bilibiliInfo.quality)switchQ(pid,opt.key,opt.id,btn,list);list.classList.remove('show')};
        }else{item.style.opacity='0.5';item.style.cursor='not-allowed'}
        list.appendChild(item);
    });
    qc.appendChild(btn);qc.appendChild(list);pd.qualityBtn=btn;pd.qualityList=list;
    var fi=cr.querySelector('.dplayer-full-icon'),t=null;
    if(fi){var p=fi;while(p&&p.parentNode!==cr)p=p.parentNode;if(p&&p.parentNode===cr)t=p}
    t?cr.insertBefore(qc,t):cr.appendChild(qc);
}

function closeAll(){document.querySelectorAll('.dplayer-quality-list.show').forEach(function(l){l.classList.remove('show')})}
document.addEventListener('click',function(e){if(!e.target.closest('.dplayer-quality'))closeAll()});

function switchQ(pid,qk,qid,btn,list){
    var pd=players[pid];if(!pd||!pd.bilibiliInfo)return;
    var ct=0,wp=false;try{ct=pd.instance.video.currentTime||0;wp=!pd.instance.video.paused}catch(e){}
    btn.classList.add('loading');var ot=btn.textContent;btn.textContent='切换中...';
    fetch(window.DPLAYERMAX_API+'parse&url='+encodeURIComponent(pd.bilibiliInfo.bvid)+'&page='+pd.bilibiliInfo.page+'&quality='+qk)
    .then(function(r){return r.json()})
    .then(function(d){
        if(d.success&&d.video_url){
            pd.instance.switchVideo({url:d.video_url,pic:pd.config.video.pic});
            pd.bilibiliInfo.quality=d.quality;
            var done=false;pd.instance.on('canplay',function f(){if(done)return;done=true;pd.instance.off('canplay',f);if(ct>0)pd.instance.seek(ct);if(wp)pd.instance.play()});
            for(var i=0;i<qualityOpts.length;i++)if(qualityOpts[i].id===d.quality){btn.textContent=qualityOpts[i].name;break}
            list.querySelectorAll('.dplayer-quality-item').forEach(function(it,idx){it.classList.remove('active');if(qualityOpts[idx]&&qualityOpts[idx].id===d.quality)it.classList.add('active')});
        }else btn.textContent=ot;
    }).catch(function(){btn.textContent=ot}).finally(function(){btn.classList.remove('loading')});
}

function degradeToIframe(el){
    var fb=el.dataset.fallback;if(!fb)return;
    try{var d=JSON.parse(fb);if(d.type==='iframe'&&d.src)el.innerHTML='<iframe class="bilibili-iframe" src="'+d.src+'" allowfullscreen="true" allow="autoplay; encrypted-media"></iframe>'}catch(e){}
}

function setupLazy(){
    var els=document.querySelectorAll('.dplayer[data-config]');if(!els.length)return;
    if(!('IntersectionObserver' in window)){els.forEach(function(el){if(!el.dataset.initialized)initPlayer(el)});return}
    observer=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){observer.unobserve(e.target);if(!e.target.dataset.initialized)initPlayer(e.target)}})},{rootMargin:'200px 0px',threshold:0});
    els.forEach(function(el){if(el.dataset.initialized)return;el.dataset.lazy==='false'?initPlayer(el):observer.observe(el)});
}

document.addEventListener('click',function(e){var t=e.target;if(t.classList.contains('dplayer-poster')||t.classList.contains('dplayer-play-btn')){var c=t.closest('.dplayer');if(c&&!c.dataset.initialized)initPlayer(c)}});

function init(){setupLazy();document.querySelectorAll('.bilibili-embed[data-src]').forEach(function(el){if(el.dataset.initialized)return;el.dataset.initialized='1';var f=document.createElement('iframe');f.className='bilibili-iframe';f.src=el.dataset.src;f.allowFullscreen=true;f.allow='autoplay; encrypted-media';el.appendChild(f)})}
function cleanup(){Object.keys(players).forEach(function(id){try{players[id].instance.destroy()}catch(e){}});players={};playerId=0;initLock={};if(observer){observer.disconnect();observer=null}}

document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
document.addEventListener('pjax:complete',function(){cleanup();init()});
window.DPlayerMAX={init:init,cleanup:cleanup,initPlayer:initPlayer,players:players};
})();
</script>
JS;
    }

    public static function parsePlayer($attrs)
    {
        $cfg = \Utils\Helper::options()->plugin('DPlayerMAX');
        $url = $attrs['url'] ?? '';
        $isBilibili = ($attrs['bilibili'] ?? '') === 'true';

        if (!$isBilibili && $url) {
            require_once __DIR__ . '/ShortcodeParser.php';
            $isBilibili = DPlayerMAX_ShortcodeParser::isBilibiliUrl($url);
        }

        if ($isBilibili && !empty($cfg->bilibili)) {
            return self::parseBilibiliPlayer($attrs, $cfg);
        }
        return self::parseNormalPlayer($attrs, $cfg);
    }

    private static function parseNormalPlayer($attrs, $cfg)
    {
        self::$playerCount++;
        $theme = $cfg->theme ?: '#FADFA3';

        $config = [
            'live' => ($attrs['live'] ?? '') === 'true',
            'autoplay' => false,
            '_autoplay' => ($attrs['autoplay'] ?? '') === 'true',
            'theme' => $attrs['theme'] ?? $theme,
            'loop' => ($attrs['loop'] ?? '') === 'true',
            'screenshot' => ($attrs['screenshot'] ?? '') === 'true',
            'hotkey' => true,
            'preload' => $attrs['preload'] ?? 'metadata',
            'lang' => $attrs['lang'] ?? 'zh-cn',
            'logo' => $attrs['logo'] ?? null,
            'volume' => (float)($attrs['volume'] ?? 0.7),
            'video' => [
                'url' => $attrs['url'] ?? null,
                'pic' => $attrs['pic'] ?? null,
                'type' => $attrs['type'] ?? 'auto',
                'thumbnails' => $attrs['thumbnails'] ?? null,
            ],
        ];

        if (($attrs['subtitle'] ?? '') === 'true') {
            $config['subtitle'] = [
                'url' => $attrs['subtitleurl'] ?? null,
                'type' => $attrs['subtitletype'] ?? 'webvtt',
                'fontSize' => $attrs['subtitlefontsize'] ?? '25px',
                'bottom' => $attrs['subtitlebottom'] ?? '10%',
                'color' => $attrs['subtitlecolor'] ?? '#b7daff',
            ];
        }

        return self::renderPlayerHtml($config, [
            'lazy' => ($attrs['lazy'] ?? 'true') !== 'false',
            'pic' => $config['video']['pic']
        ]);
    }

    private static function parseBilibiliPlayer($attrs, $cfg)
    {
        require_once __DIR__ . '/bilibili/BilibiliParser.php';
        self::$playerCount++;

        $url = $attrs['url'] ?? '';
        $page = (int)($attrs['page'] ?? 1);
        $mode = $attrs['mode'] ?? 'dplayer';

        if ($mode === 'iframe') return self::renderBilibiliIframe($url, $page, $attrs);

        $result = DPlayerMAX_Bilibili_Parser::parse($url, $page, $cfg->bilibili_quality ?? '720p', [
            'autoplay' => ($attrs['autoplay'] ?? '') === 'true'
        ]);

        if (!$result['success']) {
            if (!empty($cfg->bilibili_fallback) && isset($result['fallback'])) {
                return self::renderFallback($result);
            }
            return self::renderError($result['error'], $url);
        }

        $theme = $cfg->theme ?: '#FADFA3';
        $config = [
            'live' => false,
            'autoplay' => false,
            '_autoplay' => ($attrs['autoplay'] ?? '') === 'true',
            'theme' => $attrs['theme'] ?? $theme,
            'loop' => ($attrs['loop'] ?? '') === 'true',
            'screenshot' => ($attrs['screenshot'] ?? '') === 'true',
            'hotkey' => true,
            'preload' => 'metadata',
            'lang' => $attrs['lang'] ?? 'zh-cn',
            'volume' => (float)($attrs['volume'] ?? 0.7),
            'video' => ['url' => $result['video_url'], 'pic' => '', 'type' => 'auto'],
            'bilibili' => ['bvid' => $result['bvid'], 'title' => $result['title'], 'page' => $result['page'], 'quality' => $result['quality']]
        ];

        return self::renderPlayerHtml($config, [
            'lazy' => ($attrs['lazy'] ?? 'true') !== 'false',
            'pic' => $result['pic'],
            'title' => $result['title'],
            'fallback' => !empty($cfg->bilibili_fallback) ? ($result['fallback'] ?? null) : null,
            'accept_quality' => $result['accept_quality'] ?? []
        ]);
    }

    private static function renderBilibiliIframe($url, $page, $attrs)
    {
        require_once __DIR__ . '/bilibili/BilibiliParser.php';
        $bvid = DPlayerMAX_Bilibili_Parser::parseUrl($url)['bvid'];
        if (!$bvid) return self::renderError('无法解析链接', $url);

        $src = 'https://player.bilibili.com/player.html?' . http_build_query([
            'bvid' => $bvid, 'page' => $page > 0 ? $page : 1, 'high_quality' => 1,
            'danmaku' => 0,
            'autoplay' => ($attrs['autoplay'] ?? '') === 'true' ? 1 : 0
        ]);

        return '<div class="bilibili-player-container"><iframe class="bilibili-iframe" src="' . htmlspecialchars($src) . '" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; encrypted-media"></iframe></div>';
    }

    private static function renderPlayerHtml($config, $opts = [])
    {
        $json = htmlspecialchars(json_encode($config, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $lazy = $opts['lazy'] ?? true;
        $cls = 'dplayer' . ($lazy ? ' dplayer-lazy' : '');
        $attr = 'data-config="' . $json . '" data-lazy="' . ($lazy ? 'true' : 'false') . '"';

        if (!empty($opts['fallback'])) $attr .= ' data-fallback="' . htmlspecialchars(json_encode($opts['fallback']), ENT_QUOTES, 'UTF-8') . '"';
        if (!empty($opts['accept_quality'])) $attr .= ' data-qualities="' . htmlspecialchars(json_encode($opts['accept_quality']), ENT_QUOTES, 'UTF-8') . '"';
        if (!empty($opts['title'])) $attr .= ' title="' . htmlspecialchars($opts['title'], ENT_QUOTES, 'UTF-8') . '"';

        $html = '<div class="' . $cls . '" ' . $attr . '>';
        if ($lazy && !empty($opts['pic'])) {
            $pic = htmlspecialchars($opts['pic'], ENT_QUOTES, 'UTF-8');
            $html .= '<img class="dplayer-poster" src="' . $pic . '" alt="封面" loading="lazy" referrerpolicy="no-referrer" /><div class="dplayer-play-btn"></div>';
        }
        return $html . '</div>';
    }

    private static function renderFallback($result)
    {
        $fb = $result['fallback'];
        if ($fb['type'] !== 'iframe') return self::renderError($result['error'] ?? '加载失败', '');

        $title = $result['videoInfo']['title'] ?? '';
        $pic = $result['videoInfo']['pic'] ?? '';
        $src = htmlspecialchars($fb['src'], ENT_QUOTES, 'UTF-8');

        if ($pic) {
            return '<div class="dplayer-fallback"><div class="bilibili-embed-wrapper" style="position:relative;aspect-ratio:16/9;background:#000;cursor:pointer" onclick="this.innerHTML=\'<iframe class=bilibili-iframe src=' . $src . ' allowfullscreen></iframe>\'"><img src="' . htmlspecialchars($pic, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:100%;object-fit:cover" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" /><div class="dplayer-play-btn"></div></div></div>';
        }
        return '<div class="bilibili-player-container"><iframe class="bilibili-iframe" src="' . $src . '" allowfullscreen="true"></iframe></div>';
    }

    public static function renderError($msg, $url = '')
    {
        $html = '<div class="dplayer-error" style="padding:20px;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:8px;text-align:center">';
        $html .= '<svg style="width:48px;height:48px;margin-bottom:10px;fill:#999" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';
        $html .= '<p style="color:#666;margin:0 0 10px;font-size:14px">加载失败: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($url) $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" style="display:inline-block;padding:8px 16px;background:#00a1d6;color:#fff;text-decoration:none;border-radius:4px;font-size:14px">前往源站</a>';
        return $html . '</div>';
    }
}
