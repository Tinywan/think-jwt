<?php
/**
 * @desc JWT.php 描述信息
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/2/21 9:45
 */
declare(strict_types=1);

namespace tinywan;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as BaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use tinywan\exception\JWTCacheTokenException;
use tinywan\exception\JWTRefreshTokenExpiredException;
use tinywan\exception\JWTStoreRefreshTokenExpiredException;
use tinywan\exception\JWTTokenException;
use tinywan\exception\JWTConfigException;
use tinywan\exception\JWTTokenExpiredException;
use UnexpectedValueException;

class JWT
{
    /**
     * access_token.
     */
    private const ACCESS_TOKEN = 1;

    /**
     * refresh_token.
     */
    private const REFRESH_TOKEN = 2;

    /**
     * @desc: 获取当前登录ID
     * @throws JwtTokenException
     * @return mixed
     * @author Tinywan(ShaoBo Wan)
     */
    public static function getCurrentId()
    {
        return self::getExtendVal('id') ?? 0;
    }

    /**
     * @desc: 获取当前获取角色code
     * @throws JwtTokenException
     * @return mixed
     * @author Tinywan(ShaoBo Wan)
     */
    public static function getCurrentRoleCode()
    {
        return self::getExtendVal('role') ?? 0;
    }

    /**
     * @desc: 获取当前获取角色id
     * @throws JwtTokenException
     * @return mixed
     * @author Tinywan(ShaoBo Wan)
     */
    public static function getCurrentRoleId()
    {
        return self::getExtendVal('role_id') ?? 0;
    }

    /**
     * @desc: 获取当前用户信息
     * @return array
     * @author Tinywan(ShaoBo Wan)
     */
    public static function getUser(): array
    {
        $config = self::_getConfig();
        if (is_callable($config['user_model'])) {
            return $config['user_model'](self::getCurrentId()) ?? [];
        }
        return [];
    }

    /**
     * @desc: 获取指定令牌扩展内容字段的值
     *
     * @param string $val
     * @return mixed|string
     * @throws JwtTokenException
     */
    public static function getExtendVal(string $val)
    {
        return self::getTokenExtend()[$val] ?? '';
    }

    /**
     * @desc 获取指定令牌扩展内容
     * @return array
     * @throws JwtTokenException
     */
    public static function getExtend(): array
    {
        return self::getTokenExtend();
    }

    /**
     * @desc: 刷新令牌
     * @return array|string[]
     * @throws JWTTokenException
     */
    public static function refreshToken(): array
    {
        $refreshToken = self::getTokenFromHeaders();
        try {
            $config = self::_getConfig();
            $extend = self::verifyToken($config, $refreshToken, self::REFRESH_TOKEN);
            if (isset($config['refresh_is_store']) && $config['refresh_is_store'] === true) {
                self::checkStoreRefreshToken((string) $extend['extend']['id'], $refreshToken);
            }
        } catch (SignatureInvalidException $signatureInvalidException) {
            throw new JWTRefreshTokenExpiredException('刷新令牌无效');
        } catch (BeforeValidException $beforeValidException) {
            throw new JWTRefreshTokenExpiredException('刷新令牌尚未生效');
        } catch (ExpiredException $expiredException) {
            throw new JWTRefreshTokenExpiredException('刷新令牌会话已过期，请再次登录！');
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new JWTRefreshTokenExpiredException('刷新令牌获取的扩展字段不存在');
        } catch (JWTStoreRefreshTokenExpiredException $expiredException) {
            throw new JWTRefreshTokenExpiredException('存储刷新令牌会话已过期，请再次登录！');
        } catch (JwtCacheTokenException | \Exception $exception) {
            throw new JWTRefreshTokenExpiredException($exception->getMessage());
        }
        $secretKey = self::getPrivateKey($config);
        $extend['exp'] = time() + $config['access_exp'];
        return ['access_token' => self::makeToken($extend, $secretKey, $config['algorithms'])];
    }

