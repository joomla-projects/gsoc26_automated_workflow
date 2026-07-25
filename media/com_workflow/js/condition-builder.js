/**
 * Automation condition builder.
 *
 * Renders a single expression tree where every row in a group's list is either
 * a check (field / operator / value) or a nested group. Reads its choices from
 * a data-config blob the PHP field emits, and serialises the tree to JSON in a
 * hidden input on every change. Self-contained: no Joomla subforms, no showon.
 */
((document) => {
  "use strict";

  const OP_TO_MATCH = { and: "all", or: "any" };
  const MATCH_TO_OP = { all: "and", any: "or" };

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
      }    });
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

      this.ui = el("div", { class: "cb-ui" });
      root.appendChild(this.ui);

      root.addEventListener("click", (event) => this.onClick(event));
      root.addEventListener("change", (event) => this.onChange(event));

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
        return this.emptyGroup();
      }
      return this.nodeFromJson(parsed);
    }

    nodeFromJson(node) {
      if (node && typeof node.field !== "undefined") {
        return {
          type: "leaf",
          field: node.field,
          operator: node.operator || "",
          value: node.value ?? "",
        };
      }
      let negate = false;
      let groupNode = node;
      if (node && node.op === "not") {
        negate = true;
        groupNode = (node.children && node.children[0]) || {
          op: "and",
          children: [],
        };
      }
      const match = OP_TO_MATCH[groupNode.op] || "all";
      const children = Array.isArray(groupNode.children)
        ? groupNode.children.map((child) => this.nodeFromJson(child))
        : [];
      return { type: "group", match, negate, children };
    }

    nodeToJson(node) {
      if (node.type === "leaf") {
        return {
          field: node.field,
          operator: node.operator,
          value: node.value,
        };
      }
      const base = {
        op: MATCH_TO_OP[node.match] || "and",
        children: node.children.map((child) => this.nodeToJson(child)),
      };
      return node.negate ? { op: "not", children: [base] } : base;
    }

    sync() {
      this.input.value = this.tree.children.length
        ? JSON.stringify(this.nodeToJson(this.tree))
        : "";
    }

    // ----- node factories -----

    emptyGroup() {
      return { type: "group", match: "all", negate: false, children: [] };
    }

    emptyLeaf() {
      const firstField = (this.config.fields[0] || {}).value || "";
      return {
        type: "leaf",
        field: firstField,
        operator: this.firstOperator(firstField),
        value: this.emptyValue(firstField),
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
        .reduce((node, index) => node.children[Number(index)], this.tree);
    }

    removeAt(path) {
      const parts = path.split(".");
      const index = Number(parts.pop());
      this.nodeAtPath(parts.join(".")).children.splice(index, 1);
    }

    // ----- events -----

    onClick(event) {
      const button = event.target.closest("[data-action]");
      if (!button || !this.root.contains(button)) return;
      event.preventDefault();

      const path = (button.closest("[data-path]") || {}).getAttribute
        ? button.closest("[data-path]").getAttribute("data-path")
        : "";
      const action = button.getAttribute("data-action");

      if (action === "add-check")
        this.nodeAtPath(path).children.push(this.emptyLeaf());
      else if (action === "add-group")
        this.nodeAtPath(path).children.push(this.emptyGroup());
      else if (action === "remove") this.removeAt(path);

      this.render();
    }

    onChange(event) {
      const role = event.target.getAttribute("data-role");
      if (!role) return;

      const node = this.nodeAtPath(
        event.target.closest("[data-path]").getAttribute("data-path"),
      );

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
      else if (role === "match") node.match = event.target.value;
      else if (role === "negate") node.negate = event.target.checked;

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
      this.ui.appendChild(this.renderGroup(this.tree, "", true));
      this.sync();
    }

    renderGroup(node, path, isRoot) {
      const text = this.config.text;

      const head = el(
        "div",
        { class: "cb-group-head" },
        el("span", { class: "cb-chip cb-chip-group", text: text.group }),
        this.renderMatch(node),
        isRoot ? null : this.renderNegate(node),
        isRoot
          ? null
          : el("button", {
              type: "button",
              class: "btn btn-sm btn-danger cb-remove",
              "data-action": "remove",
              "aria-label": text.remove,
              text: "\u00d7",
            }),
      );

      const list = el("div", { class: "cb-list" });
      node.children.forEach((child, index) => {
        if (index > 0) {
          list.appendChild(
            el(
              "div",
              { class: "cb-band" },
              node.match === "any" ? "OR" : "AND",
            ),
          );
        }
        const childPath = path === "" ? String(index) : path + "." + index;
        list.appendChild(
          child.type === "leaf"
            ? this.renderLeaf(child, childPath)
            : this.renderGroup(child, childPath, false),
        );
      });
      if (node.children.length === 0) {
        list.appendChild(el("div", { class: "cb-empty", text: text.empty }));
      }

      const footer = el(
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
            "data-action": "add-group",
          },
          "+ " + text.addGroup,
        ),
      );

      return el(
        "div",
        { class: isRoot ? "cb-group cb-root" : "cb-group", "data-path": path },
        head,
        list,
        footer,
      );
    }

    renderLeaf(node, path) {
      const text = this.config.text;
      return el(
        "div",
        { class: "cb-leaf", "data-path": path },
        el("span", { class: "cb-chip cb-chip-check", text: text.check }),
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

      if (type === "date") {
        return el("input", {
          type: "date",
          class: "form-control",
          "data-role": "value",
          value: node.value || "",
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

    renderMatch(node) {
      const text = this.config.text;
      const select = el("select", {
        class: "form-select cb-match",
        "data-role": "match",
      });
      [
        ["all", text.matchAll],
        ["any", text.matchAny],
      ].forEach(([value, label]) => {
        const option = el("option", { value, text: label });
        if (value === node.match) option.selected = true;
        select.appendChild(option);
      });
      return select;
    }

    renderNegate(node) {
      const label = el("label", { class: "cb-negate" });
      const checkbox = el("input", { type: "checkbox", "data-role": "negate" });
      checkbox.checked = !!node.negate;
      label.appendChild(checkbox);
      label.appendChild(document.createTextNode(" " + this.config.text.negate));
      return label;
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
