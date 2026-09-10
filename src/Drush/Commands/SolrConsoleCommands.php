<?php

declare(strict_types=1);

namespace Drupal\s360_solr_health\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\s360_solr_health\SolrConsole;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the Solr console.
 *
 * Drush 12.4+/13 registration: discovered from src/Drush/Commands/ and
 * instantiated via AutowireTrait::create(). No permission check: drush runs
 * as root, and the command only reads.
 */
class SolrConsoleCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 's360_solr_health.console')]
    protected SolrConsole $console,
  ) {
    parent::__construct();
  }

  /**
   * Shows tracker-versus-Solr counts for every Solr-backed index.
   *
   * Exits non-zero when any index is empty in Solr, short of the tracker,
   * or on an unreachable server, so the command can gate a release step.
   */
  #[CLI\Command(name: 's360:solr:health', aliases: ['s360-solr-health'])]
  #[CLI\Usage(name: 'drush s360:solr:health', description: 'Table of tracked / indexed / in-Solr counts per index.')]
  #[CLI\Usage(name: 'drush s360:solr:health --format=json', description: 'Same as JSON, for scripts.')]
  #[CLI\FieldLabels(labels: [
    'index' => 'Index',
    'server' => 'Server',
    'tracked' => 'Tracked',
    'indexed' => 'Indexed',
    'solr' => 'In Solr',
    'state' => 'State',
    'message' => 'Message',
  ])]
  #[CLI\DefaultTableFields(fields: ['index', 'tracked', 'indexed', 'solr', 'state', 'message'])]
  public function status(): RowsOfFields {
    $rows = [];
    $bad = FALSE;
    foreach ($this->console->summary() as $id => $row) {
      $rows[$id] = [
        'index' => $id,
        'server' => $row['server'],
        'tracked' => $row['tracked'] ?? '',
        'indexed' => $row['indexed'] ?? '',
        'solr' => $row['solr_docs'] ?? '',
        'state' => $row['state'],
        'message' => (string) $row['message'],
      ];
      $bad_states = [
        SolrConsole::STATE_EMPTY,
        SolrConsole::STATE_SHORT,
        SolrConsole::STATE_UNREACHABLE,
        SolrConsole::STATE_ERROR,
      ];
      if (in_array($row['state'], $bad_states, TRUE)) {
        $bad = TRUE;
      }
    }
    if ($bad) {
      $this->logger()->warning(dt('At least one index is out of step with Solr.'));
    }
    return new RowsOfFields($rows);
  }

}
