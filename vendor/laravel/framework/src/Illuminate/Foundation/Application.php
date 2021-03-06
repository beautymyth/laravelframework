<?php

namespace Illuminate\Foundation;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;

class Application extends Container implements ApplicationContract, HttpKernelInterface {

    /**
     * The Laravel framework version.
     * <br>laravel框架版本
     * @var string
     */
    const VERSION = '5.6.20';

    /**
     * The base path for the Laravel installation.
     * <br>项目文件夹路径
     * @var string
     */
    protected $basePath;

    /**
     * Indicates if the application has been bootstrapped before.
     * <br>应用是否已启动
     * @var bool
     */
    protected $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has "booted".
     * <br>标记应用是否已启动
     * @var bool
     */
    protected $booted = false;

    /**
     * The array of booting callbacks.
     * <br>启动的回调
     * <br>[$callback]
     * @var array
     */
    protected $bootingCallbacks = [];

    /**
     * The array of booted callbacks.
     * <br>启动后的回调
     * <br>[$callback]
     * @var array
     */
    protected $bootedCallbacks = [];

    /**
     * The array of terminating callbacks.
     * <br>应用中止时的回调
     * <br>[$callback]
     * @var array
     */
    protected $terminatingCallbacks = [];

    /**
     * All of the registered service providers.
     * <br>所有已注册的服务提供者
     * <br>[$provider]
     * @var array
     */
    protected $serviceProviders = [];

    /**
     * The names of the loaded service providers.
     * <br>已加载过的服务提供者
     * <br>['key'=>$provider]
     * @var array
     */
    protected $loadedProviders = [];

    /**
     * The deferred services and their providers.
     * <br>延迟服务与对应的服务提供者
     * <br>['key'=>$service,'value'=>$provider]
     * @var array
     */
    protected $deferredServices = [];

    /**
     * The custom database path defined by the developer.
     * @var string
     */
    protected $databasePath;

    /**
     * The custom storage path defined by the developer.
     *
     * @var string
     */
    protected $storagePath;

    /**
     * The custom environment path defined by the developer.
     *
     * @var string
     */
    protected $environmentPath;

    /**
     * The environment file to load during bootstrapping.
     *
     * @var string
     */
    protected $environmentFile = '.env';

    /**
     * The application namespace.
     * <br>应用的命名空间
     * @var string
     */
    protected $namespace;

    /**
     * Create a new Illuminate application instance.
     * <br>创建应用实例
     * @param  string|null  $basePath
     * @return void
     */
    public function __construct($basePath = null) {
        if ($basePath) {
            //设置应用路径
            $this->setBasePath($basePath);
        }
        
        //注册基本的绑定
        $this->registerBaseBindings();
        
        //注册基本的服务提供者
        $this->registerBaseServiceProviders();
        
        //注册核心的容器服务别名
        $this->registerCoreContainerAliases();
    }

    /**
     * Get the version number of the application.
     * <br>获取应用的版本
     * @return string
     */
    public function version() {
        return static::VERSION;
    }

    /**
     * Register the basic bindings into the container.
     * <br>注册基本的绑定
     * @return void
     */
    protected function registerBaseBindings() {
        static::setInstance($this);

        $this->instance('app', $this);

        $this->instance(Container::class, $this);

        $this->instance(PackageManifest::class, new PackageManifest(
                new Filesystem, $this->basePath(), $this->getCachedPackagesPath()
        ));
    }

    /**
     * Register all of the base service providers.
     * <br>注册基本的服务提供者
     * @return void
     */
    protected function registerBaseServiceProviders() {
        $this->register(new EventServiceProvider($this));

        $this->register(new LogServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }

    /**
     * Run the given array of bootstrap classes.
     * <br>运行给定的启动类
     * @param  array  $bootstrappers
     * @return void
     */
    public function bootstrapWith(array $bootstrappers) {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->fire('bootstrapping: ' . $bootstrapper, [$this]);

            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->fire('bootstrapped: ' . $bootstrapper, [$this]);
        }
    }

