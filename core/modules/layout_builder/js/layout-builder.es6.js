/**
 * @file
 * Attaches the behaviors for the Layout Builder module.
 */

(($, Drupal) => {
  const { ajax, behaviors, debounce, announce, formatPlural } = Drupal;

  /*
   * Boolean that tracks if block listing is currently being filtered. Declared
   * outside of behaviors so value is retained on rebuild.
   */
  let layoutBuilderBlocksFiltered = false;

  /**
   * Provides the ability to filter the block listing in Add Block dialog.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach block filtering behavior to Add Block dialog.
   */
  behaviors.layoutBuilderBlockFilter = {
    attach(context) {
      const $categories = $('.js-layout-builder-categories', context);
      const $filterLinks = $categories.find('.js-layout-builder-block-link');

      /**
       * Filters the block list.
       *
       * @param {jQuery.Event} e
       *   The jQuery event for the keyup event that triggered the filter.
       */
      const filterBlockList = e => {
        const query = $(e.target)
          .val()
          .toLowerCase();

        /**
         * Shows or hides the block entry based on the query.
         *
         * @param {number} index
         *   The index in the loop, as provided by `jQuery.each`
         * @param {HTMLElement} link
         *   The link to add the block.
         */
        const toggleBlockEntry = (index, link) => {
          const $link = $(link);
          const textMatch =
            $link
              .text()
              .toLowerCase()
              .indexOf(query) !== -1;
          $link.toggle(textMatch);
        };

        // Filter if the length of the query is at least 2 characters.
        if (query.length >= 2) {
          // Attribute to note which categories are closed before opening all.
          $categories
            .find('.js-layout-builder-category:not([open])')
            .attr('remember-closed', '');

          // Open all categories so every block is available to filtering.
          $categories.find('.js-layout-builder-category').attr('open', '');
          // Toggle visibility of links based on query.
          $filterLinks.each(toggleBlockEntry);

          // Only display categories containing visible links.
          $categories
            .find(
              '.js-layout-builder-category:not(:has(.js-layout-builder-block-link:visible))',
            )
            .hide();

          announce(
            formatPlural(
              $categories.find('.js-layout-builder-block-link:visible').length,
              '1 block is available in the modified list.',
              '@count blocks are available in the modified list.',
            ),
          );
          layoutBuilderBlocksFiltered = true;
        } else if (layoutBuilderBlocksFiltered) {
          layoutBuilderBlocksFiltered = false;
          // Remove "open" attr from categories that were closed pre-filtering.
          $categories
            .find('.js-layout-builder-category[remember-closed]')
            .removeAttr('open')
            .removeAttr('remember-closed');
          $categories.find('.js-layout-builder-category').show();
          $filterLinks.show();
          announce(Drupal.t('All available blocks are listed.'));
        }
      };

      $('input.js-layout-builder-filter', context)
        .once('block-filter-text')
        .on('keyup', debounce(filterBlockList, 200));
    },
  };

  /**
   * Provides the ability to drag blocks to new positions in the layout.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach block drag behavior to the Layout Builder UI.
   */
  behaviors.layoutBuilderBlockDrag = {
    attach(context) {
      $(context)
        .find('.layout-builder__region')
        .sortable({
          items: '> .draggable',
          connectWith: '.layout-builder__region',
          placeholder: 'ui-state-drop',

          /**
           * Updates the layout with the new position of the block.
           *
           * @param {jQuery.Event} event
           *   The jQuery Event object.
           * @param {Object} ui
           *   An object containing information about the item being sorted.
           */
          update(event, ui) {
            // Check if the region from the event and region for the item match.
            const itemRegion = ui.item.closest('.layout-builder__region');
            if (event.target === itemRegion[0]) {
              // Find the destination delta.
              const deltaTo = ui.item
                .closest('[data-layout-delta]')
                .data('layout-delta');
              // If the block didn't leave the original delta use the destination.
              const deltaFrom = ui.sender
                ? ui.sender.closest('[data-layout-delta]').data('layout-delta')
                : deltaTo;
              ajax({
                url: [
                  ui.item
                    .closest('[data-layout-update-url]')
                    .data('layout-update-url'),
                  deltaFrom,
                  deltaTo,
                  itemRegion.data('region'),
                  ui.item.data('layout-block-uuid'),
                  ui.item
                    .prev('[data-layout-block-uuid]')
                    .data('layout-block-uuid'),
                ]
                  .filter(element => element !== undefined)
                  .join('/'),
              }).execute();
            }
          },
        });
    },
  };

  /**
   * Disables interactive elements in previewed blocks.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach disabling interactive elements behavior to the Layout Builder UI.
   */
  behaviors.layoutBuilderDisableInteractiveElements = {
    attach() {
      // Disable interactive elements inside preview blocks.
      const $blocks = $('#layout-builder [data-layout-block-uuid]');
      $blocks.find('input, textarea, select').prop('disabled', true);
      $blocks
        .find('a')
        // Don't disable contextual links.
        // @see \Drupal\contextual\Element\ContextualLinksPlaceholder
        .not(
          (index, element) =>
            $(element).closest('[data-contextual-id]').length > 0,
        )
        .on('click mouseup touchstart', e => {
          e.preventDefault();
          e.stopPropagation();
        });

      /*
       * In preview blocks, remove from the tabbing order all input elements
       * and elements specifically assigned a tab index, other than those
       * related to contextual links.
       */
      $blocks
        .find(
          'button, [href], input, select, textarea, iframe, [tabindex]:not([tabindex="-1"]):not(.tabbable)',
        )
        .not(
          (index, element) =>
            $(element).closest('[data-contextual-id]').length > 0,
        )
        .attr('tabindex', -1);
    },
  };

  // After a dialog opens, highlight element that the dialog is acting on.
  $(window).on('dialog:aftercreate', (event, dialog, $element) => {
    if (Drupal.offCanvas.isOffCanvas($element)) {
      // Start by removing any existing highlighted elements.
      $('.is-layout-builder-highlighted').removeClass(
        'is-layout-builder-highlighted',
      );

      /*
       * Every dialog has a single 'data-layout-builder-target-highlight-id'
       * attribute. Every dialog-opening element has a unique
       * 'data-layout-builder-highlight-id' attribute.
       *
       * When the value of data-layout-builder-target-highlight-id matches
       * an element's value of data-layout-builder-highlight-id, the class
       * 'is-layout-builder-highlighted' is added to element.
       */
      const id = $element
        .find('[data-layout-builder-target-highlight-id]')
        .attr('data-layout-builder-target-highlight-id');
      if (id) {
        $(`[data-layout-builder-highlight-id="${id}"]`).addClass(
          'is-layout-builder-highlighted',
        );
      }
    }
  });

  /*
   * When a Layout Builder dialog is triggered, the main canvas resizes. After
   * the resize transition is complete, see if the target element is still
   * visible in viewport. If not, scroll page so the target element is again
   * visible.
   *
   * @todo Replace this custom solution when a general solution is made
   *   available with https://www.drupal.org/node/3033410
   */
  if (document.querySelector('[data-off-canvas-main-canvas]')) {
    const mainCanvas = document.querySelector('[data-off-canvas-main-canvas]');

    // This event fires when canvas CSS transitions are complete.
    mainCanvas.addEventListener('transitionend', () => {
      const $target = $('.is-layout-builder-highlighted');

      if ($target.length > 0) {
        // These four variables are used to determine if the element is in the
        // viewport.
        const targetTop = $target.offset().top;
        const targetBottom = targetTop + $target.outerHeight();
        const viewportTop = $(window).scrollTop();
        const viewportBottom = viewportTop + $(window).height();

        // If the element is not in the viewport, scroll it into view.
        if (targetBottom < viewportTop || targetTop > viewportBottom) {
          const viewportMiddle = (viewportBottom + viewportTop) / 2;
          const scrollAmount = targetTop - viewportMiddle;

          // Check whether the browser supports scrollBy(options). If it does
          // not, use scrollBy(x-coord, y-coord) instead.
          if ('scrollBehavior' in document.documentElement.style) {
            window.scrollBy({
              top: scrollAmount,
              left: 0,
              behavior: 'smooth',
            });
          } else {
            window.scrollBy(0, scrollAmount);
          }
        }
      }
    });
  }

  // When a dialog closes, remove the highlight from all elements.
  $(window).on('dialog:afterclose', (event, dialog, $element) => {
    if (Drupal.offCanvas.isOffCanvas($element)) {
      $('.is-layout-builder-highlighted').removeClass(
        'is-layout-builder-highlighted',
      );
    }
  });
})(jQuery, Drupal);
