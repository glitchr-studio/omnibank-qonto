<?php

namespace Omnibank\Qonto\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Qonto\Accounts;
use Omnibank\Qonto\Api;
use Omnibank\Request\FetchAccounts;
use Omnibank\Request\Request;

/** The organization's bank accounts. */
final class AccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $request->setResult(array_map(Accounts::account(...), Accounts::fetch($this->api)));
    }
}
