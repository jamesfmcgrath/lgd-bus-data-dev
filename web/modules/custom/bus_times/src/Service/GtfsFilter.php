<?php

declare(strict_types=1);

namespace Drupal\bus_times\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Filters a downloaded GTFS directory to a geographic bounding box.
 *
 * Operator datasets from BODS often cover multiple council areas. This service
 * trims the GTFS files to only trips that serve at least one stop inside the
 * configured bounding box — but retains the FULL stop sequence for those trips,
 * including any stops outside the box (e.g. cross-boundary routes).
 *
 * This preserves complete route data for services like Carlisle → Gretna:
 * the Carlisle stop triggers inclusion, but Gretna is kept so the timetable
 * shows the correct terminal and stop sequence.
 *
 * Processing order:
 *   1. Scan stops.txt       → collect in-box stop IDs (read-only)
 *   2. Scan stop_times.txt  → collect trip IDs with ≥1 in-box stop (read-only)
 *   3. Rewrite stop_times.txt keeping valid trips; collect all stop IDs they use
 *   4. Rewrite stops.txt    keeping all stops referenced by valid trips
 *   5. Rewrite trips.txt    keeping valid trips
 *
 * All files are processed line-by-line; peak memory is proportional to the
 * number of unique trip/stop IDs, not the file size.
 */
final class GtfsFilter {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Filters GTFS files to trips that serve the configured bounding box.
   *
   * If no bounding box is configured the directory is left untouched.
   *
   * @param string $gtfsDir
   *   Absolute path to the extracted GTFS directory.
   *
   * @return array{stops: int, stop_times: int, trips: int}
   *   Row counts remaining after filtering (zeroes if no bbox configured).
   */
  public function filterByBoundingBox(string $gtfsDir): array {
    $bbox = $this->getBoundingBox();
    if ($bbox === NULL) {
      $this->logger->info('GtfsFilter: no bounding box configured, skipping geographic filter.');
      return ['stops' => 0, 'stop_times' => 0, 'trips' => 0];
    }

    $this->logger->info('GtfsFilter: filtering to bbox N:@n S:@s E:@e W:@w', [
      '@n' => $bbox['north'],
      '@s' => $bbox['south'],
      '@e' => $bbox['east'],
      '@w' => $bbox['west'],
    ]);

    // Step 1 — which stops are inside the box?
    $inBoxStopIds = $this->collectInBoxStopIds($gtfsDir . '/stops.txt', $bbox);
    $this->logger->info('GtfsFilter: @count stops found within bounding box.', [
      '@count' => count($inBoxStopIds),
    ]);

    // Step 2 — which trips call at least one in-box stop?
    $validTripIds = $this->collectValidTripIds($gtfsDir . '/stop_times.txt', $inBoxStopIds);
    $this->logger->info('GtfsFilter: @count trips serve at least one in-box stop.', [
      '@count' => count($validTripIds),
    ]);

    // Step 3 — rewrite stop_times.txt keeping all stop times for valid trips;
    // simultaneously collect every stop ID those trips reference (including
    // out-of-box stops on cross-boundary routes).
    $allNeededStopIds = $this->rewriteStopTimesAndCollectStops(
      $gtfsDir . '/stop_times.txt',
      $validTripIds,
    );
    $stopTimesKept = count($allNeededStopIds);

    // Step 4 — rewrite stops.txt keeping all stops used by any valid trip.
    $stopsKept = $this->rewriteByStopId($gtfsDir . '/stops.txt', $allNeededStopIds);
    $this->logger->info('GtfsFilter: @count stops retained (includes cross-boundary stops).', [
      '@count' => $stopsKept,
    ]);

    // Step 5 — rewrite trips.txt.
    $tripsKept = $this->rewriteByTripId($gtfsDir . '/trips.txt', $validTripIds);
    $this->logger->info('GtfsFilter: @count trips retained.', ['@count' => $tripsKept]);

    return [
      'stops'      => $stopsKept,
      'stop_times' => $stopTimesKept,
      'trips'      => $tripsKept,
    ];
  }

