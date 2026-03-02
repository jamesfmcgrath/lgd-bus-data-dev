<?php

declare(strict_types=1);

namespace Drupal\bus_times\Form;

use Drupal\bus_times\Service\BodsApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bus Times admin settings form.
 *
 * Provides API source configuration and a live test-connection button.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly BodsApiClient $apiClient,
  ) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('key.repository'),
      $container->get('bus_times.bods_api_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'bus_times_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['bus_times.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bus_times.settings');

    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Data Source'),
      '#open' => TRUE,
    ];

    $form['source']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source label'),
      '#description' => $this->t('Human-readable name for this data source (e.g. "Cumberland BODS").'),
      '#default_value' => $config->get('source.label') ?? '',
    ];

    $form['source']['api_key_id'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Select the Key entity that holds your BODS API key. Create keys at <a href="/admin/config/system/keys">Configuration → System → Keys</a>.'),
      '#default_value' => $config->get('source.api_key_id') ?? '',
      '#empty_option' => $this->t('- Select a key -'),
      '#required' => FALSE,
    ];

    $form['source']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('BODS API base URL'),
      '#description' => $this->t('Leave as default unless using a staging or mock endpoint.'),
      '#default_value' => $config->get('source.base_url') ?? 'https://data.bus-data.dft.gov.uk/api/v1',
      '#required' => TRUE,
    ];

    $form['source']['admin_area'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ATCO admin area code'),
      '#description' => $this->t('Filter GTFS data to a single admin area (e.g. <code>099</code> for Cumberland). Leave empty to import all areas (not recommended).'),
      '#default_value' => $config->get('source.admin_area') ?? '',
      '#maxlength' => 10,
    ];

    $form['source']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable this source'),
      '#default_value' => $config->get('source.enabled') ?? TRUE,
    ];

    // Test connection button + result wrapper.
    $form['source']['connection_test'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'bus-times-connection-test'],
    ];

    $form['source']['connection_test']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test connection'),
      '#ajax' => [
        'callback' => '::ajaxTestConnection',
        'wrapper' => 'bus-times-connection-test',
        'effect' => 'fade',
      ],
      '#limit_validation_errors' => [],
    ];

    // Show result from a previous AJAX call if present.
    if ($form_state->has('connection_test_result')) {
      $result = $form_state->get('connection_test_result');
      $form['source']['connection_test']['result'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $result['success']
            ? ['messages', 'messages--status']
            : ['messages', 'messages--error'],
          'role' => 'alert',
        ],
        'message' => [
          '#plain_text' => $result['message'],
        ],
      ];
    }

    $form['import'] = [
      '#type' => 'details',
      '#title' => $this->t('Import settings'),
      '#open' => FALSE,
    ];

    $form['import']['schedule'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Import schedule (cron expression)'),
      '#description' => $this->t('Standard 5-field cron expression. Default <code>0 3 * * *</code> runs daily at 03:00.'),
      '#default_value' => $config->get('source.schedule') ?? '0 3 * * *',
      '#maxlength' => 30,
    ];

    $form['import']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#description' => $this->t('Number of GTFS rows to process per batch. Reduce if hitting memory limits.'),
      '#default_value' => $config->get('import.batch_size') ?? 500,
      '#min' => 50,
      '#max' => 5000,
    ];

    $form['import']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP timeout (seconds)'),
      '#default_value' => $config->get('import.timeout') ?? 30,
      '#min' => 5,
      '#max' => 120,
    ];

    $form['import']['log_retention'] = [
      '#type' => 'number',
      '#title' => $this->t('Import log retention (days)'),
      '#default_value' => $config->get('import.log_retention') ?? 30,
      '#min' => 1,
      '#max' => 365,
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#open' => FALSE,
    ];

    $form['display']['realtime_poll_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Real-time poll interval (seconds)'),
      '#description' => $this->t('How often the departure board JS polls for live data.'),
      '#default_value' => $config->get('display.realtime_poll_interval') ?? 30,
      '#min' => 10,
      '#max' => 300,
    ];

    $form['display']['departures_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Default departures to show'),
      '#default_value' => $config->get('display.departures_limit') ?? 10,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['display']['map_default_zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Map default zoom level'),
      '#default_value' => $config->get('display.map_default_zoom') ?? 13,
      '#min' => 1,
      '#max' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback for the test-connection button.
   */
  public function ajaxTestConnection(array &$form, FormStateInterface $form_state): array {
    $result = $this->apiClient->testConnection();
    $form_state->set('connection_test_result', $result);
    $form_state->setRebuild(TRUE);
    return $form['source']['connection_test'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('bus_times.settings')
      ->set('source.label', $form_state->getValue('label'))
      ->set('source.api_key_id', $form_state->getValue('api_key_id'))
      ->set('source.base_url', rtrim((string) $form_state->getValue('base_url'), '/'))
      ->set('source.admin_area', trim((string) $form_state->getValue('admin_area')))
      ->set('source.schedule', trim((string) $form_state->getValue('schedule')))
      ->set('source.enabled', (bool) $form_state->getValue('enabled'))
      ->set('import.batch_size', (int) $form_state->getValue('batch_size'))
      ->set('import.timeout', (int) $form_state->getValue('timeout'))
      ->set('import.log_retention', (int) $form_state->getValue('log_retention'))
      ->set('display.realtime_poll_interval', (int) $form_state->getValue('realtime_poll_interval'))
      ->set('display.departures_limit', (int) $form_state->getValue('departures_limit'))
      ->set('display.map_default_zoom', (int) $form_state->getValue('map_default_zoom'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
