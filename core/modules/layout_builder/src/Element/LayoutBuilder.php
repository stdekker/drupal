<?php

namespace Drupal\layout_builder\Element;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElement;
use Drupal\Core\Url;
use Drupal\layout_builder\Context\LayoutBuilderContextTrait;
use Drupal\layout_builder\LayoutBuilderHighlightTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a render element for building the Layout Builder UI.
 *
 * @RenderElement("layout_builder")
 */
class LayoutBuilder extends RenderElement implements ContainerFactoryPluginInterface {

  use AjaxHelperTrait;
  use LayoutBuilderContextTrait;
  use LayoutBuilderHighlightTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new LayoutBuilder.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   *   The layout tempstore repository.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LayoutTempstoreRepositoryInterface $layout_tempstore_repository, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('layout_builder.tempstore_repository'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#section_storage' => NULL,
      '#pre_render' => [
        [$this, 'preRender'],
      ],
    ];
  }

  /**
   * Pre-render callback: Renders the Layout Builder UI.
   */
  public function preRender($element) {
    if ($element['#section_storage'] instanceof SectionStorageInterface) {
      $element['layout_builder'] = $this->layout($element['#section_storage']);
    }
    return $element;
  }

  /**
   * Renders the Layout UI.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return array
   *   A render array.
   */
  protected function layout(SectionStorageInterface $section_storage) {
    $this->prepareLayout($section_storage);

    $output = [];
    if ($this->isAjax()) {
      $output['status_messages'] = [
        '#type' => 'status_messages',
      ];
    }
    $count = 0;
    for ($i = 0; $i < $section_storage->count(); $i++) {
      $output[] = $this->buildAddSectionLink($section_storage, $count);
      $output[] = $this->buildAdministrativeSection($section_storage, $count);
      $count++;
    }
    $output[] = $this->buildAddSectionLink($section_storage, $count);
    $output['#attached']['library'][] = 'layout_builder/drupal.layout_builder';
    $output['#type'] = 'container';
    $output['#attributes']['id'] = 'layout-builder';
    $output['#attributes']['class'][] = 'layout-builder';
    // Mark this UI as uncacheable.
    $output['#cache']['max-age'] = 0;
    return $output;
  }

  /**
   * Prepares a layout for use in the UI.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   */
  protected function prepareLayout(SectionStorageInterface $section_storage) {
    // If the layout has pending changes, add a warning.
    if ($this->layoutTempstoreRepository->has($section_storage)) {
      $this->messenger->addWarning($this->t('You have unsaved changes.'));
    }
    // If the layout is an override that has not yet been overridden, copy the
    // sections from the corresponding default.
    elseif ($section_storage instanceof OverridesSectionStorageInterface && !$section_storage->isOverridden()) {
      $sections = $section_storage->getDefaultSectionStorage()->getSections();
      foreach ($sections as $section) {
        $section_storage->appendSection($section);
      }
      $this->layoutTempstoreRepository->set($section_storage);
    }
  }

  /**
   * Builds a link to add a new section at a given delta.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The delta of the section to splice.
   *
   * @return array
   *   A render array for a link.
   */
  protected function buildAddSectionLink(SectionStorageInterface $section_storage, $delta) {
    $storage_type = $section_storage->getStorageType();
    $storage_id = $section_storage->getStorageId();

    // If the delta and the count are the same, it is either the end of the
    // layout or an empty layout.
    if ($delta === count($section_storage)) {
      if ($delta === 0) {
        $title = $this->t('Add Section');
      }
      else {
        $title = $this->t('Add Section <span class="visually-hidden">at end of layout</span>');
      }
    }
    // If the delta and the count are different, it is either the beginning of
    // the layout or in between two sections.
    else {
      if ($delta === 0) {
        $title = $this->t('Add Section <span class="visually-hidden">at start of layout</span>');
      }
      else {
        $title = $this->t('Add Section <span class="visually-hidden">between @first and @second</span>', ['@first' => $delta, '@second' => $delta + 1]);
      }
    }

    return [
      'link' => [
        '#type' => 'link',
        '#title' => $title,
        '#url' => Url::fromRoute('layout_builder.choose_section',
          [
            'section_storage_type' => $storage_type,
            'section_storage' => $storage_id,
            'delta' => $delta,
          ],
          [
            'attributes' => [
              'class' => [
                'use-ajax',
                'layout-builder__link',
                'layout-builder__link--add',
              ],
              'data-dialog-type' => 'dialog',
              'data-dialog-renderer' => 'off_canvas',
            ],
          ]
        ),
      ],
      '#type' => 'container',
      '#attributes' => [
        'class' => ['layout-builder__add-section'],
        'data-layout-builder-highlight-id' => $this->sectionAddHighlightId($delta),
      ],
    ];
  }

  /**
   * Builds the render array for the layout section while editing.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The delta of the section.
   *
   * @return array
   *   The render array for a given section.
   */
  protected function buildAdministrativeSection(SectionStorageInterface $section_storage, $delta) {
    $storage_type = $section_storage->getStorageType();
    $storage_id = $section_storage->getStorageId();
    $section = $section_storage->getSection($delta);

    $layout = $section->getLayout();
    $build = $section->toRenderArray($this->getAvailableContexts($section_storage), TRUE);
    $layout_definition = $layout->getPluginDefinition();

    $region_labels = $layout_definition->getRegionLabels();
    foreach ($layout_definition->getRegions() as $region => $info) {
      if (!empty($build[$region])) {
        foreach (Element::children($build[$region]) as $uuid) {
          $build[$region][$uuid]['#attributes']['class'][] = 'draggable';
          $build[$region][$uuid]['#attributes']['data-layout-block-uuid'] = $uuid;
          $build[$region][$uuid]['#attributes']['data-layout-builder-highlight-id'] = $this->blockUpdateHighlightId($uuid);
          $build[$region][$uuid]['#contextual_links'] = [
            'layout_builder_block' => [
              'route_parameters' => [
                'section_storage_type' => $storage_type,
                'section_storage' => $storage_id,
                'delta' => $delta,
                'region' => $region,
                'uuid' => $uuid,
              ],
            ],
          ];
        }
      }

      $build[$region]['layout_builder_add_block']['link'] = [
        '#type' => 'link',
        // Add one to the current delta since it is zero-indexed.
        '#title' => $this->t('Add Block <span class="visually-hidden">in section @section, @region region</span>', ['@section' => $delta + 1, '@region' => $region_labels[$region]]),
        '#url' => Url::fromRoute('layout_builder.choose_block',
          [
            'section_storage_type' => $storage_type,
            'section_storage' => $storage_id,
            'delta' => $delta,
            'region' => $region,
          ],
          [
            'attributes' => [
              'class' => [
                'use-ajax',
                'layout-builder__link',
                'layout-builder__link--add',
              ],
              'data-dialog-type' => 'dialog',
              'data-dialog-renderer' => 'off_canvas',
            ],
          ]
        ),
      ];
      $build[$region]['layout_builder_add_block']['#type'] = 'container';
      $build[$region]['layout_builder_add_block']['#attributes'] = [
        'class' => ['layout-builder__add-block'],
        'data-layout-builder-highlight-id' => $this->blockAddHighlightId($delta, $region),
      ];
      $build[$region]['layout_builder_add_block']['#weight'] = 1000;
      $build[$region]['#attributes']['data-region'] = $region;
      $build[$region]['#attributes']['class'][] = 'layout-builder__region';
      $build[$region]['#attributes']['role'] = 'group';
      $build[$region]['#attributes']['aria-label'] = $this->t('@region region in section @section', [
        '@region' => $info['label'],
        '@section' => $delta + 1,
      ]);
    }

    $build['#attributes']['data-layout-update-url'] = Url::fromRoute('layout_builder.move_block', [
      'section_storage_type' => $storage_type,
      'section_storage' => $storage_id,
    ])->toString();

    $build['#attributes']['data-layout-delta'] = $delta;
    $build['#attributes']['class'][] = 'layout-builder__layout';
    $build['#attributes']['data-layout-builder-highlight-id'] = $this->sectionUpdateHighlightId($delta);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['layout-builder__section'],
        'role' => 'group',
        'aria-label' => $this->t('Section @section', ['@section' => $delta + 1]),
      ],
      'remove' => [
        '#type' => 'link',
        '#title' => $this->t('Remove section <span class="visually-hidden">@section</span>', ['@section' => $delta + 1]),
        '#url' => Url::fromRoute('layout_builder.remove_section', [
          'section_storage_type' => $storage_type,
          'section_storage' => $storage_id,
          'delta' => $delta,
        ]),
        '#attributes' => [
          'class' => [
            'use-ajax',
            'layout-builder__link',
            'layout-builder__link--remove',
          ],
          'data-dialog-type' => 'dialog',
          'data-dialog-renderer' => 'off_canvas',
        ],
      ],
      'configure' => [
        '#type' => 'link',
        '#title' => $this->t('Configure section <span class="visually-hidden">@section</span>', ['@section' => $delta + 1]),
        '#access' => $layout instanceof PluginFormInterface,
        '#url' => Url::fromRoute('layout_builder.configure_section', [
          'section_storage_type' => $storage_type,
          'section_storage' => $storage_id,
          'delta' => $delta,
        ]),
        '#attributes' => [
          'class' => [
            'use-ajax',
            'layout-builder__link',
            'layout-builder__link--configure',
          ],
          'data-dialog-type' => 'dialog',
          'data-dialog-renderer' => 'off_canvas',
        ],
      ],
      'layout-builder__section' => $build,
    ];
  }

}
