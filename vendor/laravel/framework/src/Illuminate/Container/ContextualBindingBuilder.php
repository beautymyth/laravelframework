<?php

namespace Illuminate\Container;

use Illuminate\Contracts\Container\ContextualBindingBuilder as ContextualBindingBuilderContract;

class ContextualBindingBuilder implements ContextualBindingBuilderContract {

    /**
     * The underlying container instance.
     * <br>服务容器
     * @var \Illuminate\Container\Container
     */
    protected $container;

    /**
     * The concrete instance.
     * <br>具体类型
     * @var string
     */
    protected $concrete;

    /**
     * The abstract target.
     * <br>抽象目标
     * @var string
     */
    protected $needs;

    /**
     * Create a new contextual binding builder.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  string  $concrete
     * @return void
     */
    public function __construct(Container $container, $concrete) {
        $this->concrete = $concrete;
        $this->container = $container;
    }

    /**
     * Define the abstract target that depends on the context.
     * <br>上下文依赖的抽象类型
     * @param  string  $abstract
     * @return $this
     */
    public function needs($abstract) {
        $this->needs = $abstract;
        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     * <br>抽象类型的实现
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function give($implementation) {
        //将上下文绑定添加到容器中
        $this->container->addContextualBinding(
                $this->concrete, $this->needs, $implementation
        );
    }

}
