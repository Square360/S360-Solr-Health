<?php

declare(strict_types=1);

namespace Drupal\Tests\s360_solr_health\Unit;

use Drupal\s360_solr_health\QueryParams;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Solr console parameter builder.
 *
 * @coversDefaultClass \Drupal\s360_solr_health\QueryParams
 *
 * @group s360_solr_health
 */
class QueryParamsTest extends UnitTestCase {

  /**
   * Blank input yields a match-all query with safe defaults.
   *
   * @covers ::build
   */
  public function testDefaults(): void {
    $p = QueryParams::build([]);
    $this->assertSame('*:*', $p['q']);
    $this->assertSame(0, $p['start']);
    $this->assertSame(QueryParams::DEFAULT_ROWS, $p['rows']);
    $this->assertSame(QueryParams::DEFAULT_FL, $p['fl']);
    $this->assertSame('json', $p['wt']);
    $this->assertArrayNotHasKey('fq', $p);
    $this->assertArrayNotHasKey('sort', $p);
    $this->assertArrayNotHasKey('facet', $p);
    $this->assertArrayNotHasKey('debugQuery', $p);
  }

  /**
   * The index filter comes first, then one fq per non-empty line.
   *
   * @covers ::build
   */
  public function testIndexFilterAndFqLines(): void {
    $p = QueryParams::build([
      'index_id' => 'case_directory',
      'fq' => "ss_bundle:cooked_case\n\n  bs_field_is_free:true  \r\n",
    ]);
    $this->assertSame(
      ['index_id:case_directory', 'ss_bundle:cooked_case', 'bs_field_is_free:true'],
      $p['fq'],
    );
  }

  /**
   * Rows are clamped to [1, MAX_ROWS] and default when blank or junk.
   *
   * @covers ::clampRows
   * @dataProvider rowsProvider
   */
  public function testRowsClamp(mixed $in, int $expected): void {
    $this->assertSame($expected, QueryParams::clampRows($in));
  }

  /**
   * Data provider for testRowsClamp().
   */
  public static function rowsProvider(): array {
    return [
      'blank' => ['', QueryParams::DEFAULT_ROWS],
      'null' => [NULL, QueryParams::DEFAULT_ROWS],
      'junk' => ['lots', QueryParams::DEFAULT_ROWS],
      'zero' => [0, 1],
      'negative' => [-5, 1],
      'in range' => ['25', 25],
      'over cap' => [5000, QueryParams::MAX_ROWS],
    ];
  }

  /**
   * Facet fields expand to the facet parameter set, de-duplicated.
   *
   * @covers ::build
   */
  public function testFacets(): void {
    $p = QueryParams::build(['facet_fields' => "ss_bundle, sm_field_ert_perspectives ss_bundle\n"]);
    $this->assertSame('true', $p['facet']);
    $this->assertSame(['ss_bundle', 'sm_field_ert_perspectives'], $p['facet.field']);
    $this->assertSame(1, $p['facet.mincount']);
  }

  /**
   * Sort, debug, and a trimmed field list pass through.
   *
   * @covers ::build
   */
  public function testPassThrough(): void {
    $p = QueryParams::build([
      'q' => '  anxiety ',
      'sort' => 'score desc',
      'fl' => ' id ss_title ',
      'debug' => TRUE,
      'start' => '20',
    ]);
    $this->assertSame('anxiety', $p['q']);
    $this->assertSame('score desc', $p['sort']);
    $this->assertSame('id ss_title', $p['fl']);
    $this->assertSame('true', $p['debugQuery']);
    $this->assertSame(20, $p['start']);
  }

  /**
   * Both Solr facet output shapes normalise to value => count.
   *
   * @covers ::facetCounts
   */
  public function testFacetCounts(): void {
    $this->assertSame(['a' => 3, 'b' => 1], QueryParams::facetCounts(['a', 3, 'b', 1]));
    $this->assertSame(['a' => 3, 'b' => 1], QueryParams::facetCounts([['a', 3], ['b', 1]]));
    $this->assertSame([], QueryParams::facetCounts([]));
  }

}
