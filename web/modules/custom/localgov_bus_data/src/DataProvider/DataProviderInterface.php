<?php

declare(strict_types=1);

namespace Drupal\localgov_bus_data\DataProvider;

/**
 * Contract for GTFS data provider plugins.
 *
 * Implement this interface to add a new data source (e.g. a different
 * national bus open data service) without changing any business logic.
 */
interface DataProviderInterface {

  /**
   * Returns a human-readable label for this provider.
   */
  public function getLabel(): string;

  /**
   * Verifies the provider is reachable and the credentials are valid.
   *
   * @return array
   *   Array with 'success' (bool) and 'message' (string) keys.
   */
  public function testConnection(): array;

  /**
   * Returns the URL of the GTFS ZIP file to download.
   *
   * @param array<int, string> $adminAreaCodes
   *   NaPTAN admin area codes to filter by (e.g. ['080', '081', '082']).
   *   Pass an empty array to fetch all areas (not recommended in production).
   *
   * @return string
   *   Absolute URL of the GTFS ZIP.
   *
   * @throws \RuntimeException
   *   When no datasets are found or the provider is unreachable.
   */
  public function getGtfsDownloadUrl(array $adminAreaCodes = []): string;

  /**
   * Returns real-time departure data for a single stop.
   *
   * @param string $stopId
   *   ATCO stop code.
   *
   * @return array<int, array{line: string, destination: string, aimed: string, expected: string|null, status: string}>
   *   Departure rows. Returns an empty array when no live data is available.
   */
  public function getDepartures(string $stopId): array;

}
