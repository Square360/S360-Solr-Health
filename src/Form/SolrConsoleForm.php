<?php

declare(strict_types=1);

namespace Drupal\s360_solr_health\Form;

use Drupal\s360_solr_health\QueryParams;
use Drupal\s360_solr_health\SolrConsole;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin console for read-only Solr queries against this environment's core.
 */
final class SolrConsoleForm extends FormBase {

  public function __construct(
    protected SolrConsole $console,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('s360_solr_health.console'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 's360_solr_health.console';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache'] = ['max-age' => 0];
    $form['summary'] = $this->buildSummary();

    $indexes = $this->console->indexes();
    $options = ['' => $this->t('- Whole core (no index filter) -')];
    foreach ($indexes as $id => $index) {
      $options[$id] = $index->label() . ' (' . $id . ')';
    }

    $values = $form_state->get('console_input') ?? [];
    $form['query'] = [
      '#type' => 'details',
      '#title' => $this->t('Query'),
      '#open' => TRUE,
    ];
    $form['query']['index_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Index'),
      '#options' => $options,
      '#default_value' => $values['index_id'] ?? array_key_first($indexes) ?? '',
      '#description' => $this->t('Adds an index_id filter so results come from one index. The whole core is what the directory REST resources see before their own filter.'),
    ];
    $form['query']['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('q'),
      '#default_value' => $values['q'] ?? '*:*',
      '#maxlength' => 1024,
      '#description' => $this->t('Solr query. Blank means *:*. Field-scoped syntax works, e.g. tm_X3b_en_title_search:anxiety.'),
    ];
    $form['query']['fq'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Filter queries'),
      '#default_value' => $values['fq'] ?? '',
      '#rows' => 3,
      '#description' => $this->t('One fq per line, e.g. ss_bundle:cooked_case'),
    ];
    $form['query']['row1'] = ['#type' => 'container', '#attributes' => ['class' => ['form--inline']]];
    $form['query']['row1']['sort'] = [
      '#type' => 'textfield',
      '#title' => $this->t('sort'),
      '#default_value' => $values['sort'] ?? '',
      '#size' => 40,
      '#placeholder' => 'score desc, ds_field_published_date desc',
    ];
    $form['query']['row1']['rows'] = [
      '#type' => 'number',
      '#title' => $this->t('rows'),
      '#default_value' => $values['rows'] ?? QueryParams::DEFAULT_ROWS,
      '#min' => 1,
      '#max' => QueryParams::MAX_ROWS,
      '#size' => 4,
    ];
    $form['query']['row1']['start'] = [
      '#type' => 'number',
      '#title' => $this->t('start'),
      '#default_value' => $values['start'] ?? 0,
      '#min' => 0,
      '#size' => 6,
    ];
    $form['query']['fl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field list (fl)'),
      '#default_value' => $values['fl'] ?? '',
      '#maxlength' => 1024,
      '#description' => $this->t('Blank shows @default. Use * for every stored field.', ['@default' => QueryParams::DEFAULT_FL]),
    ];
    $form['query']['facet_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facet fields'),
      '#default_value' => $values['facet_fields'] ?? '',
      '#maxlength' => 512,
      '#description' => $this->t('Space- or comma-separated, e.g. ss_bundle sm_field_ert_perspectives'),
    ];
    $form['query']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('debugQuery (parsed query and scoring explain)'),
      '#default_value' => (bool) ($values['debug'] ?? FALSE),
    ];
    $form['query']['actions'] = ['#type' => 'actions'];
    $form['query']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run query'),
      '#button_type' => 'primary',
    ];

    if ($result = $form_state->get('console_result')) {
      $form['result'] = $this->buildResult($result, $form_state->get('console_params') ?? []);
    }
    if ($error = $form_state->get('console_error')) {
      $form['error'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['error' => [$error]],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keys = ['index_id', 'q', 'fq', 'sort', 'rows', 'start', 'fl', 'facet_fields', 'debug'];
    $input = [];
    foreach ($keys as $key) {
      $input[$key] = $form_state->getValue($key);
    }
    $params = QueryParams::build($input);
    $index = $input['index_id'] !== '' ? ($this->console->indexes()[$input['index_id']] ?? NULL) : NULL;

    $form_state->set('console_input', $input);
    $form_state->set('console_params', $params);
    $form_state->set('console_result', NULL);
    $form_state->set('console_error', NULL);
    try {
      $form_state->set('console_result', $this->console->query($index, $params));
    }
    catch (\Throwable $e) {
      $form_state->set('console_error', $e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Builds the tracker-versus-Solr summary table.
   */
  protected function buildSummary(): array {
    $rows = [];
    foreach ($this->console->summary() as $id => $row) {
      $rows[] = [
        'data' => [
          $row['label'] . ' (' . $id . ')',
          $row['server'],
          $row['tracked'] ?? '—',
          $row['indexed'] ?? '—',
          $row['solr_docs'] ?? '—',
          $row['message'],
        ],
        'class' => [$row['state'] === SolrConsole::STATE_OK ? 'color-success' : 'color-warning'],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Index health'),
      '#open' => TRUE,
      '#description' => $this->t('Tracker counts come from the database; the Solr count is a live query against this environment\'s core. A database cloned from another environment carries that environment\'s tracker, so "indexed" can read full while Solr is empty.'),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Index'),
          $this->t('Server'),
          $this->t('Tracked'),
          $this->t('Indexed'),
          $this->t('In Solr'),
          $this->t('Status'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No Solr-backed index is configured.'),
      ],
    ];
  }

  /**
   * Renders a Solr select response.
   */
  protected function buildResult(array $result, array $params): array {
    $response = $result['response'] ?? [];
    $docs = $response['docs'] ?? [];
    $num_found = (int) ($response['numFound'] ?? 0);
    $qtime = $result['responseHeader']['QTime'] ?? NULL;

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Results: @n found', ['@n' => $num_found]),
      '#open' => TRUE,
    ];
    $build['meta'] = [
      '#markup' => '<p>' . $this->t('@server · Solr QTime @qtime ms · round trip @rt ms · showing @from–@to', [
        '@server' => $result['console']['server'] ?? '',
        '@qtime' => $qtime ?? '?',
        '@rt' => $result['console']['elapsed_ms'] ?? '?',
        '@from' => $docs ? (int) ($response['start'] ?? 0) + 1 : 0,
        '@to' => (int) ($response['start'] ?? 0) + count($docs),
      ]) . '</p>',
    ];

    $facet_fields = $result['facet_counts']['facet_fields'] ?? [];
    if ($facet_fields) {
      $facet_rows = [];
      foreach ($facet_fields as $field => $raw) {
        foreach (QueryParams::facetCounts(is_array($raw) ? $raw : []) as $value => $count) {
          $facet_rows[] = [$field, $value, $count];
        }
      }
      $build['facets'] = [
        '#type' => 'details',
        '#title' => $this->t('Facets'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Field'), $this->t('Value'), $this->t('Count')],
          '#rows' => $facet_rows,
          '#empty' => $this->t('No facet values.'),
        ],
      ];
    }

    if ($docs) {
      $columns = [];
      foreach ($docs as $doc) {
        foreach (array_keys($doc) as $key) {
          $columns[$key] = TRUE;
        }
      }
      $columns = array_keys($columns);
      $doc_rows = [];
      foreach ($docs as $doc) {
        $cells = [];
        foreach ($columns as $column) {
          $value = $doc[$column] ?? '';
          if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
          }
          $value = (string) $value;
          $cells[] = mb_strlen($value) > 160 ? mb_substr($value, 0, 157) . '…' : $value;
        }
        $doc_rows[] = $cells;
      }
      $build['docs'] = [
        '#type' => 'table',
        '#header' => $columns,
        '#rows' => $doc_rows,
        '#attributes' => ['class' => ['solr-health-docs']],
        '#prefix' => '<div style="overflow-x:auto">',
        '#suffix' => '</div>',
      ];
    }

    if (!empty($result['debug'])) {
      $build['debug'] = [
        '#type' => 'details',
        '#title' => $this->t('Debug'),
        '#open' => FALSE,
        'body' => ['#markup' => '<pre>' . Html::escape(json_encode($result['debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'],
      ];
    }

    $build['params'] = [
      '#type' => 'details',
      '#title' => $this->t('Parameters sent'),
      '#open' => FALSE,
      'body' => ['#markup' => '<pre>' . Html::escape(json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'],
    ];
    $raw = $result;
    unset($raw['console']);
    $build['raw'] = [
      '#type' => 'details',
      '#title' => $this->t('Raw response'),
      '#open' => FALSE,
      'body' => ['#markup' => '<pre>' . Html::escape(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'],
    ];

    return $build;
  }

}