  /**
   * Scans stops.txt and returns IDs of stops inside the bounding box.
   *
   * Read-only; does not modify the file.
   *
   * @param string $file
   *   Absolute path to stops.txt.
   * @param array{north: float, south: float, east: float, west: float} $bbox
   *   Bounding box coordinates.
   *
   * @return array<string, true>
   *   Stop IDs inside the box, keyed for O(1) lookup.
   */
  private function collectInBoxStopIds(string $file, array $bbox): array {
    $inBox = [];
    $this->scanCsv($file, function (array $header, array $row) use ($bbox, &$inBox): void {
      $lat = (float) ($row[array_search('stop_lat', $header, TRUE)] ?? 0);
      $lon = (float) ($row[array_search('stop_lon', $header, TRUE)] ?? 0);
      if ($lat >= $bbox['south'] && $lat <= $bbox['north']
        && $lon >= $bbox['west'] && $lon <= $bbox['east']) {
        $stopId = $row[array_search('stop_id', $header, TRUE)] ?? '';
        if ($stopId !== '') {
          $inBox[$stopId] = TRUE;
        }
      }
    });
    return $inBox;
  }

  /**
   * Scans stop_times.txt and returns IDs of trips with ≥1 in-box stop.
   *
   * Read-only; does not modify the file.
   *
   * @param string $file
   *   Absolute path to stop_times.txt.
   * @param array<string, true> $inBoxStopIds
   *   Stop IDs inside the bounding box (from collectInBoxStopIds).
   *
   * @return array<string, true>
   *   Trip IDs that call at least one in-box stop, keyed for O(1) lookup.
   */
  private function collectValidTripIds(string $file, array $inBoxStopIds): array {
    $validTrips = [];
    $this->scanCsv($file, function (array $header, array $row) use ($inBoxStopIds, &$validTrips): void {
      $stopId = $row[array_search('stop_id', $header, TRUE)] ?? '';
      if (isset($inBoxStopIds[$stopId])) {
        $tripId = $row[array_search('trip_id', $header, TRUE)] ?? '';
        if ($tripId !== '') {
          $validTrips[$tripId] = TRUE;
        }
      }
    });
    return $validTrips;
  }

  /**
   * Rewrites stop_times.txt keeping all rows for valid trips.
   *
   * Also collects every stop_id referenced by those trips so that
   * cross-boundary stops can be retained in stops.txt.
   *
   * @param string $file
   *   Absolute path to stop_times.txt.
   * @param array<string, true> $validTripIds
   *   Trip IDs to keep (from collectValidTripIds).
   *
   * @return array<string, true>
   *   All stop IDs referenced by the retained trips, keyed for O(1) lookup.
   */
  private function rewriteStopTimesAndCollectStops(string $file, array $validTripIds): array {
    $neededStopIds = [];
    $this->rewriteCsv(
      $file,
      function (array $header, array $row) use ($validTripIds, &$neededStopIds): bool {
        $tripId = $row[array_search('trip_id', $header, TRUE)] ?? '';
        if (isset($validTripIds[$tripId])) {
          $stopId = $row[array_search('stop_id', $header, TRUE)] ?? '';
          if ($stopId !== '') {
            $neededStopIds[$stopId] = TRUE;
          }
          return TRUE;
        }
        return FALSE;
      },
    );
    return $neededStopIds;
  }

  /**
   * Rewrites stops.txt keeping all stops used by valid trips.
   *
   * @param string $file
   *   Absolute path to stops.txt.
   * @param array<string, true> $neededStopIds
   *   Stop IDs to keep (from rewriteStopTimesAndCollectStops).
   *
   * @return int
   *   Number of stop rows retained.
   */
  private function rewriteByStopId(string $file, array $neededStopIds): int {
    $count = 0;
    $this->rewriteCsv(
      $file,
      function (array $header, array $row) use ($neededStopIds, &$count): bool {
        $stopId = $row[array_search('stop_id', $header, TRUE)] ?? '';
        if (isset($neededStopIds[$stopId])) {
          $count++;
          return TRUE;
        }
        return FALSE;
      },
    );
    return $count;
  }

