<?php

namespace Illuminate\Container;

use Closure;
use ArrayAccess;
use LogicException;
use ReflectionClass;
use ReflectionParameter;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container as ContainerContract;

class Container implements ArrayAccess, ContainerContract {

    /**
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * An array of the types that have been resolved.
     * <br>所有已解析过的类型
     * <br>[$abstract=>true]
     * @var array
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     * <br>服务容器中的绑定关系
     * <br>[$abstract=>['concrete'=>Closure,'shared'=>true|false]]
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's method bindings.
     * <br>回调方法绑定
     * <br>[$method=>$callback]
     * @var array
     */
    protected $methodBindings = [];

    /**
     * The container's shared instances.
     * <br>服务容器中的共享实例
     * <br>[$abstract=>$instance]
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     * <br>类别名
     * <br>[$alias(实际类名)=>$abstract(别名)]
     * <br>$app->registerCoreContainerAliases
     * @var array
     */
    protected $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     * <br>类别名，使用抽象名称作为键值
     * <br>[$abstract=>[$alias]]
     * <br>$app->registerCoreContainerAliases
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * The extension closures for services.
     * <br>服务的扩展方法
     * <br>[$abstract=>[$closure]]
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     * <br>绑定的标记信息
     * <br>[$tag=>[$abstract]]
     * @var array
     */
    protected $tags = [];

    /**
     * The stack of concretions currently being built.
     * <br>当前正在构建服务的堆栈
     * <br>[$concrete]
     * @var array
     */
    protected $buildStack = [];

    /**
     * The parameter override stack.
     * <br>当前正在构建服务的堆栈的参数
     * <br>[$parameters]
     * @var array
     */
    protected $with = [];

    /**
     * The contextual binding map.
     * <br>上下文绑定映射
     * <br>[$concrete=>[$this->getAlias($abstract)=>$implementation]]
     * @var array
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     * <br>类重新绑定时需要触发的回调方法
     * <br>[$abstract=>[$callback]]
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     * <br>全局解析回调
     * <br>[$abstract]
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     * <br>全局解析后回调
     * <br>[$abstract]
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     * <br>抽象类型的解析回调
     * <br>[$abstract=>[$callback]]
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     * <br>抽象类型的解析后回调
     * <br>[$abstract=>[$callback]]
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    /**
     * Define a contextual binding.
     * <br>创建上下文绑定
     * @param  string  $concrete 实际类名
     * @return \Illuminate\Contracts\Container\ContextualBindingBuilder
     */
    public function when($concrete) {
        //实例化上下文绑定对象
        return new ContextualBindingBuilder($this, $this->getAlias($concrete));
    }

    /**
     * Determine if the given abstract type has been bound.
     * <br>抽象类型是否已绑定过
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract) {
        return isset($this->bindings[$abstract]) ||
                isset($this->instances[$abstract]) ||
                $this->isAlias($abstract);
    }

    /**
     *  {@inheritdoc}
     */
    public function has($id) {
        return $this->bound($id);
    }

