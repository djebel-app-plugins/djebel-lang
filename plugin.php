<?php
/*
plugin_name: Djebel Lang
plugin_uri: https://djebel.com/plugins/djebel-seo
description: Provides multi-lingual features
version: 1.0.0
load_priority:20
tags: seo, meta, tags
stable_version: 1.0.0
min_php_ver: 5.6
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-lang
license: gpl2
*/

$obj = Djebel_Plugin_Lang::getInstance();

Dj_App_Hooks::addAction('app.core.init', [ $obj, 'maybeRedirect' ]);
Dj_App_Hooks::addFilter('app.core.request.page.get', [ $obj, 'resetPageOnLangRoot' ]);
Dj_App_Hooks::addFilter('app.core.request.page.get.full_page', [ $obj, 'resetPageOnLangRoot' ]);

Dj_App_Hooks::addFilter('app.themes.current_theme.pages_dir', [ $obj, 'maybePrependLandDir' ]);

/**
 * Multi-lingual plugin for Djebel framework
 * Handles language detection, URL routing, and page file resolution
 *
 * URL Structure: /lang/page/
 * File Structure: themes/current_theme/pages/lang/page.php
 *
 * Examples:
 * - /en/ -> pages/en/home.php
 * - /en/blog/ -> pages/en/blog.php
 * - /bg/about/ -> pages/bg/about.php
 * - / -> redirects to /en/ (default lang)
 * - /blog/ -> redirects to /en/blog/
 */
class Djebel_Plugin_Lang
{
    // Available languages, filterable via 'app.plugins.lang.available_langs'
    private $langs = ['en',];

    // Default language when no preference is set
    private $default_lang = 'en';

    // Current request language, auto-detected from URL segment1
    private $current_lang = '';

    public function __construct()
    {
    }

    /**
     * Get formatted segment1 from request (security: formats as safe slug)
     * @return string Formatted segment1 or empty string
     */
    public function getSegment1(): string
    {
        $req_obj = Dj_App_Request::getInstance();
        $segment1 = $req_obj->segment1;
        $segment1 = Dj_App_String_Util::formatStringId($segment1, Dj_App_String_Util::KEEP_DASH);

        return $segment1;
    }

    /**
     * Strip language prefix from page slug for routing
     * Examples: 'en' -> '', 'en/blog' -> 'blog', 'bg/about' -> 'about'
     * @param string $full_page
     * @return string
     */
    public function resetPageOnLangRoot($full_page) {
        $full_page = trim($full_page, '/');
        $langs = $this->getLangs();

        // Exactly 'en' or 'bg' -> return empty (front page)
        if (in_array($full_page, $langs)) {
            return '';
        }

        // Strip lang prefix: en/blog -> blog
        foreach ($langs as $lang) {
            $lang_prefix = $lang . '/';

            if (strpos($full_page, $lang_prefix) === 0) {
                $remaining = substr($full_page, strlen($lang_prefix));
                return $remaining;
            }
        }

        return $full_page;
    }

    /**
     * Add language directory to pages_dir for multi-lingual file routing
     * Examples: pages/ + en -> pages/en/, pages/ + bg -> pages/bg/
     * @param string $pages_dir
     * @return string
     */
    public function maybePrependLandDir($pages_dir)
    {
        $current_lang = $this->getCurrentLang();
        $pages_dir .= '/' . $current_lang;

        return $pages_dir;
    }

    /**
     * Redirect to default language if URL doesn't have a language prefix
     * Single responsibility: only handles redirect logic
     * @param array $ctx
     * @return void
     */
    public function maybeRedirect($ctx = [])
    {
        $segment1 = $this->getSegment1();
        $langs = $this->getLangs();

        // If URL already has a language prefix, no redirect needed
        if (in_array($segment1, $langs)) {
            return;
        }

        // No lang prefix - redirect to add default language
        $req_obj = Dj_App_Request::getInstance();
        $default_lang = $this->getDefaultLang();
        $web_path = $req_obj->getWebPath();
        $relative_path = $req_obj->getRelWebPath();

        // Build redirect: web_path + default_lang + relative_path
        $redirect_url = $web_path . '/' . $default_lang;

        if (!empty($relative_path) && $relative_path !== '/') {
            $redirect_url .= $relative_path;
        }

        $req_obj->redirect($redirect_url);
    }

    /**
     * Get available languages with filter hook support
     * @return array Available language codes (e.g., ['en', 'bg', 'fr'])
     */
    public function getLangs()
    {
        $langs = Dj_App_Hooks::applyFilter('app.plugins.lang.available_langs', $this->langs);
        return $langs;
    }

    /**
     * Get current request language
     * Auto-detects from segment1 if not explicitly set
     * Falls back to default language if detection fails
     * @return string Current language code (e.g., 'en', 'bg')
     */
    public function getCurrentLang(): string
    {
        $current_lang = $this->current_lang;

        // Auto-detect from segment1 if not set
        if (empty($current_lang)) {
            $segment1 = $this->getSegment1();
            $langs = $this->getLangs();

            if (in_array($segment1, $langs)) {
                $current_lang = $segment1;
            } else {
                $current_lang = $this->getDefaultLang();
            }
        }

        $current_lang = Dj_App_Hooks::applyFilter('app.plugins.lang.current_lang', $current_lang);

        return $current_lang;
    }

    /**
     * Set current language explicitly
     * @param string $current_lang Language code to set
     */
    public function setCurrentLang(string $current_lang)
    {
        $this->current_lang = $current_lang;
    }

    /**
     * Get default language with filter hook support
     * Falls back to first language in list if default is not set
     * @return string Default language code
     */
    public function getDefaultLang(): string
    {
        $default_lang = $this->default_lang;

        if (empty($default_lang)) {
            $langs = $this->getLangs();
            $first = reset($langs);
            $default_lang = $first;
        }

        $default_lang = Dj_App_Hooks::applyFilter('app.plugins.lang.default_lang', $default_lang);

        return $default_lang;
    }

    /**
     * Set default language
     * @param string $default_lang Language code to set as default
     */
    public function setDefaultLang(string $default_lang): void
    {
        $this->default_lang = $default_lang;
    }

    /**
     * Singleton pattern i.e. we have only one instance of this obj
     *
     * @staticvar static $instance
     * @return static
     */
    public static function getInstance() {
        static $instance = null;

        // This will make the calling class to be instantiated.
        // no need each sub class to define this method.
        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }
}