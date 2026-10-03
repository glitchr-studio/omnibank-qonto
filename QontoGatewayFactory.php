<?php

namespace Omnibank\Qonto;

use Omnibank\Config;
use Omnibank\Exception\InvalidConfigException;
use Omnibank\GatewayFactory;
use Omnibank\Qonto\Action\AccountsAction;
use Omnibank\Qonto\Action\BalancesAction;
use Omnibank\Qonto\Action\ConnectAction;
use Omnibank\Qonto\Action\TransactionsAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Qonto: the organization's accounts, their balances and their settled
 * transactions, with an API key - no consent to give, connect() has nothing
 * to do. No transfers in this version.
 *
 *   options:
 *     login: '%env(QONTO_LOGIN)%'            # the organization's slug-like login (Settings → Integrations → API key)
 *     secret_key: '%env(QONTO_SECRET_KEY)%'
 *     sandbox: false                         # true: the sandbox host, with staging_token
 *     staging_token: null                    # the developer portal's X-Qonto-Staging-Token
 *     host: null                             # another host, whatever sandbox says
 */
final class QontoGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'qonto',
            'omnibank.factory_title' => 'Qonto',
            'omnibank.required_options' => ['login', 'secret_key'],
            'sandbox' => false,
            'staging_token' => null,
            'host' => null,
            'omnibank.api' => fn (Config $c) => new Api(
                $this->http ?? (class_exists(HttpClient::class) ? HttpClient::create() : throw new InvalidConfigException('The "qonto" gateway needs an HTTP client: symfony/http-client.')),
                (string) $c['login'],
                (string) $c['secret_key'],
                (string) ($c['host'] ?: ($c['sandbox'] ? Api::SANDBOX_HOST : Api::HOST)),
                $c['staging_token'] ?: null,
            ),
            'omnibank.action.connect' => new ConnectAction(),
            'omnibank.action.accounts' => new AccountsAction(),
            'omnibank.action.balances' => new BalancesAction(),
            'omnibank.action.transactions' => new TransactionsAction(),
        ]);
    }
}
