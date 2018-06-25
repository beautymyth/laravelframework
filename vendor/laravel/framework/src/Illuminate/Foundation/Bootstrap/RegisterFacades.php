<?php

namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Contracts\Foundation\Application;

class RegisterFacades
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {   
        //清空已解析的实例
        Facade::clearResolvedInstances();
        
        //设置app实例到facade中
        Facade::setFacadeApplication($app);
        
        //获取配置的facade+外部包的facade
        //注册自动加载器
        AliasLoader::getInstance(array_merge(
            $app->make('config')->get('app.aliases', []),
            $app->make(PackageManifest::class)->aliases()
        ))->register();
    }
}
