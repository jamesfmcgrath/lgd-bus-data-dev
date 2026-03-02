<?php

declare(strict_types=1);

namespace Drupal\bus_times\Plugin\DataProvider;

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
   * @return array{success: bool, message: string}
   */
  public function testConnection(): array;

  /**
   * Returns the URL of the GTFS ZIP file to download.
   *
   * @param string $adminArea
   *   ATCO area code to filter by (e.g. '099' for Cumberland).
   *
   * @return string
   *   Absolute URL of the GTFS ZIP.
   *
   * @throws \Drupal\bus_times\Exception\DataProviderException
   */
  public function getGtfsDownloadUrl(string $adminArea = ''): string;

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
