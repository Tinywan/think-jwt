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

class JWTService extends Service
{
    /**
     * @desc 启动方法，在所有的系统服务注册完成之后调用，用于定义启动某个系统服务之前需要做的操作
     */
    public function boot()
    {
        $this->commands(['tinywan:jwt' => \tinywan\command\JWTPublish::class]);
    }
}
