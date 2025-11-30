<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (!class_exists('Typecho_Request') && class_exists('Typecho\Request')) {
    class_alias('Typecho\Request', 'Typecho_Request');
}

if (!class_exists('Typecho_Db') && class_exists('Typecho\Db')) {
    class_alias('Typecho\Db', 'Typecho_Db');
}

if (!class_exists('Typecho_Widget') && class_exists('Typecho\Widget')) {
    class_alias('Typecho\Widget', 'Typecho_Widget');
}

if (!class_exists('Widget_Options') && class_exists('Widget\Options')) {
    class_alias('Widget\Options', 'Widget_Options');
}

if (!class_exists('Typecho_Plugin') && class_exists('Typecho\Plugin')) {
    class_alias('Typecho\Plugin', 'Typecho_Plugin');
}

if (!class_exists('Typecho_Common') && class_exists('Typecho\Common')) {
    class_alias('Typecho\Common', 'Typecho_Common');
}

if (!class_exists('Helper') && class_exists('Utils\Helper')) {
    class_alias('Utils\Helper', 'Helper');
}

if (!class_exists('Typecho\Request') && class_exists('Typecho_Request')) {
    class_alias('Typecho_Request', 'Typecho\Request');
}

if (!class_exists('Typecho\Db') && class_exists('Typecho_Db')) {
    class_alias('Typecho_Db', 'Typecho\Db');
}

if (!class_exists('Widget\Options') && class_exists('Widget_Options')) {
    class_alias('Widget_Options', 'Widget\Options');
}
