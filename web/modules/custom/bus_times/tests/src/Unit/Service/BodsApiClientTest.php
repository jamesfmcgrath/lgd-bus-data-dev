<?php

declare(strict_types=1);

namespace Drupal\Tests\bus_times\Unit\Service;

use Drupal\bus_times\Service\BodsApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BodsApiClient.
 *
 * All HTTP, config, key, and logger dependencies are mocked so that no real
 * network calls or Drupal container are needed.
 *
 * @coversDefaultClass \Drupal\bus_times\Service\BodsApiClient
 * @group bus_times
 */
final class BodsApiClientTest extends UnitTestCase {

  private const BASE_URL = 'https://test.example.com/api/v1';
  private const KEY_ID = 'bods-key';
  private const KEY_VALUE = 'secret-api-key';

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a config factory stub returning predictable values.
   */
  private function makeConfigFactory(string $apiKeyId = self::KEY_ID, string $baseUrl = self::BASE_URL): ConfigFactoryInterface {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['source.api_key_id', $apiKeyId],
      ['source.base_url', $baseUrl],
      ['import.timeout', 30],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('bus_times.settings')
      ->willReturn($settings);

    return $configFactory;
  }

  /**
   * Builds a key repository stub returning the given key value.
   */
  private function makeKeyRepository(string $keyId = self::KEY_ID, string $keyValue = self::KEY_VALUE): KeyRepositoryInterface {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($keyValue);

    $repo = $this->createMock(KeyRepositoryInterface::class);
    $repo->method('getKey')->with($keyId)->willReturn($key);

    return $repo;
  }

  // ---------------------------------------------------------------------------
  // testConnection
  // ---------------------------------------------------------------------------

  /**
   * A 200 response returns success with the dataset count from the body.
   *
   * @covers ::testConnection
   */
  public function testTestConnectionSuccessReturnsDatasetCount(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willReturn(new Response(200, [], json_encode(['count' => 42, 'results' => []])));

    $result = (new BodsApiClient(
      $httpClient,
      $this->makeConfigFactory(),
      $this->makeKeyRepository(),
      $this->createMock(LoggerInterface::class),
    ))->testConnection();

    $this->assertTrue($result['success']);
    $this->assertStringContainsString('Connected', $result['message']);
    $this->assertStringContainsString('42', $result['message']);
  }

  /**
   * When no API key ID is configured, no HTTP request is made.
   *
   * @covers ::testConnection
   */
  public function testTestConnectionWithNoApiKeyIdConfiguredReturnsFailure(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->never())->method('request');

    $result = (new BodsApiClient(
      $httpClient,
      $this->makeConfigFactory(''),        // empty api_key_id
      $this->createMock(KeyRepositoryInterface::class),
      $this->createMock(LoggerInterface::class),
    ))->testConnection();

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('No API key', $result['message']);
  }

  /**
   * A Guzzle exception must not leak the API key in the returned message.
   *
   * The API key appears in the request URL; logging $e->getMessage() would
   * expose it. The returned user-facing message must be generic.
   *
   * @covers ::testConnection
   */
  public function testTestConnectionGuzzleExceptionDoesNotLeakApiKey(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willThrowException(new TransferException(
        'cURL error: Failed to connect; api_key=' . self::KEY_VALUE,
      ));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $result = (new BodsApiClient(
      $httpClient,
      $this->makeConfigFactory(),
      $this->makeKeyRepository(),
      $logger,
    ))->testConnection();

    $this->assertFalse($result['success']);
    // The raw API key value must not appear in the user-facing message.
    $this->assertStringNotContainsString(self::KEY_VALUE, $result['message']);
    $this->assertStringContainsString('Connection failed', $result['message']);
  }

  // ---------------------------------------------------------------------------
  // listDatasets
  // ---------------------------------------------------------------------------

  /**
   * Multiple admin area codes produce repeated params, not PHP array notation.
   *
   * BODS expects ?adminArea=080&adminArea=081 — NOT adminArea%5B0%5D=080 which
   * http_build_query would produce for an indexed array.
   *
   * @covers ::listDatasets
   */
  public function testListDatasetsBuildsRepeatedAdminAreaParams(): void {
    $capturedUrl = '';

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willReturnCallback(
        static function (string $method, string $url, array $options) use (&$capturedUrl): Response {
          $capturedUrl = $url;
          return new Response(200, [], json_encode(['count' => 2, 'results' => [['id' => 1], ['id' => 2]]]));
        },
      );

    $results = (new BodsApiClient(
      $httpClient,
      $this->makeConfigFactory(),
      $this->makeKeyRepository(),
      $this->createMock(LoggerInterface::class),
    ))->listDatasets(['080', '081'], 1);

    $this->assertCount(2, $results);

    // Each code must appear as a separate adminArea= parameter.
    $this->assertStringContainsString('adminArea=080', $capturedUrl);
    $this->assertStringContainsString('adminArea=081', $capturedUrl);

    // PHP array notation must NOT be present.
    $this->assertStringNotContainsString('adminArea%5B', $capturedUrl);  // %5B = [
    $this->assertStringNotContainsString('adminArea[', $capturedUrl);
  }

  /**
   * When the configured Key entity does not exist, a warning is logged.
   *
   * The request still proceeds (with an empty API key); the method returns
   * whatever the API returns.
   *
   * @covers ::listDatasets
   */
  public function testListDatasetsWithMissingKeyEntityLogsWarning(): void {
    $keyRepo = $this->createMock(KeyRepositoryInterface::class);
    $keyRepo->method('getKey')->willReturn(NULL);

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willReturn(new Response(200, [], json_encode(['count' => 0, 'results' => []])));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('not found'));

    $results = (new BodsApiClient(
      $httpClient,
      $this->makeConfigFactory(self::KEY_ID),
      $keyRepo,
      $logger,
    ))->listDatasets(['080']);

    $this->assertSame([], $results);
  }

}
