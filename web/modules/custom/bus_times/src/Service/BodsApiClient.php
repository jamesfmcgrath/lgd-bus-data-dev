<?php

declare(strict_types=1);

namespace Drupal\bus_times\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Authenticated HTTP client for the Bus Open Data Service (BODS) API.
 *
 * All requests are authenticated via the API key stored in a Key entity.
 * The key is never stored in plain-text config.
 *
 * @see https://data.bus-data.dft.gov.uk/guidance/
 */
final class BodsApiClient {

  private string $baseUrl;
  private string $apiKeyId;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly LoggerInterface $logger,
  ) {
    $config = $this->configFactory->get('bus_times.settings');
    $this->baseUrl = rtrim((string) ($config->get('source.base_url') ?? 'https://data.bus-data.dft.gov.uk/api/v1'), '/');
    $this->apiKeyId = (string) ($config->get('source.api_key_id') ?? '');
  }

  /**
   * Tests that the API key is valid by fetching a single dataset record.
   *
   * @return array{success: bool, message: string}
   */
  public function testConnection(): array {
    $apiKey = $this->resolveApiKey();
    if ($apiKey === '') {
      return ['success' => FALSE, 'message' => 'No API key configured. Select a Key entity in Bus Times Settings.'];
    }

    try {
      $response = $this->httpClient->request('GET', $this->baseUrl . '/dataset/', [
        'query' => ['api_key' => $apiKey, 'limit' => 1],
        'timeout' => 10,
      ]);

      $statusCode = $response->getStatusCode();

      if ($statusCode === 200) {
        $body = json_decode((string) $response->getBody(), TRUE);
        $count = $body['count'] ?? '?';
        return ['success' => TRUE, 'message' => "Connected. {$count} datasets available."];
      }

      return ['success' => FALSE, 'message' => "Unexpected HTTP {$statusCode} from BODS API."];
    }
    catch (GuzzleException $e) {
      $this->logger->error('BODS API connection test failed: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'message' => 'Connection failed: ' . $e->getMessage()];
    }
  }

  /**
   * Lists available GTFS timetable datasets, optionally filtered by area.
   *
   * @param string $adminArea
   *   ATCO admin area code (e.g. '099' for Cumberland). Pass empty string
   *   to retrieve all areas.
   * @param int $limit
   *   Maximum number of results per page.
   * @param int $offset
   *   Pagination offset.
   *
   * @return array<int, array<string, mixed>>
   *   Decoded dataset objects from the BODS API response.
   *
   * @throws \RuntimeException
   */
  public function listDatasets(string $adminArea = '', int $limit = 25, int $offset = 0): array {
    $query = [
      'api_key' => $this->resolveApiKey(),
      'limit' => $limit,
      'offset' => $offset,
    ];
    if ($adminArea !== '') {
      $query['adminArea'] = $adminArea;
    }

    $response = $this->request('GET', '/dataset/', $query);
    return $response['results'] ?? [];
  }

  /**
   * Returns the GTFS download URL for a specific dataset.
   *
   * @param int $datasetId
   *   BODS dataset ID.
   *
   * @return string
   *   Absolute URL to the GTFS ZIP file.
   *
   * @throws \RuntimeException
   */
  public function getDatasetDownloadUrl(int $datasetId): string {
    $response = $this->request('GET', "/dataset/{$datasetId}/", [
      'api_key' => $this->resolveApiKey(),
    ]);
    return (string) ($response['url'] ?? '');
  }

  /**
   * Fetches real-time departures for a stop (SIRI-SM departure monitor).
   *
   * @param string $stopId
   *   ATCO stop code.
   *
   * @return array<int, array<string, mixed>>
   *   Departure rows from the BODS real-time feed.
   *
   * @throws \RuntimeException
   */
  public function getDepartures(string $stopId): array {
    $query = [
      'api_key' => $this->resolveApiKey(),
      'operatorRef' => '',
      'lineRef' => '',
      'monitoringRef' => $stopId,
    ];

    $response = $this->request('GET', '/avl/', $query);
    return $response['results'] ?? [];
  }

  /**
   * Makes an authenticated GET request and returns the decoded JSON body.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path relative to base URL (must start with '/').
   * @param array<string, mixed> $query
   *   Query parameters (api_key will be added if not present).
   *
   * @return array<string, mixed>
   *
   * @throws \RuntimeException
   */
  private function request(string $method, string $path, array $query = []): array {
    if (!isset($query['api_key'])) {
      $query['api_key'] = $this->resolveApiKey();
    }

    try {
      $response = $this->httpClient->request($method, $this->baseUrl . $path, [
        'query' => $query,
        'timeout' => (int) ($this->configFactory->get('bus_times.settings')->get('import.timeout') ?? 30),
      ]);

      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('BODS API returned non-JSON response.');
      }
      return $decoded;
    }
    catch (GuzzleException $e) {
      $this->logger->error('BODS API request to @path failed: @message', [
        '@path' => $path,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('BODS API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Resolves the API key value from the configured Key entity.
   *
   * @return string
   *   The raw API key string, or empty string if not configured.
   */
  private function resolveApiKey(): string {
    if ($this->apiKeyId === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($this->apiKeyId);
    if ($key === NULL) {
      $this->logger->warning('Bus Times: Key entity "@id" not found.', ['@id' => $this->apiKeyId]);
      return '';
    }
    return (string) ($key->getKeyValue() ?? '');
  }

}
