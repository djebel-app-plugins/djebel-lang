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
Dj_App_Hooks::addFilter('app.core.request.web_path', [ $obj, 'appendLangToWebPath' ]);
Dj_App_Hooks::addFilter('app.core.request.segments', [ $obj, 'shiftSegmentsAfterLang' ]);

Dj_App_Hooks::addFilter('app.plugin.static_content.site_content_dir', [ $obj, 'maybePrependLangDir' ]);

/**
 * Multi-lingual plugin for Djebel framework
 * Handles language detection, URL routing, and content path resolution
 *
 * URL Structure: /lang/page/
 * Content Structure: site_content/lang/page.html
 *
 * Examples:
 * - /en/ -> site_content/en/home.html
 * - /en/blog/ -> site_content/en/blog.html
 * - /bg/about/ -> site_content/bg/about.html
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
        $full_page = Dj_App_String_Util::trim($full_page, '/');
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
     * Add language directory to path for multi-lingual file routing
     * Works for both theme pages_dir and site_content_dir
     * Examples: pages/ + en -> pages/en/, site_content/ + en -> site_content/en/
     * @param string $dir Base directory path
     * @return string Directory path with language appended
     */
    public function maybePrependLangDir($dir)
    {
        $current_lang = $this->getCurrentLang();
        $dir .= '/' . $current_lang;

        return $dir;
    }

    /**
     * Append current language to web path
     * Skip for asset/content URLs (context = content_url)
     * @param string $web_path Base web path
     * @param array $ctx Context with optional 'context' key
     * @return string Web path with language appended
     */
    public function appendLangToWebPath($web_path, $ctx = [])
    {
        // Skip for asset/site/theme URLs and internal redirect building
        $context = empty($ctx['context']) ? '' : $ctx['context'];
        $skip_contexts = [ 'content_url', 'site_url', 'theme_url', 'lang_redirect', ];

        if (in_array($context, $skip_contexts)) {
            return $web_path;
        }

        $current_lang = $this->getCurrentLang();
        $lang_segment = '/' . $current_lang . '/';

        // Prevent multiple appends - check if lang already in path
        if (strpos($web_path, $lang_segment) !== false) {
            return $web_path;
        }

        // Also check if ends with lang (no trailing slash)
        $lang_suffix = '/' . $current_lang;

        if (substr($web_path, -strlen($lang_suffix)) === $lang_suffix) {
            return $web_path;
        }

        $web_path = Dj_App_Util::removeSlash($web_path);
        $web_path = $web_path . $lang_suffix;

        return $web_path;
    }

    /**
     * Shift segments to remove lang prefix
     * e.g., [en, blog, post] → [blog, post] so segment1 = blog
     * @param array $segments
     * @return array
     */
    public function shiftSegmentsAfterLang($segments)
    {
        if (empty($segments)) {
            return $segments;
        }

        $first_segment = reset($segments);
        $available_langs = $this->getLangs();

        if (!in_array($first_segment, $available_langs)) {
            return $segments;
        }

        array_shift($segments);
        $shifted_segments = array_values($segments);

        return $shifted_segments;
    }

    /**
     * Redirect to default language if URL doesn't have a language prefix
     * Single responsibility: only handles redirect logic
     * Checks URL path directly (not shifted segments)
     * @param array $ctx
     * @return void
     */
    public function maybeRedirect($ctx = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $relative_path = $req_obj->getRelWebPath();
        $relative_path = Dj_App_String_Util::trim($relative_path, '/');
        $langs = $this->getLangs();

        // Check URL path directly for lang prefix (segments may be shifted)
        foreach ($langs as $lang) {
            // URL is exactly lang (e.g., /en)
            if ($relative_path === $lang) {
                return;
            }

            // URL starts with lang/ (e.g., /en/blog)
            $lang_prefix = $lang . '/';
            $has_lang_prefix = strpos($relative_path, $lang_prefix) === 0;

            if ($has_lang_prefix) {
                return;
            }
        }

        // No lang prefix - redirect to add default language
        $default_lang = $this->getDefaultLang();

        // Get base web_path without lang filter (context tells filter to skip)
        $web_path_ctx = [ 'context' => 'lang_redirect', ];
        $web_path = $req_obj->getWebPath($web_path_ctx);

        // Build redirect: web_path + default_lang + relative_path
        $web_path = Dj_App_Util::removeSlash($web_path);
        $redirect_url = $web_path . '/' . $default_lang;

        if (!empty($relative_path)) {
            $redirect_url .= '/' . $relative_path;
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