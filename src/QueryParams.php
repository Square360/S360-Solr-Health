<?php

declare(strict_types=1);

namespace Drupal\s360_solr_health;

/**
 * Turns console form input into Solr select parameters.
 *
 * Pure and static so the shaping rules can be unit-tested without a site:
 * defaults, the rows cap, the index filter, and facet expansion all live here
 * rather than in the form.
 */
final class QueryParams {

  /**
   * Hard cap on rows per request. The console is for looking, not exporting.
   */
  public const MAX_ROWS = 50;

  /**
   * Rows returned when the form leaves the field blank.
   */
  public const DEFAULT_ROWS = 10;

  /**
   * Fields shown when the field list is blank.
   */
  public const DEFAULT_FL = 'id index_id ss_search_api_id ss_title score';

  /**
   * Builds the select parameters.
   *
   * @param array $input
   *   Raw values: q, index_id, fq (newline-separated), sort, fl, rows, start,
   *   facet_fields (whitespace- or comma-separated), debug (bool).
   *
   * @return array
   *   Parameters ready for the Solr select handler.
   */
  public static function build(array $input): array {
    $q = trim((string) ($input['q'] ?? ''));
    $params = [
      'q' => $q === '' ? '*:*' : $q,
      'start' => max(0, (int) ($input['start'] ?? 0)),
      'rows' => self::clampRows($input['rows'] ?? NULL),
      'wt' => 'json',
    ];

    $fl = trim((string) ($input['fl'] ?? ''));
    $params['fl'] = $fl === '' ? self::DEFAULT_FL : $fl;

    $fq = [];
    $index_id = trim((string) ($input['index_id'] ?? ''));
    if ($index_id !== '') {
      $fq[] = 'index_id:' . $index_id;
    }
    foreach (preg_split('/\R/', (string) ($input['fq'] ?? '')) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $fq[] = $line;
      }
    }
    if ($fq) {
      $params['fq'] = $fq;
    }

    $sort = trim((string) ($input['sort'] ?? ''));
    if ($sort !== '') {
      $params['sort'] = $sort;
    }

    $facets = preg_split('/[\s,]+/', trim((string) ($input['facet_fields'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if ($facets) {
      $params['facet'] = 'true';
      $params['facet.field'] = array_values(array_unique($facets));
      $params['facet.mincount'] = 1;
      $params['facet.limit'] = 25;
    }

    if (!empty($input['debug'])) {
      $params['debugQuery'] = 'true';
    }

    return $params;
  }

  /**
   * Clamps a rows value into [1, MAX_ROWS], defaulting when blank or invalid.
   */
  public static function clampRows(mixed $rows): int {
    if ($rows === NULL || $rows === '' || !is_numeric($rows)) {
      return self::DEFAULT_ROWS;
    }
    return max(1, min(self::MAX_ROWS, (int) $rows));
  }

  /**
   * Normalises a Solr facet_fields entry into [value => count].
   *
   * Solr returns either a flat [value, count, value, count] list (default
   * json.nl) or [[value, count], ...] pairs (json.nl=arrarr). Both are read.
   */
  public static function facetCounts(array $raw): array {
    $out = [];
    if ($raw && is_array($raw[0] ?? NULL)) {
      foreach ($raw as $pair) {
        if (isset($pair[0])) {
          $out[(string) $pair[0]] = (int) ($pair[1] ?? 0);
        }
      }
      return $out;
    }
    for ($i = 0, $n = count($raw); $i + 1 < $n; $i += 2) {
      $out[(string) $raw[$i]] = (int) $raw[$i + 1];
    }
    return $out;
  }

}