    /**
     * @desc: 生成令牌.
     * @param array $extend
     * @return array
     * @throws JWTConfigException
     */
    public static function generateToken(array $extend): array
    {
        if (!isset($extend['id'])) {
            throw new JWTTokenException('缺少全局唯一字段：id');
        }
        $config = self::_getConfig();
        $config['access_exp'] = $extend['access_exp'] ?? $config['access_exp'];
        $config['refresh_exp'] = $extend['refresh_exp'] ?? $config['refresh_exp'];
        $payload = self::generatePayload($config, $extend);
        $secretKey = self::getPrivateKey($config);
        $token = [
            'token_type' => 'Bearer',
            'expires_in' => $config['access_exp'],
            'access_token' => self::makeToken($payload['accessPayload'], $secretKey, $config['algorithms'])
        ];
        if (!isset($config['refresh_disable']) || (isset($config['refresh_disable']) && $config['refresh_disable'] === false)) {
            $refreshSecretKey = self::getPrivateKey($config, self::REFRESH_TOKEN);
            $token['refresh_token'] = self::makeToken($payload['refreshPayload'], $refreshSecretKey, $config['algorithms']);
            if (isset($config['refresh_is_store']) && $config['refresh_is_store'] === true) {
                RedisHandler::setRefreshToken((string) $extend['id'], $token['refresh_token'], $config['refresh_exp']);
            }
        }
        return $token;
    }

    /**
     * @desc: 验证令牌
     * @param int $tokenType
     * @param string|null $token
     * @return array
     * @throws JWTTokenException
     * @author Tinywan(ShaoBo Wan)
     */
    public static function verify(int $tokenType = self::ACCESS_TOKEN, string $token = null): array
    {
        try {
            $token = $token ?? self::getTokenFromHeaders();
            $config = self::_getConfig();
            $extend = self::verifyToken($config, $token, $tokenType);
            if (isset($config['access_force_exp'])) {
                self::isForceExpire($extend['iat'], $extend['exp'], $config['access_force_exp']);
            }
            return $extend;
        } catch (SignatureInvalidException $signatureInvalidException) {
            throw new JWTTokenException('身份验证令牌无效');
        } catch (BeforeValidException $beforeValidException) {
            throw new JWTTokenException('身份验证令牌尚未生效');
        } catch (ExpiredException $expiredException) {
            throw new JWTTokenExpiredException('身份验证会话已过期，请重新登录！');
        } catch (JWTRefreshTokenExpiredException $forceExpiredException) {
            throw new JWTRefreshTokenExpiredException('身份验证会话已过期，请重新登录！(暴力)');
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new JWTTokenException('获取的扩展字段不存在');
        } catch (JWTCacheTokenException | \Exception $exception) {
            throw new JWTTokenException($exception->getMessage());
        }
    }

    /**
     * @desc: 获取扩展字段.
     * @return array
     * @throws JwtTokenException
     */
    private static function getTokenExtend(): array
    {
        return (array) self::verify()['extend'];
    }

    /**
     * @desc: 获令牌有效期剩余时长.
     * @param int $tokenType
     * @return int
     */
    public static function getTokenExp(int $tokenType = self::ACCESS_TOKEN): int
    {
        return (int) self::verify($tokenType)['exp'] - time();
    }

    /**
     * @desc:
     * @return string
     * @throws JwtTokenException
     */
    private static function getTokenFromHeaders(): string
    {
        $authorization = request()->header('authorization');
        if (!$authorization || 'undefined' == $authorization) {
            throw new JWTTokenException('身份验证会话已过期，请重新登录！');
        }

        if (self::REFRESH_TOKEN != substr_count($authorization, '.')) {
            throw new JWTTokenException('身份验证会话已过期，请重新登录！');
        }

        if (2 != count(explode(' ', $authorization))) {
            throw new JWTTokenException('Bearer验证中的凭证格式有误，中间必须有个空格');
        }

        [$type, $token] = explode(' ', $authorization);
        if ('Bearer' !== $type) {
            throw new JWTTokenException('接口认证方式需为Bearer');
        }
        if (!$token || 'undefined' === $token) {
            throw new JWTTokenException('尝试获取的Authorization信息不存在');
        }

        return $token;
    }

    /**
     * @desc: 校验令牌
     * @param array $config
     * @param string $token
     * @param int $tokenType
     * @return array
     * @author Tinywan(ShaoBo Wan)
     */
    private static function verifyToken(array $config, string $token, int $tokenType): array
    {
        $publicKey = self::ACCESS_TOKEN == $tokenType ? self::getPublicKey($config['algorithms']) : self::getPublicKey($config['algorithms'], self::REFRESH_TOKEN);
        BaseJWT::$leeway = $config['leeway'];

        $decoded = BaseJWT::decode($token, new Key($publicKey, $config['algorithms']));
        $token = json_decode(json_encode($decoded), true);
        if ($config['is_single_device']) {
            RedisHandler::verifyToken($config['cache_token_pre'], (string) $token['extend']['id'], request()->ip());
        }
        return $token;
    }

