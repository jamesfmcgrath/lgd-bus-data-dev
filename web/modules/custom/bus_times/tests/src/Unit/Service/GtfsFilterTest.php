<?php

declare(strict_types=1);

namespace Drupal\Tests\bus_times\Unit\Service;

use Drupal\bus_times\Service\GtfsFilter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for GtfsFilter.
 *
 * Uses real temporary CSV files so that file I/O logic (fgetcsv, fputcsv,
 * rename) is exercised without any filesystem mocking.
 *
 * @coversDefaultClass \Drupal\bus_times\Service\GtfsFilter
 * @group bus_times
 */
final class GtfsFilterTest extends UnitTestCase {

  private string $tmpDir;

  protected function setUp(): void {
    parent::setUp();
    $this->tmpDir = sys_get_temp_dir() . '/gtfs_filter_test_' . uniqid();
    mkdir($this->tmpDir, 0750, TRUE);
  }

  protected function tearDown(): void {
    foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
    rmdir($this->tmpDir);
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Creates a GtfsFilter backed by a config stub returning the given bbox.
   *
   * @param array{north: float, south: float, east: float, west: float}|null $bbox
   */
  private function makeFilter(?array $bbox): GtfsFilter {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn($bbox);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new GtfsFilter($configFactory, $this->createMock(LoggerInterface::class));
  }

  /** @param array<int, list<string>> $rows */
  private function writeCsv(string $filename, array $rows): void {
    $fh = fopen($this->tmpDir . '/' . $filename, 'w');
    assert($fh !== FALSE);
    foreach ($rows as $row) {
      fputcsv($fh, $row);
    }
    fclose($fh);
  }

  /** @return list<list<string>> */
  private function readCsv(string $filename): array {
    $rows = [];
    $fh = fopen($this->tmpDir . '/' . $filename, 'r');
    assert($fh !== FALSE);
    while (($row = fgetcsv($fh)) !== FALSE) {
      $rows[] = $row;
    }
    fclose($fh);
    return $rows;
  }

  // ---------------------------------------------------------------------------
  // Tests
  // ---------------------------------------------------------------------------

  /**
   * When no bounding box is configured, files are left untouched.
   *
   * @covers ::filterByBoundingBox
   */
  public function testNoBboxConfiguredSkipsFiltering(): void {
    $this->writeCsv('stops.txt', [
      ['stop_id', 'stop_lat', 'stop_lon'],
      ['S1', '54.900', '-3.500'],
    ]);
    $this->writeCsv('stop_times.txt', [
      ['trip_id', 'stop_id'],
      ['T1', 'S1'],
    ]);
    $this->writeCsv('trips.txt', [
      ['trip_id', 'route_id'],
      ['T1', 'R1'],
    ]);

    $result = $this->makeFilter(NULL)->filterByBoundingBox($this->tmpDir);

    $this->assertSame(['stops' => 0, 'stop_times' => 0, 'trips' => 0], $result);
    // Files must not be modified.
    $this->assertCount(2, $this->readCsv('stops.txt'));
    $this->assertCount(2, $this->readCsv('stop_times.txt'));
    $this->assertCount(2, $this->readCsv('trips.txt'));
  }

  /**
   * Trips that serve only out-of-box stops are removed; in-box trips remain.
   *
   * Bounding box used: N=55.1, S=54.3, E=-2.0, W=-3.75 (roughly Cumberland).
   *
   * @covers ::filterByBoundingBox
   */
  public function testInBoxTripsRetainedOutBoxTripsRemoved(): void {
    $bbox = ['north' => 55.1, 'south' => 54.3, 'east' => -2.0, 'west' => -3.75];

    $this->writeCsv('stops.txt', [
      ['stop_id', 'stop_lat', 'stop_lon'],
      ['S1', '54.900', '-3.500'],   // inside
      ['S2', '54.800', '-3.200'],   // inside
      ['S3', '56.000', '-1.500'],   // outside (north of box)
    ]);
    $this->writeCsv('stop_times.txt', [
      ['trip_id', 'stop_id', 'stop_sequence'],
      ['T1', 'S1', '1'],            // T1 calls in-box stop → valid
      ['T1', 'S2', '2'],
      ['T2', 'S3', '1'],            // T2 only calls out-of-box stop → removed
    ]);
    $this->writeCsv('trips.txt', [
      ['trip_id', 'route_id'],
      ['T1', 'R1'],
      ['T2', 'R2'],
    ]);

    $result = $this->makeFilter($bbox)->filterByBoundingBox($this->tmpDir);

    $this->assertSame(2, $result['stops']);
    $this->assertSame(1, $result['trips']);

    // stops.txt: header + S1 + S2 only.
    $stops = $this->readCsv('stops.txt');
    $this->assertCount(3, $stops);
    $stopIds = array_column(array_slice($stops, 1), 0);
    $this->assertContains('S1', $stopIds);
    $this->assertContains('S2', $stopIds);
    $this->assertNotContains('S3', $stopIds);

    // trips.txt: header + T1 only.
    $trips = $this->readCsv('trips.txt');
    $this->assertCount(2, $trips);
    $this->assertSame('T1', $trips[1][0]);
  }

  /**
   * A cross-boundary trip retains stops inside AND outside the bounding box.
   *
   * A route like Carlisle → Gretna (Scotland) must keep the Gretna stop even
   * though it is outside the box, so the timetable shows the correct terminal.
   *
   * @covers ::filterByBoundingBox
   */
  public function testCrossBoundaryRouteRetainsBothStops(): void {
    $bbox = ['north' => 55.1, 'south' => 54.3, 'east' => -2.0, 'west' => -3.75];

    $this->writeCsv('stops.txt', [
      ['stop_id', 'stop_lat', 'stop_lon'],
      ['S-in', '54.900', '-3.500'],   // inside box
      ['S-out', '56.000', '-1.500'],  // outside box
    ]);
    $this->writeCsv('stop_times.txt', [
      ['trip_id', 'stop_id', 'stop_sequence'],
      ['T1', 'S-in', '1'],
      ['T1', 'S-out', '2'],
    ]);
    $this->writeCsv('trips.txt', [
      ['trip_id', 'route_id'],
      ['T1', 'R1'],
    ]);

    $result = $this->makeFilter($bbox)->filterByBoundingBox($this->tmpDir);

    $this->assertSame(1, $result['trips']);
    // Both stops must be retained.
    $this->assertSame(2, $result['stops']);
    $stopIds = array_column(array_slice($this->readCsv('stops.txt'), 1), 0);
    $this->assertContains('S-in', $stopIds);
    $this->assertContains('S-out', $stopIds);
  }

  /**
   * Columns in a non-standard order in the CSV header are resolved correctly.
   *
   * @covers ::filterByBoundingBox
   */
  public function testNonStandardColumnOrderFiltersCorrectly(): void {
    $bbox = ['north' => 55.1, 'south' => 54.3, 'east' => -2.0, 'west' => -3.75];

    // Deliberately reorder columns: lon before lat, name before id.
    $this->writeCsv('stops.txt', [
      ['stop_name', 'stop_lon', 'stop_lat', 'stop_id'],
      ['In-box stop', '-3.500', '54.900', 'S1'],
      ['Out-box stop', '-1.500', '56.000', 'S2'],
    ]);
    // trip_id and stop_id in reverse order.
    $this->writeCsv('stop_times.txt', [
      ['stop_sequence', 'stop_id', 'trip_id'],
      ['1', 'S1', 'T1'],
      ['1', 'S2', 'T2'],
    ]);
    // route_id before trip_id.
    $this->writeCsv('trips.txt', [
      ['route_id', 'trip_id'],
      ['R1', 'T1'],
      ['R2', 'T2'],
    ]);

    $result = $this->makeFilter($bbox)->filterByBoundingBox($this->tmpDir);

    $this->assertSame(1, $result['stops']);
    $this->assertSame(1, $result['trips']);

    // trip_id is at column index 1 in the rewritten trips.txt.
    $trips = $this->readCsv('trips.txt');
    $this->assertSame('T1', $trips[1][1]);
  }

  /**
   * A missing required CSV column triggers a logged error and returns zeros.
   *
   * @covers ::filterByBoundingBox
   */
  public function testMissingRequiredColumnLogsErrorAndReturnsZeros(): void {
    $bbox = ['north' => 55.1, 'south' => 54.3, 'east' => -2.0, 'west' => -3.75];

    // stops.txt is missing the 'stop_lat' column.
    $this->writeCsv('stops.txt', [
      ['stop_id', 'stop_lon'],
      ['S1', '-3.500'],
    ]);
    $this->writeCsv('stop_times.txt', [
      ['trip_id', 'stop_id'],
      ['T1', 'S1'],
    ]);
    $this->writeCsv('trips.txt', [
      ['trip_id', 'route_id'],
      ['T1', 'R1'],
    ]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn($bbox);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('missing required column'),
        $this->arrayHasKey('@col'),
      );

    $result = (new GtfsFilter($configFactory, $logger))->filterByBoundingBox($this->tmpDir);

    // No in-box stops found → no valid trips → all counts zero.
    $this->assertSame(['stops' => 0, 'stop_times' => 0, 'trips' => 0], $result);
  }

}
