<?php

declare(strict_types=1);

namespace Drupal\Tests\s360_solr_health\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\s360_solr_health\SolrConsole;
use Drupal\Tests\UnitTestCase;

/**
 * Tests how a summary row is assessed against the excluded count.
 *
 * @coversDefaultClass \Drupal\s360_solr_health\SolrConsole
 *
 * @group s360_solr_health
 */
class SolrConsoleAssessTest extends UnitTestCase {

  /**
   * Calls the protected assess() on a console with stubbed services.
   */
  protected function assess(array $row): string {
    $console = new SolrConsole(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->getStringTranslationStub(),
      $this->createMock(Connection::class),
    );
    $method = new \ReflectionMethod($console, 'assess');
    return $method->invoke($console, $row + [
      'server_available' => TRUE,
      'error' => NULL,
      'enabled' => TRUE,
      'excluded' => 0,
      'solr_other' => 0,
      'id' => 'site_search',
      'site_hash' => 'abc123',
    ])[0];
  }

  /**
   * Unpublished items dropped by entity_status are not a shortfall.
   *
   * @covers ::assess
   */
  public function testExcludedGapIsOk(): void {
    $this->assertSame(SolrConsole::STATE_OK, $this->assess([
      'tracked' => 1492,
      'indexed' => 1492,
      'excluded' => 57,
      'solr_docs' => 1435,
    ]));
  }

  /**
   * A shortfall beyond the excluded items still warns.
   *
   * @covers ::assess
   */
  public function testShortBeyondExcluded(): void {
    $this->assertSame(SolrConsole::STATE_SHORT, $this->assess([
      'tracked' => 1492,
      'indexed' => 1492,
      'excluded' => 57,
      'solr_docs' => 1400,
    ]));
  }

  /**
   * Without the processor the original comparison is unchanged.
   *
   * @covers ::assess
   */
  public function testShortWithoutExcluded(): void {
    $this->assertSame(SolrConsole::STATE_SHORT, $this->assess([
      'tracked' => 100,
      'indexed' => 100,
      'solr_docs' => 90,
    ]));
  }

  /**
   * The post-clone shape (empty core, full tracker) is still caught.
   *
   * @covers ::assess
   */
  public function testEmptyCoreStillCaught(): void {
    $this->assertSame(SolrConsole::STATE_EMPTY, $this->assess([
      'tracked' => 1492,
      'indexed' => 1492,
      'excluded' => 57,
      'solr_docs' => 0,
    ]));
  }

  /**
   * An index whose every item is unpublished is legitimately empty.
   *
   * @covers ::assess
   */
  public function testAllExcludedIsNotEmpty(): void {
    $this->assertSame(SolrConsole::STATE_OK, $this->assess([
      'tracked' => 5,
      'indexed' => 5,
      'excluded' => 5,
      'solr_docs' => 0,
    ]));
  }

  /**
   * Documents under another site hash raise over, even with a clean count.
   *
   * The nj211-drupal shape (NJ2P-829, 2026-10-19) once the count filters by
   * the current hash: 303 of this site's documents, 303 stale copies.
   *
   * @covers ::assess
   */
  public function testOtherHashIsOver(): void {
    $this->assertSame(SolrConsole::STATE_OVER, $this->assess([
      'tracked' => 648,
      'indexed' => 648,
      'excluded' => 345,
      'solr_docs' => 303,
      'solr_other' => 303,
    ]));
  }

  /**
   * The same shape on a server that does not filter by hash (our default).
   *
   * @covers ::assess
   */
  public function testOtherHashUnfilteredIsOver(): void {
    $this->assertSame(SolrConsole::STATE_OVER, $this->assess([
      'tracked' => 648,
      'indexed' => 648,
      'excluded' => 345,
      'solr_docs' => 606,
      'solr_other' => 303,
      'hash_filtered' => FALSE,
    ]));
  }

  /**
   * More of this site's documents than the tracker could send is over.
   *
   * @covers ::assess
   */
  public function testSurplusBeyondTrackedIsOver(): void {
    $this->assertSame(SolrConsole::STATE_OVER, $this->assess([
      'tracked' => 648,
      'indexed' => 648,
      'excluded' => 345,
      'solr_docs' => 606,
    ]));
  }

  /**
   * A reindex after reset-tracker leaves old documents in place: backlog.
   *
   * @covers ::assess
   */
  public function testReindexInProgressIsBacklog(): void {
    $this->assertSame(SolrConsole::STATE_BACKLOG, $this->assess([
      'tracked' => 100,
      'indexed' => 0,
      'solr_docs' => 100,
    ]));
  }

  /**
   * An unknown excluded count falls back to the plain comparison.
   *
   * @covers ::assess
   */
  public function testUnknownExcludedFallsBack(): void {
    $this->assertSame(SolrConsole::STATE_SHORT, $this->assess([
      'tracked' => 1492,
      'indexed' => 1492,
      'excluded' => NULL,
      'solr_docs' => 1435,
    ]));
  }

}
