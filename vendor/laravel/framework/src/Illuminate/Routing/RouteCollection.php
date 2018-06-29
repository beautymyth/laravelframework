<?php

namespace Illuminate\Routing;

use Countable;
use ArrayIterator;
use IteratorAggregate;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class RouteCollection implements Countable, IteratorAggregate {

    /**
     * An array of the routes keyed by method.
     * <br>使用method作为key的路由数组
     * <br>[$method=>[[$domainAndUri=>$route]]]
     * @var array
     */
    protected $routes = [];

    /**
     * An flattened array of all of the routes.
     * <br>使用method+domain+uri作为key的路由数组
     * <br>[$method . $domainAndUri=>$route]
     * @var array
     */
    protected $allRoutes = [];

    /**
     * A look-up table of routes by their names.
     * <br>以name为key的查找表
     * @var array
     */
    protected $nameList = [];

    /**
     * A look-up table of routes by controller action.
     * <br>以controller为key的查找表
     * @var array
     */
    protected $actionList = [];

    /**
     * Add a Route instance to the collection.
     * <br>向集合添加路由实例
     * @param  \Illuminate\Routing\Route  $route
     * @return \Illuminate\Routing\Route
     */
    public function add(Route $route) {
        //将路由添加进路由数组
        $this->addToCollections($route);
        
        //将路由添加到快速查找表
        $this->addLookups($route);

        return $route;
    }

    /**
     * Add the given route to the arrays of routes.
     * <br>将路由添加进路由数组
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addToCollections($route) {
        $domainAndUri = $route->getDomain() . $route->uri();

        foreach ($route->methods() as $method) {
            $this->routes[$method][$domainAndUri] = $route;
        }

        $this->allRoutes[$method . $domainAndUri] = $route;
    }

    /**
     * Add the route to any look-up tables if necessary.
     * <br>将路由添加到查找表中，方便定位路由
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addLookups($route) {
        // If the route has a name, we will add it to the name look-up table so that we
        // will quickly be able to find any route associate with a name and not have
        // to iterate through every route every time we need to perform a look-up.
        //获取路由的操作
        $action = $route->getAction();
        
        if (isset($action['as'])) {
            //如果路由有命名，添加到以name为key的查找表中
            $this->nameList[$action['as']] = $route;
        }

        // When the route is routing to a controller we will also store the action that
        // is used by the route. This will let us reverse route to controllers while
        // processing a request and easily generate URLs to the given controllers.
        if (isset($action['controller'])) {
            //如果路由有控制器，添加到以controller为key的查找表中
            $this->addToActionList($action, $route);
        }
    }

    /**
     * Add a route to the controller action dictionary.
     * <br>添加路由到控制器字典
     * @param  array  $action
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addToActionList($action, $route) {
        $this->actionList[trim($action['controller'], '\\')] = $route;
    }

    /**
     * Refresh the name look-up table.
     *
     * This is done in case any names are fluently defined or if routes are overwritten.
     *
     * @return void
     */
    public function refreshNameLookups() {
        $this->nameList = [];

        foreach ($this->allRoutes as $route) {
            if ($route->getName()) {
                $this->nameList[$route->getName()] = $route;
            }
        }
    }

    /**
     * Refresh the action look-up table.
     *
     * This is done in case any actions are overwritten with new controllers.
     *
     * @return void
     */
    public function refreshActionLookups() {
        $this->actionList = [];

        foreach ($this->allRoutes as $route) {
            if (isset($route->getAction()['controller'])) {
                $this->addToActionList($route->getAction(), $route);
            }
        }
    }

    /**
     * Find the first route matching a given request.
     * <br>查找与给定请求匹配的第一个路由
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Routing\Route
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function match(Request $request) {
        //获取匹配请求方式的路由
        $routes = $this->get($request->getMethod());

        // First, we will see if we can find a matching route for this current request
        // method. If we can, great, we can just return it so that it can be called
        // by the consumer. Otherwise we will check for routes with another verb.
        //查找匹配请求的路由
        $route = $this->matchAgainstRoutes($routes, $request);

        if (!is_null($route)) {        
            //有匹配的路由，将路由与请求绑定
            return $route->bind($request);
        }

        // If no route was found we will now check if a matching route is specified by
        // another HTTP verb. If it is we will need to throw a MethodNotAllowed and
        // inform the user agent of which HTTP verb it should use for this route.
        //查找其它请求方式中，是否有匹配的路由
        $others = $this->checkForAlternateVerbs($request);

        if (count($others) > 0) {
            return $this->getRouteForMethods($request, $others);
        }
        
        //抛出未找到路由异常
        throw new NotFoundHttpException;
    }

    /**
     * Determine if a route in the array matches the request.
     * <br>确定数组中的路由是否与请求匹配
     * @param  array  $routes
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $includingMethod
     * @return \Illuminate\Routing\Route|null
     */
    protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true) {
        //将正常路由与后备路由进行拆分
        list($fallbacks, $routes) = collect($routes)->partition(function ($route) {
            return $route->isFallback;
        });
        
        //1.将后备路由插入到正常路由的后面
        //2.获取第一匹配的路由
        return $routes->merge($fallbacks)->first(function ($value) use ($request, $includingMethod) {
                    return $value->matches($request, $includingMethod);
                });
    }

    /**
     * Determine if any routes match on another HTTP verb.
     * <br>请求是否匹配其它执行方式
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function checkForAlternateVerbs($request) {
        $methods = array_diff(Router::$verbs, [$request->getMethod()]);

        // Here we will spin through all verbs except for the current request verb and
        // check to see if any routes respond to them. If they do, we will return a
        // proper error response with the correct headers on the response string.
        $others = [];

        foreach ($methods as $method) {
            if (!is_null($this->matchAgainstRoutes($this->get($method), $request, false))) {
                $others[] = $method;
            }
        }

        return $others;
    }

    /**
     * Get a route (if necessary) that responds when other available methods are present.
     * <br>获得一个路由(如果需要)，当有其他可用方法时响应该路由
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $methods
     * @return \Illuminate\Routing\Route
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function getRouteForMethods($request, array $methods) {
        if ($request->method() == 'OPTIONS') {
            //如果当前请求为options，则生成一个options路由
            return (new Route('OPTIONS', $request->path(), function () use ($methods) {
                return new Response('', 200, ['Allow' => implode(',', $methods)]);
            }))->bind($request);
        }
        
        //抛出请求方式不允许异常
        $this->methodNotAllowed($methods);
    }

    /**
     * Throw a method not allowed HTTP exception.
     * <br>抛出请求方式不允许异常
     * @param  array  $others
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function methodNotAllowed(array $others) {
        throw new MethodNotAllowedHttpException($others);
    }

    /**
     * Get routes from the collection by method.
     * <br>获取匹配请求方式的路由
     * @param  string|null  $method
     * @return array
     */
    public function get($method = null) {
        return is_null($method) ? $this->getRoutes() : Arr::get($this->routes, $method, []);
    }

    /**
     * Determine if the route collection contains a given named route.
     *
     * @param  string  $name
     * @return bool
     */
    public function hasNamedRoute($name) {
        return !is_null($this->getByName($name));
    }

    /**
     * Get a route instance by its name.
     *
     * @param  string  $name
     * @return \Illuminate\Routing\Route|null
     */
    public function getByName($name) {
        //return $this->nameList[$name] ?? null;
        return isset($this->nameList[$name]) ? $this->nameList[$name] : null;
    }

    /**
     * Get a route instance by its controller action.
     *
     * @param  string  $action
     * @return \Illuminate\Routing\Route|null
     */
    public function getByAction($action) {
        //return $this->actionList[$action] ?? null;
        return isset($this->actionList[$action]) ? $this->actionList[$action] : null;
    }

    /**
     * Get all of the routes in the collection.
     * <br>获取集合中的所有路由
     * @return array
     */
    public function getRoutes() {
        return array_values($this->allRoutes);
    }

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array
     */
    public function getRoutesByMethod() {
        return $this->routes;
    }

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array
     */
    public function getRoutesByName() {
        return $this->nameList;
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator
     */
    public function getIterator() {
        return new ArrayIterator($this->getRoutes());
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count() {
        return count($this->getRoutes());
    }

}
