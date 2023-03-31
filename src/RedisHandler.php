<?php
/**
 * @desc RedisHanle.php 描述信息
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/3/18 17:13
 */
declare(strict_types=1);

namespace tinywan;

use tinywan\exception\JwtCacheTokenException;
use tinywan\service\RedisService;

class RedisHandler
{
    /**
     * @desc: 生成设备缓存令牌
     * （1）登录时，判断该账号是否在其它设备登录，如果有，就请空之前key清除，
     * （2）重新设置key 。然后存储用户信息和ip地址拼接为key，存储在redis当中
     * @param array $args
     * @author Tinywan(ShaoBo Wan)
     */
    public static function generateToken(array $args): void
    {
        $cacheKey = $args['cache_token_pre'] . $args['id'];
        $key = RedisService::keys($cacheKey . ':*');
        if (!empty($key)) {
            RedisService::del(current($key));
        }
        RedisService::setex($cacheKey . ':' . $args['ip'], $args['cache_token_ttl'], $args['extend']);
    }

    /**
     * @desc: 检查设备缓存令牌
     * @param string $pre
     * @param string $uid
     * @param string $ip
     * @return bool
     * @author Tinywan(ShaoBo Wan)
     */
    public static function verifyToken(string $pre, string $uid, string $ip): bool
    {
        $cacheKey = $pre . $uid . ':' . $ip;
        if (!RedisService::exists($cacheKey)) {
            throw new JwtCacheTokenException('该账号已在其他设备登录，强制下线');
        }
        return true;
    }

    /**
     * @desc: SetRefreshToken stores a refresh token with an expiry time
     * @param string $tokenId
     * @param string $refreshToken
     * @param int $refreshTokenExpire
     * @return bool
     * @author Tinywan(ShaoBo Wan)
     */
    public static function setRefreshToken(string $tokenId, string $refreshToken, int $refreshTokenExpire): bool
    {
        return RedisService::set('JWT:REFRESH_TOKEN:' . $tokenId, $refreshToken, $refreshTokenExpire);
    }

    /**
     * @desc: DeleteRefreshToken used to delete old  refresh tokens
     * @param string $tokenId
     * @return int
     * @author Tinywan(ShaoBo Wan)
     */
    public static function deleteRefreshToken(string $tokenId): int
    {
        return RedisService::del('JWT:REFRESH_TOKEN:' . $tokenId);
    }
}
