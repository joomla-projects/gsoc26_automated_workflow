/**
 * Constrains the operator dropdown and value input in each condition leaf to
 * the selected field. Replaces Joomla's `showon`, which stops resolving once a
 * subform is nested more than one level deep. Works by DOM proximity instead,
 * so it is unaffected by nesting depth.
 */
((document) => {
  "use strict";

  // Which operators make sense for each field type.
  const VALID_OPERATORS = {
    day_of_week: ["in", "not in"],
    date: ["after", "before", "on", "not on"],
    tag: ["has", "not has"],
    category: ["is", "is not"],
    author_group: ["has", "not has"],
  };

  // Which value input belongs to each field type.
  const VALUE_FIELDS = {
    day_of_week: "value_day_of_week",
    date: "value_date",
    tag: "value_tag",
    category: "value_category",
    author_group: "value_author_group",
  };

  const constrainOperators = (fieldSelect) => {
    const leafRow = fieldSelect.closest(".subform-repeatable-group");

    if (!leafRow) {
      return;
    }

    const operatorSelect = leafRow.querySelector('select[name$="[operator]"]');

    if (!operatorSelect) {
      return;
    }

    if (!operatorSelect.fullOptionList) {
      operatorSelect.fullOptionList = Array.from(operatorSelect.options).map(
        (option) => ({ value: option.value, label: option.textContent }),
      );
    }

    const allowedValues =
      VALID_OPERATORS[fieldSelect.value] ||
      operatorSelect.fullOptionList.map((option) => option.value);
    const previousValue = operatorSelect.value;

    operatorSelect.innerHTML = "";

    operatorSelect.fullOptionList.forEach(({ value, label }) => {
      if (!allowedValues.includes(value)) {
        return;
      }

      const option = document.createElement("option");
      option.value = value;
      option.textContent = label;
      operatorSelect.appendChild(option);
    });

    if (allowedValues.includes(previousValue)) {
      operatorSelect.value = previousValue;
    }
  };

  const toggleValueFields = (fieldSelect) => {
    const leafRow = fieldSelect.closest(".subform-repeatable-group");

    if (!leafRow) {
      return;
    }

    const selectedField = fieldSelect.value;

    Object.keys(VALUE_FIELDS).forEach((fieldName) => {
      const input = leafRow.querySelector(
        '[name*="[' + VALUE_FIELDS[fieldName] + ']"]',
      );

      if (!input) {
        return;
      }

      const controlGroup = input.closest(".control-group");

      if (controlGroup) {
        controlGroup.style.display = fieldName === selectedField ? "" : "none";
      }
    });
  };

  const updateLeaf = (fieldSelect) => {
    constrainOperators(fieldSelect);
    toggleValueFields(fieldSelect);
  };

  const isFieldSelect = (element) =>
    element && element.tagName === "SELECT" && /\[field\]$/.test(element.name);

  const initialiseWithin = (root) => {
    (root || document)
      .querySelectorAll('select[name$="[field]"]')
      .forEach(updateLeaf);
  };

  document.addEventListener("change", (event) => {
    if (isFieldSelect(event.target)) {
      updateLeaf(event.target);
    }
  });

  document.addEventListener("joomla:updated", (event) => {
    initialiseWithin(event.target);
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () =>
      initialiseWithin(document),
    );
  } else {
    initialiseWithin(document);
  }
})(document);