    /**
     * @desc: 生成令牌.
     *
     * @param array  $payload    载荷信息
     * @param string $secretKey  签名key
     * @param string $algorithms 算法
     * @return string
     */
    private static function makeToken(array $payload, string $secretKey, string $algorithms): string
    {
        return BaseJWT::encode($payload, $secretKey, $algorithms);
    }

    /**
     * @desc: 获取加密载体
     * @param array $config
     * @param array $extend
     * @return array
     * @author Tinywan(ShaoBo Wan)
     */
    private static function generatePayload(array $config, array $extend): array
    {
        if ($config['is_single_device']) {
            RedisHandler::generateToken([
                'id' => $extend['id'],
                'ip' => request()->ip(),
                'extend' => json_encode($extend),
                'cache_token_ttl' => $config['cache_token_ttl'],
                'cache_token_pre' => $config['cache_token_pre']
            ]);
        }
        $basePayload = [
            'iss' => $config['iss'], // 签发者
            'aud' => $config['iss'], // 接收该JWT的一方
            'iat' => time(), // 签发时间
            'nbf' => time() + ($config['nbf'] ?? 0), // 某个时间点后才能访问
            'exp' => time() + $config['access_exp'], // 过期时间
            'extend' => $extend // 自定义扩展信息
        ];
        $resPayLoad['accessPayload'] = $basePayload;
        $basePayload['exp'] = time() + $config['refresh_exp'];
        $resPayLoad['refreshPayload'] = $basePayload;

        return $resPayLoad;
    }

    /**
     * @desc: 根据签名算法获取【公钥】签名值
     * @param string $algorithm 算法
     * @param int $tokenType 类型
     * @return string
     * @throws JwtConfigException
     */
    private static function getPublicKey(string $algorithm, int $tokenType = self::ACCESS_TOKEN): string
    {
        $config = self::_getConfig();
        switch ($algorithm) {
            case 'HS256':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_secret_key'] : $config['refresh_secret_key'];
                break;
            case 'RS512':
            case 'RS256':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_public_key'] : $config['refresh_public_key'];
                break;
            default:
                $key = $config['access_secret_key'];
        }

        return $key;
    }

    /**
     * @desc: 根据签名算法获取【私钥】签名值
     * @param array $config 配置文件
     * @param int $tokenType 令牌类型
     * @return string
     */
    private static function getPrivateKey(array $config, int $tokenType = self::ACCESS_TOKEN): string
    {
        switch ($config['algorithms']) {
            case 'HS256':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_secret_key'] : $config['refresh_secret_key'];
                break;
            case 'RS512':
            case 'RS256':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_private_key'] : $config['refresh_private_key'];
                break;
            default:
                $key = $config['access_secret_key'];
        }

        return $key;
    }

    /**
     * @desc: 获取配置文件
     * @return array
     * @throws JWTConfigException
     */
    private static function _getConfig(): array
    {
        $config = config('jwt');
        if (empty($config)) {
            throw new JWTConfigException('jwt配置文件不存在');
        }
        return $config;
    }

    /**
     * @desc 存储校验刷新令牌
     * @param string $tokenId
     * @param string $refreshToken
     * @return void
     */
    private static function checkStoreRefreshToken(string $tokenId, string $refreshToken): void
    {
        $storeRefreshToken = RedisHandler::getRefreshToken($tokenId);
        if (false === $storeRefreshToken) {
            throw new JWTStoreRefreshTokenExpiredException('存储刷新令牌已被删除');
        }

        if ($storeRefreshToken != $refreshToken) {
            throw new JWTStoreRefreshTokenExpiredException('存储刷新令牌和请求刷新令牌不一致');
        }
    }

    /**
     * @desc: 删除刷新令牌
     * @param string $tokenId
     * @return int
     */
    public static function deleteRefreshToken(string $tokenId): int
    {
        return RedisHandler::deleteRefreshToken($tokenId);
    }

    /**
     * @desc: 是否强制过期
     * @param int $iat 签发时间
     * @param int $exp 过期时间
     * @param int $forceExpire 强制过期时间
     * @author Tinywan(ShaoBo Wan)
     */
    private static function isForceExpire(int $iat, int $exp, int $forceExpire)
    {
        if (($iat + $forceExpire) < $exp) {
            throw new JWTRefreshTokenExpiredException('暴力提前过期');
        }
    }

}
