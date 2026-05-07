/**
 * @copyright  (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

((document, Joomla) => {
  'use strict';

  const disableCategory = () => {
    var dropdown = document.getElementById('toolbar-status-group');
    if (!dropdown) {
      return;
    }
    var batchButton = document.getElementById('status-group-children-batch');
    if (batchButton) {
      batchButton.addEventListener('click', function () {
        var observer = new MutationObserver(function (mutations, observer) {
          var categorySelector = document.getElementById('batch-category-id');
          if (categorySelector) {
            categorySelector.disabled = true;
            observer.disconnect();
          }
        });

        observer.observe(document.body, {childList: true, subtree: true});
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    disableCategory();
  });
})(document, Joomla);
