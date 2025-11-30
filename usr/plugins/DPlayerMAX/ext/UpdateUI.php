<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class DPlayerMAX_UpdateUI
{
    public static function render()
    {
        require_once dirname(__DIR__) . '/Plugin.php';
        $ver = DPlayerMAX_Plugin::getVersion();
        $css = Helper::options()->pluginUrl . '/DPlayerMAX/assets/update-widget.css';

        return '<link rel="stylesheet" href="' . $css . '" /><div class="dplayermax-update-widget"><div class="update-header"><h3>更新状态<span class="dplayermax-status-light status-not-checked"></span></h3></div><div class="version-info"><p><strong>当前:</strong> ' . htmlspecialchars($ver) . '</p></div><div class="update-status"><p class="status-message">点击检查更新</p></div><div class="update-actions"><button type="button" id="dplayermax-check-update-btn" class="btn">检查更新</button><button type="button" id="dplayermax-perform-update-btn" class="btn primary" style="display:none">立即更新</button><button type="button" id="dplayermax-force-update-btn" class="btn danger" style="display:none;margin-left:8px">强制更新</button><a id="dplayermax-release-link" href="https://github.com/GamblerIX/DPlayerMAX/tree/main/Changelog" target="_blank" class="btn" style="display:none">更新日志</a><span id="dplayermax-update-status" style="margin-left:10px"></span></div></div>' . self::script();
    }

    private static function script()
    {
        return <<<'JS'
<script>
(function(){
var cb=document.getElementById('dplayermax-check-update-btn'),pb=document.getElementById('dplayermax-perform-update-btn'),fb=document.getElementById('dplayermax-force-update-btn'),rl=document.getElementById('dplayermax-release-link'),st=document.getElementById('dplayermax-update-status'),sl=document.querySelector('.dplayermax-status-light'),lt=0;
function light(s){if(sl)sl.className='dplayermax-status-light status-'+s}
function req(a,fn){var fd=new FormData();fd.append('dplayermax_action',a);fetch(location.href,{method:'POST',body:fd}).then(function(r){if(!r.ok)throw Error('请求失败');return r.json()}).then(fn).catch(function(e){st.textContent='✗ '+e.message;cb.disabled=false;cb.textContent='检查更新';pb.disabled=false;pb.textContent='立即更新';if(fb){fb.disabled=false;fb.textContent='强制更新'}})}
if(cb)cb.onclick=function(){var n=Date.now();if(n-lt<2000)return;lt=n;cb.disabled=true;cb.textContent='检查中...';st.innerHTML='<span class="loading-spinner"></span>';req('check',function(d){cb.disabled=false;cb.textContent='检查更新';if(d.success===false){st.innerHTML='<span style="color:red">✗ '+d.message+'</span>';light('error');if(fb)fb.style.display='inline-block'}else if(d.hasUpdate){st.innerHTML='<span style="color:orange">⚠ '+d.message+'</span>';light('update-available');pb.style.display='inline-block';if(fb)fb.style.display='none';rl.style.display='inline-block'}else{st.innerHTML='<span style="color:green">✓ '+d.message+'</span>';light('up-to-date');pb.style.display='none';if(fb)fb.style.display='inline-block';rl.style.display='none'}})};
if(pb)pb.onclick=function(){if(!confirm('确定更新？建议先备份。'))return;pb.disabled=true;pb.textContent='更新中...';st.innerHTML='<span class="loading-spinner"></span> 更新中...';req('perform',function(d){if(d.success){st.textContent='✓ '+d.message;setTimeout(function(){location.reload()},2000)}else{st.textContent='✗ '+d.message;pb.disabled=false;pb.textContent='立即更新'}})};
if(fb)fb.onclick=function(){if(!confirm('强制更新将覆盖本地版本，确定继续？'))return;fb.disabled=true;fb.textContent='更新中...';st.innerHTML='<span class="loading-spinner"></span> 强制更新...';req('force',function(d){if(d.success){st.textContent='✓ '+d.message;setTimeout(function(){location.reload()},2000)}else{st.textContent='✗ '+d.message;fb.disabled=false;fb.textContent='强制更新'}})};
})();
</script>
JS;
    }
}
