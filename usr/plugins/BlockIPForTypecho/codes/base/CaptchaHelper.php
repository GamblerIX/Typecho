<?php
namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class CaptchaHelper
{
    public static function getCaptchaUICode(string $captchaUrl): string
    {
        $src = $captchaUrl . (strpos($captchaUrl, '?') !== false ? '&t=' : '?t=');
        
        return <<<HTML
<script>
(function () {
    let _src = '{$captchaUrl}';
    const src = _src + (_src.includes('?') ? '&t=' : '?t=');
    let pwd = document.getElementById('password');
    pwd?.parentNode?.insertAdjacentHTML('afterend', `<p id="captcha-section">
        <label class="sr-only" for="captcha">验证码</label>
        <input type="text" name="captcha" id="captcha" class="text-l w-100" 
               pattern=".{4}" title="请输入4个字符" placeholder="验证码" required />
        <img id="captcha-img" src="{$captchaUrl}" title="点击刷新" />
    </p>`);
    let img = document.getElementById('captcha-img');
    let timeOut;
    img?.addEventListener('click', function () {
        if (img.classList.contains('not-allow')) {
            return;
        }
        img.classList.add('not-allow');
        img.src = src + Math.random();
        timeOut = setTimeout(() => {
            img.classList.remove('not-allow');
        }, 1000);
    });
})()
</script>
<style>
#captcha-section {
    display: flex;
}
#captcha {
    box-sizing: border-box;
}
#captcha:invalid:not(:placeholder-shown) {
    border: 2px solid red;
}
#captcha:valid {
    border: 2px solid green;
}
#captcha-img {
    cursor: pointer;
}
#captcha-img.not-allow {
    cursor: not-allowed;
}
</style>
HTML;
    }
}
