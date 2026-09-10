<?php

declare(strict_types=1);

namespace Drupal\s360_solr_health;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;

/**
 * Read-only access to this environment's Solr core.
 *
 * Talks to Solr through the Search API server's connector, so it reaches
 * whichever core the current environment owns (Pantheon Search or any other
 * search_api_solr backend). Only the select handler is ever called.
 */
final class SolrConsole {

  use StringTranslationTrait;

  public const STATE_OK = 'ok';
  public const STATE_EMPTY = 'empty';
  public const STATE_SHORT = 'short';
  public const STATE_BACKLOG = 'backlog';
  public const STATE_DISABLED = 'disabled';
  public const STATE_UNREACHABLE = 'unreachable';
  public const STATE_ERROR = 'error';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Search API indexes that live on a Solr server, keyed by index ID.
   *
   * @return \Drupal\search_api\IndexInterface[]
   *   The indexes.
   */
  public function indexes(): array {
    $out = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $id => $index) {
      $server = $index->getServerInstance();
      if ($server && $server->getBackendId() === 'search_api_solr') {
        $out[$id] = $index;
      }
    }
    return $out;
  }

  /**
   * Tracker-versus-Solr counts for every Solr-backed index.
   *
   * Every failure is returned in the row rather than thrown, so one broken
   * index cannot blank the whole summary.
   *
   * @return array
   *   Rows keyed by index ID with: label, server, server_available, enabled,
   *   tracked, indexed, solr_docs (int|null), error (string|null), state
   *   (one of the STATE_* constants), message (translatable).
   */
  public function summary(): array {
    $rows = [];
    $availability = [];
    foreach ($this->indexes() as $id => $index) {
      $server = $index->getServerInstance();
      $server_id = $server->id();
      if (!array_key_exists($server_id, $availability)) {
        try {
          $availability[$server_id] = $server->isAvailable();
        }
        catch (\Throwable $e) {
          $availability[$server_id] = FALSE;
        }
      }

      $row = [
        'label' => $index->label(),
        'server' => $server->label(),
        'server_available' => $availability[$server_id],
        'enabled' => $index->status(),
        'tracked' => NULL,
        'indexed' => NULL,
        'solr_docs' => NULL,
        'error' => NULL,
      ];

      try {
        $tracker = $index->getTrackerInstance();
        $row['tracked'] = $tracker->getTotalItemsCount();
        $row['indexed'] = $tracker->getIndexedItemsCount();
      }
      catch (\Throwable $e) {
        $row['error'] = 'Tracker: ' . $e->getMessage();
      }

      if ($availability[$server_id]) {
        try {
          $response = $this->select($server, [
            'q' => '*:*',
            'fq' => ['index_id:' . $id],
            'rows' => 0,
            'wt' => 'json',
          ]);
          $row['solr_docs'] = (int) ($response['response']['numFound'] ?? 0);
        }
        catch (\Throwable $e) {
          $row['error'] = trim(($row['error'] ? $row['error'] . ' ' : '') . 'Solr: ' . $e->getMessage());
        }
      }

      [$row['state'], $row['message']] = $this->assess($row);
      $rows[$id] = $row;
    }
    return $rows;
  }

  /**
   * Names the failure shape a summary row is in.
   *
   * @return array
   *   [state, message].
   */
  protected function assess(array $row): array {
    $tracked = $row['tracked'] ?? 0;
    $indexed = $row['indexed'] ?? 0;
    $solr = $row['solr_docs'];
    return match (TRUE) {
      !$row['server_available'] => [self::STATE_UNREACHABLE, $this->t('Server unreachable')],
      $row['error'] !== NULL => [self::STATE_ERROR, $row['error']],
      !$row['enabled'] => [self::STATE_DISABLED, $this->t('Index disabled')],
      $solr === 0 && $tracked > 0 => [
        self::STATE_EMPTY,
        $this->t('Solr holds no documents for this index while the tracker has @n item(s). Typical after a database clone from another environment: run a full reindex.', ['@n' => $tracked]),
      ],
      $solr !== NULL && $solr < $indexed => [
        self::STATE_SHORT,
        $this->t('Solr holds @solr document(s) but the tracker claims @indexed indexed. Reindex to reconcile.', [
          '@solr' => $solr,
          '@indexed' => $indexed,
        ]),
      ],
      $tracked > $indexed => [
        self::STATE_BACKLOG,
        $this->t('@n item(s) waiting to be indexed.', ['@n' => $tracked - $indexed]),
      ],
      default => [self::STATE_OK, $this->t('In sync')],
    };
  }

  /**
   * Runs a select query against the server that hosts an index.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The index whose server to query, or NULL for the first Solr server.
   * @param array $params
   *   Select parameters, normally from QueryParams::build().
   *
   * @return array
   *   Decoded Solr response plus 'console' => ['elapsed_ms', 'server'].
   *
   * @throws \RuntimeException
   *   When no Solr server exists.
   */
  public function query(?IndexInterface $index, array $params): array {
    $server = $index?->getServerInstance();
    if (!$server) {
      foreach ($this->indexes() as $candidate) {
        $server = $candidate->getServerInstance();
        break;
      }
    }
    if (!$server) {
      throw new \RuntimeException('No Solr-backed Search API server is configured.');
    }

    $start = microtime(TRUE);
    $response = $this->select($server, $params);
    $response['console'] = [
      'elapsed_ms' => round((microtime(TRUE) - $start) * 1000, 1),
      'server' => $server->label(),
    ];
    return $response;
  }

  /**
   * POSTs select parameters to the server's core.
   */
  protected function select(ServerInterface $server, array $params): array {
    /** @var \Drupal\search_api_solr\SolrBackendInterface $backend */
    $backend = $server->getBackend();
    $connector = $backend->getSolrConnector();
    $response = $connector->coreRestPost('select', json_encode(['params' => $params]));
    if (is_string($response)) {
      $decoded = json_decode($response, TRUE);
      $response = is_array($decoded) ? $decoded : ['raw' => $response];
    }
    return is_array($response) ? $response : [];
  }

}
