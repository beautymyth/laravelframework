<?php

namespace Illuminate\Foundation\Http;

use Exception;
use Throwable;
use Illuminate\Routing\Router;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Symfony\Component\Debug\Exception\FatalThrowableError;

class Kernel implements KernelContract {

    /**
     * The application implementation.
     * <br>应用实例
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The router instance.
     *　路由实例
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * The bootstrap classes for the application.
     * <br>应用需要启动的类
     * @var array
     */
    protected $bootstrappers = [
        \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        \Illuminate\Foundation\Bootstrap\BootProviders::class,
    ];

    /**
     * The application's middleware stack.
     * <br>应用程序的全局HTTP中间件堆栈，每次请求都会执行这些中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * The application's route middleware groups.
     * <br>应用程序的路由中间件组
     * @var array
     */
    protected $middlewareGroups = [];

    /**
     * The application's route middleware.
     * <br>应用程序的路由中间件
     * <br>这些中间件可能分配给中间件组或单独使用
     * @var array
     */
    protected $routeMiddleware = [];

    /**
     * The priority-sorted list of middleware.
     * <br>优先级排序的中间件列表
     * Forces the listed middleware to always be in the given order.
     * <br>强制列出的中间件始终按照给定的顺序
     * @var array
     */
    protected $middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Auth\Middleware\Authenticate::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    /**
     * Create a new HTTP kernel instance.
     * <br>创建内核实例
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Application $app, Router $router) {
        $this->app = $app;
        $this->router = $router;
        //设置路由的中间件信息
        $router->middlewarePriority = $this->middlewarePriority;
        foreach ($this->middlewareGroups as $key => $middleware) {
            $router->middlewareGroup($key, $middleware);
        }
        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->aliasMiddleware($key, $middleware);
        }
    }

    /**
     * Handle an incoming HTTP request.
     * <br>处理http请求
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle($request) {
        try {
            $request->enableHttpMethodParameterOverride();
            //通过中间件/路由器发送请求
            $response = $this->sendRequestThroughRouter($request);
        } catch (Exception $e) {
            $this->reportException($e);
            $response = $this->renderException($request, $e);
        } catch (Throwable $e) {
            $this->reportException($e = new FatalThrowableError($e));
            $response = $this->renderException($request, $e);
        }
        $this->app['events']->dispatch(
                new Events\RequestHandled($request, $response)
        );
        return $response;
    }

    /**
     * Send the given request through the middleware / router.
     * <br>通过中间件/路由器发送请求
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function sendRequestThroughRouter($request) {

        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        //为Http请求启动应用
        $this->bootstrap();

        //创建处理管道
        return (new Pipeline($this->app))
                        ->send($request)
                        ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                        ->then($this->dispatchToRouter());
    }

    /**
     * Bootstrap the application for HTTP requests.
     * <br>为Http请求启动应用
     * @return void
     */
    public function bootstrap() {
        //应用是否已经启动
        if (!$this->app->hasBeenBootstrapped()) {
            //运行给定的启动类
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Get the route dispatcher callback.
     * <br>获取路由分发处理的闭包函数
     * @return \Closure
     */
    protected function dispatchToRouter() {
        return function ($request) {
            $this->app->instance('request', $request);
            return $this->router->dispatch($request);
        };
    }

    /**
     * Call the terminate method on any terminable middleware.
     * <br>调用中间件的terminate方法与应用的terminate方法
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     */
    public function terminate($request, $response) {
        $this->terminateMiddleware($request, $response);
        $this->app->terminate();
    }

    /**
     * Call the terminate method on any terminable middleware.
     * <br>调用中间件的terminate方法
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     */
    protected function terminateMiddleware($request, $response) {
        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
                        $this->gatherRouteMiddleware($request), $this->middleware
        );
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }
            list($name) = $this->parseMiddleware($middleware);
            $instance = $this->app->make($name);
            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
    }

    /**
     * Gather the route middleware for the given request.
     * <br>为给定的请求收集路由中间件
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function gatherRouteMiddleware($request) {
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddleware($route);
        }
        return [];
    }

    /**
     * Parse a middleware string to get the name and parameters.
     * <br>解析中间件字符串以获取名称和参数
     * @param  string  $middleware
     * @return array
     */
    protected function parseMiddleware($middleware) {
        list($name, $parameters) = array_pad(explode(':', $middleware, 2), 2, []);
        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }
        return [$name, $parameters];
    }

    /**
     * Determine if the kernel has a given middleware.
     * <br>内核是否包含某个中间件
     * @param  string  $middleware
     * @return bool
     */
    public function hasMiddleware($middleware) {
        return in_array($middleware, $this->middleware);
    }

    /**
     * Add a new middleware to beginning of the stack if it does not already exist.
     * <br>在全局中间件的堆栈前增加一个中间件
     * @param  string  $middleware
     * @return $this
     */
    public function prependMiddleware($middleware) {
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }
        return $this;
    }

    /**
     * Add a new middleware to end of the stack if it does not already exist.
     * <br>在全局中间件的堆栈后增加一个中间件
     * @param  string  $middleware
     * @return $this
     */
    public function pushMiddleware($middleware) {
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }
        return $this;
    }

    /**
     * Get the bootstrap classes for the application.
     * <br>获取应用的启动类
     * @return array
     */
    protected function bootstrappers() {
        return $this->bootstrappers;
    }

    /**
     * Report the exception to the exception handler.
     * <br>将异常报告给异常处理程序
     * @param  \Exception  $e
     * @return void
     */
    protected function reportException(Exception $e) {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception to a response.
     * <br>为响应呈现异常
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function renderException($request, Exception $e) {
        return $this->app[ExceptionHandler::class]->render($request, $e);
    }

    /**
     * Get the Laravel application instance.
     * <br>获取应用实例
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public function getApplication() {
        return $this->app;
    }

}
