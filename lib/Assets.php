<?php

namespace Perfumer;

use Perfumer\Cache\AbstractCache;

class Assets
{
    protected $cache;

    protected $vendor_path;
    protected $source_path;
    protected $web_path;
    protected $combine;

    protected $vendor_css = [];
    protected $vendor_js = [];
    protected $css = [];
    protected $js = [];

    public function __construct(AbstractCache $cache, array $params)
    {
        $this->cache = $cache;

        $this->vendor_path = '/' . trim($params['vendor_path'], '/') . '/';
        $this->source_path = '/' . trim($params['source_path'], '/') . '/';
        $this->web_path = '/' . trim($params['web_path'], '/') . '/';
        $this->combine = (bool) $params['combine'];
    }

    public function getCss()
    {
        $vendors = [];

        foreach ($this->vendor_css as $css)
            $vendors[] = '/vendor/' . $css . '.css';

        $stylesheets = [];

        foreach ($this->css as $css)
            $stylesheets[] = '/css/' . $css . '.css';

        if ($this->combine)
        {
            $stylesheets = serialize($this->css);

            $key = substr(md5($stylesheets), 0, 10);

            $this->cache->set('assets.css.' . $key, $stylesheets);

            $stylesheets = ['/css/' . $key . '.css'];
        }

        return array_merge($vendors, $stylesheets);
    }

    public function getJs()
    {
        $vendors = [];

        foreach ($this->vendor_js as $js)
            $vendors[] = '/vendor/' . $js . '.js';

        $javascripts = [];

        foreach ($this->js as $js)
            $javascripts[] = '/js/' . $js . '.js';

        if ($this->combine)
        {
            $javascripts = serialize($this->js);

            $key = substr(md5($javascripts), 0, 10);

            $this->cache->set('assets.js.' . $key, $javascripts);

            $javascripts = ['/js/' . $key . '.js'];
        }

        return array_merge($vendors, $javascripts);
    }

    public function addCss($css)
    {
        if (!in_array($css, $this->css))
            $this->css[] = $css;

        if (!$this->combine)
        {
            $file = 'css/' . $css . '.css';

            @unlink($this->web_path . $file);

            $this->copyFile($file, $this->source_path, $this->web_path);
        }

        return $this;
    }

    public function addJs($js)
    {
        if (!in_array($js, $this->js))
            $this->js[] = $js;

        if (!$this->combine)
        {
            $file = 'js/' . $js . '.js';

            @unlink($this->web_path . $file);

            $this->copyFile($file, $this->source_path, $this->web_path);
        }

        return $this;
    }

    public function addVendorCss($css)
    {
        if (!in_array($css, $this->vendor_css))
            $this->vendor_css[] = $css;

        $file = $css . '.css';

        @unlink($this->vendor_path . $file);

        $this->copyFile($file, $this->vendor_path, $this->web_path . 'vendor/');

        return $this;
    }

    public function addVendorJs($js)
    {
        if (!in_array($js, $this->vendor_js))
            $this->vendor_js[] = $js;

        $file = $js . '.js';

        @unlink($this->vendor_path . $file);

        $this->copyFile($file, $this->vendor_path, $this->web_path . 'vendor/');

        return $this;
    }

    protected function copyFile($file, $source_dir, $target_dir)
    {
        $reversed_file = strrev($file);
        $slash_pos = strpos($reversed_file, '/');

        if ($slash_pos !== false)
        {
            $reversed_dir = substr($reversed_file, $slash_pos);
            $dir = strrev($reversed_dir);

            @mkdir($target_dir . $dir, 0777, true);
        }

        copy($source_dir . $file, $target_dir . $file);
    }
}