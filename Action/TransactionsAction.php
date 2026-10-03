<?php

namespace Omnibank\Qonto\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Money;
use Omnibank\Qonto\Api;
use Omnibank\Request\FetchTransactions;
use Omnibank\Request\Request;

/**
 * The account's settled transactions (GET /v2/transactions by IBAN, status
 * completed, settled_at between the dates), every page (meta.next_page).
 * side "debit" is money gone out: the amount is made negative. Qonto's label
 * is the counterparty's name; the transfer's IBAN comes with it when Qonto
 * includes the transfer.
 */
final class TransactionsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const PER_PAGE = 100;
    private const MAX_PAGES = 1000;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransactions;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransactions);
        $iban = $request->account->iban ?? throw new ProviderException('qonto', 'Qonto lists transactions by IBAN: the account has none.');
        $query = [
            'iban' => $iban,
            'status' => ['completed'],
            'settled_at_from' => $request->since ? \DateTimeImmutable::createFromInterface($request->since)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z') : null,
            'settled_at_to' => $request->until ? \DateTimeImmutable::createFromInterface($request->until)->setTime(23, 59, 59, 999000)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z') : null,
            'sort_by' => 'settled_at:asc',
            'includes' => ['transfer'],
            'per_page' => self::PER_PAGE,
        ];

        $transactions = [];
        $page = 1;
        for ($i = 0; null !== $page && $i < self::MAX_PAGES; ++$i) {
            $data = $this->api->get('/v2/transactions', $query + ['current_page' => $page]);
            foreach ((array) ($data['transactions'] ?? []) as $line) {
                if (\is_array($line) && null !== ($transaction = self::transaction($line, $request->account->currency)) && $request->covers($transaction->bookedOn)) {
                    $transactions[] = $transaction;
                }
            }
            $next = $data['meta']['next_page'] ?? null;
            $page = null !== $next && (int) $next > $page ? (int) $next : null;
        }
        usort($transactions, static fn (BankTransaction $a, BankTransaction $b) => $a->bookedOn <=> $b->bookedOn);

        $request->setResult($transactions);
    }

    private static function transaction(array $line, string $currency): ?BankTransaction
    {
        $date = $line['settled_at'] ?? null;
        if (!\is_string($date) || '' === $date) {
            return null;
        }
        $cents = (int) ($line['amount_cents'] ?? round(((float) ($line['amount'] ?? 0)) * 100));
        $amount = Money::of('debit' === ($line['side'] ?? null) ? -abs($cents) : abs($cents), (string) ($line['currency'] ?? $currency));
        $transfer = \is_array($line['transfer'] ?? null) ? $line['transfer'] : [];
        $label = (string) ($line['label'] ?? '');

        return new BankTransaction(
            id: (string) ($line['transaction_id'] ?? $line['id'] ?? ''),
            bookedOn: new \DateTimeImmutable($date),
            valueOn: null,
            amount: $amount,
            label: $label,
            counterpartyName: $transfer['counterparty_account_name'] ?? ('' !== $label ? $label : null),
            counterpartyIban: $transfer['counterparty_account_number'] ?? null,
            reference: isset($line['reference']) && '' !== $line['reference'] ? (string) $line['reference'] : null,
            raw: $line,
        );
    }
}
