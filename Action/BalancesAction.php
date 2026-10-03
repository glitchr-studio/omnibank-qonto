<?php

namespace Omnibank\Qonto\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\ProviderException;
use Omnibank\Qonto\Accounts;
use Omnibank\Qonto\Api;
use Omnibank\Request\FetchBalances;
use Omnibank\Request\Request;

/** The account's balance (booked) and authorized balance (available), as Qonto has them now. */
final class BalancesAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchBalances;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchBalances);
        foreach (Accounts::fetch($this->api) as $data) {
            if ((string) ($data['id'] ?? $data['slug'] ?? $data['iban'] ?? '') === $request->account->id) {
                $request->setResult(Accounts::balances($data));

                return;
            }
        }

        throw new ProviderException('qonto', \sprintf('The organization has no account "%s".', $request->account->id));
    }
}
