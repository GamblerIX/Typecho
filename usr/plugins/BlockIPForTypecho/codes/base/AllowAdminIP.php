<?php

namespace TypechoPlugin\BlockIPForTypecho;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AllowAdminIP
{
    const PLUGIN_NAME = 'BlockIPForTypecho';
    
    public static function checkAdminAccess(string $clientIP, object $config): void
    {
        try {
            $adminWhitelist = isset($config->adminWhitelist) ? $config->adminWhitelist : '';
            
            if (empty($adminWhitelist) || trim($adminWhitelist) === '') {
                return;
            }
            
            $lines = explode("\n", $adminWhitelist);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                
                if (strpos($line, '#') !== false) {
                    $line = trim(substr($line, 0, strpos($line, '#')));
                }
                
                if ($line === '0.0.0.0') {
                    return;
                }
            }
            
            if (IPAccessControl::matchIPRules($clientIP, $adminWhitelist, $config)) {
                return;
            }
            
            self::clearLoginCredentials();
            
            $redirectUrl = isset($config->adminRedirectUrl) && !empty($config->adminRedirectUrl)
                ? $config->adminRedirectUrl
                : \Helper::options()->siteUrl;
            
            self::redirectToUrl($redirectUrl);
            
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " AllowAdminIP Error: " . $e->getMessage());
        }
    }
    
    private static function clearLoginCredentials(): void
    {
        try {
            \Typecho\Cookie::delete('__typecho_uid');
            \Typecho\Cookie::delete('__typecho_authCode');
            
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_destroy();
            }
            
            error_log(self::PLUGIN_NAME . " Admin access denied: Login credentials cleared");
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " clearLoginCredentials Error: " . $e->getMessage());
        }
    }
    
    private static function redirectToUrl(string $url): void
    {
        try {
            error_log(self::PLUGIN_NAME . " Admin access denied: Redirecting to " . $url);
            header('Location: ' . $url);
            exit;
        } catch (\Exception $e) {
            error_log(self::PLUGIN_NAME . " redirectToUrl Error: " . $e->getMessage());
            exit;
        }
    }
}
