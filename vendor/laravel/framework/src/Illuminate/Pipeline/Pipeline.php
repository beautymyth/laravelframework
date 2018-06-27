<?php

namespace Illuminate\Pipeline;

use Closure;
use RuntimeException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pipeline\Pipeline as PipelineContract;

class Pipeline implements PipelineContract {

    /**
     * The container implementation.
     * <br>应用实例
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The object being passed through the pipeline.
     * <br>通过管道传递的对象
     * @var mixed
     */
    protected $passable;

    /**
     * The array of class pipes.
     * <br>管道数组
     * @var array
     */
    protected $pipes = [];

    /**
     * The method to call on each pipe.
     * <br>调用每个管道的方法
     * @var string
     */
    protected $method = 'handle';

    /**
     * Create a new class instance.
     * <br>创建一个新的管道实例
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return void
     */
    public function __construct(Container $container = null) {
        $this->container = $container;
    }

    /**
     * Set the object being sent through the pipeline.
     * <br>设置通过管道发送的对象
     * @param  mixed  $passable
     * @return $this
     */
    public function send($passable) {
        $this->passable = $passable;
        return $this;
    }

    /**
     * Set the array of pipes.
     * <br>设置管道数组
     * @param  array|mixed  $pipes
     * @return $this
     */
    public function through($pipes) {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();
        return $this;
    }

    /**
     * Set the method to call on the pipes.
     * <br>设置调用中间件的方法
     * @param  string  $method
     * @return $this
     */
    public function via($method) {
        $this->method = $method;
        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     * <br>拼接最终的回调函数(按中间件顺序执行)
     * @param  \Closure  $destination
     * @return mixed
     */
    public function then(Closure $destination) {
        //生成中间件的嵌套回调，按$this->pipes执行，最后执行prepareDestination中的闭包方法
        $pipeline = array_reduce(
                array_reverse($this->pipes), $this->carry(), $this->prepareDestination($destination)
        );
        //运行嵌套的回调
        return $pipeline($this->passable);
    }

    /**
     * Get the final piece of the Closure onion.
     * <br>获取闭包(洋葱)的最后一块
     * @param  \Closure  $destination
     * @return \Closure
     */
    protected function prepareDestination(Closure $destination) {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * Get a Closure that represents a slice of the application onion.
     * <br>获取每个中间件的调用闭包
     * @return \Closure
     */
    protected function carry() {
        return function ($stack, $pipe) {
            /**
             * $stack：嵌套的闭包
             * $pipe：中间件类名或闭包
             */
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    // If the pipe is an instance of a Closure, we will just call it directly but
                    // otherwise we'll resolve the pipes out of the container and call it with
                    // the appropriate method and arguments, returning the results back out.
                    //如果通道是闭包，则直接运行
                    return $pipe($passable, $stack);
                } elseif (!is_object($pipe)) {
                    list($name, $parameters) = $this->parsePipeString($pipe);
                    // If the pipe is a string we will parse the string and resolve the class out
                    // of the dependency injection container. We can then build a callable and
                    // execute the pipe function giving in the parameters that are required.
                    //如果通道不是对象，需要进行解析
                    $pipe = $this->getContainer()->make($name);

                    //获取传入执行方法的参数
                    $parameters = array_merge([$passable, $stack], $parameters);
                } else {
                    // If the pipe is already an object we'll just make a callable and pass it to
                    // the pipe as-is. There is no need to do any extra parsing and formatting
                    // since the object we're given was already a fully instantiated object.
                    //获取传入执行方法的参数
                    $parameters = [$passable, $stack];
                }

                //调用中间件中的指定方法
                return method_exists($pipe, $this->method) ? $pipe->{$this->method}(...$parameters) : $pipe(...$parameters);
            };
        };
    }

    /**
     * Parse full pipe string to get name and parameters.
     * <br>解析中间件字符串以获取名称和参数
     * @param  string $pipe
     * @return array
     */
    protected function parsePipeString($pipe) {
        list($name, $parameters) = array_pad(explode(':', $pipe, 2), 2, []);
        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }
        return [$name, $parameters];
    }

    /**
     * Get the container instance.
     * <br>获取容器实例
     * @return \Illuminate\Contracts\Container\Container
     * @throws \RuntimeException
     */
    protected function getContainer() {
        if (!$this->container) {
            throw new RuntimeException('A container instance has not been passed to the Pipeline.');
        }
        return $this->container;
    }

}
