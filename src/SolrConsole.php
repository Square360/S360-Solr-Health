<?php

declare(strict_types=1);

namespace Drupal\s360_solr_health;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Tracker\TrackerPluginBase;
use Drupal\user\UserInterface;

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
  public const STATE_OVER = 'over';
  public const STATE_BACKLOG = 'backlog';
  public const STATE_DISABLED = 'disabled';
  public const STATE_UNREACHABLE = 'unreachable';
  public const STATE_ERROR = 'error';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
    protected Connection $database,
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
   * Solr counts use the backend's own index filter: the targeted index ID
   * and, when the server filters by site hash, the targeted site hash.
   * Documents for the same index under another hash, left by an earlier
   * database or environment, are counted apart.
   *
   * @return array
   *   Rows keyed by index ID with: id, label, server, server_available,
   *   enabled, tracked, indexed, excluded (int|null), solr_docs (int|null),
   *   solr_other (int|null), solr_index_id (string|null), site_hash
   *   (string|null), hash_filtered (bool), error (string|null),
   *   state (one of the STATE_* constants), message (translatable).
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
        'id' => $id,
        'label' => $index->label(),
        'server' => $server->label(),
        'server_available' => $availability[$server_id],
        'enabled' => $index->status(),
        'tracked' => NULL,
        'indexed' => NULL,
        'excluded' => NULL,
        'solr_docs' => NULL,
        'solr_other' => NULL,
        'solr_index_id' => NULL,
        'site_hash' => NULL,
        'hash_filtered' => FALSE,
        'error' => NULL,
      ];

      try {
        $tracker = $index->getTrackerInstance();
        $row['tracked'] = $tracker->getTotalItemsCount();
        $row['indexed'] = $tracker->getIndexedItemsCount();
        $row['excluded'] = $this->excludedCount($index);
      }
      catch (\Throwable $e) {
        $row['error'] = 'Tracker: ' . $e->getMessage();
      }

      if ($availability[$server_id]) {
        try {
          // Same filter the backend puts on every search
          // (getIndexFilterQueryString), so solr_docs is what searches see.
          // Documents under another site hash, left by an earlier database,
          // are counted apart either way: with the server's site_hash option
          // off (the search_api_solr default) searches see them too.
          /** @var \Drupal\search_api_solr\SolrBackendInterface $backend */
          $backend = $server->getBackend();
          $row['solr_index_id'] = $backend->getTargetedIndexId($index);
          $row['site_hash'] = $backend->getTargetedSiteHash($index);
          $row['hash_filtered'] = !empty($backend->getConfiguration()['site_hash']);
          $index_fq = 'index_id:"' . $row['solr_index_id'] . '"';
          $hash_fq = 'hash:"' . $row['site_hash'] . '"';
          $row['solr_docs'] = $this->count($server, $row['hash_filtered'] ? [$index_fq, $hash_fq] : [$index_fq]);
          $row['solr_other'] = $this->count($server, [$index_fq, '-' . $hash_fq]);
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
    $excluded = $row['excluded'] ?? 0;
    // Items the entity_status processor drops at index time are tracked and
    // marked indexed but never written to Solr, so Solr is expected to hold
    // that many fewer documents than the tracker's indexed count.
    $expected = max(0, $indexed - $excluded);
    // Solr can legitimately hold up to the tracker's full count while a
    // reindex is in progress (reset-tracker leaves the old documents in place),
    // so a surplus is only stale beyond tracked less excluded.
    $ceiling = max(0, $tracked - $excluded);
    $solr = $row['solr_docs'];
    $other = $row['solr_other'] ?? 0;
    return match (TRUE) {
      !$row['server_available'] => [self::STATE_UNREACHABLE, $this->t('Server unreachable')],
      $row['error'] !== NULL => [self::STATE_ERROR, $row['error']],
      !$row['enabled'] => [self::STATE_DISABLED, $this->t('Index disabled')],
      $solr === 0 && $tracked - $excluded > 0 => [
        self::STATE_EMPTY,
        $this->t('Solr holds no documents for this index while the tracker has @n item(s). Typical after a database clone from another environment: run a full reindex.', ['@n' => $tracked]),
      ],
      $solr !== NULL && $solr < $expected => [
        self::STATE_SHORT,
        $excluded > 0
          ? $this->t('Solr holds @solr document(s) but expects @expected (@indexed indexed, less @excluded unpublished excluded by entity_status). Reindex to reconcile.', [
            '@solr' => $solr,
            '@expected' => $expected,
            '@indexed' => $indexed,
            '@excluded' => $excluded,
          ])
          : $this->t('Solr holds @solr document(s) but the tracker claims @indexed indexed. Reindex to reconcile.', [
            '@solr' => $solr,
            '@indexed' => $indexed,
          ]),
      ],
      $other > 0 => [
        self::STATE_OVER,
        $this->t('Solr also holds @n document(s) for this index under another site hash, left by an earlier database or environment. @impact Remove them with a delete-by-query on: index_id:@index AND -hash:@hash', [
          '@n' => $other,
          '@impact' => !empty($row['hash_filtered'])
            ? $this->t('Searches ignore them, but they skew counts.')
            : $this->t('This server does not filter searches by site hash, so searches see them too: result counts and pagers include the copies.'),
          '@index' => $row['solr_index_id'] ?? '',
          '@hash' => $row['site_hash'] ?? '',
        ]),
      ],
      $solr !== NULL && $solr > $ceiling => [
        self::STATE_OVER,
        $this->t('Solr holds @solr document(s) but the tracker has only @ceiling to send (@tracked tracked, less @excluded excluded): @n stale document(s), usually items deleted in Drupal but not removed from Solr. Clear the index and reindex to reconcile.', [
          '@solr' => $solr,
          '@ceiling' => $ceiling,
          '@tracked' => $tracked,
          '@excluded' => $excluded,
          '@n' => $solr - $ceiling,
        ]),
      ],
      $tracked > $indexed => [
        self::STATE_BACKLOG,
        $this->t('@n item(s) waiting to be indexed.', ['@n' => $tracked - $indexed]),
      ],
      $excluded > 0 => [
        self::STATE_OK,
        $this->t('In sync (@n unpublished item(s) excluded by entity_status)', ['@n' => $excluded]),
      ],
      default => [self::STATE_OK, $this->t('In sync')],
    };
  }

  /**
   * Counts indexed items the entity_status processor keeps out of Solr.
   *
   * The processor drops unpublished entities and blocked users in
   * alterIndexedItems(), but the tracker still marks them indexed. Each
   * tracked item is one translation, so publication is checked per language.
   *
   * @return int|null
   *   The count; 0 when the processor is off; NULL when the tracker does not
   *   keep its items in the search_api_item table (count unknown).
   */
  public function excludedCount(IndexInterface $index): ?int {
    if (!$index->isValidProcessor('entity_status')) {
      return 0;
    }
    if (!$index->getTrackerInstance() instanceof TrackerPluginBase) {
      return NULL;
    }

    $excluded = 0;
    foreach ($index->getDatasources() as $datasource_id => $datasource) {
      $entity_type_id = $datasource->getEntityTypeId();
      if (!$entity_type_id) {
        continue;
      }
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$definition) {
        continue;
      }
      if ($entity_type_id === 'user') {
        $status_field = 'status';
      }
      elseif ($definition->entityClassImplements(EntityPublishedInterface::class) && $definition->hasKey('published')) {
        $status_field = $definition->getKey('published');
      }
      else {
        continue;
      }

      // Tracked item IDs look like "entity:node/123:en".
      $tracked = [];
      $item_ids = $this->database->select('search_api_item', 'sai')
        ->fields('sai', ['item_id'])
        ->condition('sai.index_id', $index->id())
        ->condition('sai.datasource', $datasource_id)
        ->condition('sai.status', TrackerPluginBase::STATUS_INDEXED)
        ->execute()
        ->fetchCol();
      foreach ($item_ids as $item_id) {
        $raw = substr($item_id, strlen($datasource_id) + 1);
        $colon = strrpos($raw, ':');
        if ($colon === FALSE) {
          continue;
        }
        $tracked[substr($raw, 0, $colon)][] = substr($raw, $colon + 1);
      }
      if (!$tracked) {
        continue;
      }

      // Candidates: entities with any unpublished translation. Usually few.
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $candidates = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($status_field, 0)
        ->execute();
      $candidates = array_intersect(array_map('strval', $candidates), array_map('strval', array_keys($tracked)));
      foreach (array_chunk($candidates, 200) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $id => $entity) {
          foreach ($tracked[(string) $id] ?? [] as $langcode) {
            $translation = ($entity->isTranslatable() && $entity->hasTranslation($langcode)) ? $entity->getTranslation($langcode) : $entity;
            $enabled = TRUE;
            if ($translation instanceof EntityPublishedInterface) {
              $enabled = $translation->isPublished();
            }
            elseif ($translation instanceof UserInterface) {
              $enabled = $translation->isActive();
            }
            if (!$enabled) {
              $excluded++;
            }
          }
        }
        $storage->resetCache($chunk);
      }
    }
    return $excluded;
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
   * Counts the documents matching a set of filter queries.
   */
  protected function count(ServerInterface $server, array $fq): int {
    $response = $this->select($server, [
      'q' => '*:*',
      'fq' => $fq,
      'rows' => 0,
      'wt' => 'json',
    ]);
    return (int) ($response['response']['numFound'] ?? 0);
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
