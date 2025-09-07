<?php
/*
plugin_name: Djebel Lang
plugin_uri: https://djebel.com/plugins/djebel-seo
description: Provides multi-lingual featores to a site
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

$obj = Djebel_Lang::getInstance();

Dj_App_Hooks::addAction('app.core.init', [ $obj, 'maybeRedirect' ]);
Dj_App_Hooks::addFilter('app.core.request.page.get', [ $obj, 'resetPageOnLangRoot' ]);
Dj_App_Hooks::addFilter('app.core.request.page.get.full_page', [ $obj, 'resetPageOnLangRoot' ]);
Dj_App_Hooks::addFilter('app.core.request.web_path', [ $obj, 'updateWebPath' ]);

Dj_App_Hooks::addFilter('app.themes.current_theme.pages_dir', [ $obj, 'maybePrependLandDir' ]);

class Djebel_Lang
{
    private $langs = ['en',];
    private $default_lang = 'en';
    private $current_lang = '';

    public function __construct()
    {
    }

    /**
     * // e.g. /en or /en/ -> make it empty so default option can be used.
     * @param string $full_page
     * @return string
     */
    public function resetPageOnLangRoot($full_page) {
        $full_page = trim($full_page, '/');
        $langs = $this->getLangs();

        // exactly 'en'
        if (in_array($full_page, $langs)) {
            return '';
        }

        $langs_regex = join('|', $langs);

        if (preg_match('#/(' . $langs_regex . ')/?$#si', $full_page)) {
            return '';
        }

        return $full_page;
    }

    /**
     * We want to update the web path most of the time but not when we're generating the path to theme url and uploads.
     * @param string $web_path
     * @param array $ctx
     * @return string
     */
    public function updateWebPath($web_path, $ctx = [])
    {
        if (!empty($ctx['context'])) {
            return $web_path;
        }

        $langs_regex = join('|', $this->getLangs());

        if (!preg_match('#/(' . $langs_regex . ')$#si', $web_path)) {
            $web_path .= '/' . $this->getDefaultLang();
        }

        return $web_path;
    }

    /**
     * Theme files are in current_theme/pages/ if there's a lang prefix in segment1 we're good otherwise we prepend
     * @param string $web_path
     * @return string
     */
    public function maybePrependLandDir($pages_dir)
    {
        $req_obj = Dj_App_Request::getInstance();
        $segment1 = $req_obj->segment1;
        $langs = $this->getLangs();

        // if there's another page e.g. en/vision -> the dir prefix is there so don't add it.
        if (in_array($segment1, $langs) && empty($req_obj->segment2)) {
            $pages_dir .= '/' . $segment1;
        }

        return $pages_dir;
    }

    /**
     * @param $data
     * @param $ctx
     * @return void
     */
    public function maybeRedirect($ctx = [] )
    {
        $lang = $this->getCurrentLang();

        if (!empty($lang)) {
            return;
        }

        $req_obj = Dj_App_Request::getInstance();

        $segment1 = $req_obj->segment1;
        $segment1 = trim($segment1, '/');
        $langs_regex = join('|', $this->getLangs());

        // if it doesn't have a prefix add it. this is for loading the file
        // it's either en or en/page
        $do_redirect = false;

        if (empty($segment1)
            || !in_array($segment1, $this->getLangs())
            || !preg_match('#^/?(' . $langs_regex . ')(/|$)#si', $segment1, $matches)) {
            $do_redirect = true;
        }

        if ($do_redirect) {
            $web_path = $req_obj->getWebPath(); // one of the funcs above should add en
            $req_obj->redirect($web_path);
        }
    }

    public function getLangs()
    {
        $langs = Dj_App_Hooks::applyFilter('app.plugins.lang.current_lang', $this->langs);
        return $langs;
    }

    public function getCurrentLang()
    {
        $val = $this->current_lang;
        $current_lang = Dj_App_Hooks::applyFilter('app.plugins.lang.langs', $val);
        return $current_lang;
    }

    public function setCurrentLang(string $current_lang)
    {
        $this->current_lang = $current_lang;
    }

    public function getDefaultLang(): string
    {
        return $this->default_lang;
    }

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