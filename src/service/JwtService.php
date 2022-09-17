<?php
/**
 * @desc Service
 * @author Tinywan(ShaoBo Wan)
 * @email 756684177@qq.com
 * @date 2022/9/11 15:48
 */

declare(strict_types=1);

namespace tinywan\service;

use think\Service;

class JwtService extends Service
{
    /**
     * @desc 启动方法，在所有的系统服务注册完成之后调用，用于定义启动某个系统服务之前需要做的操作
     */
    public function boot()
    {
        $this->commands(['tinywan:jwt' => \tinywan\command\Publish::class]);
    }

    /**
     * Merge the given configuration with the existing configuration.
     *
     * @param string $path
     * @param string $key
     *
     * @return void
     */
    protected function mergeConfigFrom(string $path, string $key)
    {
        $config = $this->app->config->get($key, []);

        $this->app->config->set(array_merge(require $path, $config), $key);
    }
}
