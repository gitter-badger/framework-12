<?php

namespace Perfumer\Proxy;

use Perfumer\Container\Core as Container;
use Perfumer\Proxy\Exception\ForwardException;
use Perfumer\Proxy\Exception\ProxyException;

class Core
{
    protected $container;
    protected $request_pool = [];
    protected $request_main;
    protected $request_initial;
    protected $request_body;

    protected $request_url;
    protected $request_action;
    protected $request_args = [];

    protected $http_prefixes = [];
    protected $http_id;
    protected $http_query = [];
    protected $http_args = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->request_action = strtolower($_SERVER['REQUEST_METHOD']);

        if ($_SERVER['PATH_INFO'] == '/')
        {
            $this->request_url = $this->container->p('proxy.default_url');
        }
        else
        {
            $url = trim($_SERVER['PATH_INFO'], '/');
            $hyphen_pos = strpos($url, '-');

            if ($hyphen_pos !== false)
            {
                $this->http_id = substr($url, $hyphen_pos + 1);
                $url = substr($url, 0, $hyphen_pos);
            }

            if ($prefixes = $this->container->p('proxy.prefixes'))
            {
                $url = explode('/', $url);

                $prefix_values = array_slice($url, 0, count($prefixes));

                foreach ($prefixes as $key => $prefix)
                {
                    $this->http_prefixes[$prefix] = isset($prefix_values[$key]) ? $prefix_values[$key] : null;
                }

                if (count($prefixes) >= count($url))
                {
                    $url = $this->container->p('proxy.default_url');
                }
                else
                {
                    $url = array_slice($url, count($prefixes));
                    $url = implode('/', $url);
                }
            }

            $this->request_url = $url;
        }

        $this->request_body = file_get_contents("php://input");

        $data_type = $this->container->p('proxy.data_type');

        // Get query parameters and args depending from type of data in the http request body
        if ($data_type == 'query_string')
        {
            switch ($this->request_action)
            {
                case 'get':
                    $this->http_query = $_GET;
                    break;
                case 'post':
                    $this->http_query = $_GET;
                    $this->http_args = $_POST;
                    break;
                default:
                    $this->http_query = $_GET;
                    parse_str($this->getRequestBody(), $this->http_args);
                    break;
            }
        }
        else if ($data_type == 'json')
        {
            $this->http_query = $_GET;
            $this->http_args = json_decode($this->getRequestBody(), true);
        }

        // Trim all args if auto_trim setting enabled
        if ($this->container->p('proxy.auto_trim'))
        {
            $this->http_args = $this->container->s('arr')->trim($this->http_args);
        }

        // Convert empty strings to null values if auto_null setting enabled
        if ($this->container->p('proxy.auto_null'))
        {
            $this->http_args = $this->container->s('arr')->convertValues($this->http_args, '', null);
        }
    }

    public function start()
    {
        try
        {
            $response = $this->execute($this->request_url, $this->request_action, $this->request_args);
        }
        catch (ForwardException $e)
        {
            return $this->start();
        }

        return $response;
    }

    public function execute($url, $action, array $args = [])
    {
        $request = $this->container->s('request');

        $this->request_pool[] = $request;

        if ($this->request_initial === null)
            $this->request_initial = $request;

        return $request->execute($url, $action, $args);
    }

    public function forward($url, $action, array $args = [])
    {
        $this->request_initial = null;

        $this->request_url = $url;
        $this->request_action = $action;
        $this->request_args = $args;

        throw new ForwardException();
    }

    public function getRequestPool()
    {
        return $this->request_pool;
    }

    public function getRequestMain()
    {
        return $this->request_pool[0];
    }

    public function getRequestInitial()
    {
        return $this->request_initial;
    }

    public function getRequestBody()
    {
        return $this->request_body;
    }

    public function p($name = null, $default = null)
    {
        return $this->getPrefix($name, $default);
    }

    public function getPrefix($name = null, $default = null)
    {
        if ($name === null)
            return $this->http_prefixes;

        return isset($this->http_prefixes[$name]) ? $this->http_prefixes[$name] : $default;
    }

    public function setPrefix($name, $value)
    {
        if (!in_array($name, $this->container->p('proxy.prefixes')))
            throw new ProxyException('Prefix "' . $name . '" is not registered in configuration');

        $this->http_prefixes[$name] = $value;
    }

    public function i()
    {
        return $this->http_id;
    }

    public function getId()
    {
        return $this->http_id;
    }

    public function setId($id)
    {
        $this->http_id = $id;
    }

    public function a($name = null, $default = null)
    {
        return $this->getArg($name, $default);
    }

    public function getArg($name = null, $default = null)
    {
        if ($name === null)
            return $this->http_args;

        return isset($this->http_args[$name]) ? $this->http_args[$name] : $default;
    }

    public function setArg($name, $value)
    {
        $this->http_args[$name] = $value;
    }

    public function q($name = null, $default = null)
    {
        return $this->getQuery($name, $default);
    }

    public function getQuery($name = null, $default = null)
    {
        if ($name === null)
            return $this->http_query;

        return isset($this->http_query[$name]) ? $this->http_query[$name] : $default;
    }

    public function setQuery($name, $value)
    {
        $this->http_query[$name] = $value;
    }

    public function generateUrl($url, $id = null, $query = [], $prefixes = [])
    {
        $generated_url = '/' . trim($url, '/');

        if ($this->container->p('proxy.prefixes'))
        {
            if ($prefixes)
            {
                $prefixes = $this->container->s('arr')->fetch($prefixes, $this->container->p('proxy.prefixes'));
                $prefixes = array_merge($this->getPrefix(), $prefixes);
            }
            else
            {
                $prefixes = $this->getPrefix();
            }

            $generated_url = '/' . implode('/', $prefixes) . $generated_url;
        }

        if ($id)
            $generated_url .= '-' . $id;

        if ($query)
        {
            $query_parts = [];

            foreach ($query as $key => $value)
                $query_parts[] = $key . '=' . urlencode($value);

            $generated_url .= '?' . implode('&', $query_parts);
        }

        return $generated_url;
    }
}