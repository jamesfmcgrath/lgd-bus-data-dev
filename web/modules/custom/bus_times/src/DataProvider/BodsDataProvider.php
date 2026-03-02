<?php

declare(strict_types=1);

namespace Drupal\bus_times\DataProvider;

use Drupal\bus_times\Service\BodsApiClient;

/**
 * BODS (Bus Open Data Service) data provider.
 *
 * Wraps BodsApiClient to satisfy DataProviderInterface. Additional providers
 * (e.g. a mock for testing) can implement the same interface without touching
 * any module business logic.
 */
final class BodsDataProvider implements DataProviderInterface {

  public function __construct(
    private readonly BodsApiClient $apiClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Bus Open Data Service (BODS)';
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): array {
    return $this->apiClient->testConnection();
  }

  /**
   * {@inheritdoc}
   */
  public function getGtfsDownloadUrl(array $adminAreaCodes = []): string {
    $datasets = $this->apiClient->listDatasets($adminAreaCodes, 1);
    if (empty($datasets)) {
      $codes = implode(', ', $adminAreaCodes) ?: '(all areas)';
      throw new \RuntimeException("No GTFS datasets found for area codes: {$codes}.");
    }
    $datasetId = (int) ($datasets[0]['id'] ?? 0);
    if ($datasetId === 0) {
      throw new \RuntimeException('BODS dataset missing ID field.');
    }
    return $this->apiClient->getDatasetDownloadUrl($datasetId);
  }

  /**
   * {@inheritdoc}
   */
  public function getDepartures(string $stopId): array {
    try {
      return $this->apiClient->getDepartures($stopId);
    }
    catch (\RuntimeException) {
      // Real-time data is best-effort; caller falls back to scheduled times.
      return [];
    }
  }

}
