<?php

namespace Omnibank\Qonto\Tests;

use Omnibank\Exception\ProviderException;
use Omnibank\Exception\RequestNotSupportedException;
use Omnibank\Exception\UnavailableException;
use Omnibank\GatewayInterface;
use Omnibank\Model\Connection;
use Omnibank\Model\ConsentStatus;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer as TransferModel;
use Omnibank\Qonto\QontoGatewayFactory;
use Omnibank\Request\Notify;
use Omnibank\Request\Transfer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QontoGatewayTest extends TestCase
{
    /** @var list<array{string, string, array}> method, url, headers */
    private array $calls = [];

    private function gateway(?\Closure $answer = null, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->calls[] = [$method, $url, $options['headers']];
            if ($answer) {
                return $answer($method, $url);
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return match ((string) parse_url($url, \PHP_URL_PATH)) {
                '/v2/organization' => new MockResponse(self::fixture('organization.json')),
                '/v2/transactions' => new MockResponse(self::fixture('transactions-page-'.($query['current_page'] ?? 1).'.json')),
                default => new MockResponse('{"errors":[{"code":"not_found","detail":"Not found"}]}', ['http_code' => 404]),
            };
        });

        return (new QontoGatewayFactory($http))->create($options + ['login' => 'nakaya-sas-1234', 'secret_key' => 'sk_qonto']);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    public function testConnectHasNothingToDo(): void
    {
        $result = $this->gateway()->connect(new Connection(), 'https://app.example/back');

        self::assertNull($result->url);
        self::assertSame(ConsentStatus::ACTIVE, $result->connection->consent->status);
        self::assertSame([], $this->calls, 'no call');
    }

    public function testTheOrganizationsAccountsAndBalances(): void
    {
        $gateway = $this->gateway();
        $accounts = $gateway->accounts(new Connection());

        self::assertCount(2, $accounts);
        self::assertSame('7d3c9c4e-2b1f-4a7e-8d61-0b5f1e2a9c01', $accounts[0]->id);
        self::assertSame('FR5116958000011234567890142', $accounts[0]->iban);
        self::assertSame('QNTOFRP1XXX', $accounts[0]->bic);
        self::assertSame('Compte principal', $accounts[0]->name);
        self::assertSame('EUR', $accounts[0]->currency);
        self::assertTrue($accounts[0]->raw['main']);
        self::assertSame('Réserve TVA', $accounts[1]->name);

        [$method, $url, $headers] = $this->calls[0];
        self::assertSame('GET', $method);
        self::assertSame('https://thirdparty.qonto.com/v2/organization', $url);
        self::assertContains('Authorization: nakaya-sas-1234:sk_qonto', $headers, 'login:secret-key, no scheme');

        $balances = $gateway->balances(new Connection(), $accounts[0]);
        self::assertSame(['booked', 'available'], array_map(static fn ($b) => $b->type, $balances));
        self::assertSame([1843217, 1810217], array_map(static fn ($b) => $b->amount->amount, $balances));
        self::assertSame('2026-09-30T21:04:11+00:00', $balances[0]->at->format(\DATE_ATOM));
    }

    public function testTransactionsEveryPageSignedBySide(): void
    {
        $gateway = $this->gateway();
        $account = $gateway->accounts(new Connection())[0];
        $transactions = $gateway->transactions(new Connection(), $account, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertCount(3, $transactions, 'both pages');
        [$credit, $card, $salary] = $transactions;
        self::assertSame('nakaya-sas-1234-1-transaction-1042', $credit->id);
        self::assertTrue($credit->amount->equals(Money::of(125000, 'EUR')));
        self::assertSame('Camille Durand', $credit->counterpartyName);
        self::assertSame('FR1420041010050500013M02606', $credit->counterpartyIban);
        self::assertSame('Facture F-2026-042', $credit->reference);
        self::assertSame('2026-09-02', $credit->bookedOn->format('Y-m-d'));
        self::assertSame(-2340, $card->amount->amount, 'side debit: money gone out');
        self::assertNull($card->counterpartyIban);
        self::assertSame('4242', $card->raw['card_last_digits']);
        self::assertSame(-530000, $salary->amount->amount);

        $transactionCalls = array_values(array_filter($this->calls, static fn ($c) => str_contains($c[1], '/v2/transactions')));
        self::assertCount(2, $transactionCalls);
        $query = (string) parse_url($transactionCalls[0][1], \PHP_URL_QUERY);
        self::assertStringContainsString('iban=FR5116958000011234567890142', $query);
        self::assertStringContainsString('status[]=completed', rawurldecode($query), 'a list as Rails reads it');
        self::assertStringContainsString('settled_at_from=2026-09-01T00%3A00%3A00.000Z', $query);
        self::assertStringContainsString('settled_at_to=2026-09-30T23%3A59%3A59.999Z', $query);
        self::assertStringContainsString('current_page=2', (string) parse_url($transactionCalls[1][1], \PHP_URL_QUERY), 'meta.next_page followed');
    }

    public function testASandboxSendsItsStagingToken(): void
    {
        $gateway = $this->gateway(options: ['sandbox' => true, 'staging_token' => 'stg_x']);
        $gateway->accounts(new Connection());

        self::assertStringStartsWith('https://thirdparty-sandbox.staging.qonto.co/v2/organization', $this->calls[0][1]);
        self::assertContains('X-Qonto-Staging-Token: stg_x', $this->calls[0][2]);
    }

    public function testAnOutageIsNeverNoData(): void
    {
        $gateway = $this->gateway(static fn () => new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]));
        $this->expectException(UnavailableException::class);
        $gateway->accounts(new Connection());
    }

    public function testANetworkFailureIsUnavailable(): void
    {
        $gateway = $this->gateway(static fn () => new MockResponse('', ['error' => 'Could not resolve host']));
        $this->expectException(UnavailableException::class);
        $gateway->accounts(new Connection());
    }

    public function testWrongCredentialsAreTheProvidersRefusal(): void
    {
        $gateway = $this->gateway(static fn () => new MockResponse('{"errors":[{"code":"unauthorized","detail":"Invalid credentials"}]}', ['http_code' => 401]));
        try {
            $gateway->accounts(new Connection());
            self::fail('401');
        } catch (UnavailableException) {
            self::fail('a refusal is not an outage');
        } catch (ProviderException $e) {
            self::assertSame('[qonto] Invalid credentials', $e->getMessage());
            self::assertSame('unauthorized', $e->providerCode);
        }
    }

    public function testNoTransfersNorWebhooksInThisVersion(): void
    {
        $gateway = $this->gateway();
        self::assertFalse($gateway->supports(Transfer::class));
        self::assertFalse($gateway->supports(Notify::class));

        $this->expectException(RequestNotSupportedException::class);
        $gateway->transfer(new Connection(), new TransferModel('FR5116958000011234567890142', 'Lucie Martin', 'FR6010278060410002051020134', null, Money::of(100, 'EUR'), 'x'));
    }
}
