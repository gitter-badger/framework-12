<?php

namespace Perfumer;

class Assets
{
    protected $css_path;
    protected $js_path;

    protected $css = [];
    protected $js = [];

    public function __construct(array $params)
    {
        $this->css_path = '/' . trim($params['css_path'], '/');
        $this->js_path = '/' . trim($params['js_path'], '/');
    }

    public function getCss()
    {
        $array = [];

        foreach ($this->css as $css)
            $array[] = $this->css_path . '/' . $css;

        return $array;
    }

    public function getJs()
    {
        $array = [];

        foreach ($this->js as $js)
            $array[] = $this->js_path . '/' . $js;

        return $array;
    }

    public function addCss($css)
    {
        if (!in_array($css, $this->css))
            $this->css[] = $css;

        return $this;
    }

    public function addJs($js)
    {
        if (!in_array($js, $this->js))
            $this->js[] = $js;

        return $this;
    }
}