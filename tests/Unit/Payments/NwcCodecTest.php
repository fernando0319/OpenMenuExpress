<?php

namespace Tests\Unit\Payments;

use App\Payments\Nwc\NwcCodec;
use App\Payments\Nwc\NwcConnection;
use App\Payments\PaymentException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * Talks to a wallet simulated with real keys and real encryption, as Alby Hub or Phoenixd would.
 */
class NwcCodecTest extends TestCase
{
    private string $walletSecret;

    private string $walletPubkey;

    private NwcConnection $connection;

    protected function setUp(): void
    {
        $key = new Key;
        $this->walletSecret = $key->generatePrivateKey();
        $this->walletPubkey = $key->getPublicKey($this->walletSecret);
        $clientSecret = $key->generatePrivateKey();

        $this->connection = NwcConnection::parse(
            "nostr+walletconnect://{$this->walletPubkey}?relay=wss%3A%2F%2Frelay.one.test&relay=wss://relay.two.test&secret={$clientSecret}&lud16=loja@carteira.test",
        );
    }

    public function test_it_reads_the_connection_string(): void
    {
        $this->assertSame(['wss://relay.one.test', 'wss://relay.two.test'], $this->connection->relays);
        $this->assertSame('loja@carteira.test', $this->connection->label());
        $this->assertSame(64, strlen($this->connection->clientPubkey()));

        $this->expectException(InvalidArgumentException::class);
        NwcConnection::parse('nostr+walletconnect://'.$this->walletPubkey.'?secret=abc');
    }

    #[DataProvider('encryptions')]
    public function test_a_request_reaches_the_wallet_and_its_answer_comes_back(string $encryption): void
    {
        $request = NwcCodec::request($this->connection, 'make_invoice', ['amount' => 10_000_000, 'description' => 'Pedido 7'], $encryption);

        // The wallet checks the signature, sees it is for it, and reads the request.
        $this->assertTrue($request->verify());
        $this->assertSame(NwcCodec::REQUEST_KIND, $request->getKind());
        $this->assertSame($this->connection->clientPubkey(), $request->getPublicKey());
        $this->assertContains(['p', $this->walletPubkey], $request->getTags());
        $payload = json_decode($this->walletDecrypt($request->getContent(), $encryption), true);
        $this->assertSame(['method' => 'make_invoice', 'params' => ['amount' => 10_000_000, 'description' => 'Pedido 7']], $payload);

        $answer = $this->walletAnswer($request->getId(), ['result_type' => 'make_invoice', 'result' => ['invoice' => 'lnbc100u1test', 'payment_hash' => str_repeat('ab', 32)]], $encryption);

        $this->assertSame(
            ['invoice' => 'lnbc100u1test', 'payment_hash' => str_repeat('ab', 32)],
            NwcCodec::response($this->connection, $answer, $request->getId()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function encryptions(): array
    {
        return ['nip44' => [NwcCodec::NIP44], 'nip04' => [NwcCodec::NIP04]];
    }

    public function test_refusals_and_forged_answers_are_errors(): void
    {
        $request = NwcCodec::request($this->connection, 'lookup_invoice', ['payment_hash' => 'x'], NwcCodec::NIP44);

        $refusal = $this->walletAnswer($request->getId(), ['result_type' => 'lookup_invoice', 'error' => ['code' => 'NOT_FOUND', 'message' => 'invoice not found']], NwcCodec::NIP44);

        try {
            NwcCodec::response($this->connection, $refusal, $request->getId());
            $this->fail('A refusal must be an error.');
        } catch (PaymentException $exception) {
            $this->assertSame(404, $exception->getCode());
            $this->assertStringContainsString('NOT_FOUND', $exception->getMessage());
        }

        // An answer to another request, or with its content changed, is not accepted.
        $other = $this->walletAnswer(str_repeat('0', 64), ['result' => []], NwcCodec::NIP44);
        $tampered = $this->walletAnswer($request->getId(), ['result' => ['invoice' => 'a']], NwcCodec::NIP44);
        $tampered->content = NwcCodec::encrypt('{"result":{"invoice":"b"}}', $this->connection, NwcCodec::NIP44);

        foreach ([$other, $tampered] as $answer) {
            try {
                NwcCodec::response($this->connection, $answer, $request->getId());
                $this->fail('A forged answer must be refused.');
            } catch (PaymentException $exception) {
                $this->assertSame('Resposta da carteira inválida.', $exception->getMessage());
            }
        }
    }

    public function test_it_reads_what_the_wallet_announces(): void
    {
        $info = (new Event)->setKind(NwcCodec::INFO_KIND)->setCreatedAt(time())
            ->setContent('pay_invoice make_invoice lookup_invoice get_info')
            ->setTags([['encryption', 'nip44_v2 nip04']]);
        (new Sign)->signEvent($info, $this->walletSecret);

        $read = NwcCodec::info($this->connection, json_decode($info->toJson()));

        $this->assertContains('make_invoice', $read['methods']);
        $this->assertSame(NwcCodec::NIP44, NwcCodec::preferredEncryption($read['encryptions']));
        $this->assertSame(NwcCodec::NIP04, NwcCodec::preferredEncryption(['nip04']));
    }

    private function walletDecrypt(string $content, string $encryption): string
    {
        $client = $this->connection->clientPubkey();

        return $encryption === NwcCodec::NIP44
            ? Nip44::decrypt($content, Nip44::getConversationKey($this->walletSecret, $client))
            : Nip04::decrypt($content, $this->walletSecret, $client);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function walletAnswer(string $requestId, array $body, string $encryption): object
    {
        $client = $this->connection->clientPubkey();
        $json = (string) json_encode($body);

        $event = (new Event)->setKind(NwcCodec::RESPONSE_KIND)->setCreatedAt(time())
            ->setTags([['p', $client], ['e', $requestId]])
            ->setContent($encryption === NwcCodec::NIP44
                ? Nip44::encrypt($json, Nip44::getConversationKey($this->walletSecret, $client))
                : Nip04::encrypt($json, $this->walletSecret, $client));
        (new Sign)->signEvent($event, $this->walletSecret);

        return json_decode($event->toJson());
    }
}
