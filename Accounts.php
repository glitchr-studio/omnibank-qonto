<?php

namespace Omnibank\Qonto;

use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\Money;

/** Qonto's bank accounts (GET /v2/organization), read as Omnibank's. */
final class Accounts
{
    /** @return list<array<string, mixed>> the organization's bank accounts, as Qonto gives them */
    public static function fetch(Api $api): array
    {
        $organization = $api->get('/v2/organization')['organization'] ?? [];

        return array_values(array_filter((array) ($organization['bank_accounts'] ?? []), 'is_array'));
    }

    public static function account(array $data): Account
    {
        $id = (string) ($data['id'] ?? $data['slug'] ?? $data['iban'] ?? '');

        return new Account(
            id: $id,
            iban: isset($data['iban']) ? (string) $data['iban'] : null,
            bic: isset($data['bic']) ? (string) $data['bic'] : null,
            name: (string) ($data['name'] ?? $data['slug'] ?? $id),
            currency: strtoupper((string) ($data['currency'] ?? 'EUR')),
            type: isset($data['is_external_account']) && $data['is_external_account'] ? 'external' : 'checking',
            raw: $data,
        );
    }

    /** @return list<Balance> booked (balance_cents) and available (authorized_balance_cents) */
    public static function balances(array $data): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));
        $at = isset($data['updated_at']) ? new \DateTimeImmutable((string) $data['updated_at']) : new \DateTimeImmutable();
        $balances = [];
        if (isset($data['balance_cents'])) {
            $balances[] = new Balance(Money::of((int) $data['balance_cents'], $currency), $at, 'booked');
        }
        if (isset($data['authorized_balance_cents'])) {
            $balances[] = new Balance(Money::of((int) $data['authorized_balance_cents'], $currency), $at, 'available');
        }

        return $balances;
    }
}