    /**
     * Determine if the given abstract type has been resolved.
     * <br>抽象类型是否已解析过
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract) {
        //抽象类型是否为别名
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        //抽象类型已解析过或存在共享实例
        return isset($this->resolved[$abstract]) ||
                isset($this->instances[$abstract]);
    }

    /**
     * Determine if a given type is shared.
     * <br>检查类型是否为共享类型
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract) {
        return isset($this->instances[$abstract]) ||
                (isset($this->bindings[$abstract]['shared']) &&
                $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * Determine if a given string is an alias.
     * <br>给定的字符串是否为类的别名
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name) {
        return isset($this->aliases[$name]);
    }

    /**
     * Register a binding with the container.
     * <br>绑定服务到容器
     * @param  string  $abstract 类别名，实际类名，接口类名
     * @param  \Closure|string|null  $concrete 类的构建闭包，实际类名，null=>$concrete=$abstract
     * @param  bool  $shared 是否为共享服务
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false) {
        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        //移除抽象类型关联的共享实例与别名
        $this->dropStaleInstances($abstract);

        //如果没有给出具体类型，则将抽象类型设置为具体类型
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        //如果具体类型不是闭包，则生成闭包，方便后面的使用
        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        //记录抽象类绑定关系
        $this->bindings[$abstract] = compact('concrete', 'shared');

        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        //抽象类型是否已解析过
        if ($this->resolved($abstract)) {
            //触发回调
            $this->rebound($abstract);
        }
    }

    /**
     * Get the Closure to be used when building a type.
     * <br>获取在构建类型时使用的闭包
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete) {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete, $parameters);
        };
    }

    /**
     * Determine if the container has a method binding.
     * <br>容器是否存在回调方法绑定
     * @param  string  $method
     * @return bool
     */
    public function hasMethodBinding($method) {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     * <br>绑定可通过Container::call解析的回调方法
     * @param  array|string  $method
     * @param  \Closure  $callback
     * @return void
     */
    public function bindMethod($method, $callback) {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * Get the method to be bound in class@method format.
     * <br>获取格式化的方法名
     * @param  array|string $method
     * @return string
     */
    protected function parseBindMethod($method) {
        if (is_array($method)) {
            return $method[0] . '@' . $method[1];
        }

        return $method;
    }

    /**
     * Get the method binding for the given method.
     * <br>执行绑定的回调方法
     * @param  string  $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance) {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * Add a contextual binding to the container.
     * <br>将上下文绑定添加到容器中
     * @param  string  $concrete 
     * @param  string  $abstract
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation) {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    /**
     * Register a binding if it hasn't already been registered.
     * <br>如果抽象类型还没绑定过，则进行绑定
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false) {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a shared binding in the container.
     * <br>绑定单例服务到容器
     * @param  string  $abstract 类别名，实际类名，接口类名
     * @param  \Closure|string|null  $concrete 类的构建闭包，实际类名，null=>$concrete=$abstract
     * @return void
     */
    public function singleton($abstract, $concrete = null) {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * "Extend" an abstract type in the container.
     * <br>扩展容器中的服务
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure) {
        //获取抽象类型的别名
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            //已存在共享实例，进行扩展处理
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            //触发'rebound'回调
            $this->rebound($abstract);
        } else {
            //记录服务扩展方法
            $this->extenders[$abstract][] = $closure;
            //如果解析过，触发'rebound'回调
            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     * Register an existing instance as shared in the container.
     * <br>绑定抽象类型的实例为共享实例
     * @param  string  $abstract 类别名，实际类名，接口类名
     * @param  mixed   $instance 实例
     * @return mixed
     */
    public function instance($abstract, $instance) {
        //删除抽象类关联的实际类名
        $this->removeAbstractAlias($abstract);

        //是否已绑定
        $isBound = $this->bound($abstract);

        //删除类别名
        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        //将实例记录到共享实例中
        $this->instances[$abstract] = $instance;

        //绑定过触发'rebound'回调
        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     * <br>删除抽象类键值的别名
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched) {
        if (!isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Assign a set of tags to a given binding.
     * <br>为一组绑定设定标记
     * @param  array|string  $abstracts
     * @param  array|mixed   ...$tags
     * @return void
     */
    public function tag($abstracts, $tags) {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     * <br>解析标记中的所有绑定
     * @param  string  $tag
     * @return array
     */
    public function tagged($tag) {
        $results = [];

        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Alias a type to a different name.
     * <br>将类型别名设为不同的名称
     * @param  string  $abstract 如view
     * @param  string  $alias 如\Illuminate\View\Factory::class
     * @return void
     */
    public function alias($abstract, $alias) {
        $this->aliases[$alias] = $abstract;

        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     * <br>为抽象类绑定一个'rebind'事件的回调方法
     * @param  string    $abstract
     * @param  \Closure  $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback) {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     * <br>使用给定目标中的方法，刷新实例。主要是绑定一个回调
     * @param  string  $abstract
     * @param  mixed   $target
     * @param  string  $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method) {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
                    $target->{$method}($instance);
                });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     * <br>触发抽象类型的'rebound'回调
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract) {
        //获取类的实例
        $instance = $this->make($abstract);
        //循环触发所有绑定的回调
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     * <br>获取类的'rebound'回调
     * @param  string  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract) {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     * <br>包装给定的闭包，使其依赖项在执行时被注入
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = []) {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     * <br>调用给定的闭包/ class@method并注入它的依赖项
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null) {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get a closure to resolve the given type from the container.
     * <br>获取用于解析类型的闭包工厂
     * @param  string  $abstract
     * @return \Closure
     */
    public function factory($abstract) {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * An alias function name for make().
     * <br>make方法的别名
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function makeWith($abstract, array $parameters = []) {
        return $this->make($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     * <br>从服务容器中解析服务
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = []) {
        return $this->resolve($abstract, $parameters);
    }

    /**
     *  {@inheritdoc}
     */
    public function get($id) {
        if ($this->has($id)) {
            return $this->resolve($id);
        }

        throw new EntryNotFoundException;
    }

    /**
     * Resolve the given type from the container.
     * <br>从服务容器中解析服务
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    protected function resolve($abstract, $parameters = []) {
        //获取最终需要解析的类名
        $abstract = $this->getAlias($abstract);

        //是否需要重建实例
        //1.传入了参数
        //2.有上下文绑定
        $needsContextualBuild = !empty($parameters) || !is_null(
                        $this->getContextualConcrete($abstract)
        );

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$abstract]) && !$needsContextualBuild) {
            //如果存在服务的共享实例，且不需要重建，直接返回共享实例
            return $this->instances[$abstract];
        }

        //将解析参数放入参数堆栈中
        $this->with[] = $parameters;

        //获取抽象类型的具体类型
        $concrete = $this->getConcrete($abstract);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        if ($this->isBuildable($concrete, $abstract)) {
            //如果可构建，调用构建方法
            $object = $this->build($concrete);
        } else {
            //如果不可构建，调归调用make，使用$concrete
            $object = $this->make($concrete);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        //对服务的实例进行扩展
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        //如果抽象类型为可共享的，且不需要重新构建
        if ($this->isShared($abstract) && !$needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }

        //触发解析回调与解析后回调
        $this->fireResolvingCallbacks($abstract, $object);

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return back the fully constructed class instance.
        //标记抽象类型为已解析
        $this->resolved[$abstract] = true;

        //从参数堆栈移除当前解析参数
        array_pop($this->with);

        //解析完成，返回服务的实例
        return $object;
    }

    /**
     * Get the concrete type for a given abstract.
     * <br>获取抽象类型的具体类型
     * <br>1.从上下文绑定获取
     * <br>2.从绑定关系获取
     * <br>3.直接返回抽象类型
     * @param  string  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract) {
        //从上下文绑定获取
        if (!is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        //从绑定关系获取
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        //返回抽象类型
        return $abstract;
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     * <br>获取类型的上下文绑定
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract) {
        //通过别名获取
        if (!is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        //如果通过别名找不到，尝试通过抽象别名获取到别名再进行查找
        if (empty($this->abstractAliases[$abstract])) {
            return;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (!is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     * <br>从上下文绑定数组中查找参数
     * @param  string  $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract) {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    /**
     * Determine if the given concrete is buildable.
     * <br>具体类型是否可构建实例
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract) {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Instantiate a concrete instance of the given type.
     * <br>获取类型的实例
     * @param  string  $concrete
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function build($concrete) {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        //如果类型为闭包函数，则直接执行
        //在闭包函数里会有实例化类的逻辑
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        //获取类型的反射类
        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        if (!$reflector->isInstantiable()) {
            //如果反射类不可实例化，则抛出异常
            return $this->notInstantiable($concrete);
        }

        //将类型放入解析堆栈
        $this->buildStack[] = $concrete;

        //获取反射类的构造方法
        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        if (is_null($constructor)) {
            //如果反射类没有构造方法
            //从解析堆栈中移除类型
            array_pop($this->buildStack);
            //返回类型的实例
            return new $concrete;
        }

        //获取反射类构造函数的参数
        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        //解析构造函数的依赖项
        $instances = $this->resolveDependencies(
                $dependencies
        );

        //从解析堆栈中移除类型
        array_pop($this->buildStack);

        //使用构造函数的依赖性参数来生成实例
        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     * <br>解析构造函数的依赖项
     * <br>1.解析参数中包含
     * <br>2.简单类型
     * <br>3.类类型
     * @param  array  $dependencies
     * @return array
     */
    protected function resolveDependencies(array $dependencies) {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has a override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            //如果解析参数中包含依赖项参数，则直接使用不需要解析
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            //解析简单类型或类类型
            $results[] = is_null($dependency->getClass()) ? $this->resolvePrimitive($dependency) : $this->resolveClass($dependency);
        }

        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     * <br>解析参数中是否有依赖项参数
     * @param  \ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency) {
        return array_key_exists(
                $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     * <br>获取解析参数中的依赖项参数
     * @param  \ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency) {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Get the last parameter override.
     * <br>获取参数堆栈中的最后一个
     * @return array
     */
    protected function getLastParameterOverride() {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     * <br>解析简单类型
     * <br>1.有上下文绑定
     * <br>2.有默认值
     * <br>3.抛出不可解析异常
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolvePrimitive(ReflectionParameter $parameter) {
        //如果有上下文绑定，则使用
        if (!is_null($concrete = $this->getContextualConcrete('$' . $parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        //参数有默认值，则使用默认值
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        //抛出不可解析参数的异常
        $this->unresolvablePrimitive($parameter);
    }

    /**
     * Resolve a class based dependency from the container.
     * <br>解析类类型
     * <br>1.类可以解析
     * <br>2.类解析异常，参数为可选，返回缺省值
     * <br>3.抛出异常
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveClass(ReflectionParameter $parameter) {
        try {
            return $this->make($parameter->getClass()->name);
        }

        // If we can not resolve the class instance, we will check to see if the value
        // is optional, and if it is we will return the optional parameter value as
        // the value of the dependency, similarly to how we do this with scalars.
        catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     * <br>抛出不可实例化的异常
     * @param  string  $concrete
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function notInstantiable($concrete) {
        if (!empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Throw an exception for an unresolvable primitive.
     * <br>抛出不可解析的参数异常
     * @param  \ReflectionParameter  $parameter
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter) {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionException($message);
    }

    /**
     * Register a new resolving callback.
     * <br>注册一个解析回调
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null) {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     * <br>注册一个解析后回调
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null) {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the resolving callbacks.
     * <br>触发解析回调
     * <br>1.全局解析回调
     * <br>2.抽象类型的解析回调
     * <br>3.全局解析后回调
     * <br>4.抽象类型的解析后回调
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object) {
        //1.全局解析回调
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);
        //2.抽象类型的解析回调
        $this->fireCallbackArray(
                $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );
        //3.触发解析后回调
        $this->fireAfterResolvingCallbacks($abstract, $object);
    }

    /**
     * Fire all of the after resolving callbacks.
     * <br>触发解析后回调
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object) {
        //1.全局解析后回调
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);
        //2.抽象类型的解析后回调
        $this->fireCallbackArray(
                $object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     * <br>根据给定类型的回调方法
     * @param  string  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType) {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     * <br>执行回调方法
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks) {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Get the container's bindings.
     * <br>获取服务容器的所有绑定关系
     * @return array
     */
    public function getBindings() {
        return $this->bindings;
    }

    /**
     * Get the alias for an abstract if available.
     * <br>获取抽象类型的别名
     * @param  string  $abstract
     * @return string
     *
     * @throws \LogicException
     */
    public function getAlias($abstract) {
        //是否存在别名
        if (!isset($this->aliases[$abstract])) {
            return $abstract;
        }

        //别名=抽象类名，抛出逻辑异常
        if ($this->aliases[$abstract] === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        //递归获取
        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Get the extender callbacks for a given type.
     * <br>获取类型的扩展方法
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract) {
        $abstract = $this->getAlias($abstract);

        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }

        return [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     * <br>移除给定类型的扩展方法
     * @param  string  $abstract
     * @return void
     */
    public function forgetExtenders($abstract) {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

    /**
     * Drop all of the stale instances and aliases.
     * <br>移除抽象类型关联的共享实例与别名
     * <br>$this->instances,$this->aliases
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract) {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.
     * <br>移除抽象类型的共享实例
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract) {
        unset($this->instances[$abstract]);
    }

    /**
     * Clear all of the instances from the container.
     * <br>从服务容器中移除所有共享实例
     * @return void
     */
    public function forgetInstances() {
        $this->instances = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.
     * <br>移除容器中的所有绑定与解析的实例
     * @return void
     */
    public function flush() {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
    }

    /**
     * Set the globally available instance of the container.
     * <br>获取容器的单例
     * @return static
     */
    public static function getInstance() {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     * <br>设置容器的单例
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return static
     */
    public static function setInstance(ContainerContract $container = null) {
        return static::$instance = $container;
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key) {
        return $this->bound($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key) {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value) {
        $this->bind($key, $value instanceof Closure ? $value : function () use ($value) {
                    return $value;
                });
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key) {
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    /**
     * Dynamically access container services.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key) {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value) {
        $this[$key] = $value;
    }

}
