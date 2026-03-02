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

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Tests that the API key is valid by fetching a single dataset record.
   *
   * @return array
   *   Array with 'success' (bool) and 'message' (string) keys.
   */
  public function testConnection(): array {
    $apiKey = $this->resolveApiKey();
    if ($apiKey === '') {
      return ['success' => FALSE, 'message' => 'No API key configured. Select a Key entity in Bus Times Settings.'];
    }

    try {
      $response = $this->httpClient->request('GET', $this->baseUrl() . '/dataset/', [
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
      // Do not log $e->getMessage() — Guzzle includes the full request URL,
      // which contains the api_key query parameter in plain text.
      $this->logger->error('BODS API connection test failed (@type).', [
        '@type' => get_class($e),
      ]);
      return ['success' => FALSE, 'message' => 'Connection failed. Check the site logs for details.'];
    }
  }

  /**
   * Lists available GTFS timetable datasets, filtered to configured areas.
   *
   * Multiple NaPTAN admin area codes are sent as repeated query parameters
   * (e.g. ?adminArea=080&adminArea=081) so BODS returns only datasets that
   * contain stops in at least one of those areas.
   *
   * @param array<int, string> $adminAreaCodes
   *   NaPTAN admin area codes to filter by (e.g. ['080', '081', '082']).
   *   Pass an empty array to retrieve all areas (not recommended in
   *   production).
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
  public function listDatasets(array $adminAreaCodes = [], int $limit = 25, int $offset = 0): array {
    // Build the query string manually so repeated adminArea= params are not
    // converted to adminArea[0]= by http_build_query.
    $base = http_build_query([
      'api_key' => $this->resolveApiKey(),
      'limit'   => $limit,
      'offset'  => $offset,
    ]);

    $areaParts = array_map(
      static fn(string $code) => 'adminArea=' . rawurlencode(trim($code)),
      array_filter($adminAreaCodes),
    );

    $queryString = $areaParts !== []
      ? $base . '&' . implode('&', $areaParts)
      : $base;

    $decoded = $this->requestRaw('GET', '/dataset/?' . $queryString);
    return $decoded['results'] ?? [];
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
   * Makes a GET request with standard query array and returns decoded JSON.
   *
   * Use this for endpoints that don't need repeated query params.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path relative to base URL (must start with '/').
   * @param array<string, mixed> $query
   *   Query parameters (api_key will be added if not present).
   *
   * @return array<string, mixed>
   *   Decoded JSON response body.
   *
   * @throws \RuntimeException
   */
  private function request(string $method, string $path, array $query = []): array {
    if (!isset($query['api_key'])) {
      $query['api_key'] = $this->resolveApiKey();
    }

    try {
      $response = $this->httpClient->request($method, $this->baseUrl() . $path, [
        'query'   => $query,
        'timeout' => (int) ($this->configFactory->get('bus_times.settings')->get('import.timeout') ?? 30),
      ]);

      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('BODS API returned non-JSON response.');
      }
      return $decoded;
    }
    catch (GuzzleException $e) {
      // Do not use $e->getMessage() — it contains the full request URL
      // including the api_key query parameter.
      $this->logger->error('BODS API request to @path failed (@type).', [
        '@path' => $path,
        '@type' => get_class($e),
      ]);
      throw new \RuntimeException("BODS API request to {$path} failed.", 0, $e);
    }
  }

  /**
   * Makes a GET request using a pre-built URL and returns decoded JSON.
   *
   * Use when repeated query params are required, as Guzzle's 'query' option
   * converts repeated keys to array notation (e.g. adminArea[0]=).
   *
   * @param string $method
   *   HTTP method.
   * @param string $urlWithQuery
   *   Full path including pre-built query string (e.g. '/dataset/?foo=bar').
   *
   * @return array<string, mixed>
   *   Decoded JSON response body.
   *
   * @throws \RuntimeException
   */
  private function requestRaw(string $method, string $urlWithQuery): array {
    try {
      $response = $this->httpClient->request($method, $this->baseUrl() . $urlWithQuery, [
        'timeout' => (int) ($this->configFactory->get('bus_times.settings')->get('import.timeout') ?? 30),
      ]);

      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('BODS API returned non-JSON response.');
      }
      return $decoded;
    }
    catch (GuzzleException $e) {
      // Do not use $e->getMessage() — it contains the full request URL
      // including the api_key query parameter.
      $this->logger->error('BODS API request failed (@type).', [
        '@type' => get_class($e),
      ]);
      throw new \RuntimeException('BODS API request failed.', 0, $e);
    }
  }

  /**
   * Returns the configured BODS API base URL with trailing slash stripped.
   *
   * @return string
   *   The base URL read fresh from config on every call.
   */
  private function baseUrl(): string {
    $url = (string) ($this->configFactory->get('bus_times.settings')->get('source.base_url') ?? 'https://data.bus-data.dft.gov.uk/api/v1');
    return rtrim($url, '/');
  }

  /**
   * Resolves the API key value from the configured Key entity.
   *
   * @return string
   *   The raw API key string, or empty string if not configured.
   */
  private function resolveApiKey(): string {
    $apiKeyId = (string) ($this->configFactory->get('bus_times.settings')->get('source.api_key_id') ?? '');
    if ($apiKeyId === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($apiKeyId);
    if ($key === NULL) {
      $this->logger->warning('Bus Times: Key entity "@id" not found.', ['@id' => $apiKeyId]);
      return '';
    }
    // KeyInterface declares @return string but implementations may return null.
    // @phpstan-ignore-next-line
    return (string) ($key->getKeyValue() ?? '');
  }

}
