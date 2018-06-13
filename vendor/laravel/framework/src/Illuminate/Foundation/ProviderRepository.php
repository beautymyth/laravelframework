<?php

namespace Illuminate\Foundation;

use Exception;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;

class ProviderRepository {

    /**
     * The application implementation.
     * <br>当前应用实例
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The filesystem instance.
     * <br>文件操作实例
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * The path to the manifest file.
     * <br>服务清单缓存文件路径
     * @var string
     */
    protected $manifestPath;

    /**
     * Create a new service repository instance.
     * <br>创建服务存储库实例
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @param  string  $manifestPath
     * @return void
     */
    public function __construct(ApplicationContract $app, Filesystem $files, $manifestPath) {
        $this->app = $app;
        $this->files = $files;
        $this->manifestPath = $manifestPath;
    }

    /**
     * Register the application service providers.
     * <br>注册应用的服务提供者
     * @param  array  $providers
     * @return void
     */
    public function load(array $providers) {
        //加载缓存的服务提供者
        //bootstrap/cache/service
        $manifest = $this->loadManifest();

        // First we will load the service manifest, which contains information on all
        // service providers registered with the application and which services it
        // provides. This is used to know which services are "deferred" loaders.
        //第一步，加载服务清单，其中包含应用程序注册的所有服务提供者的信息以及它提供的服务。
        //同时可以知道哪些服务提供者是延迟加载的
        if ($this->shouldRecompile($manifest, $providers)) {
            $manifest = $this->compileManifest($providers);
        }

        // Next, we will register events to load the providers for each of the events
        // that it has requested. This allows the service provider to defer itself
        // while still getting automatically loaded when a certain event occurs.
        // 第二步，为延迟的服务提供者注册自动加载的事件
        // 当某些事件触发时，延迟服务提供者会自动加载
        foreach ($manifest['when'] as $provider => $events) {
            $this->registerLoadEvents($provider, $events);
        }

        // We will go ahead and register all of the eagerly loaded providers with the
        // application so their services can be registered with the application as
        // a provided service. Then we will set the deferred service list on it.
        //第三步，向应用中注册需要即时加载的服务提供者
        foreach ($manifest['eager'] as $provider) {
            $this->app->register($provider);
        }
        //记录需要延迟加载的服务提供者
        $this->app->addDeferredServices($manifest['deferred']);
    }

    /**
     * Load the service provider manifest JSON file.
     * <br>加载缓存的服务提供者(bootstrap/cache/service)
     * @return array|null
     */
    public function loadManifest() {
        // The service manifest is a file containing a JSON representation of every
        // service provided by the application and whether its provider is using
        // deferred loading or should be eagerly loaded on each request to us.
        //服务清单是一个json文件，包含应用中的服务
        //以及它的提供者是使用延迟加载还是应该在每个请求上热加载
        if ($this->files->exists($this->manifestPath)) {
            $manifest = $this->files->getRequire($this->manifestPath);

            if ($manifest) {
                return array_merge(['when' => []], $manifest);
            }
        }
    }

    /**
     * Determine if the manifest should be compiled.
     * <br>确定是否应该编译清单
     * @param  array  $manifest
     * @param  array  $providers
     * @return bool
     */
    public function shouldRecompile($manifest, $providers) {
        return is_null($manifest) || $manifest['providers'] != $providers;
    }

    /**
     * Register the load events for the given provider.
     * <br>为服务提供者注册需要加载的事件
     * @param  string  $provider
     * @param  array  $events
     * @return void
     */
    protected function registerLoadEvents($provider, array $events) {
        if (count($events) < 1) {
            return;
        }

        $this->app->make('events')->listen($events, function () use ($provider) {
            $this->app->register($provider);
        });
    }

    /**
     * Compile the application service manifest file.
     * <br>编译应用服务清单
     * @param  array  $providers
     * @return array
     */
    protected function compileManifest($providers) {
        // The service manifest should contain a list of all of the providers for
        // the application so we can compare it on each request to the service
        // and determine if the manifest should be recompiled or is current.
        //服务清单应该包含应用程序的所有提供程序的列表，
        //以便我们可以在每个请求上对它与服务进行比较，
        //并确定应该重新编译该清单还是当前的清单
        $manifest = $this->freshManifest($providers);

        //循环处理每个服务提供者
        foreach ($providers as $provider) {
            //获取服务提供者实例
            $instance = $this->createProvider($provider);

            // When recompiling the service manifest, we will spin through each of the
            // providers and check if it's a deferred provider or not. If so we'll
            // add it's provided services to the manifest and note the provider.
            if ($instance->isDeferred()) {
                //延迟加载
                
                foreach ($instance->provides() as $service) {
                    //循环服务提供者提供的服务
                    $manifest['deferred'][$service] = $provider;
                }

                $manifest['when'][$provider] = $instance->when();
            } else {
                // If the service providers are not deferred, we will simply add it to an
                // array of eagerly loaded providers that will get registered on every
                // request to this application instead of "lazy" loading every time.
                //非延迟加载
                $manifest['eager'][] = $provider;
            }
        }
        //写入缓存文件
        return $this->writeManifest($manifest);
    }

    /**
     * Create a fresh service manifest data structure.
     * <br>创建一个新的服务清单数据结构
     * @param  array  $providers
     * @return array
     */
    protected function freshManifest(array $providers) {
        return ['providers' => $providers, 'eager' => [], 'deferred' => []];
    }

    /**
     * Write the service manifest file to disk.
     * <br>将服务清单，写入缓存文件
     * @param  array  $manifest
     * @return array
     *
     * @throws \Exception
     */
    public function writeManifest($manifest) {
        if (!is_writable(dirname($this->manifestPath))) {
            throw new Exception('The bootstrap/cache directory must be present and writable.');
        }

        $this->files->put(
                $this->manifestPath, '<?php return ' . var_export($manifest, true) . ';'
        );

        return array_merge(['when' => []], $manifest);
    }

    /**
     * Create a new provider instance.
     * <br>获取服务提供者实例
     * @param  string  $provider
     * @return \Illuminate\Support\ServiceProvider
     */
    public function createProvider($provider) {
        return new $provider($this->app);
    }

}
