<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle;

use GuzzleHttp\Client;
use Iperson1337\NctClientBundle\Gov\NctGovClient;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Identifier\NationalCatalogIdentifier;
use Iperson1337\NctClientBundle\Portal\NctPortalClient;
use Iperson1337\NctClientBundle\RateLimit\NullRateLimiter;
use Iperson1337\NctClientBundle\RateLimit\RedisSlidingWindowRateLimiter;
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Клиент Национального каталога товаров (НКТ) для Symfony.
 *
 * Конфигурация `iperson1337_nct_client` (см. README): хост и ключ портала, таймауты, повторы,
 * лимиты на оба хоста, источник карточки по GTIN, Redis для лимитера. Регистрирует сервисы
 * {@see NctClient}, {@see NctPortalClient}, {@see NctGovClient}, {@see NctHttpClient},
 * {@see RequestDetailsParser}, {@see NationalCatalogIdentifier} — автовайринг по классу.
 */
final class NctClientBundle extends AbstractBundle
{
    protected string $extensionAlias = 'iperson1337_nct_client';

    public const string PORTAL_RATE_LIMITER = 'iperson1337_nct_client.rate_limiter.portal';
    public const string GOV_RATE_LIMITER = 'iperson1337_nct_client.rate_limiter.gov';
    public const string HTTP = 'iperson1337_nct_client.http';

    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line method.notFound (конфигуратор возвращает динамический билдер дерева)
        $definition->rootNode()
            ->children()
                ->arrayNode('portal')
                    ->info('nationalcatalog.kz — API портала, авторизация X-API-KEY')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('host')->defaultValue('https://nationalcatalog.kz')->cannotBeEmpty()->end()
                        ->scalarNode('api_key')->defaultValue('')->end()
                        ->integerNode('timeout')->defaultValue(30)->info('таймаут ответа, секунды')->end()
                        ->integerNode('connect_timeout')->defaultValue(5)->end()
                        ->integerNode('retry_count')->defaultValue(3)->info('повторов после 429, помимо первой попытки')->end()
                        ->integerNode('retry_after_seconds')->defaultValue(30)->info('пауза перед повтором после 429')->end()
                        ->append($this->rateLimitNode(100, 60, 'nct:rate_limit:requests'))
                    ->end()
                ->end()
                ->arrayNode('gov')
                    ->info('nct.gov.kz — публичные выборки без авторизации')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('host')->defaultValue('https://nct.gov.kz')->cannotBeEmpty()->end()
                        ->integerNode('retry_after_seconds')->defaultValue(2)->end()
                        ->append($this->rateLimitNode(2, 1, 'nct:gov:rate_limit:requests'))
                    ->end()
                ->end()
                ->scalarNode('lookup_source')
                    ->defaultValue(GtinLookupSource::NctGov->value)
                    ->info('карточка по GTIN: nctgov (nct.gov.kz) или nationalcatalog (портал)')
                ->end()
                ->scalarNode('redis_dsn')
                    ->defaultNull()
                    ->info('Redis для лимитеров, общий для процессов; null — лимиты выключены')
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $portal = $config['portal'];
        $gov = $config['gov'];

        $services->set(self::HTTP . '.guzzle', Client::class)
            ->args([[
                'timeout' => $portal['timeout'],
                'connect_timeout' => $portal['connect_timeout'],
                'verify' => true,
            ]]);

        $this->registerRateLimiter($container, self::PORTAL_RATE_LIMITER, $config['redis_dsn'], $portal['rate_limit']);
        $this->registerRateLimiter($container, self::GOV_RATE_LIMITER, $config['redis_dsn'], $gov['rate_limit']);

        $services->set(NctHttpClient::class)
            ->args([
                service(self::HTTP . '.guzzle'),
                service('logger'),
                service(self::PORTAL_RATE_LIMITER),
                service(self::GOV_RATE_LIMITER),
                $portal['retry_count'],
                $portal['retry_after_seconds'],
                $gov['retry_after_seconds'],
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-KEY' => $portal['api_key'],
                ],
            ]);

        $services->set(NctPortalClient::class)->args([service(NctHttpClient::class), $portal['host']]);
        $services->set(NctGovClient::class)->args([service(NctHttpClient::class), $gov['host']]);
        $services->set(NctClient::class)->args([service(NctPortalClient::class), service(NctGovClient::class), $config['lookup_source']]);

        $services->set(RequestDetailsParser::class);
        $services->set(NationalCatalogIdentifier::class);
    }

    /**
     * @param array{requests: int, window_seconds: int, redis_key: string} $rateLimit
     */
    private function registerRateLimiter(ContainerConfigurator $container, string $id, ?string $redisDsn, array $rateLimit): void
    {
        if ($redisDsn === null) {
            $container->services()->set($id, NullRateLimiter::class);

            return;
        }

        $container->services()->set($id, RedisSlidingWindowRateLimiter::class)
            ->args([$redisDsn, service('logger'), $rateLimit['requests'], $rateLimit['window_seconds'], $rateLimit['redis_key']]);
    }

    private function rateLimitNode(int $requests, int $windowSeconds, string $redisKey): \Symfony\Component\Config\Definition\Builder\NodeDefinition
    {
        $node = (new \Symfony\Component\Config\Definition\Builder\TreeBuilder('rate_limit'))->getRootNode();
        // @phpstan-ignore-next-line method.notFound
        $node
            ->info('упреждающий лимит: не больше requests запросов за window_seconds (скользящее окно в Redis)')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('requests')->defaultValue($requests)->end()
                ->integerNode('window_seconds')->defaultValue($windowSeconds)->end()
                ->scalarNode('redis_key')->defaultValue($redisKey)->cannotBeEmpty()->end()
            ->end();

        return $node;
    }
}
