<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ConfigAliyunAcm;

use GuzzleHttp;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Hyperf\Utils\Codec\Json;
use Psr\Container\ContainerInterface;
use RuntimeException;

class Client implements ClientInterface
{
    /**
     * @var array
     */
    public $fetchConfig;

    /**
     * @var null|GuzzleHttp\Client
     */
    private $client;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $servers;

    /**
     * @var array[]
     */
    private $cachedSecurityCredentials = [];

    public function __construct(ContainerInterface $container)
    {
        $clientFactory = $container->get(GuzzleClientFactory::class);
        $this->client = $clientFactory->create();
        $this->config = $container->get(ConfigInterface::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
    }

    public function pull(): array
    {
        $client = $this->client;
        if (! $client instanceof GuzzleHttp\Client) {
            throw new RuntimeException('aliyun acm: Invalid http client.');
        }

        // aliyun_acm.group
        $groups = $this->config->get('aliyun_acm.group', '');
        if (empty($groups)) {
            return [];
        }

        //获取服务器
        if (! $this->servers) {
            $endpoint = $this->config->get('aliyun_acm.endpoint', 'acm.aliyun.com');
            // server list
            $response = $this->client->get("http://{$endpoint}:8080/diamond-server/diamond");
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Get server list failed from Aliyun ACM.');
            }
            $this->servers = array_filter(explode("\n", $response->getBody()->getContents()));
        }

        // ACM config.key
        $accessKey  = $this->config->get('aliyun_acm.access_key', '');
        $secretKey  = $this->config->get('aliyun_acm.secret_key', '');
        $ecsRamRole = (string) $this->config->get('aliyun_acm.ecs_ram_role', '');

        //获取RAM认证
        $securityToken = [];
        if (empty($accessKey) && ! empty($ecsRamRole)) {
            $securityCredentials = $this->getSecurityCredentialsWithEcsRamRole($ecsRamRole);
            if (! empty($securityCredentials)) {
                $accessKey = $securityCredentials['AccessKeyId'];
                $secretKey = $securityCredentials['AccessKeySecret'];
                $securityToken = [
                    'Spas-SecurityToken' => $securityCredentials['SecurityToken'],
                ];
            }
        }

        $config = [];
        // 支持多分组拉取
        $groups = explode(',', $groups);
        foreach ($groups as $group) {
            $cfg = $this->getConfigByGroup($group, $accessKey, $secretKey, $securityToken);
            if ($cfg) {
                $config = array_merge($config, $cfg);
            }
        }

        return $config;
    }

    private function getConfigByGroup($group, $accessKey, $secretKey, $securityToken)
    {
        // ACM config
        $namespace  = $this->config->get('aliyun_acm.namespace', '');
        $dataId     = $this->config->get('aliyun_acm.data_id', '');


        // Sign
        $timestamp = round(microtime(true) * 1000);
        $sign = base64_encode(hash_hmac('sha1', "{$namespace}+{$group}+{$timestamp}", $secretKey, true));

        $server = $this->servers[array_rand($this->servers)];
        // Get config
        try{
            $response = $this->client->get("http://{$server}:8080/diamond-server/config.co", [
                'headers' => array_merge([
                    'Spas-AccessKey' => $accessKey,
                    'timeStamp' => $timestamp,
                    'Spas-Signature' => $sign,
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ], $securityToken),
                'query' => [
                    'tenant' => $namespace,
                    'dataId' => $dataId,
                    'group' => $group,
                ],
            ]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() == 404) {
                $this->logger->warning(sprintf('Config Group[%s] Not Exists', $group));
            } else {
                $this->logger->warning(sprintf('%s[line:%d] in %s', $e->getMessage(), $e->getLine(), $e->getFile()));
            }
            return [];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('%s[line:%d] in %s', $e->getMessage(), $e->getLine(), $e->getFile()));
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Get config failed from Aliyun ACM.');
            return [];
        }
        $content = $response->getBody()->getContents();
        if (! $content) {
            return [];
        }
        return Json::decode($content);
    }

    /**
     * Get ECS RAM authorization.
     * @see https://help.aliyun.com/document_detail/72013.html
     * @see https://help.aliyun.com/document_detail/54579.html?#title-9w8-ufj-kz6
     */
    private function getSecurityCredentialsWithEcsRamRole(string $ecsRamRole): ?array
    {
        $securityCredentials = $this->cachedSecurityCredentials[$ecsRamRole] ?? null;
        if (! empty($securityCredentials) && time() > strtotime($securityCredentials['Expiration']) - 60) {
            $securityCredentials = null;
        }
        if (empty($securityCredentials)) {
            $response = $this->client->get('http://100.100.100.200/latest/meta-data/ram/security-credentials/' . $ecsRamRole);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Get config failed from Aliyun ACM.');
            }
            $securityCredentials = Json::decode($response->getBody()->getContents());
            if (! empty($securityCredentials)) {
                $this->cachedSecurityCredentials[$ecsRamRole] = $securityCredentials;
            }
        }
        return $securityCredentials;
    }
}