    /**
     * Register a callback to run after loading the environment.
     * <br>注册在环境加载好后的回调
     * @param  \Closure  $callback
     * @return void
     */
    public function afterLoadingEnvironment(Closure $callback) {
        return $this->afterBootstrapping(
                        LoadEnvironmentVariables::class, $callback
        );
    }

    /**
     * Register a callback to run before a bootstrapper.
     * <br>注册在应用启动前的回调
     * @param  string  $bootstrapper
     * @param  \Closure  $callback
     * @return void
     */
    public function beforeBootstrapping($bootstrapper, Closure $callback) {
        $this['events']->listen('bootstrapping: ' . $bootstrapper, $callback);
    }

    /**
     * Register a callback to run after a bootstrapper.
     * <br>注册在应用启动后的回调
     * @param  string  $bootstrapper
     * @param  \Closure  $callback
     * @return void
     */
    public function afterBootstrapping($bootstrapper, Closure $callback) {
        $this['events']->listen('bootstrapped: ' . $bootstrapper, $callback);
    }

    /**
     * Determine if the application has been bootstrapped before.
     * <br>应用是否已经启动
     * @return bool
     */
    public function hasBeenBootstrapped() {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Set the base path for the application.
     * <br>设置应用路径
     * @param  string  $basePath
     * @return $this
     */
    public function setBasePath($basePath) {
        $this->basePath = rtrim($basePath, '\/');

        $this->bindPathsInContainer();

        return $this;
    }

    /**
     * Bind all of the application paths in the container.
     * <br>将应用相关的路径绑定到容器中
     * @return void
     */
    protected function bindPathsInContainer() {
        $this->instance('path', $this->path());
        $this->instance('path.base', $this->basePath());
        $this->instance('path.lang', $this->langPath());
        $this->instance('path.config', $this->configPath());
        $this->instance('path.public', $this->publicPath());
        $this->instance('path.storage', $this->storagePath());
        $this->instance('path.database', $this->databasePath());
        $this->instance('path.resources', $this->resourcePath());
        $this->instance('path.bootstrap', $this->bootstrapPath());
    }

    /**
     * Get the path to the application "app" directory.
     * <br>获取应用的'app'目录路径
     * @param  string  $path Optionally, a path to append to the app path
     * @return string
     */
    public function path($path = '') {
        return $this->basePath . DIRECTORY_SEPARATOR . 'app' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the base path of the Laravel installation.
     * <br>获取应用的基本路径
     * @param  string  $path Optionally, a path to append to the base path
     * @return string
     */
    public function basePath($path = '') {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the path to the bootstrap directory.
     * <br>获取应用的'bootstrap'目录路径
     * @param  string  $path Optionally, a path to append to the bootstrap path
     * @return string
     */
    public function bootstrapPath($path = '') {
        return $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the path to the application configuration files.
     * <br>获取应用的'config'目录路径
     * @param  string  $path Optionally, a path to append to the config path
     * @return string
     */
    public function configPath($path = '') {
        return $this->basePath . DIRECTORY_SEPARATOR . 'config' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the path to the database directory.
     * <br>获取应用的'database'目录路径
     * @param  string  $path Optionally, a path to append to the database path
     * @return string
     */
    public function databasePath($path = '') {
        return ($this->databasePath ? : $this->basePath . DIRECTORY_SEPARATOR . 'database') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Set the database directory.
     *
     * @param  string  $path
     * @return $this
     */
    public function useDatabasePath($path) {
        $this->databasePath = $path;

        $this->instance('path.database', $path);

        return $this;
    }

    /**
     * Get the path to the language files.
     *
     * @return string
     */
    public function langPath() {
        return $this->resourcePath() . DIRECTORY_SEPARATOR . 'lang';
    }

    /**
     * Get the path to the public / web directory.
     *
     * @return string
     */
    public function publicPath() {
        return $this->basePath . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * Get the path to the storage directory.
     *
     * @return string
     */
    public function storagePath() {
        return $this->storagePath ? : $this->basePath . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * Set the storage directory.
     *
     * @param  string  $path
     * @return $this
     */
    public function useStoragePath($path) {
        $this->storagePath = $path;

        $this->instance('path.storage', $path);

        return $this;
    }

    /**
     * Get the path to the resources directory.
     * <br>获取应用的'resources'目录路径
     * @param  string  $path
     * @return string
     */
    public function resourcePath($path = '') {
        return $this->basePath . DIRECTORY_SEPARATOR . 'resources' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the path to the environment file directory.
     *
     * @return string
     */
    public function environmentPath() {
        return $this->environmentPath ? : $this->basePath;
    }

    /**
     * Set the directory for the environment file.
     *
     * @param  string  $path
     * @return $this
     */
    public function useEnvironmentPath($path) {
        $this->environmentPath = $path;

        return $this;
    }

    /**
     * Set the environment file to be loaded during bootstrapping.
     *
     * @param  string  $file
     * @return $this
     */
    public function loadEnvironmentFrom($file) {
        $this->environmentFile = $file;

        return $this;
    }

    /**
     * Get the environment file the application is using.
     *
     * @return string
     */
    public function environmentFile() {
        return $this->environmentFile ? : '.env';
    }

    /**
     * Get the fully qualified path to the environment file.
     *
     * @return string
     */
    public function environmentFilePath() {
        return $this->environmentPath() . DIRECTORY_SEPARATOR . $this->environmentFile();
    }

    /**
     * Get or check the current application environment.
     *
     * @return string|bool
     */
    public function environment() {
        if (func_num_args() > 0) {
            $patterns = is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args();

            return Str::is($patterns, $this['env']);
        }

        return $this['env'];
    }

    /**
     * Determine if application is in local environment.
     *
     * @return bool
     */
    public function isLocal() {
        return $this['env'] == 'local';
    }

    /**
     * Detect the application's current environment.
     *
     * @param  \Closure  $callback
     * @return string
     */
    public function detectEnvironment(Closure $callback) {
        $args = $_SERVER['argv'] ?? null;

        return $this['env'] = (new EnvironmentDetector)->detect($callback, $args);
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole() {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests() {
        return $this['env'] === 'testing';
    }

    /**
     * Register all of the configured providers.
     * <br>注册所有配置的服务提供者
     * @return void
     */
    public function registerConfiguredProviders() {
        //将服务提供者数组，拆分为2个数组
        //"Illuminate\"开头的在第一个子集里面，剩余的在第二个子集里面
        $providers = Collection::make($this->config['app.providers'])
                ->partition(function ($provider) {
            return Str::startsWith($provider, 'Illuminate\\');
        });
        //加入所有外部包的服务提供者，将位置放入到框架提供者后面，应用提供者前面
        //vendor/composer/installed.json=>['extra']['laravel']['providers']
        $providers->splice(1, 0, [$this->make(PackageManifest::class)->providers()]);
        //注册应用的服务提供者
        (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPath()))
                ->load($providers->collapse()->toArray());
    }

    /**
     * Register a service provider with the application.
     * <br>向应用注册服务提供者
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @param  array  $options
     * @param  bool   $force
     * @return \Illuminate\Support\ServiceProvider
     */
    public function register($provider, $options = [], $force = false) {
        //服务提供者已注册过，且不强制重新注册，则直接返回
        if (($registered = $this->getProvider($provider)) && !$force) {
            return $registered;
        }

        // If the given "provider" is a string, we will resolve it, passing in the
        // application instance automatically for the developer. This is simply
        // a more convenient way of specifying your service provider classes.
        // 如果“provider”是一个字符串，我们将解析它，并自动传入应用程序实例
        // 这只是指定服务提供者类的一种更方便的方式
        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }
        //如果服务提供者有register方法则执行
        if (method_exists($provider, 'register')) {
            //此方法将具体服务的绑定到服务容器
            $provider->register();
        }

        // If there are bindings / singletons set as properties on the provider we
        // will spin through them and register them with the application, which
        // serves as a convenience layer while registering a lot of bindings.
        //如果服务提供者有bindings或singletons属性，则将服务绑定到服务容器 

        //普通绑定
        if (property_exists($provider, 'bindings')) {
            foreach ($provider->bindings as $key => $value) {
                $this->bind($key, $value);
            }
        }
        //单例绑定
        if (property_exists($provider, 'singletons')) {
            foreach ($provider->singletons as $key => $value) {
                $this->singleton($key, $value);
            }
        }

        //标记服务提供者为已注册
        $this->markAsRegistered($provider);

        // If the application has already booted, we will call this boot method on
        // the provider class so it has an opportunity to do its boot logic and
        // will be ready for any usage by this developer's application logic.
        //如果应用程序已经被引导，
        //我们将在provider类上调用这个引导方法，这样它就有机会执行它的引导逻辑，
        //并准备好供开发人员的应用程序逻辑使用
        if ($this->booted) {
            $this->bootProvider($provider);
        }
        
        //返回服务提供者实例
        return $provider;
    }

    /**
     * Get the registered service provider instance if it exists.
     * <br>获取一个已注册的服务提供者实例，没有则返回null
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @return \Illuminate\Support\ServiceProvider|null
     */
    public function getProvider($provider) {
        return array_values($this->getProviders($provider))[0] ?? null;
    }

    /**
     * Get the registered service provider instances if any exist.
     * <br>获取所有已注册的服务提供者实例
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @return array
     */
    public function getProviders($provider) {
        //获取服务提供者的类名
        $name = is_string($provider) ? $provider : get_class($provider);
        //匹配所有服务提供者的实例
        return Arr::where($this->serviceProviders, function ($value) use ($name) {
                    return $value instanceof $name;
                });
    }

    /**
     * Resolve a service provider instance from the class name.
     * <br>根据服务提供者的类名获取实例
     * @param  string  $provider
     * @return \Illuminate\Support\ServiceProvider
     */
    public function resolveProvider($provider) {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     * <br>标记服务提供者为已注册
     * @param  \Illuminate\Support\ServiceProvider  $provider
     * @return void
     */
    protected function markAsRegistered($provider) {
        $this->serviceProviders[] = $provider;

        $this->loadedProviders[get_class($provider)] = true;
    }

    /**
     * Load and boot all of the remaining deferred providers.
     * <br>加载并启动剩余的延迟服务提供者(主要是提供给控制台kernel使用)
     * @return void
     */
    public function loadDeferredProviders() {
        // We will simply spin through each of the deferred providers and register each
        // one and boot them if the application has booted. This should make each of
        // the remaining services available to this application for immediate use.
        //我们将简单地遍历每个延迟提供程序，并注册每个提供程序，
        //并在应用程序已启动时引导它们。这将使该应用程序可以立即使用其余的每个服务
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }

        $this->deferredServices = [];
    }

    /**
     * Load the provider for a deferred service.
     * <br>加载延迟服务提供者
     * @param  string  $service
     * @return void
     */
    public function loadDeferredProvider($service) {
        //服务是否为延迟服务
        if (!isset($this->deferredServices[$service])) {
            return;
        }
        //获取服务提供者
        $provider = $this->deferredServices[$service];

        // If the service provider has not already been loaded and registered we can
        // register it with the application and remove the service from this list
        // of deferred services, since it will already be loaded on subsequent.
        //　如果服务提供者还没有被加载和注册，
        //　我们可以将其注册到应用程序并将服务从这个延迟服务列表中删除，因为它将在随后加载。
        if (!isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Register a deferred provider and service.
     * <br>注册延迟服务与服务提供者
     * @param  string  $provider
     * @param  string|null  $service
     * @return void
     */
    public function registerDeferredProvider($provider, $service = null) {
        // Once the provider that provides the deferred service has been registered we
        // will remove it from our local list of the deferred services with related
        // providers so that this container does not try to resolve it out again.
        // 一旦提供递延服务的提供者注册完毕，
        // 我们将把它从本地的递延服务列表中删除，
        // 这样这个容器就不会试图再次解析它
        if ($service) {
            unset($this->deferredServices[$service]);
        }

        //使用服务提供者实例进行注册
        $this->register($instance = new $provider($this));
        
        //应用还没启动
        if (!$this->booted) {
            //在应用的启动回调列表里，增加服务提供者的启动回调
            $this->booting(function () use ($instance) {
                $this->bootProvider($instance);
            });
        }
    }

    /**
     * Resolve the given type from the container.
     * 从服务容器中解析服务
     * (Overriding Container::make)
     * @param  string  $abstract 类别名，实际类名，接口类名
     * @param  array  $parameters 类依赖的参数
     * @return mixed
     */
    public function make($abstract, array $parameters = []) {
        //获取抽象类型的别名
        $abstract = $this->getAlias($abstract);

        if (isset($this->deferredServices[$abstract]) && !isset($this->instances[$abstract])) {
            //服务为延迟加载，且没有被加载过
            $this->loadDeferredProvider($abstract);
        }
        
        //调用父类Container->make，从服务容器中解析服务
        return parent::make($abstract, $parameters);
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * (Overriding Container::bound)
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract) {
        return isset($this->deferredServices[$abstract]) || parent::bound($abstract);
    }

    /**
     * Determine if the application has booted.
     * 应用是否已启动
     * @return bool
     */
    public function isBooted() {
        return $this->booted;
    }

    /**
     * Boot the application's service providers.
     * 
     * @return void
     */
    public function boot() {
        if ($this->booted) {
            return;
        }
        
        // Once the application has booted we will also fire some "booted" callbacks
        // for any listeners that need to do work after this initial booting gets
        // finished. This is useful when ordering the boot-up processes we run.
        $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });

        $this->booted = true;

        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    /**
     * Boot the given service provider.
     * <br>启动服务提供者
     * @param  \Illuminate\Support\ServiceProvider  $provider
     * @return mixed
     */
    protected function bootProvider(ServiceProvider $provider) {
        //如果服务提供者有boot方法
        if (method_exists($provider, 'boot')) {
            return $this->call([$provider, 'boot']);
        }
    }

    /**
     * Register a new boot listener.
     * <br>增加一个启动回调
     * @param  mixed  $callback
     * @return void
     */
    public function booting($callback) {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a new "booted" listener.
     * <br>增加一个启动后回调
     * @param  mixed  $callback
     * @return void
     */
    public function booted($callback) {
        $this->bootedCallbacks[] = $callback;

        if ($this->isBooted()) {
            $this->fireAppCallbacks([$callback]);
        }
    }

    /**
     * Call the booting callbacks for the application.
     *
     * @param  array  $callbacks
     * @return void
     */
    protected function fireAppCallbacks(array $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handle(SymfonyRequest $request, $type = self::MASTER_REQUEST, $catch = true) {
        return $this[HttpKernelContract::class]->handle(Request::createFromBase($request));
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware() {
        return $this->bound('middleware.disable') &&
                $this->make('middleware.disable') === true;
    }

    /**
     * Get the path to the cached services.php file.
     * <br>获取缓存的services.php文件路径
     * @return string
     */
    public function getCachedServicesPath() {
        return $this->bootstrapPath() . '/cache/services.php';
    }

    /**
     * Get the path to the cached packages.php file.
     *
     * @return string
     */
    public function getCachedPackagesPath() {
        return $this->bootstrapPath() . '/cache/packages.php';
    }

    /**
     * Determine if the application configuration is cached.
     *
     * @return bool
     */
    public function configurationIsCached() {
        return file_exists($this->getCachedConfigPath());
    }

    /**
     * Get the path to the configuration cache file.
     *
     * @return string
     */
    public function getCachedConfigPath() {
        return $this->bootstrapPath() . '/cache/config.php';
    }

    /**
     * Determine if the application routes are cached.
     *
     * @return bool
     */
    public function routesAreCached() {
        return $this['files']->exists($this->getCachedRoutesPath());
    }

    /**
     * Get the path to the routes cache file.
     *
     * @return string
     */
    public function getCachedRoutesPath() {
        return $this->bootstrapPath() . '/cache/routes.php';
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     */
    public function isDownForMaintenance() {
        return file_exists($this->storagePath() . '/framework/down');
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @param  int     $code
     * @param  string  $message
     * @param  array   $headers
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function abort($code, $message = '', array $headers = []) {
        if ($code == 404) {
            throw new NotFoundHttpException($message);
        }

        throw new HttpException($code, $message, null, $headers);
    }

    /**
     * Register a terminating callback with the application.
     * <br>注册应用的中止回调
     * @param  \Closure  $callback
     * @return $this
     */
    public function terminating(Closure $callback) {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Terminate the application.
     * <br>中止应用
     * @return void
     */
    public function terminate() {
        foreach ($this->terminatingCallbacks as $terminating) {
            $this->call($terminating);
        }
    }

    /**
     * Get the service providers that have been loaded.
     *
     * @return array
     */
    public function getLoadedProviders() {
        return $this->loadedProviders;
    }

    /**
     * Get the application's deferred services.
     *
     * @return array
     */
    public function getDeferredServices() {
        return $this->deferredServices;
    }

    /**
     * Set the application's deferred services.
     *
     * @param  array  $services
     * @return void
     */
    public function setDeferredServices(array $services) {
        $this->deferredServices = $services;
    }

    /**
     * Add an array of services to the application's deferred services.
     *
     * @param  array  $services
     * @return void
     */
    public function addDeferredServices(array $services) {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }

    /**
     * Determine if the given service is a deferred service.
     *
     * @param  string  $service
     * @return bool
     */
    public function isDeferredService($service) {
        return isset($this->deferredServices[$service]);
    }

    /**
     * Configure the real-time facade namespace.
     * <br>配置实时的facades
     * @param  string  $namespace
     * @return void
     */
    public function provideFacades($namespace) {
        AliasLoader::setFacadeNamespace($namespace);
    }

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function getLocale() {
        return $this['config']->get('app.locale');
    }

    /**
     * Set the current application locale.
     *
     * @param  string  $locale
     * @return void
     */
    public function setLocale($locale) {
        $this['config']->set('app.locale', $locale);

        $this['translator']->setLocale($locale);

        $this['events']->dispatch(new Events\LocaleUpdated($locale));
    }

    /**
     * Determine if application locale is the given locale.
     *
     * @param  string  $locale
     * @return bool
     */
    public function isLocale($locale) {
        return $this->getLocale() == $locale;
    }

    /**
     * Register the core class aliases in the container.
     * <br>注册核心的容器服务别名
     * @return void
     */
    public function registerCoreContainerAliases() {
        foreach ([
    'app' => [\Illuminate\Foundation\Application::class, \Illuminate\Contracts\Container\Container::class, \Illuminate\Contracts\Foundation\Application::class, \Psr\Container\ContainerInterface::class],
    'auth' => [\Illuminate\Auth\AuthManager::class, \Illuminate\Contracts\Auth\Factory::class],
    'auth.driver' => [\Illuminate\Contracts\Auth\Guard::class],
    'blade.compiler' => [\Illuminate\View\Compilers\BladeCompiler::class],
    'cache' => [\Illuminate\Cache\CacheManager::class, \Illuminate\Contracts\Cache\Factory::class],
    'cache.store' => [\Illuminate\Cache\Repository::class, \Illuminate\Contracts\Cache\Repository::class],
    'config' => [\Illuminate\Config\Repository::class, \Illuminate\Contracts\Config\Repository::class],
    'cookie' => [\Illuminate\Cookie\CookieJar::class, \Illuminate\Contracts\Cookie\Factory::class, \Illuminate\Contracts\Cookie\QueueingFactory::class],
    'encrypter' => [\Illuminate\Encryption\Encrypter::class, \Illuminate\Contracts\Encryption\Encrypter::class],
    'db' => [\Illuminate\Database\DatabaseManager::class],
    'db.connection' => [\Illuminate\Database\Connection::class, \Illuminate\Database\ConnectionInterface::class],
    'events' => [\Illuminate\Events\Dispatcher::class, \Illuminate\Contracts\Events\Dispatcher::class],
    'files' => [\Illuminate\Filesystem\Filesystem::class],
    'filesystem' => [\Illuminate\Filesystem\FilesystemManager::class, \Illuminate\Contracts\Filesystem\Factory::class],
    'filesystem.disk' => [\Illuminate\Contracts\Filesystem\Filesystem::class],
    'filesystem.cloud' => [\Illuminate\Contracts\Filesystem\Cloud::class],
    'hash' => [\Illuminate\Hashing\HashManager::class],
    'hash.driver' => [\Illuminate\Contracts\Hashing\Hasher::class],
    'translator' => [\Illuminate\Translation\Translator::class, \Illuminate\Contracts\Translation\Translator::class],
    'log' => [\Illuminate\Log\LogManager::class, \Psr\Log\LoggerInterface::class],
    'mailer' => [\Illuminate\Mail\Mailer::class, \Illuminate\Contracts\Mail\Mailer::class, \Illuminate\Contracts\Mail\MailQueue::class],
    'auth.password' => [\Illuminate\Auth\Passwords\PasswordBrokerManager::class, \Illuminate\Contracts\Auth\PasswordBrokerFactory::class],
    'auth.password.broker' => [\Illuminate\Auth\Passwords\PasswordBroker::class, \Illuminate\Contracts\Auth\PasswordBroker::class],
    'queue' => [\Illuminate\Queue\QueueManager::class, \Illuminate\Contracts\Queue\Factory::class, \Illuminate\Contracts\Queue\Monitor::class],
    'queue.connection' => [\Illuminate\Contracts\Queue\Queue::class],
    'queue.failer' => [\Illuminate\Queue\Failed\FailedJobProviderInterface::class],
    'redirect' => [\Illuminate\Routing\Redirector::class],
    'redis' => [\Illuminate\Redis\RedisManager::class, \Illuminate\Contracts\Redis\Factory::class],
    'request' => [\Illuminate\Http\Request::class, \Symfony\Component\HttpFoundation\Request::class],
    'router' => [\Illuminate\Routing\Router::class, \Illuminate\Contracts\Routing\Registrar::class, \Illuminate\Contracts\Routing\BindingRegistrar::class],
    'session' => [\Illuminate\Session\SessionManager::class],
    'session.store' => [\Illuminate\Session\Store::class, \Illuminate\Contracts\Session\Session::class],
    'url' => [\Illuminate\Routing\UrlGenerator::class, \Illuminate\Contracts\Routing\UrlGenerator::class],
    'validator' => [\Illuminate\Validation\Factory::class, \Illuminate\Contracts\Validation\Factory::class],
    'view' => [\Illuminate\View\Factory::class, \Illuminate\Contracts\View\Factory::class],
        ] as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush() {
        parent::flush();

        $this->buildStack = [];
        $this->loadedProviders = [];
        $this->bootedCallbacks = [];
        $this->bootingCallbacks = [];
        $this->deferredServices = [];
        $this->reboundCallbacks = [];
        $this->serviceProviders = [];
        $this->resolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->globalResolvingCallbacks = [];
    }

    /**
     * Get the application namespace.
     * <br>获取应用的命名空间
     * @return string
     *
     * @throws \RuntimeException
     */
    public function getNamespace() {
        if (!is_null($this->namespace)) {
            return $this->namespace;
        }

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path) {
            foreach ((array) $path as $pathChoice) {
                if (realpath(app_path()) == realpath(base_path() . '/' . $pathChoice)) {
                    return $this->namespace = $namespace;
                }
            }
        }

        throw new RuntimeException('Unable to detect application namespace.');
    }

}
