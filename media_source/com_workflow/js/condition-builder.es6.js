/**
 * Automation condition builder.
 *
 * Renders one expression as a vertical list of rows, where every row is either a
 * check (field / operator / value) or a nested expression (a bracket). The AND/OR
 * joining two rows sits between them and is chosen per pair when the second row
 * is added. Reads its choices from a data-config blob the PHP field emits, and
 * serialises the expression to JSON in a hidden input on every change.
 *
 * Stored shape:
 *   check:      { "field": "...", "operator": "...", "value": ..., "not": true? }
 *   expression: { "items": [ ...rows ], "ops": [ "and"|"or", ... ], "not": true? }
 * ops always holds one entry fewer than items; evaluation runs top to bottom.
 */
((document) => {
  "use strict";

  // Tiny DOM helper. Keys with "-" become attributes; "text" sets textContent.
  const el = (tag, attrs, ...children) => {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (key === "class") node.className = value;
      else if (key === "text") node.textContent = value;
      else if (key.includes("-")) node.setAttribute(key, value);
      else {
        try {
          node[key] = value;
        } catch (error) {
          node.setAttribute(key, value);
        }
      }
    });
    children.flat().forEach((child) => {
      if (child === null || child === undefined || child === false) return;
      node.appendChild(
        typeof child === "string" ? document.createTextNode(child) : child,
      );
    });
    return node;
  };

  class ConditionBuilder {
    constructor(root) {
      this.root = root;
      this.input = root.querySelector('input[type="hidden"]');
      this.config = JSON.parse(root.dataset.config || "{}");
      this.tree = this.deserialize(this.input.value);

      // While adding a second row to a chain we pause on an AND/OR prompt.
      // Holds { path, kind } while that prompt is showing, otherwise null.
      this.pendingAdd = null;

      this.ui = el("div", { class: "cb-ui" });
      root.appendChild(this.ui);

      root.addEventListener("click", (event) => this.onClick(event));
      root.addEventListener("change", (event) => this.onChange(event));

      // A typed value otherwise only commits when the box loses focus, so a rule saved from
      // the keyboard would store the value as it was before the last edit. Only inputs need
      // this: a select already commits on change, and the multiselect is wrapped in a
      // fancy-select that fires its own events.
      root.addEventListener("input", (event) => {
        if (
          event.target.tagName === "INPUT" &&
          event.target.getAttribute("data-role") === "value"
        ) {
          this.onChange(event);
        }
      });

      this.render();
    }

    // ----- model <-> stored json -----

    deserialize(jsonString) {
      let parsed = null;
      try {
        parsed = JSON.parse(jsonString);
      } catch (error) {
        parsed = null;
      }
      if (!parsed || typeof parsed !== "object") {
        return this.emptyChain();
      }
      // The old format stored { op, children }. It is not migrated; the rule
      // must be rebuilt. Warn so the disappearance is explained, never silent.
      if (parsed.op !== undefined || parsed.children !== undefined) {
        console.warn(
          "[condition-builder] Discarding a condition stored in the old group format; rebuild and save it.",
        );
        return this.emptyChain();
      }
      return this.nodeFromJson(parsed);
    }

    nodeFromJson(node) {
      if (node && typeof node.field !== "undefined") {
        return {
          type: "check",
          field: node.field,
          operator: node.operator || "",
          value: node.value ?? "",
          not: node.not === true,
        };
      }

      const items = Array.isArray(node.items)
        ? node.items.map((child) => this.nodeFromJson(child))
        : [];
      const wanted = Math.max(0, items.length - 1);
      const ops = Array.isArray(node.ops)
        ? node.ops.slice(0, wanted).map((op) => (op === "or" ? "or" : "and"))
        : [];
      while (ops.length < wanted) ops.push("and");

      return { type: "chain", not: node.not === true, items, ops };
    }

    nodeToJson(node) {
      if (node.type === "check") {
        // Drop incomplete checks so they never serialise into a broken rule.
        const emptyValue =
          node.value === "" ||
          node.value === null ||
          (Array.isArray(node.value) && node.value.length === 0);

        if (!node.field || emptyValue) {
          return null;
        }

        const json = {
          field: node.field,
          operator: node.operator,
          value: node.value,
        };
        if (node.not) json.not = true;
        return json;
      }

      // Serialise the rows, dropping incomplete ones together with the
      // operator that would have joined them.
      const items = [];
      const ops = [];

      node.items.forEach((child, index) => {
        const childJson = this.nodeToJson(child);
        if (childJson === null) return;
        if (items.length > 0) {
          ops.push(node.ops[index - 1] === "or" ? "or" : "and");
        }
        items.push(childJson);
      });

      if (items.length === 0) {
        return null;
      }

      const json = { items, ops };
      if (node.not) json.not = true;
      return json;
    }

    sync() {
      const serialised = this.nodeToJson(this.tree);
      this.input.value = serialised ? JSON.stringify(serialised) : "";
    }

    // ----- node factories -----

    emptyChain() {
      return { type: "chain", not: false, items: [], ops: [] };
    }

    emptyCheck() {
      const firstField = ((this.config.fields || [])[0] || {}).value || "";
      return {
        type: "check",
        field: firstField,
        operator: this.firstOperator(firstField),
        value: this.emptyValue(firstField),
        not: false,
      };
    }

    firstOperator(field) {
      const operators = (this.config.operators || {})[field] || [];
      return (operators[0] || {}).value || "";
    }

    emptyValue(field) {
      return (this.config.valueTypes || {})[field] === "multiselect" ? [] : "";
    }

    // ----- locating nodes by path -----

    nodeAtPath(path) {
      if (!path) return this.tree;
      return path
        .split(".")
        .reduce((node, index) => node.items[Number(index)], this.tree);
    }

    removeAt(path) {
      const parts = path.split(".");
      const index = Number(parts.pop());
      const parent = this.nodeAtPath(parts.join("."));

      parent.items.splice(index, 1);

      // Removing a row also removes the operator that joined it: the one
      // before it, or the first one when the removed row was on top.
      if (parent.ops.length > 0) {
        parent.ops.splice(index === 0 ? 0 : index - 1, 1);
      }
    }

    // ----- events -----

    onClick(event) {
      const button = event.target.closest("[data-action]");
      if (!button || !this.root.contains(button)) return;
      event.preventDefault();

      const pathEl = button.closest("[data-path]");
      const path = pathEl ? pathEl.getAttribute("data-path") : "";
      const action = button.getAttribute("data-action");
      const chain = this.nodeAtPath(path);

      if (action === "add-check" || action === "add-expression") {
        const kind = action === "add-check" ? "check" : "chain";

        // The first row needs no connector. Any later row pauses on the
        // AND/OR prompt so the operator is chosen before the row appears.
        if (chain.items.length >= 1) {
          this.pendingAdd = { path, kind };
        } else {
          chain.items.push(
            kind === "check" ? this.emptyCheck() : this.emptyChain(),
          );
          this.pendingAdd = null;
        }
        this.render();
        return;
      }

      if (action === "choose-op") {
        // The prompt was answered: record the connector, then add the row
        // that was waiting on it.
        if (this.pendingAdd && this.pendingAdd.path === path) {
          chain.ops.push(
            button.getAttribute("data-op") === "or" ? "or" : "and",
          );
          chain.items.push(
            this.pendingAdd.kind === "check"
              ? this.emptyCheck()
              : this.emptyChain(),
          );
        }
        this.pendingAdd = null;
        this.render();
        return;
      }

      if (action === "cancel-add") {
        this.pendingAdd = null;
        this.render();
        return;
      }

      if (action === "remove") {
        this.pendingAdd = null;
        this.removeAt(path);
        this.render();
      }
    }

    onChange(event) {
      const role = event.target.getAttribute("data-role");
      if (!role) return;

      const node = this.nodeAtPath(
        event.target.closest("[data-path]").getAttribute("data-path"),
      );

      if (role === "op") {
        const opIndex = Number(event.target.getAttribute("data-index"));
        node.ops[opIndex] = event.target.value === "or" ? "or" : "and";
        this.sync();
        return;
      }

      if (role === "field") {
        node.field = event.target.value;
        node.operator = this.firstOperator(node.field);
        node.value = this.emptyValue(node.field);
        this.render();
        return;
      }

      if (role === "operator") node.operator = event.target.value;
      else if (role === "value")
        node.value = this.readValue(event.target, node.field);
      else if (role === "not") node.not = event.target.checked;

      this.sync();
    }

    readValue(target, field) {
      if ((this.config.valueTypes || {})[field] === "multiselect") {
        return Array.from(target.selectedOptions).map((option) => option.value);
      }
      return target.value;
    }

    // ----- rendering -----

    render() {
      this.ui.innerHTML = "";
      this.ui.appendChild(this.renderChain(this.tree, "", true));
      this.sync();
    }

    renderChain(node, path, isRoot) {
      const text = this.config.text;
      const parts = [];

      if (!isRoot) {
        // A nested expression keeps its chrome: label, NOT and remove. The
        // AND/OR joining rows never lives here, it sits between the rows.
        parts.push(
          el(
            "div",
            { class: "cb-expression-head" },
            el("span", { class: "cb-chip cb-chip-expression", text: text.expression }),
            this.renderNot(node),
            el("button", {
              type: "button",
              class: "btn btn-sm btn-danger cb-remove",
              "data-action": "remove",
              "aria-label": text.remove,
              text: "\u00d7",
            }),
          ),
        );
      }

      const list = el("div", {
        class: isRoot ? "cb-list cb-list-root" : "cb-list",
      });

      node.items.forEach((child, index) => {
        if (index > 0) {
          // The connector between two rows, chosen per pair and editable.
          list.appendChild(
            el(
              "div",
              { class: "cb-band" },
              this.renderOpSelect(node, index - 1),
            ),
          );
        }
        const childPath = path === "" ? String(index) : path + "." + index;
        list.appendChild(
          child.type === "check"
            ? this.renderCheck(child, childPath)
            : this.renderChain(child, childPath, false),
        );
      });

      if (node.items.length === 0) {
        list.appendChild(
          el("div", {
            class: "cb-empty",
            text: isRoot ? text.empty : text.emptyExpression,
          }),
        );
      }
      parts.push(list);

      parts.push(this.renderAddArea(path));

      return el(
        "div",
        { class: isRoot ? "cb-root" : "cb-expression", "data-path": path },
        ...parts,
      );
    }

    renderOpSelect(chainNode, opIndex) {
      const text = this.config.text;
      const select = el("select", {
        class: "form-select cb-op",
        "data-role": "op",
        "data-index": String(opIndex),
        "aria-label": text.joinWith,
      });

      [
        ["and", text.opAnd],
        ["or", text.opOr],
      ].forEach(([value, label]) => {
        const option = el("option", { value, text: label });
        if (value === chainNode.ops[opIndex]) option.selected = true;
        select.appendChild(option);
      });

      return select;
    }

    renderAddArea(path) {
      const text = this.config.text;

      // While an add waits on the operator choice, the prompt replaces the
      // add buttons so the connector is picked before the row appears.
      if (this.pendingAdd && this.pendingAdd.path === path) {
        return el(
          "div",
          { class: "cb-add cb-add-op" },
          el("span", { class: "cb-join-label", text: text.joinWith }),
          el(
            "button",
            {
              type: "button",
              class: "btn btn-sm btn-primary",
              "data-action": "choose-op",
              "data-op": "and",
            },
            text.opAnd,
          ),
          el(
            "button",
            {
              type: "button",
              class: "btn btn-sm btn-primary",
              "data-action": "choose-op",
              "data-op": "or",
            },
            text.opOr,
          ),
          el(
            "button",
            {
              type: "button",
              class: "btn btn-sm btn-link cb-cancel-add",
              "data-action": "cancel-add",
            },
            text.cancel,
          ),
        );
      }

      return el(
        "div",
        { class: "cb-add" },
        el(
          "button",
          {
            type: "button",
            class: "btn btn-sm btn-success",
            "data-action": "add-check",
          },
          "+ " + text.addCheck,
        ),
        el(
          "button",
          {
            type: "button",
            class: "btn btn-sm btn-success",
            "data-action": "add-expression",
          },
          "+ " + text.addExpression,
        ),
      );
    }

    renderCheck(node, path) {
      const text = this.config.text;
      return el(
        "div",
        { class: "cb-leaf", "data-path": path },
        el("span", { class: "cb-chip cb-chip-check", text: text.check }),
        this.renderNot(node),
        el(
          "div",
          { class: "cb-leaf-fields" },
          this.renderFieldSelect(node),
          this.renderOperatorSelect(node),
          this.renderValue(node),
        ),
        el("button", {
          type: "button",
          class: "btn btn-sm btn-danger cb-remove",
          "data-action": "remove",
          "aria-label": text.remove,
          text: "\u00d7",
        }),
      );
    }

    renderNot(node) {
      const label = el("label", { class: "cb-not" });
      const checkbox = el("input", { type: "checkbox", "data-role": "not" });
      checkbox.checked = !!node.not;
      label.appendChild(checkbox);
      label.appendChild(document.createTextNode(" " + this.config.text.negate));
      return label;
    }

    renderFieldSelect(node) {
      const select = el("select", {
        class: "form-select",
        "data-role": "field",
      });
      this.config.fields.forEach((field) => {
        const option = el("option", { value: field.value, text: field.label });
        if (field.value === node.field) option.selected = true;
        select.appendChild(option);
      });
      return select;
    }

    renderOperatorSelect(node) {
      const select = el("select", {
        class: "form-select",
        "data-role": "operator",
      });
      ((this.config.operators || {})[node.field] || []).forEach((operator) => {
        const option = el("option", {
          value: operator.value,
          text: operator.label,
        });
        if (operator.value === node.operator) option.selected = true;
        select.appendChild(option);
      });
      return select;
    }

    renderValue(node) {
      const type = (this.config.valueTypes || {})[node.field];

      // Typed values: the check supplies no list to pick from, so the value is
      // whatever the person writes. A number gets the numeric input so the
      // browser offers the right keyboard and rejects letters.
      if (type === "date" || type === "text" || type === "number") {
        return el("input", {
          type: type,
          class: "form-control",
          "data-role": "value",
          value: node.value === null || node.value === undefined ? "" : String(node.value),
        });
      }

      const options = (this.config.valueOptions || {})[node.field] || [];
      const multiple = type === "multiselect";
      const selected = multiple
        ? Array.isArray(node.value)
          ? node.value.map(String)
          : []
        : [String(node.value)];

      const select = el("select", {
        class: "form-select",
        "data-role": "value",
      });
      if (multiple) select.multiple = true;
      else select.appendChild(el("option", { value: "", text: "\u2014" }));

      options.forEach((choice) => {
        const option = el("option", {
          value: choice.value,
          text: choice.label,
        });
        if (selected.includes(String(choice.value))) {
          option.selected = true;
          option.setAttribute("selected", "selected");
        }
        select.appendChild(option);
      });

      if (multiple) {
        const fancy = el("joomla-field-fancy-select", {
          placeholder: (this.config.text || {}).placeholder || "",
        });
        fancy.appendChild(select);
        return fancy;
      }
      return select;
    }
  }

  const initialiseWithin = (scope) => {
    (scope || document)
      .querySelectorAll("[data-condition-builder]")
      .forEach((root) => {
        if (root.dataset.cbInit) return;
        root.dataset.cbInit = "1";
        new ConditionBuilder(root);
      });
  };

  document.addEventListener("joomla:updated", (event) =>
    initialiseWithin(event.target),
  );

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () =>
      initialiseWithin(document),
    );
  } else {
    initialiseWithin(document);
  }
})(document);