  /**
   * Rewrites trips.txt keeping only valid trips.
   *
   * @param string $file
   *   Absolute path to trips.txt.
   * @param array<string, true> $validTripIds
   *   Trip IDs to keep.
   *
   * @return int
   *   Number of trip rows retained.
   */
  private function rewriteByTripId(string $file, array $validTripIds): int {
    if (!file_exists($file) || $validTripIds === []) {
      return 0;
    }
    $count = 0;
    $this->rewriteCsv(
      $file,
      function (array $header, array $row) use ($validTripIds, &$count): bool {
        $tripId = $row[array_search('trip_id', $header, TRUE)] ?? '';
        if (isset($validTripIds[$tripId])) {
          $count++;
          return TRUE;
        }
        return FALSE;
      },
    );
    return $count;
  }

  /**
   * Scans a CSV file line-by-line without modifying it.
   *
   * @param string $file
   *   Absolute path to the CSV file.
   * @param callable(array<int, string> $header, array<int, string> $row): void $cb
   *   Called for every data row.
   */
  private function scanCsv(string $file, callable $cb): void {
    if (!file_exists($file)) {
      return;
    }
    $fh = fopen($file, 'r');
    if ($fh === FALSE) {
      $this->logger->error('GtfsFilter: could not open @file for reading.', ['@file' => $file]);
      return;
    }
    $header = fgetcsv($fh);
    if (!is_array($header)) {
      fclose($fh);
      return;
    }
    while (($row = fgetcsv($fh)) !== FALSE) {
      $cb($header, $row);
    }
    fclose($fh);
  }

  /**
   * Rewrites a CSV file in-place, keeping only rows accepted by $keep.
   *
   * The header row is always preserved. Rows are processed line-by-line so
   * peak memory is proportional to a single row, not the whole file.
   *
   * @param string $file
   *   Absolute path to the CSV file.
   * @param callable(array<int, string> $header, array<int, string> $row): bool $keep
   *   Return TRUE to keep a data row, FALSE to discard it.
   */
  private function rewriteCsv(string $file, callable $keep): void {
    if (!file_exists($file)) {
      return;
    }
    $tmpFile = $file . '.tmp';
    $in  = fopen($file, 'r');
    $out = fopen($tmpFile, 'w');

    if ($in === FALSE || $out === FALSE) {
      $this->logger->error('GtfsFilter: could not open @file for rewriting.', ['@file' => $file]);
      return;
    }

    $header = fgetcsv($in);
    if (!is_array($header)) {
      fclose($in);
      fclose($out);
      return;
    }
    fputcsv($out, $header);

    while (($row = fgetcsv($in)) !== FALSE) {
      if ($keep($header, $row)) {
        fputcsv($out, $row);
      }
    }

    fclose($in);
    fclose($out);
    rename($tmpFile, $file);
  }

  /**
   * Reads the bounding box from config.
   *
   * @return array{north: float, south: float, east: float, west: float}|null
   *   NULL if no valid bounding box is configured.
   */
  private function getBoundingBox(): ?array {
    $bbox = $this->configFactory->get('bus_times.settings')->get('source.bounding_box');

    if (!is_array($bbox)
      || !isset($bbox['north'], $bbox['south'], $bbox['east'], $bbox['west'])
      || ($bbox['north'] === 0.0 && $bbox['south'] === 0.0)) {
      return NULL;
    }

    return [
      'north' => (float) $bbox['north'],
      'south' => (float) $bbox['south'],
      'east'  => (float) $bbox['east'],
      'west'  => (float) $bbox['west'],
    ];
  }

}
