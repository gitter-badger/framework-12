<?php

namespace Perfumer\MVC\Proxy;

use \Perfumer\Component\Container\Core as Container;
use Perfumer\MVC\ExternalRouter\RouterInterface as ExternalRouter;
use Perfumer\MVC\InternalRouter\RouterInterface as InternalRouter;
use Perfumer\MVC\Proxy\Exception\ForwardException;
use Symfony\Component\HttpFoundation\Response;

class Core
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var ExternalRouter
     */
    protected $external_router;

    /**
     * @var InternalRouter
     */
    protected $internal_router;

    /**
     * @var Request
     */
    protected $current_initial;

    /**
     * @var Request
     */
    protected $next;

    /**
     * @var array
     */
    protected $request_pool = [];

    /**
     * @var array
     */
    protected $background_jobs = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->external_router = $container->getService('external_router');
        $this->internal_router = $container->getService('internal_router');
    }

    /**
     * @return ExternalRouter
     */
    public function getExternalRouter()
    {
        return $this->external_router;
    }

    /**
     * @return InternalRouter
     */
    public function getInternalRouter()
    {
        return $this->internal_router;
    }

    public function getRequestPool()
    {
        return $this->request_pool;
    }

    public function getMain()
    {
        return $this->request_pool[0];
    }

    public function run()
    {
        list($url, $action, $args) = $this->external_router->dispatch();

        $this->next = $this->internal_router->dispatch($url, $action, $args);

        $this->start()->send();

        foreach ($this->background_jobs as $job)
            $this->execute($job[0], $job[1], $job[2]);
    }

    public function execute($url, $action, array $args = [])
    {
        $request = $this->internal_router->dispatch($url, $action, $args);

        return $this->executeController($request);
    }

    public function forward($url, $action, array $args = [])
    {
        $this->current_initial = null;

        $this->next = $this->internal_router->dispatch($url, $action, $args);

        throw new ForwardException();
    }

    public function addBackgroundJob($url, $action, array $args = [])
    {
        $this->background_jobs[] = [$url, $action, $args];

        return $this;
    }

    /**
     * @return Response
     */
    protected function start()
    {
        try
        {
            $response = $this->executeController($this->next);
        }
        catch (ForwardException $e)
        {
            return $this->start();
        }

        return $response;
    }

    protected function executeController(Request $request)
    {
        if (count($this->request_pool) != 0)
            $request->setMain($this->getMain());

        $this->request_pool[] = $request;

        if ($this->current_initial === null)
        {
            $this->current_initial = $request;
        }
        else
        {
            $request->setInitial($this->current_initial);
        }

        try
        {
            $reflection_class = new \ReflectionClass($request->getController());
        }
        catch (\ReflectionException $e)
        {
            $this->forward('exception/page', 'controllerNotFound');
        }

        $response = new Response;

        $controller = $reflection_class->newInstance($this->container, $request, $response, $reflection_class);

        return $reflection_class->getMethod('execute')->invoke($controller);
    }
}