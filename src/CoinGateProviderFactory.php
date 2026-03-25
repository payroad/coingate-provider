<?php

declare(strict_types=1);

namespace Payroad\Provider\CoinGate;

use Payroad\Port\Provider\ProviderFactoryInterface;

final class CoinGateProviderFactory implements ProviderFactoryInterface
{
    public function create(array $config): CoinGateProvider
    {
        return new CoinGateProvider(
            $config['api_key'],
            $config['callback_url'],
            $config['base_url'],
        );
    }
}
