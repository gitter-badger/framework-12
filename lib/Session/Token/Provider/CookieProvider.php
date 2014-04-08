<?php

namespace Perfumer\Session\Token\Provider;

class CookieProvider extends AbstractProvider
{
    protected $session_name;

    public function __construct($session_name)
    {
        $this->session_name = $session_name;
    }

    public function getToken()
    {
        return isset($_COOKIE[$this->session_name]) ? $_COOKIE[$this->session_name] : null;
    }
}