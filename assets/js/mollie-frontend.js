(() => {
  var __defProp = Object.defineProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __publicField = (obj, key, value) => {
    __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
    return value;
  };

  // node_modules/@hotwired/stimulus/dist/stimulus.js
  var EventListener = class {
    constructor(eventTarget, eventName, eventOptions) {
      this.eventTarget = eventTarget;
      this.eventName = eventName;
      this.eventOptions = eventOptions;
      this.unorderedBindings = /* @__PURE__ */ new Set();
    }
    connect() {
      this.eventTarget.addEventListener(this.eventName, this, this.eventOptions);
    }
    disconnect() {
      this.eventTarget.removeEventListener(this.eventName, this, this.eventOptions);
    }
    bindingConnected(binding) {
      this.unorderedBindings.add(binding);
    }
    bindingDisconnected(binding) {
      this.unorderedBindings.delete(binding);
    }
    handleEvent(event) {
      const extendedEvent = extendEvent(event);
      for (const binding of this.bindings) {
        if (extendedEvent.immediatePropagationStopped) {
          break;
        } else {
          binding.handleEvent(extendedEvent);
        }
      }
    }
    hasBindings() {
      return this.unorderedBindings.size > 0;
    }
    get bindings() {
      return Array.from(this.unorderedBindings).sort((left, right) => {
        const leftIndex = left.index, rightIndex = right.index;
        return leftIndex < rightIndex ? -1 : leftIndex > rightIndex ? 1 : 0;
      });
    }
  };
  function extendEvent(event) {
    if ("immediatePropagationStopped" in event) {
      return event;
    } else {
      const { stopImmediatePropagation } = event;
      return Object.assign(event, {
        immediatePropagationStopped: false,
        stopImmediatePropagation() {
          this.immediatePropagationStopped = true;
          stopImmediatePropagation.call(this);
        }
      });
    }
  }
  var Dispatcher = class {
    constructor(application) {
      this.application = application;
      this.eventListenerMaps = /* @__PURE__ */ new Map();
      this.started = false;
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.eventListeners.forEach((eventListener) => eventListener.connect());
      }
    }
    stop() {
      if (this.started) {
        this.started = false;
        this.eventListeners.forEach((eventListener) => eventListener.disconnect());
      }
    }
    get eventListeners() {
      return Array.from(this.eventListenerMaps.values()).reduce((listeners, map) => listeners.concat(Array.from(map.values())), []);
    }
    bindingConnected(binding) {
      this.fetchEventListenerForBinding(binding).bindingConnected(binding);
    }
    bindingDisconnected(binding, clearEventListeners = false) {
      this.fetchEventListenerForBinding(binding).bindingDisconnected(binding);
      if (clearEventListeners)
        this.clearEventListenersForBinding(binding);
    }
    handleError(error2, message, detail = {}) {
      this.application.handleError(error2, `Error ${message}`, detail);
    }
    clearEventListenersForBinding(binding) {
      const eventListener = this.fetchEventListenerForBinding(binding);
      if (!eventListener.hasBindings()) {
        eventListener.disconnect();
        this.removeMappedEventListenerFor(binding);
      }
    }
    removeMappedEventListenerFor(binding) {
      const { eventTarget, eventName, eventOptions } = binding;
      const eventListenerMap = this.fetchEventListenerMapForEventTarget(eventTarget);
      const cacheKey = this.cacheKey(eventName, eventOptions);
      eventListenerMap.delete(cacheKey);
      if (eventListenerMap.size == 0)
        this.eventListenerMaps.delete(eventTarget);
    }
    fetchEventListenerForBinding(binding) {
      const { eventTarget, eventName, eventOptions } = binding;
      return this.fetchEventListener(eventTarget, eventName, eventOptions);
    }
    fetchEventListener(eventTarget, eventName, eventOptions) {
      const eventListenerMap = this.fetchEventListenerMapForEventTarget(eventTarget);
      const cacheKey = this.cacheKey(eventName, eventOptions);
      let eventListener = eventListenerMap.get(cacheKey);
      if (!eventListener) {
        eventListener = this.createEventListener(eventTarget, eventName, eventOptions);
        eventListenerMap.set(cacheKey, eventListener);
      }
      return eventListener;
    }
    createEventListener(eventTarget, eventName, eventOptions) {
      const eventListener = new EventListener(eventTarget, eventName, eventOptions);
      if (this.started) {
        eventListener.connect();
      }
      return eventListener;
    }
    fetchEventListenerMapForEventTarget(eventTarget) {
      let eventListenerMap = this.eventListenerMaps.get(eventTarget);
      if (!eventListenerMap) {
        eventListenerMap = /* @__PURE__ */ new Map();
        this.eventListenerMaps.set(eventTarget, eventListenerMap);
      }
      return eventListenerMap;
    }
    cacheKey(eventName, eventOptions) {
      const parts = [eventName];
      Object.keys(eventOptions).sort().forEach((key) => {
        parts.push(`${eventOptions[key] ? "" : "!"}${key}`);
      });
      return parts.join(":");
    }
  };
  var defaultActionDescriptorFilters = {
    stop({ event, value }) {
      if (value)
        event.stopPropagation();
      return true;
    },
    prevent({ event, value }) {
      if (value)
        event.preventDefault();
      return true;
    },
    self({ event, value, element }) {
      if (value) {
        return element === event.target;
      } else {
        return true;
      }
    }
  };
  var descriptorPattern = /^(?:(?:([^.]+?)\+)?(.+?)(?:\.(.+?))?(?:@(window|document))?->)?(.+?)(?:#([^:]+?))(?::(.+))?$/;
  function parseActionDescriptorString(descriptorString) {
    const source = descriptorString.trim();
    const matches = source.match(descriptorPattern) || [];
    let eventName = matches[2];
    let keyFilter = matches[3];
    if (keyFilter && !["keydown", "keyup", "keypress"].includes(eventName)) {
      eventName += `.${keyFilter}`;
      keyFilter = "";
    }
    return {
      eventTarget: parseEventTarget(matches[4]),
      eventName,
      eventOptions: matches[7] ? parseEventOptions(matches[7]) : {},
      identifier: matches[5],
      methodName: matches[6],
      keyFilter: matches[1] || keyFilter
    };
  }
  function parseEventTarget(eventTargetName) {
    if (eventTargetName == "window") {
      return window;
    } else if (eventTargetName == "document") {
      return document;
    }
  }
  function parseEventOptions(eventOptions) {
    return eventOptions.split(":").reduce((options, token) => Object.assign(options, { [token.replace(/^!/, "")]: !/^!/.test(token) }), {});
  }
  function stringifyEventTarget(eventTarget) {
    if (eventTarget == window) {
      return "window";
    } else if (eventTarget == document) {
      return "document";
    }
  }
  function camelize(value) {
    return value.replace(/(?:[_-])([a-z0-9])/g, (_, char) => char.toUpperCase());
  }
  function namespaceCamelize(value) {
    return camelize(value.replace(/--/g, "-").replace(/__/g, "_"));
  }
  function capitalize(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
  }
  function dasherize(value) {
    return value.replace(/([A-Z])/g, (_, char) => `-${char.toLowerCase()}`);
  }
  function tokenize(value) {
    return value.match(/[^\s]+/g) || [];
  }
  function isSomething(object) {
    return object !== null && object !== void 0;
  }
  function hasProperty(object, property) {
    return Object.prototype.hasOwnProperty.call(object, property);
  }
  var allModifiers = ["meta", "ctrl", "alt", "shift"];
  var Action = class {
    constructor(element, index, descriptor, schema) {
      this.element = element;
      this.index = index;
      this.eventTarget = descriptor.eventTarget || element;
      this.eventName = descriptor.eventName || getDefaultEventNameForElement(element) || error("missing event name");
      this.eventOptions = descriptor.eventOptions || {};
      this.identifier = descriptor.identifier || error("missing identifier");
      this.methodName = descriptor.methodName || error("missing method name");
      this.keyFilter = descriptor.keyFilter || "";
      this.schema = schema;
    }
    static forToken(token, schema) {
      return new this(token.element, token.index, parseActionDescriptorString(token.content), schema);
    }
    toString() {
      const eventFilter = this.keyFilter ? `.${this.keyFilter}` : "";
      const eventTarget = this.eventTargetName ? `@${this.eventTargetName}` : "";
      return `${this.eventName}${eventFilter}${eventTarget}->${this.identifier}#${this.methodName}`;
    }
    shouldIgnoreKeyboardEvent(event) {
      if (!this.keyFilter) {
        return false;
      }
      const filters = this.keyFilter.split("+");
      if (this.keyFilterDissatisfied(event, filters)) {
        return true;
      }
      const standardFilter = filters.filter((key) => !allModifiers.includes(key))[0];
      if (!standardFilter) {
        return false;
      }
      if (!hasProperty(this.keyMappings, standardFilter)) {
        error(`contains unknown key filter: ${this.keyFilter}`);
      }
      return this.keyMappings[standardFilter].toLowerCase() !== event.key.toLowerCase();
    }
    shouldIgnoreMouseEvent(event) {
      if (!this.keyFilter) {
        return false;
      }
      const filters = [this.keyFilter];
      if (this.keyFilterDissatisfied(event, filters)) {
        return true;
      }
      return false;
    }
    get params() {
      const params = {};
      const pattern = new RegExp(`^data-${this.identifier}-(.+)-param$`, "i");
      for (const { name, value } of Array.from(this.element.attributes)) {
        const match = name.match(pattern);
        const key = match && match[1];
        if (key) {
          params[camelize(key)] = typecast(value);
        }
      }
      return params;
    }
    get eventTargetName() {
      return stringifyEventTarget(this.eventTarget);
    }
    get keyMappings() {
      return this.schema.keyMappings;
    }
    keyFilterDissatisfied(event, filters) {
      const [meta, ctrl, alt, shift] = allModifiers.map((modifier) => filters.includes(modifier));
      return event.metaKey !== meta || event.ctrlKey !== ctrl || event.altKey !== alt || event.shiftKey !== shift;
    }
  };
  var defaultEventNames = {
    a: () => "click",
    button: () => "click",
    form: () => "submit",
    details: () => "toggle",
    input: (e) => e.getAttribute("type") == "submit" ? "click" : "input",
    select: () => "change",
    textarea: () => "input"
  };
  function getDefaultEventNameForElement(element) {
    const tagName = element.tagName.toLowerCase();
    if (tagName in defaultEventNames) {
      return defaultEventNames[tagName](element);
    }
  }
  function error(message) {
    throw new Error(message);
  }
  function typecast(value) {
    try {
      return JSON.parse(value);
    } catch (o_O) {
      return value;
    }
  }
  var Binding = class {
    constructor(context, action) {
      this.context = context;
      this.action = action;
    }
    get index() {
      return this.action.index;
    }
    get eventTarget() {
      return this.action.eventTarget;
    }
    get eventOptions() {
      return this.action.eventOptions;
    }
    get identifier() {
      return this.context.identifier;
    }
    handleEvent(event) {
      const actionEvent = this.prepareActionEvent(event);
      if (this.willBeInvokedByEvent(event) && this.applyEventModifiers(actionEvent)) {
        this.invokeWithEvent(actionEvent);
      }
    }
    get eventName() {
      return this.action.eventName;
    }
    get method() {
      const method = this.controller[this.methodName];
      if (typeof method == "function") {
        return method;
      }
      throw new Error(`Action "${this.action}" references undefined method "${this.methodName}"`);
    }
    applyEventModifiers(event) {
      const { element } = this.action;
      const { actionDescriptorFilters } = this.context.application;
      const { controller } = this.context;
      let passes = true;
      for (const [name, value] of Object.entries(this.eventOptions)) {
        if (name in actionDescriptorFilters) {
          const filter = actionDescriptorFilters[name];
          passes = passes && filter({ name, value, event, element, controller });
        } else {
          continue;
        }
      }
      return passes;
    }
    prepareActionEvent(event) {
      return Object.assign(event, { params: this.action.params });
    }
    invokeWithEvent(event) {
      const { target, currentTarget } = event;
      try {
        this.method.call(this.controller, event);
        this.context.logDebugActivity(this.methodName, { event, target, currentTarget, action: this.methodName });
      } catch (error2) {
        const { identifier, controller, element, index } = this;
        const detail = { identifier, controller, element, index, event };
        this.context.handleError(error2, `invoking action "${this.action}"`, detail);
      }
    }
    willBeInvokedByEvent(event) {
      const eventTarget = event.target;
      if (event instanceof KeyboardEvent && this.action.shouldIgnoreKeyboardEvent(event)) {
        return false;
      }
      if (event instanceof MouseEvent && this.action.shouldIgnoreMouseEvent(event)) {
        return false;
      }
      if (this.element === eventTarget) {
        return true;
      } else if (eventTarget instanceof Element && this.element.contains(eventTarget)) {
        return this.scope.containsElement(eventTarget);
      } else {
        return this.scope.containsElement(this.action.element);
      }
    }
    get controller() {
      return this.context.controller;
    }
    get methodName() {
      return this.action.methodName;
    }
    get element() {
      return this.scope.element;
    }
    get scope() {
      return this.context.scope;
    }
  };
  var ElementObserver = class {
    constructor(element, delegate) {
      this.mutationObserverInit = { attributes: true, childList: true, subtree: true };
      this.element = element;
      this.started = false;
      this.delegate = delegate;
      this.elements = /* @__PURE__ */ new Set();
      this.mutationObserver = new MutationObserver((mutations) => this.processMutations(mutations));
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.mutationObserver.observe(this.element, this.mutationObserverInit);
        this.refresh();
      }
    }
    pause(callback) {
      if (this.started) {
        this.mutationObserver.disconnect();
        this.started = false;
      }
      callback();
      if (!this.started) {
        this.mutationObserver.observe(this.element, this.mutationObserverInit);
        this.started = true;
      }
    }
    stop() {
      if (this.started) {
        this.mutationObserver.takeRecords();
        this.mutationObserver.disconnect();
        this.started = false;
      }
    }
    refresh() {
      if (this.started) {
        const matches = new Set(this.matchElementsInTree());
        for (const element of Array.from(this.elements)) {
          if (!matches.has(element)) {
            this.removeElement(element);
          }
        }
        for (const element of Array.from(matches)) {
          this.addElement(element);
        }
      }
    }
    processMutations(mutations) {
      if (this.started) {
        for (const mutation of mutations) {
          this.processMutation(mutation);
        }
      }
    }
    processMutation(mutation) {
      if (mutation.type == "attributes") {
        this.processAttributeChange(mutation.target, mutation.attributeName);
      } else if (mutation.type == "childList") {
        this.processRemovedNodes(mutation.removedNodes);
        this.processAddedNodes(mutation.addedNodes);
      }
    }
    processAttributeChange(element, attributeName) {
      if (this.elements.has(element)) {
        if (this.delegate.elementAttributeChanged && this.matchElement(element)) {
          this.delegate.elementAttributeChanged(element, attributeName);
        } else {
          this.removeElement(element);
        }
      } else if (this.matchElement(element)) {
        this.addElement(element);
      }
    }
    processRemovedNodes(nodes) {
      for (const node of Array.from(nodes)) {
        const element = this.elementFromNode(node);
        if (element) {
          this.processTree(element, this.removeElement);
        }
      }
    }
    processAddedNodes(nodes) {
      for (const node of Array.from(nodes)) {
        const element = this.elementFromNode(node);
        if (element && this.elementIsActive(element)) {
          this.processTree(element, this.addElement);
        }
      }
    }
    matchElement(element) {
      return this.delegate.matchElement(element);
    }
    matchElementsInTree(tree = this.element) {
      return this.delegate.matchElementsInTree(tree);
    }
    processTree(tree, processor) {
      for (const element of this.matchElementsInTree(tree)) {
        processor.call(this, element);
      }
    }
    elementFromNode(node) {
      if (node.nodeType == Node.ELEMENT_NODE) {
        return node;
      }
    }
    elementIsActive(element) {
      if (element.isConnected != this.element.isConnected) {
        return false;
      } else {
        return this.element.contains(element);
      }
    }
    addElement(element) {
      if (!this.elements.has(element)) {
        if (this.elementIsActive(element)) {
          this.elements.add(element);
          if (this.delegate.elementMatched) {
            this.delegate.elementMatched(element);
          }
        }
      }
    }
    removeElement(element) {
      if (this.elements.has(element)) {
        this.elements.delete(element);
        if (this.delegate.elementUnmatched) {
          this.delegate.elementUnmatched(element);
        }
      }
    }
  };
  var AttributeObserver = class {
    constructor(element, attributeName, delegate) {
      this.attributeName = attributeName;
      this.delegate = delegate;
      this.elementObserver = new ElementObserver(element, this);
    }
    get element() {
      return this.elementObserver.element;
    }
    get selector() {
      return `[${this.attributeName}]`;
    }
    start() {
      this.elementObserver.start();
    }
    pause(callback) {
      this.elementObserver.pause(callback);
    }
    stop() {
      this.elementObserver.stop();
    }
    refresh() {
      this.elementObserver.refresh();
    }
    get started() {
      return this.elementObserver.started;
    }
    matchElement(element) {
      return element.hasAttribute(this.attributeName);
    }
    matchElementsInTree(tree) {
      const match = this.matchElement(tree) ? [tree] : [];
      const matches = Array.from(tree.querySelectorAll(this.selector));
      return match.concat(matches);
    }
    elementMatched(element) {
      if (this.delegate.elementMatchedAttribute) {
        this.delegate.elementMatchedAttribute(element, this.attributeName);
      }
    }
    elementUnmatched(element) {
      if (this.delegate.elementUnmatchedAttribute) {
        this.delegate.elementUnmatchedAttribute(element, this.attributeName);
      }
    }
    elementAttributeChanged(element, attributeName) {
      if (this.delegate.elementAttributeValueChanged && this.attributeName == attributeName) {
        this.delegate.elementAttributeValueChanged(element, attributeName);
      }
    }
  };
  function add(map, key, value) {
    fetch(map, key).add(value);
  }
  function del(map, key, value) {
    fetch(map, key).delete(value);
    prune(map, key);
  }
  function fetch(map, key) {
    let values = map.get(key);
    if (!values) {
      values = /* @__PURE__ */ new Set();
      map.set(key, values);
    }
    return values;
  }
  function prune(map, key) {
    const values = map.get(key);
    if (values != null && values.size == 0) {
      map.delete(key);
    }
  }
  var Multimap = class {
    constructor() {
      this.valuesByKey = /* @__PURE__ */ new Map();
    }
    get keys() {
      return Array.from(this.valuesByKey.keys());
    }
    get values() {
      const sets = Array.from(this.valuesByKey.values());
      return sets.reduce((values, set) => values.concat(Array.from(set)), []);
    }
    get size() {
      const sets = Array.from(this.valuesByKey.values());
      return sets.reduce((size, set) => size + set.size, 0);
    }
    add(key, value) {
      add(this.valuesByKey, key, value);
    }
    delete(key, value) {
      del(this.valuesByKey, key, value);
    }
    has(key, value) {
      const values = this.valuesByKey.get(key);
      return values != null && values.has(value);
    }
    hasKey(key) {
      return this.valuesByKey.has(key);
    }
    hasValue(value) {
      const sets = Array.from(this.valuesByKey.values());
      return sets.some((set) => set.has(value));
    }
    getValuesForKey(key) {
      const values = this.valuesByKey.get(key);
      return values ? Array.from(values) : [];
    }
    getKeysForValue(value) {
      return Array.from(this.valuesByKey).filter(([_key, values]) => values.has(value)).map(([key, _values]) => key);
    }
  };
  var SelectorObserver = class {
    constructor(element, selector, delegate, details) {
      this._selector = selector;
      this.details = details;
      this.elementObserver = new ElementObserver(element, this);
      this.delegate = delegate;
      this.matchesByElement = new Multimap();
    }
    get started() {
      return this.elementObserver.started;
    }
    get selector() {
      return this._selector;
    }
    set selector(selector) {
      this._selector = selector;
      this.refresh();
    }
    start() {
      this.elementObserver.start();
    }
    pause(callback) {
      this.elementObserver.pause(callback);
    }
    stop() {
      this.elementObserver.stop();
    }
    refresh() {
      this.elementObserver.refresh();
    }
    get element() {
      return this.elementObserver.element;
    }
    matchElement(element) {
      const { selector } = this;
      if (selector) {
        const matches = element.matches(selector);
        if (this.delegate.selectorMatchElement) {
          return matches && this.delegate.selectorMatchElement(element, this.details);
        }
        return matches;
      } else {
        return false;
      }
    }
    matchElementsInTree(tree) {
      const { selector } = this;
      if (selector) {
        const match = this.matchElement(tree) ? [tree] : [];
        const matches = Array.from(tree.querySelectorAll(selector)).filter((match2) => this.matchElement(match2));
        return match.concat(matches);
      } else {
        return [];
      }
    }
    elementMatched(element) {
      const { selector } = this;
      if (selector) {
        this.selectorMatched(element, selector);
      }
    }
    elementUnmatched(element) {
      const selectors = this.matchesByElement.getKeysForValue(element);
      for (const selector of selectors) {
        this.selectorUnmatched(element, selector);
      }
    }
    elementAttributeChanged(element, _attributeName) {
      const { selector } = this;
      if (selector) {
        const matches = this.matchElement(element);
        const matchedBefore = this.matchesByElement.has(selector, element);
        if (matches && !matchedBefore) {
          this.selectorMatched(element, selector);
        } else if (!matches && matchedBefore) {
          this.selectorUnmatched(element, selector);
        }
      }
    }
    selectorMatched(element, selector) {
      this.delegate.selectorMatched(element, selector, this.details);
      this.matchesByElement.add(selector, element);
    }
    selectorUnmatched(element, selector) {
      this.delegate.selectorUnmatched(element, selector, this.details);
      this.matchesByElement.delete(selector, element);
    }
  };
  var StringMapObserver = class {
    constructor(element, delegate) {
      this.element = element;
      this.delegate = delegate;
      this.started = false;
      this.stringMap = /* @__PURE__ */ new Map();
      this.mutationObserver = new MutationObserver((mutations) => this.processMutations(mutations));
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.mutationObserver.observe(this.element, { attributes: true, attributeOldValue: true });
        this.refresh();
      }
    }
    stop() {
      if (this.started) {
        this.mutationObserver.takeRecords();
        this.mutationObserver.disconnect();
        this.started = false;
      }
    }
    refresh() {
      if (this.started) {
        for (const attributeName of this.knownAttributeNames) {
          this.refreshAttribute(attributeName, null);
        }
      }
    }
    processMutations(mutations) {
      if (this.started) {
        for (const mutation of mutations) {
          this.processMutation(mutation);
        }
      }
    }
    processMutation(mutation) {
      const attributeName = mutation.attributeName;
      if (attributeName) {
        this.refreshAttribute(attributeName, mutation.oldValue);
      }
    }
    refreshAttribute(attributeName, oldValue) {
      const key = this.delegate.getStringMapKeyForAttribute(attributeName);
      if (key != null) {
        if (!this.stringMap.has(attributeName)) {
          this.stringMapKeyAdded(key, attributeName);
        }
        const value = this.element.getAttribute(attributeName);
        if (this.stringMap.get(attributeName) != value) {
          this.stringMapValueChanged(value, key, oldValue);
        }
        if (value == null) {
          const oldValue2 = this.stringMap.get(attributeName);
          this.stringMap.delete(attributeName);
          if (oldValue2)
            this.stringMapKeyRemoved(key, attributeName, oldValue2);
        } else {
          this.stringMap.set(attributeName, value);
        }
      }
    }
    stringMapKeyAdded(key, attributeName) {
      if (this.delegate.stringMapKeyAdded) {
        this.delegate.stringMapKeyAdded(key, attributeName);
      }
    }
    stringMapValueChanged(value, key, oldValue) {
      if (this.delegate.stringMapValueChanged) {
        this.delegate.stringMapValueChanged(value, key, oldValue);
      }
    }
    stringMapKeyRemoved(key, attributeName, oldValue) {
      if (this.delegate.stringMapKeyRemoved) {
        this.delegate.stringMapKeyRemoved(key, attributeName, oldValue);
      }
    }
    get knownAttributeNames() {
      return Array.from(new Set(this.currentAttributeNames.concat(this.recordedAttributeNames)));
    }
    get currentAttributeNames() {
      return Array.from(this.element.attributes).map((attribute) => attribute.name);
    }
    get recordedAttributeNames() {
      return Array.from(this.stringMap.keys());
    }
  };
  var TokenListObserver = class {
    constructor(element, attributeName, delegate) {
      this.attributeObserver = new AttributeObserver(element, attributeName, this);
      this.delegate = delegate;
      this.tokensByElement = new Multimap();
    }
    get started() {
      return this.attributeObserver.started;
    }
    start() {
      this.attributeObserver.start();
    }
    pause(callback) {
      this.attributeObserver.pause(callback);
    }
    stop() {
      this.attributeObserver.stop();
    }
    refresh() {
      this.attributeObserver.refresh();
    }
    get element() {
      return this.attributeObserver.element;
    }
    get attributeName() {
      return this.attributeObserver.attributeName;
    }
    elementMatchedAttribute(element) {
      this.tokensMatched(this.readTokensForElement(element));
    }
    elementAttributeValueChanged(element) {
      const [unmatchedTokens, matchedTokens] = this.refreshTokensForElement(element);
      this.tokensUnmatched(unmatchedTokens);
      this.tokensMatched(matchedTokens);
    }
    elementUnmatchedAttribute(element) {
      this.tokensUnmatched(this.tokensByElement.getValuesForKey(element));
    }
    tokensMatched(tokens) {
      tokens.forEach((token) => this.tokenMatched(token));
    }
    tokensUnmatched(tokens) {
      tokens.forEach((token) => this.tokenUnmatched(token));
    }
    tokenMatched(token) {
      this.delegate.tokenMatched(token);
      this.tokensByElement.add(token.element, token);
    }
    tokenUnmatched(token) {
      this.delegate.tokenUnmatched(token);
      this.tokensByElement.delete(token.element, token);
    }
    refreshTokensForElement(element) {
      const previousTokens = this.tokensByElement.getValuesForKey(element);
      const currentTokens = this.readTokensForElement(element);
      const firstDifferingIndex = zip(previousTokens, currentTokens).findIndex(([previousToken, currentToken]) => !tokensAreEqual(previousToken, currentToken));
      if (firstDifferingIndex == -1) {
        return [[], []];
      } else {
        return [previousTokens.slice(firstDifferingIndex), currentTokens.slice(firstDifferingIndex)];
      }
    }
    readTokensForElement(element) {
      const attributeName = this.attributeName;
      const tokenString = element.getAttribute(attributeName) || "";
      return parseTokenString(tokenString, element, attributeName);
    }
  };
  function parseTokenString(tokenString, element, attributeName) {
    return tokenString.trim().split(/\s+/).filter((content) => content.length).map((content, index) => ({ element, attributeName, content, index }));
  }
  function zip(left, right) {
    const length = Math.max(left.length, right.length);
    return Array.from({ length }, (_, index) => [left[index], right[index]]);
  }
  function tokensAreEqual(left, right) {
    return left && right && left.index == right.index && left.content == right.content;
  }
  var ValueListObserver = class {
    constructor(element, attributeName, delegate) {
      this.tokenListObserver = new TokenListObserver(element, attributeName, this);
      this.delegate = delegate;
      this.parseResultsByToken = /* @__PURE__ */ new WeakMap();
      this.valuesByTokenByElement = /* @__PURE__ */ new WeakMap();
    }
    get started() {
      return this.tokenListObserver.started;
    }
    start() {
      this.tokenListObserver.start();
    }
    stop() {
      this.tokenListObserver.stop();
    }
    refresh() {
      this.tokenListObserver.refresh();
    }
    get element() {
      return this.tokenListObserver.element;
    }
    get attributeName() {
      return this.tokenListObserver.attributeName;
    }
    tokenMatched(token) {
      const { element } = token;
      const { value } = this.fetchParseResultForToken(token);
      if (value) {
        this.fetchValuesByTokenForElement(element).set(token, value);
        this.delegate.elementMatchedValue(element, value);
      }
    }
    tokenUnmatched(token) {
      const { element } = token;
      const { value } = this.fetchParseResultForToken(token);
      if (value) {
        this.fetchValuesByTokenForElement(element).delete(token);
        this.delegate.elementUnmatchedValue(element, value);
      }
    }
    fetchParseResultForToken(token) {
      let parseResult = this.parseResultsByToken.get(token);
      if (!parseResult) {
        parseResult = this.parseToken(token);
        this.parseResultsByToken.set(token, parseResult);
      }
      return parseResult;
    }
    fetchValuesByTokenForElement(element) {
      let valuesByToken = this.valuesByTokenByElement.get(element);
      if (!valuesByToken) {
        valuesByToken = /* @__PURE__ */ new Map();
        this.valuesByTokenByElement.set(element, valuesByToken);
      }
      return valuesByToken;
    }
    parseToken(token) {
      try {
        const value = this.delegate.parseValueForToken(token);
        return { value };
      } catch (error2) {
        return { error: error2 };
      }
    }
  };
  var BindingObserver = class {
    constructor(context, delegate) {
      this.context = context;
      this.delegate = delegate;
      this.bindingsByAction = /* @__PURE__ */ new Map();
    }
    start() {
      if (!this.valueListObserver) {
        this.valueListObserver = new ValueListObserver(this.element, this.actionAttribute, this);
        this.valueListObserver.start();
      }
    }
    stop() {
      if (this.valueListObserver) {
        this.valueListObserver.stop();
        delete this.valueListObserver;
        this.disconnectAllActions();
      }
    }
    get element() {
      return this.context.element;
    }
    get identifier() {
      return this.context.identifier;
    }
    get actionAttribute() {
      return this.schema.actionAttribute;
    }
    get schema() {
      return this.context.schema;
    }
    get bindings() {
      return Array.from(this.bindingsByAction.values());
    }
    connectAction(action) {
      const binding = new Binding(this.context, action);
      this.bindingsByAction.set(action, binding);
      this.delegate.bindingConnected(binding);
    }
    disconnectAction(action) {
      const binding = this.bindingsByAction.get(action);
      if (binding) {
        this.bindingsByAction.delete(action);
        this.delegate.bindingDisconnected(binding);
      }
    }
    disconnectAllActions() {
      this.bindings.forEach((binding) => this.delegate.bindingDisconnected(binding, true));
      this.bindingsByAction.clear();
    }
    parseValueForToken(token) {
      const action = Action.forToken(token, this.schema);
      if (action.identifier == this.identifier) {
        return action;
      }
    }
    elementMatchedValue(element, action) {
      this.connectAction(action);
    }
    elementUnmatchedValue(element, action) {
      this.disconnectAction(action);
    }
  };
  var ValueObserver = class {
    constructor(context, receiver) {
      this.context = context;
      this.receiver = receiver;
      this.stringMapObserver = new StringMapObserver(this.element, this);
      this.valueDescriptorMap = this.controller.valueDescriptorMap;
    }
    start() {
      this.stringMapObserver.start();
      this.invokeChangedCallbacksForDefaultValues();
    }
    stop() {
      this.stringMapObserver.stop();
    }
    get element() {
      return this.context.element;
    }
    get controller() {
      return this.context.controller;
    }
    getStringMapKeyForAttribute(attributeName) {
      if (attributeName in this.valueDescriptorMap) {
        return this.valueDescriptorMap[attributeName].name;
      }
    }
    stringMapKeyAdded(key, attributeName) {
      const descriptor = this.valueDescriptorMap[attributeName];
      if (!this.hasValue(key)) {
        this.invokeChangedCallback(key, descriptor.writer(this.receiver[key]), descriptor.writer(descriptor.defaultValue));
      }
    }
    stringMapValueChanged(value, name, oldValue) {
      const descriptor = this.valueDescriptorNameMap[name];
      if (value === null)
        return;
      if (oldValue === null) {
        oldValue = descriptor.writer(descriptor.defaultValue);
      }
      this.invokeChangedCallback(name, value, oldValue);
    }
    stringMapKeyRemoved(key, attributeName, oldValue) {
      const descriptor = this.valueDescriptorNameMap[key];
      if (this.hasValue(key)) {
        this.invokeChangedCallback(key, descriptor.writer(this.receiver[key]), oldValue);
      } else {
        this.invokeChangedCallback(key, descriptor.writer(descriptor.defaultValue), oldValue);
      }
    }
    invokeChangedCallbacksForDefaultValues() {
      for (const { key, name, defaultValue, writer } of this.valueDescriptors) {
        if (defaultValue != void 0 && !this.controller.data.has(key)) {
          this.invokeChangedCallback(name, writer(defaultValue), void 0);
        }
      }
    }
    invokeChangedCallback(name, rawValue, rawOldValue) {
      const changedMethodName = `${name}Changed`;
      const changedMethod = this.receiver[changedMethodName];
      if (typeof changedMethod == "function") {
        const descriptor = this.valueDescriptorNameMap[name];
        try {
          const value = descriptor.reader(rawValue);
          let oldValue = rawOldValue;
          if (rawOldValue) {
            oldValue = descriptor.reader(rawOldValue);
          }
          changedMethod.call(this.receiver, value, oldValue);
        } catch (error2) {
          if (error2 instanceof TypeError) {
            error2.message = `Stimulus Value "${this.context.identifier}.${descriptor.name}" - ${error2.message}`;
          }
          throw error2;
        }
      }
    }
    get valueDescriptors() {
      const { valueDescriptorMap } = this;
      return Object.keys(valueDescriptorMap).map((key) => valueDescriptorMap[key]);
    }
    get valueDescriptorNameMap() {
      const descriptors = {};
      Object.keys(this.valueDescriptorMap).forEach((key) => {
        const descriptor = this.valueDescriptorMap[key];
        descriptors[descriptor.name] = descriptor;
      });
      return descriptors;
    }
    hasValue(attributeName) {
      const descriptor = this.valueDescriptorNameMap[attributeName];
      const hasMethodName = `has${capitalize(descriptor.name)}`;
      return this.receiver[hasMethodName];
    }
  };
  var TargetObserver = class {
    constructor(context, delegate) {
      this.context = context;
      this.delegate = delegate;
      this.targetsByName = new Multimap();
    }
    start() {
      if (!this.tokenListObserver) {
        this.tokenListObserver = new TokenListObserver(this.element, this.attributeName, this);
        this.tokenListObserver.start();
      }
    }
    stop() {
      if (this.tokenListObserver) {
        this.disconnectAllTargets();
        this.tokenListObserver.stop();
        delete this.tokenListObserver;
      }
    }
    tokenMatched({ element, content: name }) {
      if (this.scope.containsElement(element)) {
        this.connectTarget(element, name);
      }
    }
    tokenUnmatched({ element, content: name }) {
      this.disconnectTarget(element, name);
    }
    connectTarget(element, name) {
      var _a;
      if (!this.targetsByName.has(name, element)) {
        this.targetsByName.add(name, element);
        (_a = this.tokenListObserver) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.targetConnected(element, name));
      }
    }
    disconnectTarget(element, name) {
      var _a;
      if (this.targetsByName.has(name, element)) {
        this.targetsByName.delete(name, element);
        (_a = this.tokenListObserver) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.targetDisconnected(element, name));
      }
    }
    disconnectAllTargets() {
      for (const name of this.targetsByName.keys) {
        for (const element of this.targetsByName.getValuesForKey(name)) {
          this.disconnectTarget(element, name);
        }
      }
    }
    get attributeName() {
      return `data-${this.context.identifier}-target`;
    }
    get element() {
      return this.context.element;
    }
    get scope() {
      return this.context.scope;
    }
  };
  function readInheritableStaticArrayValues(constructor, propertyName) {
    const ancestors = getAncestorsForConstructor(constructor);
    return Array.from(ancestors.reduce((values, constructor2) => {
      getOwnStaticArrayValues(constructor2, propertyName).forEach((name) => values.add(name));
      return values;
    }, /* @__PURE__ */ new Set()));
  }
  function readInheritableStaticObjectPairs(constructor, propertyName) {
    const ancestors = getAncestorsForConstructor(constructor);
    return ancestors.reduce((pairs, constructor2) => {
      pairs.push(...getOwnStaticObjectPairs(constructor2, propertyName));
      return pairs;
    }, []);
  }
  function getAncestorsForConstructor(constructor) {
    const ancestors = [];
    while (constructor) {
      ancestors.push(constructor);
      constructor = Object.getPrototypeOf(constructor);
    }
    return ancestors.reverse();
  }
  function getOwnStaticArrayValues(constructor, propertyName) {
    const definition = constructor[propertyName];
    return Array.isArray(definition) ? definition : [];
  }
  function getOwnStaticObjectPairs(constructor, propertyName) {
    const definition = constructor[propertyName];
    return definition ? Object.keys(definition).map((key) => [key, definition[key]]) : [];
  }
  var OutletObserver = class {
    constructor(context, delegate) {
      this.started = false;
      this.context = context;
      this.delegate = delegate;
      this.outletsByName = new Multimap();
      this.outletElementsByName = new Multimap();
      this.selectorObserverMap = /* @__PURE__ */ new Map();
      this.attributeObserverMap = /* @__PURE__ */ new Map();
    }
    start() {
      if (!this.started) {
        this.outletDefinitions.forEach((outletName) => {
          this.setupSelectorObserverForOutlet(outletName);
          this.setupAttributeObserverForOutlet(outletName);
        });
        this.started = true;
        this.dependentContexts.forEach((context) => context.refresh());
      }
    }
    refresh() {
      this.selectorObserverMap.forEach((observer) => observer.refresh());
      this.attributeObserverMap.forEach((observer) => observer.refresh());
    }
    stop() {
      if (this.started) {
        this.started = false;
        this.disconnectAllOutlets();
        this.stopSelectorObservers();
        this.stopAttributeObservers();
      }
    }
    stopSelectorObservers() {
      if (this.selectorObserverMap.size > 0) {
        this.selectorObserverMap.forEach((observer) => observer.stop());
        this.selectorObserverMap.clear();
      }
    }
    stopAttributeObservers() {
      if (this.attributeObserverMap.size > 0) {
        this.attributeObserverMap.forEach((observer) => observer.stop());
        this.attributeObserverMap.clear();
      }
    }
    selectorMatched(element, _selector, { outletName }) {
      const outlet = this.getOutlet(element, outletName);
      if (outlet) {
        this.connectOutlet(outlet, element, outletName);
      }
    }
    selectorUnmatched(element, _selector, { outletName }) {
      const outlet = this.getOutletFromMap(element, outletName);
      if (outlet) {
        this.disconnectOutlet(outlet, element, outletName);
      }
    }
    selectorMatchElement(element, { outletName }) {
      const selector = this.selector(outletName);
      const hasOutlet = this.hasOutlet(element, outletName);
      const hasOutletController = element.matches(`[${this.schema.controllerAttribute}~=${outletName}]`);
      if (selector) {
        return hasOutlet && hasOutletController && element.matches(selector);
      } else {
        return false;
      }
    }
    elementMatchedAttribute(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    elementAttributeValueChanged(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    elementUnmatchedAttribute(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    connectOutlet(outlet, element, outletName) {
      var _a;
      if (!this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.add(outletName, outlet);
        this.outletElementsByName.add(outletName, element);
        (_a = this.selectorObserverMap.get(outletName)) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.outletConnected(outlet, element, outletName));
      }
    }
    disconnectOutlet(outlet, element, outletName) {
      var _a;
      if (this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.delete(outletName, outlet);
        this.outletElementsByName.delete(outletName, element);
        (_a = this.selectorObserverMap.get(outletName)) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.outletDisconnected(outlet, element, outletName));
      }
    }
    disconnectAllOutlets() {
      for (const outletName of this.outletElementsByName.keys) {
        for (const element of this.outletElementsByName.getValuesForKey(outletName)) {
          for (const outlet of this.outletsByName.getValuesForKey(outletName)) {
            this.disconnectOutlet(outlet, element, outletName);
          }
        }
      }
    }
    updateSelectorObserverForOutlet(outletName) {
      const observer = this.selectorObserverMap.get(outletName);
      if (observer) {
        observer.selector = this.selector(outletName);
      }
    }
    setupSelectorObserverForOutlet(outletName) {
      const selector = this.selector(outletName);
      const selectorObserver = new SelectorObserver(document.body, selector, this, { outletName });
      this.selectorObserverMap.set(outletName, selectorObserver);
      selectorObserver.start();
    }
    setupAttributeObserverForOutlet(outletName) {
      const attributeName = this.attributeNameForOutletName(outletName);
      const attributeObserver = new AttributeObserver(this.scope.element, attributeName, this);
      this.attributeObserverMap.set(outletName, attributeObserver);
      attributeObserver.start();
    }
    selector(outletName) {
      return this.scope.outlets.getSelectorForOutletName(outletName);
    }
    attributeNameForOutletName(outletName) {
      return this.scope.schema.outletAttributeForScope(this.identifier, outletName);
    }
    getOutletNameFromOutletAttributeName(attributeName) {
      return this.outletDefinitions.find((outletName) => this.attributeNameForOutletName(outletName) === attributeName);
    }
    get outletDependencies() {
      const dependencies = new Multimap();
      this.router.modules.forEach((module) => {
        const constructor = module.definition.controllerConstructor;
        const outlets = readInheritableStaticArrayValues(constructor, "outlets");
        outlets.forEach((outlet) => dependencies.add(outlet, module.identifier));
      });
      return dependencies;
    }
    get outletDefinitions() {
      return this.outletDependencies.getKeysForValue(this.identifier);
    }
    get dependentControllerIdentifiers() {
      return this.outletDependencies.getValuesForKey(this.identifier);
    }
    get dependentContexts() {
      const identifiers = this.dependentControllerIdentifiers;
      return this.router.contexts.filter((context) => identifiers.includes(context.identifier));
    }
    hasOutlet(element, outletName) {
      return !!this.getOutlet(element, outletName) || !!this.getOutletFromMap(element, outletName);
    }
    getOutlet(element, outletName) {
      return this.application.getControllerForElementAndIdentifier(element, outletName);
    }
    getOutletFromMap(element, outletName) {
      return this.outletsByName.getValuesForKey(outletName).find((outlet) => outlet.element === element);
    }
    get scope() {
      return this.context.scope;
    }
    get schema() {
      return this.context.schema;
    }
    get identifier() {
      return this.context.identifier;
    }
    get application() {
      return this.context.application;
    }
    get router() {
      return this.application.router;
    }
  };
  var Context = class {
    constructor(module, scope) {
      this.logDebugActivity = (functionName, detail = {}) => {
        const { identifier, controller, element } = this;
        detail = Object.assign({ identifier, controller, element }, detail);
        this.application.logDebugActivity(this.identifier, functionName, detail);
      };
      this.module = module;
      this.scope = scope;
      this.controller = new module.controllerConstructor(this);
      this.bindingObserver = new BindingObserver(this, this.dispatcher);
      this.valueObserver = new ValueObserver(this, this.controller);
      this.targetObserver = new TargetObserver(this, this);
      this.outletObserver = new OutletObserver(this, this);
      try {
        this.controller.initialize();
        this.logDebugActivity("initialize");
      } catch (error2) {
        this.handleError(error2, "initializing controller");
      }
    }
    connect() {
      this.bindingObserver.start();
      this.valueObserver.start();
      this.targetObserver.start();
      this.outletObserver.start();
      try {
        this.controller.connect();
        this.logDebugActivity("connect");
      } catch (error2) {
        this.handleError(error2, "connecting controller");
      }
    }
    refresh() {
      this.outletObserver.refresh();
    }
    disconnect() {
      try {
        this.controller.disconnect();
        this.logDebugActivity("disconnect");
      } catch (error2) {
        this.handleError(error2, "disconnecting controller");
      }
      this.outletObserver.stop();
      this.targetObserver.stop();
      this.valueObserver.stop();
      this.bindingObserver.stop();
    }
    get application() {
      return this.module.application;
    }
    get identifier() {
      return this.module.identifier;
    }
    get schema() {
      return this.application.schema;
    }
    get dispatcher() {
      return this.application.dispatcher;
    }
    get element() {
      return this.scope.element;
    }
    get parentElement() {
      return this.element.parentElement;
    }
    handleError(error2, message, detail = {}) {
      const { identifier, controller, element } = this;
      detail = Object.assign({ identifier, controller, element }, detail);
      this.application.handleError(error2, `Error ${message}`, detail);
    }
    targetConnected(element, name) {
      this.invokeControllerMethod(`${name}TargetConnected`, element);
    }
    targetDisconnected(element, name) {
      this.invokeControllerMethod(`${name}TargetDisconnected`, element);
    }
    outletConnected(outlet, element, name) {
      this.invokeControllerMethod(`${namespaceCamelize(name)}OutletConnected`, outlet, element);
    }
    outletDisconnected(outlet, element, name) {
      this.invokeControllerMethod(`${namespaceCamelize(name)}OutletDisconnected`, outlet, element);
    }
    invokeControllerMethod(methodName, ...args) {
      const controller = this.controller;
      if (typeof controller[methodName] == "function") {
        controller[methodName](...args);
      }
    }
  };
  function bless(constructor) {
    return shadow(constructor, getBlessedProperties(constructor));
  }
  function shadow(constructor, properties) {
    const shadowConstructor = extend(constructor);
    const shadowProperties = getShadowProperties(constructor.prototype, properties);
    Object.defineProperties(shadowConstructor.prototype, shadowProperties);
    return shadowConstructor;
  }
  function getBlessedProperties(constructor) {
    const blessings = readInheritableStaticArrayValues(constructor, "blessings");
    return blessings.reduce((blessedProperties, blessing) => {
      const properties = blessing(constructor);
      for (const key in properties) {
        const descriptor = blessedProperties[key] || {};
        blessedProperties[key] = Object.assign(descriptor, properties[key]);
      }
      return blessedProperties;
    }, {});
  }
  function getShadowProperties(prototype, properties) {
    return getOwnKeys(properties).reduce((shadowProperties, key) => {
      const descriptor = getShadowedDescriptor(prototype, properties, key);
      if (descriptor) {
        Object.assign(shadowProperties, { [key]: descriptor });
      }
      return shadowProperties;
    }, {});
  }
  function getShadowedDescriptor(prototype, properties, key) {
    const shadowingDescriptor = Object.getOwnPropertyDescriptor(prototype, key);
    const shadowedByValue = shadowingDescriptor && "value" in shadowingDescriptor;
    if (!shadowedByValue) {
      const descriptor = Object.getOwnPropertyDescriptor(properties, key).value;
      if (shadowingDescriptor) {
        descriptor.get = shadowingDescriptor.get || descriptor.get;
        descriptor.set = shadowingDescriptor.set || descriptor.set;
      }
      return descriptor;
    }
  }
  var getOwnKeys = (() => {
    if (typeof Object.getOwnPropertySymbols == "function") {
      return (object) => [...Object.getOwnPropertyNames(object), ...Object.getOwnPropertySymbols(object)];
    } else {
      return Object.getOwnPropertyNames;
    }
  })();
  var extend = (() => {
    function extendWithReflect(constructor) {
      function extended() {
        return Reflect.construct(constructor, arguments, new.target);
      }
      extended.prototype = Object.create(constructor.prototype, {
        constructor: { value: extended }
      });
      Reflect.setPrototypeOf(extended, constructor);
      return extended;
    }
    function testReflectExtension() {
      const a = function() {
        this.a.call(this);
      };
      const b = extendWithReflect(a);
      b.prototype.a = function() {
      };
      return new b();
    }
    try {
      testReflectExtension();
      return extendWithReflect;
    } catch (error2) {
      return (constructor) => class extended extends constructor {
      };
    }
  })();
  function blessDefinition(definition) {
    return {
      identifier: definition.identifier,
      controllerConstructor: bless(definition.controllerConstructor)
    };
  }
  var Module = class {
    constructor(application, definition) {
      this.application = application;
      this.definition = blessDefinition(definition);
      this.contextsByScope = /* @__PURE__ */ new WeakMap();
      this.connectedContexts = /* @__PURE__ */ new Set();
    }
    get identifier() {
      return this.definition.identifier;
    }
    get controllerConstructor() {
      return this.definition.controllerConstructor;
    }
    get contexts() {
      return Array.from(this.connectedContexts);
    }
    connectContextForScope(scope) {
      const context = this.fetchContextForScope(scope);
      this.connectedContexts.add(context);
      context.connect();
    }
    disconnectContextForScope(scope) {
      const context = this.contextsByScope.get(scope);
      if (context) {
        this.connectedContexts.delete(context);
        context.disconnect();
      }
    }
    fetchContextForScope(scope) {
      let context = this.contextsByScope.get(scope);
      if (!context) {
        context = new Context(this, scope);
        this.contextsByScope.set(scope, context);
      }
      return context;
    }
  };
  var ClassMap = class {
    constructor(scope) {
      this.scope = scope;
    }
    has(name) {
      return this.data.has(this.getDataKey(name));
    }
    get(name) {
      return this.getAll(name)[0];
    }
    getAll(name) {
      const tokenString = this.data.get(this.getDataKey(name)) || "";
      return tokenize(tokenString);
    }
    getAttributeName(name) {
      return this.data.getAttributeNameForKey(this.getDataKey(name));
    }
    getDataKey(name) {
      return `${name}-class`;
    }
    get data() {
      return this.scope.data;
    }
  };
  var DataMap = class {
    constructor(scope) {
      this.scope = scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get(key) {
      const name = this.getAttributeNameForKey(key);
      return this.element.getAttribute(name);
    }
    set(key, value) {
      const name = this.getAttributeNameForKey(key);
      this.element.setAttribute(name, value);
      return this.get(key);
    }
    has(key) {
      const name = this.getAttributeNameForKey(key);
      return this.element.hasAttribute(name);
    }
    delete(key) {
      if (this.has(key)) {
        const name = this.getAttributeNameForKey(key);
        this.element.removeAttribute(name);
        return true;
      } else {
        return false;
      }
    }
    getAttributeNameForKey(key) {
      return `data-${this.identifier}-${dasherize(key)}`;
    }
  };
  var Guide = class {
    constructor(logger) {
      this.warnedKeysByObject = /* @__PURE__ */ new WeakMap();
      this.logger = logger;
    }
    warn(object, key, message) {
      let warnedKeys = this.warnedKeysByObject.get(object);
      if (!warnedKeys) {
        warnedKeys = /* @__PURE__ */ new Set();
        this.warnedKeysByObject.set(object, warnedKeys);
      }
      if (!warnedKeys.has(key)) {
        warnedKeys.add(key);
        this.logger.warn(message, object);
      }
    }
  };
  function attributeValueContainsToken(attributeName, token) {
    return `[${attributeName}~="${token}"]`;
  }
  var TargetSet = class {
    constructor(scope) {
      this.scope = scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get schema() {
      return this.scope.schema;
    }
    has(targetName) {
      return this.find(targetName) != null;
    }
    find(...targetNames) {
      return targetNames.reduce((target, targetName) => target || this.findTarget(targetName) || this.findLegacyTarget(targetName), void 0);
    }
    findAll(...targetNames) {
      return targetNames.reduce((targets, targetName) => [
        ...targets,
        ...this.findAllTargets(targetName),
        ...this.findAllLegacyTargets(targetName)
      ], []);
    }
    findTarget(targetName) {
      const selector = this.getSelectorForTargetName(targetName);
      return this.scope.findElement(selector);
    }
    findAllTargets(targetName) {
      const selector = this.getSelectorForTargetName(targetName);
      return this.scope.findAllElements(selector);
    }
    getSelectorForTargetName(targetName) {
      const attributeName = this.schema.targetAttributeForScope(this.identifier);
      return attributeValueContainsToken(attributeName, targetName);
    }
    findLegacyTarget(targetName) {
      const selector = this.getLegacySelectorForTargetName(targetName);
      return this.deprecate(this.scope.findElement(selector), targetName);
    }
    findAllLegacyTargets(targetName) {
      const selector = this.getLegacySelectorForTargetName(targetName);
      return this.scope.findAllElements(selector).map((element) => this.deprecate(element, targetName));
    }
    getLegacySelectorForTargetName(targetName) {
      const targetDescriptor = `${this.identifier}.${targetName}`;
      return attributeValueContainsToken(this.schema.targetAttribute, targetDescriptor);
    }
    deprecate(element, targetName) {
      if (element) {
        const { identifier } = this;
        const attributeName = this.schema.targetAttribute;
        const revisedAttributeName = this.schema.targetAttributeForScope(identifier);
        this.guide.warn(element, `target:${targetName}`, `Please replace ${attributeName}="${identifier}.${targetName}" with ${revisedAttributeName}="${targetName}". The ${attributeName} attribute is deprecated and will be removed in a future version of Stimulus.`);
      }
      return element;
    }
    get guide() {
      return this.scope.guide;
    }
  };
  var OutletSet = class {
    constructor(scope, controllerElement) {
      this.scope = scope;
      this.controllerElement = controllerElement;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get schema() {
      return this.scope.schema;
    }
    has(outletName) {
      return this.find(outletName) != null;
    }
    find(...outletNames) {
      return outletNames.reduce((outlet, outletName) => outlet || this.findOutlet(outletName), void 0);
    }
    findAll(...outletNames) {
      return outletNames.reduce((outlets, outletName) => [...outlets, ...this.findAllOutlets(outletName)], []);
    }
    getSelectorForOutletName(outletName) {
      const attributeName = this.schema.outletAttributeForScope(this.identifier, outletName);
      return this.controllerElement.getAttribute(attributeName);
    }
    findOutlet(outletName) {
      const selector = this.getSelectorForOutletName(outletName);
      if (selector)
        return this.findElement(selector, outletName);
    }
    findAllOutlets(outletName) {
      const selector = this.getSelectorForOutletName(outletName);
      return selector ? this.findAllElements(selector, outletName) : [];
    }
    findElement(selector, outletName) {
      const elements = this.scope.queryElements(selector);
      return elements.filter((element) => this.matchesElement(element, selector, outletName))[0];
    }
    findAllElements(selector, outletName) {
      const elements = this.scope.queryElements(selector);
      return elements.filter((element) => this.matchesElement(element, selector, outletName));
    }
    matchesElement(element, selector, outletName) {
      const controllerAttribute = element.getAttribute(this.scope.schema.controllerAttribute) || "";
      return element.matches(selector) && controllerAttribute.split(" ").includes(outletName);
    }
  };
  var Scope = class _Scope {
    constructor(schema, element, identifier, logger) {
      this.targets = new TargetSet(this);
      this.classes = new ClassMap(this);
      this.data = new DataMap(this);
      this.containsElement = (element2) => {
        return element2.closest(this.controllerSelector) === this.element;
      };
      this.schema = schema;
      this.element = element;
      this.identifier = identifier;
      this.guide = new Guide(logger);
      this.outlets = new OutletSet(this.documentScope, element);
    }
    findElement(selector) {
      return this.element.matches(selector) ? this.element : this.queryElements(selector).find(this.containsElement);
    }
    findAllElements(selector) {
      return [
        ...this.element.matches(selector) ? [this.element] : [],
        ...this.queryElements(selector).filter(this.containsElement)
      ];
    }
    queryElements(selector) {
      return Array.from(this.element.querySelectorAll(selector));
    }
    get controllerSelector() {
      return attributeValueContainsToken(this.schema.controllerAttribute, this.identifier);
    }
    get isDocumentScope() {
      return this.element === document.documentElement;
    }
    get documentScope() {
      return this.isDocumentScope ? this : new _Scope(this.schema, document.documentElement, this.identifier, this.guide.logger);
    }
  };
  var ScopeObserver = class {
    constructor(element, schema, delegate) {
      this.element = element;
      this.schema = schema;
      this.delegate = delegate;
      this.valueListObserver = new ValueListObserver(this.element, this.controllerAttribute, this);
      this.scopesByIdentifierByElement = /* @__PURE__ */ new WeakMap();
      this.scopeReferenceCounts = /* @__PURE__ */ new WeakMap();
    }
    start() {
      this.valueListObserver.start();
    }
    stop() {
      this.valueListObserver.stop();
    }
    get controllerAttribute() {
      return this.schema.controllerAttribute;
    }
    parseValueForToken(token) {
      const { element, content: identifier } = token;
      return this.parseValueForElementAndIdentifier(element, identifier);
    }
    parseValueForElementAndIdentifier(element, identifier) {
      const scopesByIdentifier = this.fetchScopesByIdentifierForElement(element);
      let scope = scopesByIdentifier.get(identifier);
      if (!scope) {
        scope = this.delegate.createScopeForElementAndIdentifier(element, identifier);
        scopesByIdentifier.set(identifier, scope);
      }
      return scope;
    }
    elementMatchedValue(element, value) {
      const referenceCount = (this.scopeReferenceCounts.get(value) || 0) + 1;
      this.scopeReferenceCounts.set(value, referenceCount);
      if (referenceCount == 1) {
        this.delegate.scopeConnected(value);
      }
    }
    elementUnmatchedValue(element, value) {
      const referenceCount = this.scopeReferenceCounts.get(value);
      if (referenceCount) {
        this.scopeReferenceCounts.set(value, referenceCount - 1);
        if (referenceCount == 1) {
          this.delegate.scopeDisconnected(value);
        }
      }
    }
    fetchScopesByIdentifierForElement(element) {
      let scopesByIdentifier = this.scopesByIdentifierByElement.get(element);
      if (!scopesByIdentifier) {
        scopesByIdentifier = /* @__PURE__ */ new Map();
        this.scopesByIdentifierByElement.set(element, scopesByIdentifier);
      }
      return scopesByIdentifier;
    }
  };
  var Router = class {
    constructor(application) {
      this.application = application;
      this.scopeObserver = new ScopeObserver(this.element, this.schema, this);
      this.scopesByIdentifier = new Multimap();
      this.modulesByIdentifier = /* @__PURE__ */ new Map();
    }
    get element() {
      return this.application.element;
    }
    get schema() {
      return this.application.schema;
    }
    get logger() {
      return this.application.logger;
    }
    get controllerAttribute() {
      return this.schema.controllerAttribute;
    }
    get modules() {
      return Array.from(this.modulesByIdentifier.values());
    }
    get contexts() {
      return this.modules.reduce((contexts, module) => contexts.concat(module.contexts), []);
    }
    start() {
      this.scopeObserver.start();
    }
    stop() {
      this.scopeObserver.stop();
    }
    loadDefinition(definition) {
      this.unloadIdentifier(definition.identifier);
      const module = new Module(this.application, definition);
      this.connectModule(module);
      const afterLoad = definition.controllerConstructor.afterLoad;
      if (afterLoad) {
        afterLoad.call(definition.controllerConstructor, definition.identifier, this.application);
      }
    }
    unloadIdentifier(identifier) {
      const module = this.modulesByIdentifier.get(identifier);
      if (module) {
        this.disconnectModule(module);
      }
    }
    getContextForElementAndIdentifier(element, identifier) {
      const module = this.modulesByIdentifier.get(identifier);
      if (module) {
        return module.contexts.find((context) => context.element == element);
      }
    }
    proposeToConnectScopeForElementAndIdentifier(element, identifier) {
      const scope = this.scopeObserver.parseValueForElementAndIdentifier(element, identifier);
      if (scope) {
        this.scopeObserver.elementMatchedValue(scope.element, scope);
      } else {
        console.error(`Couldn't find or create scope for identifier: "${identifier}" and element:`, element);
      }
    }
    handleError(error2, message, detail) {
      this.application.handleError(error2, message, detail);
    }
    createScopeForElementAndIdentifier(element, identifier) {
      return new Scope(this.schema, element, identifier, this.logger);
    }
    scopeConnected(scope) {
      this.scopesByIdentifier.add(scope.identifier, scope);
      const module = this.modulesByIdentifier.get(scope.identifier);
      if (module) {
        module.connectContextForScope(scope);
      }
    }
    scopeDisconnected(scope) {
      this.scopesByIdentifier.delete(scope.identifier, scope);
      const module = this.modulesByIdentifier.get(scope.identifier);
      if (module) {
        module.disconnectContextForScope(scope);
      }
    }
    connectModule(module) {
      this.modulesByIdentifier.set(module.identifier, module);
      const scopes = this.scopesByIdentifier.getValuesForKey(module.identifier);
      scopes.forEach((scope) => module.connectContextForScope(scope));
    }
    disconnectModule(module) {
      this.modulesByIdentifier.delete(module.identifier);
      const scopes = this.scopesByIdentifier.getValuesForKey(module.identifier);
      scopes.forEach((scope) => module.disconnectContextForScope(scope));
    }
  };
  var defaultSchema = {
    controllerAttribute: "data-controller",
    actionAttribute: "data-action",
    targetAttribute: "data-target",
    targetAttributeForScope: (identifier) => `data-${identifier}-target`,
    outletAttributeForScope: (identifier, outlet) => `data-${identifier}-${outlet}-outlet`,
    keyMappings: Object.assign(Object.assign({ enter: "Enter", tab: "Tab", esc: "Escape", space: " ", up: "ArrowUp", down: "ArrowDown", left: "ArrowLeft", right: "ArrowRight", home: "Home", end: "End", page_up: "PageUp", page_down: "PageDown" }, objectFromEntries("abcdefghijklmnopqrstuvwxyz".split("").map((c) => [c, c]))), objectFromEntries("0123456789".split("").map((n) => [n, n])))
  };
  function objectFromEntries(array) {
    return array.reduce((memo, [k, v]) => Object.assign(Object.assign({}, memo), { [k]: v }), {});
  }
  var Application = class {
    constructor(element = document.documentElement, schema = defaultSchema) {
      this.logger = console;
      this.debug = false;
      this.logDebugActivity = (identifier, functionName, detail = {}) => {
        if (this.debug) {
          this.logFormattedMessage(identifier, functionName, detail);
        }
      };
      this.element = element;
      this.schema = schema;
      this.dispatcher = new Dispatcher(this);
      this.router = new Router(this);
      this.actionDescriptorFilters = Object.assign({}, defaultActionDescriptorFilters);
    }
    static start(element, schema) {
      const application = new this(element, schema);
      application.start();
      return application;
    }
    async start() {
      await domReady();
      this.logDebugActivity("application", "starting");
      this.dispatcher.start();
      this.router.start();
      this.logDebugActivity("application", "start");
    }
    stop() {
      this.logDebugActivity("application", "stopping");
      this.dispatcher.stop();
      this.router.stop();
      this.logDebugActivity("application", "stop");
    }
    register(identifier, controllerConstructor) {
      this.load({ identifier, controllerConstructor });
    }
    registerActionOption(name, filter) {
      this.actionDescriptorFilters[name] = filter;
    }
    load(head, ...rest) {
      const definitions = Array.isArray(head) ? head : [head, ...rest];
      definitions.forEach((definition) => {
        if (definition.controllerConstructor.shouldLoad) {
          this.router.loadDefinition(definition);
        }
      });
    }
    unload(head, ...rest) {
      const identifiers = Array.isArray(head) ? head : [head, ...rest];
      identifiers.forEach((identifier) => this.router.unloadIdentifier(identifier));
    }
    get controllers() {
      return this.router.contexts.map((context) => context.controller);
    }
    getControllerForElementAndIdentifier(element, identifier) {
      const context = this.router.getContextForElementAndIdentifier(element, identifier);
      return context ? context.controller : null;
    }
    handleError(error2, message, detail) {
      var _a;
      this.logger.error(`%s

%o

%o`, message, error2, detail);
      (_a = window.onerror) === null || _a === void 0 ? void 0 : _a.call(window, message, "", 0, 0, error2);
    }
    logFormattedMessage(identifier, functionName, detail = {}) {
      detail = Object.assign({ application: this }, detail);
      this.logger.groupCollapsed(`${identifier} #${functionName}`);
      this.logger.log("details:", Object.assign({}, detail));
      this.logger.groupEnd();
    }
  };
  function domReady() {
    return new Promise((resolve) => {
      if (document.readyState == "loading") {
        document.addEventListener("DOMContentLoaded", () => resolve());
      } else {
        resolve();
      }
    });
  }
  function ClassPropertiesBlessing(constructor) {
    const classes = readInheritableStaticArrayValues(constructor, "classes");
    return classes.reduce((properties, classDefinition) => {
      return Object.assign(properties, propertiesForClassDefinition(classDefinition));
    }, {});
  }
  function propertiesForClassDefinition(key) {
    return {
      [`${key}Class`]: {
        get() {
          const { classes } = this;
          if (classes.has(key)) {
            return classes.get(key);
          } else {
            const attribute = classes.getAttributeName(key);
            throw new Error(`Missing attribute "${attribute}"`);
          }
        }
      },
      [`${key}Classes`]: {
        get() {
          return this.classes.getAll(key);
        }
      },
      [`has${capitalize(key)}Class`]: {
        get() {
          return this.classes.has(key);
        }
      }
    };
  }
  function OutletPropertiesBlessing(constructor) {
    const outlets = readInheritableStaticArrayValues(constructor, "outlets");
    return outlets.reduce((properties, outletDefinition) => {
      return Object.assign(properties, propertiesForOutletDefinition(outletDefinition));
    }, {});
  }
  function getOutletController(controller, element, identifier) {
    return controller.application.getControllerForElementAndIdentifier(element, identifier);
  }
  function getControllerAndEnsureConnectedScope(controller, element, outletName) {
    let outletController = getOutletController(controller, element, outletName);
    if (outletController)
      return outletController;
    controller.application.router.proposeToConnectScopeForElementAndIdentifier(element, outletName);
    outletController = getOutletController(controller, element, outletName);
    if (outletController)
      return outletController;
  }
  function propertiesForOutletDefinition(name) {
    const camelizedName = namespaceCamelize(name);
    return {
      [`${camelizedName}Outlet`]: {
        get() {
          const outletElement = this.outlets.find(name);
          const selector = this.outlets.getSelectorForOutletName(name);
          if (outletElement) {
            const outletController = getControllerAndEnsureConnectedScope(this, outletElement, name);
            if (outletController)
              return outletController;
            throw new Error(`The provided outlet element is missing an outlet controller "${name}" instance for host controller "${this.identifier}"`);
          }
          throw new Error(`Missing outlet element "${name}" for host controller "${this.identifier}". Stimulus couldn't find a matching outlet element using selector "${selector}".`);
        }
      },
      [`${camelizedName}Outlets`]: {
        get() {
          const outlets = this.outlets.findAll(name);
          if (outlets.length > 0) {
            return outlets.map((outletElement) => {
              const outletController = getControllerAndEnsureConnectedScope(this, outletElement, name);
              if (outletController)
                return outletController;
              console.warn(`The provided outlet element is missing an outlet controller "${name}" instance for host controller "${this.identifier}"`, outletElement);
            }).filter((controller) => controller);
          }
          return [];
        }
      },
      [`${camelizedName}OutletElement`]: {
        get() {
          const outletElement = this.outlets.find(name);
          const selector = this.outlets.getSelectorForOutletName(name);
          if (outletElement) {
            return outletElement;
          } else {
            throw new Error(`Missing outlet element "${name}" for host controller "${this.identifier}". Stimulus couldn't find a matching outlet element using selector "${selector}".`);
          }
        }
      },
      [`${camelizedName}OutletElements`]: {
        get() {
          return this.outlets.findAll(name);
        }
      },
      [`has${capitalize(camelizedName)}Outlet`]: {
        get() {
          return this.outlets.has(name);
        }
      }
    };
  }
  function TargetPropertiesBlessing(constructor) {
    const targets = readInheritableStaticArrayValues(constructor, "targets");
    return targets.reduce((properties, targetDefinition) => {
      return Object.assign(properties, propertiesForTargetDefinition(targetDefinition));
    }, {});
  }
  function propertiesForTargetDefinition(name) {
    return {
      [`${name}Target`]: {
        get() {
          const target = this.targets.find(name);
          if (target) {
            return target;
          } else {
            throw new Error(`Missing target element "${name}" for "${this.identifier}" controller`);
          }
        }
      },
      [`${name}Targets`]: {
        get() {
          return this.targets.findAll(name);
        }
      },
      [`has${capitalize(name)}Target`]: {
        get() {
          return this.targets.has(name);
        }
      }
    };
  }
  function ValuePropertiesBlessing(constructor) {
    const valueDefinitionPairs = readInheritableStaticObjectPairs(constructor, "values");
    const propertyDescriptorMap = {
      valueDescriptorMap: {
        get() {
          return valueDefinitionPairs.reduce((result, valueDefinitionPair) => {
            const valueDescriptor = parseValueDefinitionPair(valueDefinitionPair, this.identifier);
            const attributeName = this.data.getAttributeNameForKey(valueDescriptor.key);
            return Object.assign(result, { [attributeName]: valueDescriptor });
          }, {});
        }
      }
    };
    return valueDefinitionPairs.reduce((properties, valueDefinitionPair) => {
      return Object.assign(properties, propertiesForValueDefinitionPair(valueDefinitionPair));
    }, propertyDescriptorMap);
  }
  function propertiesForValueDefinitionPair(valueDefinitionPair, controller) {
    const definition = parseValueDefinitionPair(valueDefinitionPair, controller);
    const { key, name, reader: read, writer: write } = definition;
    return {
      [name]: {
        get() {
          const value = this.data.get(key);
          if (value !== null) {
            return read(value);
          } else {
            return definition.defaultValue;
          }
        },
        set(value) {
          if (value === void 0) {
            this.data.delete(key);
          } else {
            this.data.set(key, write(value));
          }
        }
      },
      [`has${capitalize(name)}`]: {
        get() {
          return this.data.has(key) || definition.hasCustomDefaultValue;
        }
      }
    };
  }
  function parseValueDefinitionPair([token, typeDefinition], controller) {
    return valueDescriptorForTokenAndTypeDefinition({
      controller,
      token,
      typeDefinition
    });
  }
  function parseValueTypeConstant(constant) {
    switch (constant) {
      case Array:
        return "array";
      case Boolean:
        return "boolean";
      case Number:
        return "number";
      case Object:
        return "object";
      case String:
        return "string";
    }
  }
  function parseValueTypeDefault(defaultValue) {
    switch (typeof defaultValue) {
      case "boolean":
        return "boolean";
      case "number":
        return "number";
      case "string":
        return "string";
    }
    if (Array.isArray(defaultValue))
      return "array";
    if (Object.prototype.toString.call(defaultValue) === "[object Object]")
      return "object";
  }
  function parseValueTypeObject(payload) {
    const { controller, token, typeObject } = payload;
    const hasType = isSomething(typeObject.type);
    const hasDefault = isSomething(typeObject.default);
    const fullObject = hasType && hasDefault;
    const onlyType = hasType && !hasDefault;
    const onlyDefault = !hasType && hasDefault;
    const typeFromObject = parseValueTypeConstant(typeObject.type);
    const typeFromDefaultValue = parseValueTypeDefault(payload.typeObject.default);
    if (onlyType)
      return typeFromObject;
    if (onlyDefault)
      return typeFromDefaultValue;
    if (typeFromObject !== typeFromDefaultValue) {
      const propertyPath = controller ? `${controller}.${token}` : token;
      throw new Error(`The specified default value for the Stimulus Value "${propertyPath}" must match the defined type "${typeFromObject}". The provided default value of "${typeObject.default}" is of type "${typeFromDefaultValue}".`);
    }
    if (fullObject)
      return typeFromObject;
  }
  function parseValueTypeDefinition(payload) {
    const { controller, token, typeDefinition } = payload;
    const typeObject = { controller, token, typeObject: typeDefinition };
    const typeFromObject = parseValueTypeObject(typeObject);
    const typeFromDefaultValue = parseValueTypeDefault(typeDefinition);
    const typeFromConstant = parseValueTypeConstant(typeDefinition);
    const type = typeFromObject || typeFromDefaultValue || typeFromConstant;
    if (type)
      return type;
    const propertyPath = controller ? `${controller}.${typeDefinition}` : token;
    throw new Error(`Unknown value type "${propertyPath}" for "${token}" value`);
  }
  function defaultValueForDefinition(typeDefinition) {
    const constant = parseValueTypeConstant(typeDefinition);
    if (constant)
      return defaultValuesByType[constant];
    const hasDefault = hasProperty(typeDefinition, "default");
    const hasType = hasProperty(typeDefinition, "type");
    const typeObject = typeDefinition;
    if (hasDefault)
      return typeObject.default;
    if (hasType) {
      const { type } = typeObject;
      const constantFromType = parseValueTypeConstant(type);
      if (constantFromType)
        return defaultValuesByType[constantFromType];
    }
    return typeDefinition;
  }
  function valueDescriptorForTokenAndTypeDefinition(payload) {
    const { token, typeDefinition } = payload;
    const key = `${dasherize(token)}-value`;
    const type = parseValueTypeDefinition(payload);
    return {
      type,
      key,
      name: camelize(key),
      get defaultValue() {
        return defaultValueForDefinition(typeDefinition);
      },
      get hasCustomDefaultValue() {
        return parseValueTypeDefault(typeDefinition) !== void 0;
      },
      reader: readers[type],
      writer: writers[type] || writers.default
    };
  }
  var defaultValuesByType = {
    get array() {
      return [];
    },
    boolean: false,
    number: 0,
    get object() {
      return {};
    },
    string: ""
  };
  var readers = {
    array(value) {
      const array = JSON.parse(value);
      if (!Array.isArray(array)) {
        throw new TypeError(`expected value of type "array" but instead got value "${value}" of type "${parseValueTypeDefault(array)}"`);
      }
      return array;
    },
    boolean(value) {
      return !(value == "0" || String(value).toLowerCase() == "false");
    },
    number(value) {
      return Number(value.replace(/_/g, ""));
    },
    object(value) {
      const object = JSON.parse(value);
      if (object === null || typeof object != "object" || Array.isArray(object)) {
        throw new TypeError(`expected value of type "object" but instead got value "${value}" of type "${parseValueTypeDefault(object)}"`);
      }
      return object;
    },
    string(value) {
      return value;
    }
  };
  var writers = {
    default: writeString,
    array: writeJSON,
    object: writeJSON
  };
  function writeJSON(value) {
    return JSON.stringify(value);
  }
  function writeString(value) {
    return `${value}`;
  }
  var Controller = class {
    constructor(context) {
      this.context = context;
    }
    static get shouldLoad() {
      return true;
    }
    static afterLoad(_identifier, _application) {
      return;
    }
    get application() {
      return this.context.application;
    }
    get scope() {
      return this.context.scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get targets() {
      return this.scope.targets;
    }
    get outlets() {
      return this.scope.outlets;
    }
    get classes() {
      return this.scope.classes;
    }
    get data() {
      return this.scope.data;
    }
    initialize() {
    }
    connect() {
    }
    disconnect() {
    }
    dispatch(eventName, { target = this.element, detail = {}, prefix = this.identifier, bubbles = true, cancelable = true } = {}) {
      const type = prefix ? `${prefix}:${eventName}` : eventName;
      const event = new CustomEvent(type, { detail, bubbles, cancelable });
      target.dispatchEvent(event);
      return event;
    }
  };
  Controller.blessings = [
    ClassPropertiesBlessing,
    TargetPropertiesBlessing,
    ValuePropertiesBlessing,
    OutletPropertiesBlessing
  ];
  Controller.targets = [];
  Controller.outlets = [];
  Controller.values = {};

  // resources/js/debug.js
  var consoleRef = globalThis.console;
  function createDebugLogger(isEnabled) {
    return function debug2(...args) {
      if (!isEnabled()) {
        return;
      }
      consoleRef.log(...args);
    };
  }

  // resources/js/controllers/mollie_checkout_controller.js
  var mollie_checkout_controller_default = class extends Controller {
    connect() {
      this._debug = createDebugLogger(() => this.debugValue);
      this._debug("Mollie checkout controller connected", { methodCount: this.methodTargets.length });
      this.highlightSelected();
      this._form = this.element.closest("form");
      this._onSubmit = () => this.disableContinueButton();
      if (this._form) {
        this._form.addEventListener("submit", this._onSubmit);
      }
    }
    disconnect() {
      if (this._form) {
        this._form.removeEventListener("submit", this._onSubmit);
      }
    }
    methodSelected(event) {
      this._debug("Mollie method selected", event.target.value);
      this.highlightSelected();
    }
    highlightSelected() {
      this.methodTargets.forEach((label) => {
        const input = label.querySelector('input[type="radio"]');
        label.classList.toggle("selected", Boolean(input && input.checked));
      });
    }
    /**
     * Guards against a double-click while the browser navigates away after the classic form POST
     * (there is no AJAX response to re-enable the button on — the page is about to unload).
     */
    disableContinueButton() {
      const button = document.querySelector('.col-lg-5 button[onclick*="requestSubmit"]');
      if (!button) {
        return;
      }
      button.disabled = true;
      button.classList.add("is-loading");
    }
  };
  __publicField(mollie_checkout_controller_default, "targets", ["method"]);
  __publicField(mollie_checkout_controller_default, "values", {
    debug: { type: Boolean, default: false }
  });

  // resources/js/controllers/mollie_components_controller.js
  var MOLLIE_JS = "https://js.mollie.com/v1/mollie.js";
  var FIELDS = ["cardNumber", "cardHolder", "expiryDate", "verificationCode"];
  var mollie_components_controller_default = class extends Controller {
    connect() {
      this._debug = createDebugLogger(() => this.debugValue);
      this._components = {};
      this._mounting = null;
      this._inFlight = false;
      this._orderButton = null;
      this._onPageShow = (event) => {
        if (event.persisted) {
          this._releaseOrderButton();
        }
      };
      window.addEventListener("pageshow", this._onPageShow);
      this._cardMode = this._readCardMode();
      this._syncMode();
      this._debug("Mollie Components controller connected", { cardMode: this._cardMode });
    }
    /**
     * Take the mounted components down with the controller.
     *
     * Without this, a host that is removed or replaced (OPC swaps the checkout
     * footer's content on every payment-method change) leaves its components
     * registered inside Mollie's library while their DOM is gone. The library then
     * dereferences them on the next focus, blur or keystroke —
     * "Cannot read properties of undefined (reading 'isLoaded' / 'setLabel' /
     * 'touched' / 'focusInput')" — and the card fields cannot be filled in at all.
     */
    disconnect() {
      window.removeEventListener("pageshow", this._onPageShow);
      for (const [name, component] of Object.entries(this._components || {})) {
        if (component && typeof component.unmount === "function") {
          try {
            component.unmount();
          } catch (e) {
          }
        }
        this._debug("Mollie component unmounted", { name });
      }
      this._components = {};
      this._mollie = null;
      this._mounting = null;
    }
    /** data-action: change->mollie-components#flowChanged on the method radios. */
    flowChanged() {
      this._cardMode = this._readCardMode();
      this._syncMode();
    }
    /** Card mode = the selected Mollie method is "creditcard" (inline Components). */
    _readCardMode() {
      const checked = document.querySelector('input[name="mollieMethod"]:checked');
      return !!checked && checked.value === "creditcard";
    }
    _syncMode() {
      if (this.hasFieldsTarget) {
        this.fieldsTarget.style.display = this._cardMode ? "" : "none";
      }
      if (this._cardMode) {
        this._ensureMounted();
      }
    }
    _ensureMounted() {
      if (this._mounting) {
        return this._mounting;
      }
      this._mounting = this._mount().finally(() => {
        this._mounting = null;
      });
      return this._mounting;
    }
    async _mount() {
      if (this._mollie) {
        return;
      }
      if (!this.profileIdValue) {
        this._debug("no profile id configured \u2014 cannot mount Mollie Components");
        return;
      }
      await this._loadMollieJs();
      if (!window.Mollie) {
        this._showError("Mollie.js is unavailable");
        return;
      }
      this._mollie = window.Mollie(this.profileIdValue, {
        locale: this.localeValue,
        testmode: this.testmodeValue
      });
      for (const name of FIELDS) {
        const mount = this[`${name}Target`] ? this[`${name}Target`] : null;
        if (!mount) {
          continue;
        }
        const component = this._mollie.createComponent(name);
        component.mount(mount);
        component.addEventListener("change", (event) => this._fieldChanged(name, event));
        this._components[name] = component;
      }
      this._debug("Mollie Components mounted", Object.keys(this._components));
    }
    /**
     * data-action: click->mollie-components#placeOrder on the order button.
     * Redirect flow → submit the core order form as-is. Card flow → tokenize the card, put the
     * token on the hidden field, then submit the same form (server creates the creditcard payment).
     */
    async placeOrder(event) {
      if (event) {
        event.preventDefault();
      }
      if (this._inFlight) {
        this._debug("order submission already in flight \u2014 click ignored");
        return;
      }
      const form = document.getElementById(this.formIdValue);
      if (!form) {
        this._showError("Order form not found.");
        return;
      }
      this._lockOrderButton(event ? event.currentTarget : null);
      if (!this._cardMode) {
        this._submit(form);
        return;
      }
      this._clearError();
      if (!this._mollie) {
        await this._ensureMounted();
      }
      if (!this._mollie) {
        this._showError("Card form is not ready. Please try again.");
        this._releaseOrderButton();
        return;
      }
      try {
        const { token, error: error2 } = await this._mollie.createToken();
        if (error2) {
          this._showError(error2.message || "Please check your card details.");
          this._releaseOrderButton();
          return;
        }
        if (this.hasTokenTarget) {
          this.tokenTarget.value = token;
        }
        this._debug("card tokenized \u2014 submitting order");
        this._submit(form);
      } catch (err) {
        this._debug("createToken failed", err);
        this._showError("Could not process the card. Please try again.");
        this._releaseOrderButton();
      }
    }
    _lockOrderButton(button) {
      this._inFlight = true;
      this._orderButton = button instanceof HTMLButtonElement ? button : null;
      if (this._orderButton) {
        this._orderButton.disabled = true;
        this._orderButton.classList.add("is-loading");
      }
    }
    /** Released only when the submission did not leave the page: tokenisation failed, or bfcache restore. */
    _releaseOrderButton() {
      this._inFlight = false;
      if (this._orderButton) {
        this._orderButton.disabled = false;
        this._orderButton.classList.remove("is-loading");
      }
    }
    _submit(form) {
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
    _fieldChanged(name, event) {
      if (event && event.error && event.touched) {
        this._showError(event.error);
        return;
      }
      this._clearError();
    }
    _loadMollieJs() {
      return new Promise((resolve, reject) => {
        if (window.Mollie) {
          resolve();
          return;
        }
        const existing = document.querySelector(`script[src="${MOLLIE_JS}"]`);
        if (existing) {
          existing.addEventListener("load", () => resolve());
          existing.addEventListener("error", () => reject(new Error("Failed to load Mollie.js")));
          return;
        }
        const script = document.createElement("script");
        script.src = MOLLIE_JS;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error("Failed to load Mollie.js"));
        document.head.appendChild(script);
      });
    }
    _showError(message) {
      if (this.hasErrorTarget) {
        this.errorTarget.textContent = message;
        this.errorTarget.style.display = "block";
      }
    }
    _clearError() {
      if (this.hasErrorTarget) {
        this.errorTarget.textContent = "";
        this.errorTarget.style.display = "none";
      }
    }
  };
  __publicField(mollie_components_controller_default, "targets", ["cardNumber", "cardHolder", "expiryDate", "verificationCode", "error", "token", "fields"]);
  __publicField(mollie_components_controller_default, "values", {
    profileId: String,
    testmode: { type: Boolean, default: false },
    locale: { type: String, default: "en_US" },
    // Id of the core order form the "Order now" button submits (index.php?cl=order&fnc=execute).
    formId: { type: String, default: "orderConfirmAgbBottom" },
    debug: { type: Boolean, default: false }
  });

  // resources/js/controllers/mollie_place_order_controller.js
  var mollie_place_order_controller_default = class extends Controller {
    connect() {
      this._inFlight = false;
      this.element.removeAttribute("onclick");
      this._onPageShow = (event) => {
        if (event.persisted) {
          this._release();
        }
      };
      window.addEventListener("pageshow", this._onPageShow);
    }
    disconnect() {
      window.removeEventListener("pageshow", this._onPageShow);
    }
    submit(event) {
      if (event) {
        event.preventDefault();
      }
      if (this._inFlight) {
        return;
      }
      const form = document.getElementById(this.formIdValue);
      if (!form) {
        return;
      }
      this._inFlight = true;
      this.element.disabled = true;
      this.element.classList.add("is-loading");
      form.submit();
    }
    _release() {
      this._inFlight = false;
      this.element.disabled = false;
      this.element.classList.remove("is-loading");
    }
  };
  __publicField(mollie_place_order_controller_default, "values", {
    // Id of the core order form (index.php?cl=order&fnc=execute).
    formId: { type: String, default: "orderConfirmAgbBottom" }
  });

  // resources/js/app.js
  window.Stimulus = window.Stimulus || Application.start();
  window.Stimulus.register("mollie-checkout", mollie_checkout_controller_default);
  window.Stimulus.register("mollie-components", mollie_components_controller_default);
  window.Stimulus.register("mollie-place-order", mollie_place_order_controller_default);
  var mollieDebugEnabled = () => {
    var _a;
    return ((_a = window.oMollie) == null ? void 0 : _a.debug) === true;
  };
  var debug = createDebugLogger(mollieDebugEnabled);
  debug("Mollie module: Stimulus initialized with controllers", window.Stimulus.router.modulesByIdentifier);
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9qcy9kZWJ1Zy5qcyIsICIuLi8uLi9yZXNvdXJjZXMvanMvY29udHJvbGxlcnMvbW9sbGllX2NoZWNrb3V0X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2pzL2NvbnRyb2xsZXJzL21vbGxpZV9jb21wb25lbnRzX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2pzL2NvbnRyb2xsZXJzL21vbGxpZV9wbGFjZV9vcmRlcl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9qcy9hcHAuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbIi8qXG5TdGltdWx1cyAzLjIuMVxuQ29weXJpZ2h0IFx1MDBBOSAyMDIzIEJhc2VjYW1wLCBMTENcbiAqL1xuY2xhc3MgRXZlbnRMaXN0ZW5lciB7XG4gICAgY29uc3RydWN0b3IoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBldmVudFRhcmdldDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBldmVudE5hbWU7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZXZlbnRPcHRpb25zO1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LmFkZEV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5yZW1vdmVFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmFkZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuZGVsZXRlKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBleHRlbmRlZEV2ZW50ID0gZXh0ZW5kRXZlbnQoZXZlbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IGJpbmRpbmcgb2YgdGhpcy5iaW5kaW5ncykge1xuICAgICAgICAgICAgaWYgKGV4dGVuZGVkRXZlbnQuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkKSB7XG4gICAgICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBiaW5kaW5nLmhhbmRsZUV2ZW50KGV4dGVuZGVkRXZlbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGhhc0JpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5zaXplID4gMDtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnVub3JkZXJlZEJpbmRpbmdzKS5zb3J0KChsZWZ0LCByaWdodCkgPT4ge1xuICAgICAgICAgICAgY29uc3QgbGVmdEluZGV4ID0gbGVmdC5pbmRleCwgcmlnaHRJbmRleCA9IHJpZ2h0LmluZGV4O1xuICAgICAgICAgICAgcmV0dXJuIGxlZnRJbmRleCA8IHJpZ2h0SW5kZXggPyAtMSA6IGxlZnRJbmRleCA+IHJpZ2h0SW5kZXggPyAxIDogMDtcbiAgICAgICAgfSk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXh0ZW5kRXZlbnQoZXZlbnQpIHtcbiAgICBpZiAoXCJpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWRcIiBpbiBldmVudCkge1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICBjb25zdCB7IHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiB9ID0gZXZlbnQ7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7XG4gICAgICAgICAgICBpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQ6IGZhbHNlLFxuICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24uY2FsbCh0aGlzKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0pO1xuICAgIH1cbn1cblxuY2xhc3MgRGlzcGF0Y2hlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmNvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMudmFsdWVzKCkpLnJlZHVjZSgobGlzdGVuZXJzLCBtYXApID0+IGxpc3RlbmVycy5jb25jYXQoQXJyYXkuZnJvbShtYXAudmFsdWVzKCkpKSwgW10pO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgY2xlYXJFdmVudExpc3RlbmVycyA9IGZhbHNlKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICBpZiAoY2xlYXJFdmVudExpc3RlbmVycylcbiAgICAgICAgICAgIHRoaXMuY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIGNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyLmhhc0JpbmRpbmdzKCkpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5yZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBldmVudExpc3RlbmVyTWFwLmRlbGV0ZShjYWNoZUtleSk7XG4gICAgICAgIGlmIChldmVudExpc3RlbmVyTWFwLnNpemUgPT0gMClcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZGVsZXRlKGV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICByZXR1cm4gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyID0gZXZlbnRMaXN0ZW5lck1hcC5nZXQoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIgPSB0aGlzLmNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuc2V0KGNhY2hlS2V5LCBldmVudExpc3RlbmVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IG5ldyBFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyTWFwKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5zZXQoZXZlbnRUYXJnZXQsIGV2ZW50TGlzdGVuZXJNYXApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyTWFwO1xuICAgIH1cbiAgICBjYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBwYXJ0cyA9IFtldmVudE5hbWVdO1xuICAgICAgICBPYmplY3Qua2V5cyhldmVudE9wdGlvbnMpXG4gICAgICAgICAgICAuc29ydCgpXG4gICAgICAgICAgICAuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBwYXJ0cy5wdXNoKGAke2V2ZW50T3B0aW9uc1trZXldID8gXCJcIiA6IFwiIVwifSR7a2V5fWApO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIHBhcnRzLmpvaW4oXCI6XCIpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0ge1xuICAgIHN0b3AoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBwcmV2ZW50KHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBzZWxmKHsgZXZlbnQsIHZhbHVlLCBlbGVtZW50IH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudCA9PT0gZXZlbnQudGFyZ2V0O1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICB9LFxufTtcbmNvbnN0IGRlc2NyaXB0b3JQYXR0ZXJuID0gL14oPzooPzooW14uXSs/KVxcKyk/KC4rPykoPzpcXC4oLis/KSk/KD86QCh3aW5kb3d8ZG9jdW1lbnQpKT8tPik/KC4rPykoPzojKFteOl0rPykpKD86OiguKykpPyQvO1xuZnVuY3Rpb24gcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKGRlc2NyaXB0b3JTdHJpbmcpIHtcbiAgICBjb25zdCBzb3VyY2UgPSBkZXNjcmlwdG9yU3RyaW5nLnRyaW0oKTtcbiAgICBjb25zdCBtYXRjaGVzID0gc291cmNlLm1hdGNoKGRlc2NyaXB0b3JQYXR0ZXJuKSB8fCBbXTtcbiAgICBsZXQgZXZlbnROYW1lID0gbWF0Y2hlc1syXTtcbiAgICBsZXQga2V5RmlsdGVyID0gbWF0Y2hlc1szXTtcbiAgICBpZiAoa2V5RmlsdGVyICYmICFbXCJrZXlkb3duXCIsIFwia2V5dXBcIiwgXCJrZXlwcmVzc1wiXS5pbmNsdWRlcyhldmVudE5hbWUpKSB7XG4gICAgICAgIGV2ZW50TmFtZSArPSBgLiR7a2V5RmlsdGVyfWA7XG4gICAgICAgIGtleUZpbHRlciA9IFwiXCI7XG4gICAgfVxuICAgIHJldHVybiB7XG4gICAgICAgIGV2ZW50VGFyZ2V0OiBwYXJzZUV2ZW50VGFyZ2V0KG1hdGNoZXNbNF0pLFxuICAgICAgICBldmVudE5hbWUsXG4gICAgICAgIGV2ZW50T3B0aW9uczogbWF0Y2hlc1s3XSA/IHBhcnNlRXZlbnRPcHRpb25zKG1hdGNoZXNbN10pIDoge30sXG4gICAgICAgIGlkZW50aWZpZXI6IG1hdGNoZXNbNV0sXG4gICAgICAgIG1ldGhvZE5hbWU6IG1hdGNoZXNbNl0sXG4gICAgICAgIGtleUZpbHRlcjogbWF0Y2hlc1sxXSB8fCBrZXlGaWx0ZXIsXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXROYW1lKSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcIndpbmRvd1wiKSB7XG4gICAgICAgIHJldHVybiB3aW5kb3c7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcImRvY3VtZW50XCIpIHtcbiAgICAgICAgcmV0dXJuIGRvY3VtZW50O1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRPcHRpb25zKGV2ZW50T3B0aW9ucykge1xuICAgIHJldHVybiBldmVudE9wdGlvbnNcbiAgICAgICAgLnNwbGl0KFwiOlwiKVxuICAgICAgICAucmVkdWNlKChvcHRpb25zLCB0b2tlbikgPT4gT2JqZWN0LmFzc2lnbihvcHRpb25zLCB7IFt0b2tlbi5yZXBsYWNlKC9eIS8sIFwiXCIpXTogIS9eIS8udGVzdCh0b2tlbikgfSksIHt9KTtcbn1cbmZ1bmN0aW9uIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0ID09IHdpbmRvdykge1xuICAgICAgICByZXR1cm4gXCJ3aW5kb3dcIjtcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgPT0gZG9jdW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIFwiZG9jdW1lbnRcIjtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIGNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyg/OltfLV0pKFthLXowLTldKS9nLCAoXywgY2hhcikgPT4gY2hhci50b1VwcGVyQ2FzZSgpKTtcbn1cbmZ1bmN0aW9uIG5hbWVzcGFjZUNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIGNhbWVsaXplKHZhbHVlLnJlcGxhY2UoLy0tL2csIFwiLVwiKS5yZXBsYWNlKC9fXy9nLCBcIl9cIikpO1xufVxuZnVuY3Rpb24gY2FwaXRhbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5jaGFyQXQoMCkudG9VcHBlckNhc2UoKSArIHZhbHVlLnNsaWNlKDEpO1xufVxuZnVuY3Rpb24gZGFzaGVyaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyhbQS1aXSkvZywgKF8sIGNoYXIpID0+IGAtJHtjaGFyLnRvTG93ZXJDYXNlKCl9YCk7XG59XG5mdW5jdGlvbiB0b2tlbml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5tYXRjaCgvW15cXHNdKy9nKSB8fCBbXTtcbn1cblxuZnVuY3Rpb24gaXNTb21ldGhpbmcob2JqZWN0KSB7XG4gICAgcmV0dXJuIG9iamVjdCAhPT0gbnVsbCAmJiBvYmplY3QgIT09IHVuZGVmaW5lZDtcbn1cbmZ1bmN0aW9uIGhhc1Byb3BlcnR5KG9iamVjdCwgcHJvcGVydHkpIHtcbiAgICByZXR1cm4gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKG9iamVjdCwgcHJvcGVydHkpO1xufVxuXG5jb25zdCBhbGxNb2RpZmllcnMgPSBbXCJtZXRhXCIsIFwiY3RybFwiLCBcImFsdFwiLCBcInNoaWZ0XCJdO1xuY2xhc3MgQWN0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBpbmRleCwgZGVzY3JpcHRvciwgc2NoZW1hKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaW5kZXggPSBpbmRleDtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGRlc2NyaXB0b3IuZXZlbnRUYXJnZXQgfHwgZWxlbWVudDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBkZXNjcmlwdG9yLmV2ZW50TmFtZSB8fCBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB8fCBlcnJvcihcIm1pc3NpbmcgZXZlbnQgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBkZXNjcmlwdG9yLmV2ZW50T3B0aW9ucyB8fCB7fTtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gZGVzY3JpcHRvci5pZGVudGlmaWVyIHx8IGVycm9yKFwibWlzc2luZyBpZGVudGlmaWVyXCIpO1xuICAgICAgICB0aGlzLm1ldGhvZE5hbWUgPSBkZXNjcmlwdG9yLm1ldGhvZE5hbWUgfHwgZXJyb3IoXCJtaXNzaW5nIG1ldGhvZCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmtleUZpbHRlciA9IGRlc2NyaXB0b3Iua2V5RmlsdGVyIHx8IFwiXCI7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgIH1cbiAgICBzdGF0aWMgZm9yVG9rZW4odG9rZW4sIHNjaGVtYSkge1xuICAgICAgICByZXR1cm4gbmV3IHRoaXModG9rZW4uZWxlbWVudCwgdG9rZW4uaW5kZXgsIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyh0b2tlbi5jb250ZW50KSwgc2NoZW1hKTtcbiAgICB9XG4gICAgdG9TdHJpbmcoKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50RmlsdGVyID0gdGhpcy5rZXlGaWx0ZXIgPyBgLiR7dGhpcy5rZXlGaWx0ZXJ9YCA6IFwiXCI7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gdGhpcy5ldmVudFRhcmdldE5hbWUgPyBgQCR7dGhpcy5ldmVudFRhcmdldE5hbWV9YCA6IFwiXCI7XG4gICAgICAgIHJldHVybiBgJHt0aGlzLmV2ZW50TmFtZX0ke2V2ZW50RmlsdGVyfSR7ZXZlbnRUYXJnZXR9LT4ke3RoaXMuaWRlbnRpZmllcn0jJHt0aGlzLm1ldGhvZE5hbWV9YDtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IHRoaXMua2V5RmlsdGVyLnNwbGl0KFwiK1wiKTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3Qgc3RhbmRhcmRGaWx0ZXIgPSBmaWx0ZXJzLmZpbHRlcigoa2V5KSA9PiAhYWxsTW9kaWZpZXJzLmluY2x1ZGVzKGtleSkpWzBdO1xuICAgICAgICBpZiAoIXN0YW5kYXJkRmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCFoYXNQcm9wZXJ0eSh0aGlzLmtleU1hcHBpbmdzLCBzdGFuZGFyZEZpbHRlcikpIHtcbiAgICAgICAgICAgIGVycm9yKGBjb250YWlucyB1bmtub3duIGtleSBmaWx0ZXI6ICR7dGhpcy5rZXlGaWx0ZXJ9YCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRoaXMua2V5TWFwcGluZ3Nbc3RhbmRhcmRGaWx0ZXJdLnRvTG93ZXJDYXNlKCkgIT09IGV2ZW50LmtleS50b0xvd2VyQ2FzZSgpO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gW3RoaXMua2V5RmlsdGVyXTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgIH1cbiAgICBnZXQgcGFyYW1zKCkge1xuICAgICAgICBjb25zdCBwYXJhbXMgPSB7fTtcbiAgICAgICAgY29uc3QgcGF0dGVybiA9IG5ldyBSZWdFeHAoYF5kYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0oLispLXBhcmFtJGAsIFwiaVwiKTtcbiAgICAgICAgZm9yIChjb25zdCB7IG5hbWUsIHZhbHVlIH0gb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gbmFtZS5tYXRjaChwYXR0ZXJuKTtcbiAgICAgICAgICAgIGNvbnN0IGtleSA9IG1hdGNoICYmIG1hdGNoWzFdO1xuICAgICAgICAgICAgaWYgKGtleSkge1xuICAgICAgICAgICAgICAgIHBhcmFtc1tjYW1lbGl6ZShrZXkpXSA9IHR5cGVjYXN0KHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyYW1zO1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXROYW1lKCkge1xuICAgICAgICByZXR1cm4gc3RyaW5naWZ5RXZlbnRUYXJnZXQodGhpcy5ldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGdldCBrZXlNYXBwaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmtleU1hcHBpbmdzO1xuICAgIH1cbiAgICBrZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpIHtcbiAgICAgICAgY29uc3QgW21ldGEsIGN0cmwsIGFsdCwgc2hpZnRdID0gYWxsTW9kaWZpZXJzLm1hcCgobW9kaWZpZXIpID0+IGZpbHRlcnMuaW5jbHVkZXMobW9kaWZpZXIpKTtcbiAgICAgICAgcmV0dXJuIGV2ZW50Lm1ldGFLZXkgIT09IG1ldGEgfHwgZXZlbnQuY3RybEtleSAhPT0gY3RybCB8fCBldmVudC5hbHRLZXkgIT09IGFsdCB8fCBldmVudC5zaGlmdEtleSAhPT0gc2hpZnQ7XG4gICAgfVxufVxuY29uc3QgZGVmYXVsdEV2ZW50TmFtZXMgPSB7XG4gICAgYTogKCkgPT4gXCJjbGlja1wiLFxuICAgIGJ1dHRvbjogKCkgPT4gXCJjbGlja1wiLFxuICAgIGZvcm06ICgpID0+IFwic3VibWl0XCIsXG4gICAgZGV0YWlsczogKCkgPT4gXCJ0b2dnbGVcIixcbiAgICBpbnB1dDogKGUpID0+IChlLmdldEF0dHJpYnV0ZShcInR5cGVcIikgPT0gXCJzdWJtaXRcIiA/IFwiY2xpY2tcIiA6IFwiaW5wdXRcIiksXG4gICAgc2VsZWN0OiAoKSA9PiBcImNoYW5nZVwiLFxuICAgIHRleHRhcmVhOiAoKSA9PiBcImlucHV0XCIsXG59O1xuZnVuY3Rpb24gZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgIGNvbnN0IHRhZ05hbWUgPSBlbGVtZW50LnRhZ05hbWUudG9Mb3dlckNhc2UoKTtcbiAgICBpZiAodGFnTmFtZSBpbiBkZWZhdWx0RXZlbnROYW1lcykge1xuICAgICAgICByZXR1cm4gZGVmYXVsdEV2ZW50TmFtZXNbdGFnTmFtZV0oZWxlbWVudCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXJyb3IobWVzc2FnZSkge1xuICAgIHRocm93IG5ldyBFcnJvcihtZXNzYWdlKTtcbn1cbmZ1bmN0aW9uIHR5cGVjYXN0KHZhbHVlKSB7XG4gICAgdHJ5IHtcbiAgICAgICAgcmV0dXJuIEpTT04ucGFyc2UodmFsdWUpO1xuICAgIH1cbiAgICBjYXRjaCAob19PKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmcge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmFjdGlvbiA9IGFjdGlvbjtcbiAgICB9XG4gICAgZ2V0IGluZGV4KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uaW5kZXg7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50VGFyZ2V0O1xuICAgIH1cbiAgICBnZXQgZXZlbnRPcHRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRPcHRpb25zO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBhY3Rpb25FdmVudCA9IHRoaXMucHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KTtcbiAgICAgICAgaWYgKHRoaXMud2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpICYmIHRoaXMuYXBwbHlFdmVudE1vZGlmaWVycyhhY3Rpb25FdmVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlV2l0aEV2ZW50KGFjdGlvbkV2ZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnROYW1lO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kKCkge1xuICAgICAgICBjb25zdCBtZXRob2QgPSB0aGlzLmNvbnRyb2xsZXJbdGhpcy5tZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBtZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICByZXR1cm4gbWV0aG9kO1xuICAgICAgICB9XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgQWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cIiByZWZlcmVuY2VzIHVuZGVmaW5lZCBtZXRob2QgXCIke3RoaXMubWV0aG9kTmFtZX1cImApO1xuICAgIH1cbiAgICBhcHBseUV2ZW50TW9kaWZpZXJzKGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdGhpcy5hY3Rpb247XG4gICAgICAgIGNvbnN0IHsgYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgfSA9IHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICAgICAgY29uc3QgeyBjb250cm9sbGVyIH0gPSB0aGlzLmNvbnRleHQ7XG4gICAgICAgIGxldCBwYXNzZXMgPSB0cnVlO1xuICAgICAgICBmb3IgKGNvbnN0IFtuYW1lLCB2YWx1ZV0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5ldmVudE9wdGlvbnMpKSB7XG4gICAgICAgICAgICBpZiAobmFtZSBpbiBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycykge1xuICAgICAgICAgICAgICAgIGNvbnN0IGZpbHRlciA9IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdO1xuICAgICAgICAgICAgICAgIHBhc3NlcyA9IHBhc3NlcyAmJiBmaWx0ZXIoeyBuYW1lLCB2YWx1ZSwgZXZlbnQsIGVsZW1lbnQsIGNvbnRyb2xsZXIgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFzc2VzO1xuICAgIH1cbiAgICBwcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHsgcGFyYW1zOiB0aGlzLmFjdGlvbi5wYXJhbXMgfSk7XG4gICAgfVxuICAgIGludm9rZVdpdGhFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCB7IHRhcmdldCwgY3VycmVudFRhcmdldCB9ID0gZXZlbnQ7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLm1ldGhvZC5jYWxsKHRoaXMuY29udHJvbGxlciwgZXZlbnQpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5tZXRob2ROYW1lLCB7IGV2ZW50LCB0YXJnZXQsIGN1cnJlbnRUYXJnZXQsIGFjdGlvbjogdGhpcy5tZXRob2ROYW1lIH0pO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGRldGFpbCA9IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXgsIGV2ZW50IH07XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQuaGFuZGxlRXJyb3IoZXJyb3IsIGBpbnZva2luZyBhY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiYCwgZGV0YWlsKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB3aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IGV2ZW50LnRhcmdldDtcbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgS2V5Ym9hcmRFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIE1vdXNlRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAodGhpcy5lbGVtZW50ID09PSBldmVudFRhcmdldCkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgaW5zdGFuY2VvZiBFbGVtZW50ICYmIHRoaXMuZWxlbWVudC5jb250YWlucyhldmVudFRhcmdldCkpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChldmVudFRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQodGhpcy5hY3Rpb24uZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5tZXRob2ROYW1lO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuY2xhc3MgRWxlbWVudE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0ID0geyBhdHRyaWJ1dGVzOiB0cnVlLCBjaGlsZExpc3Q6IHRydWUsIHN1YnRyZWU6IHRydWUgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNhbGxiYWNrKCk7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gbmV3IFNldCh0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUoKSk7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnRzKSkge1xuICAgICAgICAgICAgICAgIGlmICghbWF0Y2hlcy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKG1hdGNoZXMpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBpZiAobXV0YXRpb24udHlwZSA9PSBcImF0dHJpYnV0ZXNcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQXR0cmlidXRlQ2hhbmdlKG11dGF0aW9uLnRhcmdldCwgbXV0YXRpb24uYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAobXV0YXRpb24udHlwZSA9PSBcImNoaWxkTGlzdFwiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NSZW1vdmVkTm9kZXMobXV0YXRpb24ucmVtb3ZlZE5vZGVzKTtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0FkZGVkTm9kZXMobXV0YXRpb24uYWRkZWROb2Rlcyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQgJiYgdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc1JlbW92ZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLnJlbW92ZUVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBZGRlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQgJiYgdGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMuYWRkRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUgPSB0aGlzLmVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKTtcbiAgICB9XG4gICAgcHJvY2Vzc1RyZWUodHJlZSwgcHJvY2Vzc29yKSB7XG4gICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkpIHtcbiAgICAgICAgICAgIHByb2Nlc3Nvci5jYWxsKHRoaXMsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRGcm9tTm9kZShub2RlKSB7XG4gICAgICAgIGlmIChub2RlLm5vZGVUeXBlID09IE5vZGUuRUxFTUVOVF9OT0RFKSB7XG4gICAgICAgICAgICByZXR1cm4gbm9kZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50SXNBY3RpdmUoZWxlbWVudCkge1xuICAgICAgICBpZiAoZWxlbWVudC5pc0Nvbm5lY3RlZCAhPSB0aGlzLmVsZW1lbnQuaXNDb25uZWN0ZWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgYWRkRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuYWRkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZUVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuZGVsZXRlKGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQXR0cmlidXRlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlTmFtZSA9IGF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGBbJHt0aGlzLmF0dHJpYnV0ZU5hbWV9XWA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lmhhc0F0dHJpYnV0ZSh0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwodGhpcy5zZWxlY3RvcikpO1xuICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZCAmJiB0aGlzLmF0dHJpYnV0ZU5hbWUgPT0gYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhZGQobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmFkZCh2YWx1ZSk7XG59XG5mdW5jdGlvbiBkZWwobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmRlbGV0ZSh2YWx1ZSk7XG4gICAgcHJ1bmUobWFwLCBrZXkpO1xufVxuZnVuY3Rpb24gZmV0Y2gobWFwLCBrZXkpIHtcbiAgICBsZXQgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICghdmFsdWVzKSB7XG4gICAgICAgIHZhbHVlcyA9IG5ldyBTZXQoKTtcbiAgICAgICAgbWFwLnNldChrZXksIHZhbHVlcyk7XG4gICAgfVxuICAgIHJldHVybiB2YWx1ZXM7XG59XG5mdW5jdGlvbiBwcnVuZShtYXAsIGtleSkge1xuICAgIGNvbnN0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAodmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLnNpemUgPT0gMCkge1xuICAgICAgICBtYXAuZGVsZXRlKGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHRoaXMudmFsdWVzQnlLZXkgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBrZXlzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LmtleXMoKSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHZhbHVlcywgc2V0KSA9PiB2YWx1ZXMuY29uY2F0KEFycmF5LmZyb20oc2V0KSksIFtdKTtcbiAgICB9XG4gICAgZ2V0IHNpemUoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHNpemUsIHNldCkgPT4gc2l6ZSArIHNldC5zaXplLCAwKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgYWRkKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBkZWwodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGhhcyhrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgaGFzS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gdGhpcy52YWx1ZXNCeUtleS5oYXMoa2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnNvbWUoKHNldCkgPT4gc2V0Lmhhcyh2YWx1ZSkpO1xuICAgIH1cbiAgICBnZXRWYWx1ZXNGb3JLZXkoa2V5KSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgPyBBcnJheS5mcm9tKHZhbHVlcykgOiBbXTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkpXG4gICAgICAgICAgICAuZmlsdGVyKChbX2tleSwgdmFsdWVzXSkgPT4gdmFsdWVzLmhhcyh2YWx1ZSkpXG4gICAgICAgICAgICAubWFwKChba2V5LCBfdmFsdWVzXSkgPT4ga2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIEluZGV4ZWRNdWx0aW1hcCBleHRlbmRzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgc3VwZXIoKTtcbiAgICAgICAgdGhpcy5rZXlzQnlWYWx1ZSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5rZXlzQnlWYWx1ZS5rZXlzKCkpO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5hZGQoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGFkZCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuZGVsZXRlKGtleSwgdmFsdWUpO1xuICAgICAgICBkZWwodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmtleXNCeVZhbHVlLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXQgPSB0aGlzLmtleXNCeVZhbHVlLmdldCh2YWx1ZSk7XG4gICAgICAgIHJldHVybiBzZXQgPyBBcnJheS5mcm9tKHNldCkgOiBbXTtcbiAgICB9XG59XG5cbmNsYXNzIFNlbGVjdG9yT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNlbGVjdG9yLCBkZWxlZ2F0ZSwgZGV0YWlscykge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLmRldGFpbHMgPSBkZXRhaWxzO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5fc2VsZWN0b3I7XG4gICAgfVxuICAgIHNldCBzZWxlY3RvcihzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXMgJiYgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB0aGlzLmRldGFpbHMpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXM7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSkuZmlsdGVyKChtYXRjaCkgPT4gdGhpcy5tYXRjaEVsZW1lbnQobWF0Y2gpKTtcbiAgICAgICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JzID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmdldEtleXNGb3JWYWx1ZShlbGVtZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBzZWxlY3RvciBvZiBzZWxlY3RvcnMpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIF9hdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlZEJlZm9yZSA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5oYXMoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKG1hdGNoZXMgJiYgIW1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmICghbWF0Y2hlcyAmJiBtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuYWRkKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmRlbGV0ZShzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxufVxuXG5jbGFzcyBTdHJpbmdNYXBPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB7IGF0dHJpYnV0ZXM6IHRydWUsIGF0dHJpYnV0ZU9sZFZhbHVlOiB0cnVlIH0pO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBhdHRyaWJ1dGVOYW1lIG9mIHRoaXMua25vd25BdHRyaWJ1dGVOYW1lcykge1xuICAgICAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBudWxsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IG11dGF0aW9uLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbXV0YXRpb24ub2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3Qga2V5ID0gdGhpcy5kZWxlZ2F0ZS5nZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChrZXkgIT0gbnVsbCkge1xuICAgICAgICAgICAgaWYgKCF0aGlzLnN0cmluZ01hcC5oYXMoYXR0cmlidXRlTmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICBpZiAodGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpICE9IHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHZhbHVlID09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvbGRWYWx1ZSA9IHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5kZWxldGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG9sZFZhbHVlKVxuICAgICAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5zZXQoYXR0cmlidXRlTmFtZSwgdmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBrbm93bkF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShuZXcgU2V0KHRoaXMuY3VycmVudEF0dHJpYnV0ZU5hbWVzLmNvbmNhdCh0aGlzLnJlY29yZGVkQXR0cmlidXRlTmFtZXMpKSk7XG4gICAgfVxuICAgIGdldCBjdXJyZW50QXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKS5tYXAoKGF0dHJpYnV0ZSkgPT4gYXR0cmlidXRlLm5hbWUpO1xuICAgIH1cbiAgICBnZXQgcmVjb3JkZWRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5zdHJpbmdNYXAua2V5cygpKTtcbiAgICB9XG59XG5cbmNsYXNzIFRva2VuTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkpO1xuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgW3VubWF0Y2hlZFRva2VucywgbWF0Y2hlZFRva2Vuc10gPSB0aGlzLnJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh1bm1hdGNoZWRUb2tlbnMpO1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQobWF0Y2hlZFRva2Vucyk7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCkpO1xuICAgIH1cbiAgICB0b2tlbnNNYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5NYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2Vuc1VubWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuVW5tYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuTWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmFkZCh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5Vbm1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5kZWxldGUodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICByZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHByZXZpb3VzVG9rZW5zID0gdGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBjdXJyZW50VG9rZW5zID0gdGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgY29uc3QgZmlyc3REaWZmZXJpbmdJbmRleCA9IHppcChwcmV2aW91c1Rva2VucywgY3VycmVudFRva2VucykuZmluZEluZGV4KChbcHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuXSkgPT4gIXRva2Vuc0FyZUVxdWFsKHByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbikpO1xuICAgICAgICBpZiAoZmlyc3REaWZmZXJpbmdJbmRleCA9PSAtMSkge1xuICAgICAgICAgICAgcmV0dXJuIFtbXSwgW11dO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtwcmV2aW91c1Rva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KSwgY3VycmVudFRva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KV07XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgcmV0dXJuIHRva2VuU3RyaW5nXG4gICAgICAgIC50cmltKClcbiAgICAgICAgLnNwbGl0KC9cXHMrLylcbiAgICAgICAgLmZpbHRlcigoY29udGVudCkgPT4gY29udGVudC5sZW5ndGgpXG4gICAgICAgIC5tYXAoKGNvbnRlbnQsIGluZGV4KSA9PiAoeyBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBjb250ZW50LCBpbmRleCB9KSk7XG59XG5mdW5jdGlvbiB6aXAobGVmdCwgcmlnaHQpIHtcbiAgICBjb25zdCBsZW5ndGggPSBNYXRoLm1heChsZWZ0Lmxlbmd0aCwgcmlnaHQubGVuZ3RoKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbSh7IGxlbmd0aCB9LCAoXywgaW5kZXgpID0+IFtsZWZ0W2luZGV4XSwgcmlnaHRbaW5kZXhdXSk7XG59XG5mdW5jdGlvbiB0b2tlbnNBcmVFcXVhbChsZWZ0LCByaWdodCkge1xuICAgIHJldHVybiBsZWZ0ICYmIHJpZ2h0ICYmIGxlZnQuaW5kZXggPT0gcmlnaHQuaW5kZXggJiYgbGVmdC5jb250ZW50ID09IHJpZ2h0LmNvbnRlbnQ7XG59XG5cbmNsYXNzIFZhbHVlTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbiA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuc2V0KHRva2VuLCB2YWx1ZSk7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuZGVsZXRlKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgbGV0IHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLmdldCh0b2tlbik7XG4gICAgICAgIGlmICghcGFyc2VSZXN1bHQpIHtcbiAgICAgICAgICAgIHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5zZXQodG9rZW4sIHBhcnNlUmVzdWx0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyc2VSZXN1bHQ7XG4gICAgfVxuICAgIGZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgdmFsdWVzQnlUb2tlbiA9IHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghdmFsdWVzQnlUb2tlbikge1xuICAgICAgICAgICAgdmFsdWVzQnlUb2tlbiA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5zZXQoZWxlbWVudCwgdmFsdWVzQnlUb2tlbik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHZhbHVlc0J5VG9rZW47XG4gICAgfVxuICAgIHBhcnNlVG9rZW4odG9rZW4pIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kZWxlZ2F0ZS5wYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgcmV0dXJuIHsgdmFsdWUgfTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHJldHVybiB7IGVycm9yIH07XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmdPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24gPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmFjdGlvbkF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXI7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxBY3Rpb25zKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFjdGlvbkF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmFjdGlvbkF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5iaW5kaW5nc0J5QWN0aW9uLnZhbHVlcygpKTtcbiAgICB9XG4gICAgY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IG5ldyBCaW5kaW5nKHRoaXMuY29udGV4dCwgYWN0aW9uKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLnNldChhY3Rpb24sIGJpbmRpbmcpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZ2V0KGFjdGlvbik7XG4gICAgICAgIGlmIChiaW5kaW5nKSB7XG4gICAgICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZGVsZXRlKGFjdGlvbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbEFjdGlvbnMoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ3MuZm9yRWFjaCgoYmluZGluZykgPT4gdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIHRydWUpKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmNsZWFyKCk7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCBhY3Rpb24gPSBBY3Rpb24uZm9yVG9rZW4odG9rZW4sIHRoaXMuc2NoZW1hKTtcbiAgICAgICAgaWYgKGFjdGlvbi5pZGVudGlmaWVyID09IHRoaXMuaWRlbnRpZmllcikge1xuICAgICAgICAgICAgcmV0dXJuIGFjdGlvbjtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG59XG5cbmNsYXNzIFZhbHVlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIHJlY2VpdmVyKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMucmVjZWl2ZXIgPSByZWNlaXZlcjtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlciA9IG5ldyBTdHJpbmdNYXBPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCA9IHRoaXMuY29udHJvbGxlci52YWx1ZURlc2NyaXB0b3JNYXA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lIGluIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV0ubmFtZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBpZiAoIXRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwgbmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgaWYgKHZhbHVlID09PSBudWxsKVxuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICBpZiAob2xkVmFsdWUgPT09IG51bGwpIHtcbiAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2tleV07XG4gICAgICAgIGlmICh0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpIHtcbiAgICAgICAgZm9yIChjb25zdCB7IGtleSwgbmFtZSwgZGVmYXVsdFZhbHVlLCB3cml0ZXIgfSBvZiB0aGlzLnZhbHVlRGVzY3JpcHRvcnMpIHtcbiAgICAgICAgICAgIGlmIChkZWZhdWx0VmFsdWUgIT0gdW5kZWZpbmVkICYmICF0aGlzLmNvbnRyb2xsZXIuZGF0YS5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHdyaXRlcihkZWZhdWx0VmFsdWUpLCB1bmRlZmluZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCByYXdWYWx1ZSwgcmF3T2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZE5hbWUgPSBgJHtuYW1lfUNoYW5nZWRgO1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kID0gdGhpcy5yZWNlaXZlcltjaGFuZ2VkTWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgY2hhbmdlZE1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3VmFsdWUpO1xuICAgICAgICAgICAgICAgIGxldCBvbGRWYWx1ZSA9IHJhd09sZFZhbHVlO1xuICAgICAgICAgICAgICAgIGlmIChyYXdPbGRWYWx1ZSkge1xuICAgICAgICAgICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd09sZFZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY2hhbmdlZE1ldGhvZC5jYWxsKHRoaXMucmVjZWl2ZXIsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBpZiAoZXJyb3IgaW5zdGFuY2VvZiBUeXBlRXJyb3IpIHtcbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSA9IGBTdGltdWx1cyBWYWx1ZSBcIiR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LiR7ZGVzY3JpcHRvci5uYW1lfVwiIC0gJHtlcnJvci5tZXNzYWdlfWA7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IGVycm9yO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JzKCkge1xuICAgICAgICBjb25zdCB7IHZhbHVlRGVzY3JpcHRvck1hcCB9ID0gdGhpcztcbiAgICAgICAgcmV0dXJuIE9iamVjdC5rZXlzKHZhbHVlRGVzY3JpcHRvck1hcCkubWFwKChrZXkpID0+IHZhbHVlRGVzY3JpcHRvck1hcFtrZXldKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvck5hbWVNYXAoKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3JzID0ge307XG4gICAgICAgIE9iamVjdC5rZXlzKHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKS5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFtrZXldO1xuICAgICAgICAgICAgZGVzY3JpcHRvcnNbZGVzY3JpcHRvci5uYW1lXSA9IGRlc2NyaXB0b3I7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcnM7XG4gICAgfVxuICAgIGhhc1ZhbHVlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgY29uc3QgaGFzTWV0aG9kTmFtZSA9IGBoYXMke2NhcGl0YWxpemUoZGVzY3JpcHRvci5uYW1lKX1gO1xuICAgICAgICByZXR1cm4gdGhpcy5yZWNlaXZlcltoYXNNZXRob2ROYW1lXTtcbiAgICB9XG59XG5cbmNsYXNzIFRhcmdldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsVGFyZ2V0cygpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy50b2tlbkxpc3RPYnNlcnZlcjtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgaWYgKHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgIH1cbiAgICBjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5hZGQobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuZGVsZXRlKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsVGFyZ2V0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBuYW1lIG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShuYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LXRhcmdldGA7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oYW5jZXN0b3JzLnJlZHVjZSgodmFsdWVzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKS5mb3JFYWNoKChuYW1lKSA9PiB2YWx1ZXMuYWRkKG5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcztcbiAgICB9LCBuZXcgU2V0KCkpKTtcbn1cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZWR1Y2UoKHBhaXJzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBwYWlycy5wdXNoKC4uLmdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHBhaXJzO1xuICAgIH0sIFtdKTtcbn1cbmZ1bmN0aW9uIGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gW107XG4gICAgd2hpbGUgKGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGFuY2VzdG9ycy5wdXNoKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgY29uc3RydWN0b3IgPSBPYmplY3QuZ2V0UHJvdG90eXBlT2YoY29uc3RydWN0b3IpO1xuICAgIH1cbiAgICByZXR1cm4gYW5jZXN0b3JzLnJldmVyc2UoKTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gQXJyYXkuaXNBcnJheShkZWZpbml0aW9uKSA/IGRlZmluaXRpb24gOiBbXTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gZGVmaW5pdGlvbiA/IE9iamVjdC5rZXlzKGRlZmluaXRpb24pLm1hcCgoa2V5KSA9PiBba2V5LCBkZWZpbml0aW9uW2tleV1dKSA6IFtdO1xufVxuXG5jbGFzcyBPdXRsZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZm9yRWFjaCgob3V0bGV0TmFtZSkgPT4ge1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZGVwZW5kZW50Q29udGV4dHMuZm9yRWFjaCgoY29udGV4dCkgPT4gY29udGV4dC5yZWZyZXNoKCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxPdXRsZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BTZWxlY3Rvck9ic2VydmVycygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0ID0gdGhpcy5oYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldENvbnRyb2xsZXIgPSBlbGVtZW50Lm1hdGNoZXMoYFske3RoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGV9fj0ke291dGxldE5hbWV9XWApO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHJldHVybiBoYXNPdXRsZXQgJiYgaGFzT3V0bGV0Q29udHJvbGxlciAmJiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcFxuICAgICAgICAgICAgICAgIC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsT3V0bGV0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBvdXRsZXROYW1lIG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgZm9yIChjb25zdCBvdXRsZXQgb2YgdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgdXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IG9ic2VydmVyID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG9ic2VydmVyKSB7XG4gICAgICAgICAgICBvYnNlcnZlci5zZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBzZWxlY3Rvck9ic2VydmVyID0gbmV3IFNlbGVjdG9yT2JzZXJ2ZXIoZG9jdW1lbnQuYm9keSwgc2VsZWN0b3IsIHRoaXMsIHsgb3V0bGV0TmFtZSB9KTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBzZWxlY3Rvck9ic2VydmVyKTtcbiAgICAgICAgc2VsZWN0b3JPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKHRoaXMuc2NvcGUuZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIGF0dHJpYnV0ZU9ic2VydmVyKTtcbiAgICAgICAgYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2VsZWN0b3Iob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZmluZCgob3V0bGV0TmFtZSkgPT4gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSA9PT0gYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZXBlbmRlbmNpZXMoKSB7XG4gICAgICAgIGNvbnN0IGRlcGVuZGVuY2llcyA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5tb2R1bGVzLmZvckVhY2goKG1vZHVsZSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgY29uc3RydWN0b3IgPSBtb2R1bGUuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICAgICAgICAgIG91dGxldHMuZm9yRWFjaCgob3V0bGV0KSA9PiBkZXBlbmRlbmNpZXMuYWRkKG91dGxldCwgbW9kdWxlLmlkZW50aWZpZXIpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXBlbmRlbmNpZXM7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZWZpbml0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldEtleXNGb3JWYWx1ZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0VmFsdWVzRm9yS2V5KHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250ZXh0cygpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSB0aGlzLmRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycztcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLmZpbHRlcigoY29udGV4dCkgPT4gaWRlbnRpZmllcnMuaW5jbHVkZXMoY29udGV4dC5pZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiAhIXRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHx8ICEhdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKS5maW5kKChvdXRsZXQpID0+IG91dGxldC5lbGVtZW50ID09PSBlbGVtZW50KTtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgcm91dGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5yb3V0ZXI7XG4gICAgfVxufVxuXG5jbGFzcyBDb250ZXh0IHtcbiAgICBjb25zdHJ1Y3Rvcihtb2R1bGUsIHNjb3BlKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMuaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLm1vZHVsZSA9IG1vZHVsZTtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXIgPSBuZXcgbW9kdWxlLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvcih0aGlzKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIgPSBuZXcgQmluZGluZ09ic2VydmVyKHRoaXMsIHRoaXMuZGlzcGF0Y2hlcik7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlciA9IG5ldyBWYWx1ZU9ic2VydmVyKHRoaXMsIHRoaXMuY29udHJvbGxlcik7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIgPSBuZXcgVGFyZ2V0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIgPSBuZXcgT3V0bGV0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuaW5pdGlhbGl6ZSgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiaW5pdGlhbGl6ZVwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiaW5pdGlhbGl6aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmNvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImNvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImNvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJkaXNjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJkaXNjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGRpc3BhdGNoZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmRpc3BhdGNoZXI7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgcGFyZW50RWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5wYXJlbnRFbGVtZW50O1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIHRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldENvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICB0YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXREaXNjb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0Q29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0RGlzY29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgaW52b2tlQ29udHJvbGxlck1ldGhvZChtZXRob2ROYW1lLCAuLi5hcmdzKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmNvbnRyb2xsZXI7XG4gICAgICAgIGlmICh0eXBlb2YgY29udHJvbGxlclttZXRob2ROYW1lXSA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0oLi4uYXJncyk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGJsZXNzKGNvbnN0cnVjdG9yKSB7XG4gICAgcmV0dXJuIHNoYWRvdyhjb25zdHJ1Y3RvciwgZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpKTtcbn1cbmZ1bmN0aW9uIHNoYWRvdyhjb25zdHJ1Y3RvciwgcHJvcGVydGllcykge1xuICAgIGNvbnN0IHNoYWRvd0NvbnN0cnVjdG9yID0gZXh0ZW5kKGNvbnN0cnVjdG9yKTtcbiAgICBjb25zdCBzaGFkb3dQcm9wZXJ0aWVzID0gZ2V0U2hhZG93UHJvcGVydGllcyhjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHByb3BlcnRpZXMpO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0aWVzKHNoYWRvd0NvbnN0cnVjdG9yLnByb3RvdHlwZSwgc2hhZG93UHJvcGVydGllcyk7XG4gICAgcmV0dXJuIHNoYWRvd0NvbnN0cnVjdG9yO1xufVxuZnVuY3Rpb24gZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBibGVzc2luZ3MgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJibGVzc2luZ3NcIik7XG4gICAgcmV0dXJuIGJsZXNzaW5ncy5yZWR1Y2UoKGJsZXNzZWRQcm9wZXJ0aWVzLCBibGVzc2luZykgPT4ge1xuICAgICAgICBjb25zdCBwcm9wZXJ0aWVzID0gYmxlc3NpbmcoY29uc3RydWN0b3IpO1xuICAgICAgICBmb3IgKGNvbnN0IGtleSBpbiBwcm9wZXJ0aWVzKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gYmxlc3NlZFByb3BlcnRpZXNba2V5XSB8fCB7fTtcbiAgICAgICAgICAgIGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gPSBPYmplY3QuYXNzaWduKGRlc2NyaXB0b3IsIHByb3BlcnRpZXNba2V5XSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGJsZXNzZWRQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd1Byb3BlcnRpZXMocHJvdG90eXBlLCBwcm9wZXJ0aWVzKSB7XG4gICAgcmV0dXJuIGdldE93bktleXMocHJvcGVydGllcykucmVkdWNlKChzaGFkb3dQcm9wZXJ0aWVzLCBrZXkpID0+IHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSk7XG4gICAgICAgIGlmIChkZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBPYmplY3QuYXNzaWduKHNoYWRvd1Byb3BlcnRpZXMsIHsgW2tleV06IGRlc2NyaXB0b3IgfSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNoYWRvd1Byb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KSB7XG4gICAgY29uc3Qgc2hhZG93aW5nRGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvdG90eXBlLCBrZXkpO1xuICAgIGNvbnN0IHNoYWRvd2VkQnlWYWx1ZSA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IgJiYgXCJ2YWx1ZVwiIGluIHNoYWRvd2luZ0Rlc2NyaXB0b3I7XG4gICAgaWYgKCFzaGFkb3dlZEJ5VmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvcGVydGllcywga2V5KS52YWx1ZTtcbiAgICAgICAgaWYgKHNoYWRvd2luZ0Rlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIGRlc2NyaXB0b3IuZ2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5nZXQgfHwgZGVzY3JpcHRvci5nZXQ7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLnNldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3Iuc2V0IHx8IGRlc2NyaXB0b3Iuc2V0O1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9yO1xuICAgIH1cbn1cbmNvbnN0IGdldE93bktleXMgPSAoKCkgPT4ge1xuICAgIGlmICh0eXBlb2YgT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgcmV0dXJuIChvYmplY3QpID0+IFsuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcyhvYmplY3QpLCAuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzKG9iamVjdCldO1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzO1xuICAgIH1cbn0pKCk7XG5jb25zdCBleHRlbmQgPSAoKCkgPT4ge1xuICAgIGZ1bmN0aW9uIGV4dGVuZFdpdGhSZWZsZWN0KGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGZ1bmN0aW9uIGV4dGVuZGVkKCkge1xuICAgICAgICAgICAgcmV0dXJuIFJlZmxlY3QuY29uc3RydWN0KGNvbnN0cnVjdG9yLCBhcmd1bWVudHMsIG5ldy50YXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGV4dGVuZGVkLnByb3RvdHlwZSA9IE9iamVjdC5jcmVhdGUoY29uc3RydWN0b3IucHJvdG90eXBlLCB7XG4gICAgICAgICAgICBjb25zdHJ1Y3RvcjogeyB2YWx1ZTogZXh0ZW5kZWQgfSxcbiAgICAgICAgfSk7XG4gICAgICAgIFJlZmxlY3Quc2V0UHJvdG90eXBlT2YoZXh0ZW5kZWQsIGNvbnN0cnVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZGVkO1xuICAgIH1cbiAgICBmdW5jdGlvbiB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpIHtcbiAgICAgICAgY29uc3QgYSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHRoaXMuYS5jYWxsKHRoaXMpO1xuICAgICAgICB9O1xuICAgICAgICBjb25zdCBiID0gZXh0ZW5kV2l0aFJlZmxlY3QoYSk7XG4gICAgICAgIGIucHJvdG90eXBlLmEgPSBmdW5jdGlvbiAoKSB7IH07XG4gICAgICAgIHJldHVybiBuZXcgYigpO1xuICAgIH1cbiAgICB0cnkge1xuICAgICAgICB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kV2l0aFJlZmxlY3Q7XG4gICAgfVxuICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICByZXR1cm4gKGNvbnN0cnVjdG9yKSA9PiBjbGFzcyBleHRlbmRlZCBleHRlbmRzIGNvbnN0cnVjdG9yIHtcbiAgICAgICAgfTtcbiAgICB9XG59KSgpO1xuXG5mdW5jdGlvbiBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgIHJldHVybiB7XG4gICAgICAgIGlkZW50aWZpZXI6IGRlZmluaXRpb24uaWRlbnRpZmllcixcbiAgICAgICAgY29udHJvbGxlckNvbnN0cnVjdG9yOiBibGVzcyhkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciksXG4gICAgfTtcbn1cblxuY2xhc3MgTW9kdWxlIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbiwgZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZGVmaW5pdGlvbiA9IGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckNvbnN0cnVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmNvbm5lY3RlZENvbnRleHRzKTtcbiAgICB9XG4gICAgY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5mZXRjaENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuYWRkKGNvbnRleHQpO1xuICAgICAgICBjb250ZXh0LmNvbm5lY3QoKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKGNvbnRleHQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuZGVsZXRlKGNvbnRleHQpO1xuICAgICAgICAgICAgY29udGV4dC5kaXNjb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgbGV0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoIWNvbnRleHQpIHtcbiAgICAgICAgICAgIGNvbnRleHQgPSBuZXcgQ29udGV4dCh0aGlzLCBzY29wZSk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZS5zZXQoc2NvcGUsIGNvbnRleHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBjb250ZXh0O1xuICAgIH1cbn1cblxuY2xhc3MgQ2xhc3NNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgaGFzKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXModGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0QWxsKG5hbWUpWzBdO1xuICAgIH1cbiAgICBnZXRBbGwobmFtZSkge1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IHRoaXMuZGF0YS5nZXQodGhpcy5nZXREYXRhS2V5KG5hbWUpKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gdG9rZW5pemUodG9rZW5TdHJpbmcpO1xuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldERhdGFLZXkobmFtZSkge1xuICAgICAgICByZXR1cm4gYCR7bmFtZX0tY2xhc3NgO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG59XG5cbmNsYXNzIERhdGFNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIHNldChrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgdGhpcy5lbGVtZW50LnNldEF0dHJpYnV0ZShuYW1lLCB2YWx1ZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmdldChrZXkpO1xuICAgIH1cbiAgICBoYXMoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5oYXNBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXkpIHtcbiAgICAgICAgaWYgKHRoaXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5yZW1vdmVBdHRyaWJ1dGUobmFtZSk7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSR7ZGFzaGVyaXplKGtleSl9YDtcbiAgICB9XG59XG5cbmNsYXNzIEd1aWRlIHtcbiAgICBjb25zdHJ1Y3Rvcihsb2dnZXIpIHtcbiAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3QgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGxvZ2dlcjtcbiAgICB9XG4gICAgd2FybihvYmplY3QsIGtleSwgbWVzc2FnZSkge1xuICAgICAgICBsZXQgd2FybmVkS2V5cyA9IHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LmdldChvYmplY3QpO1xuICAgICAgICBpZiAoIXdhcm5lZEtleXMpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMgPSBuZXcgU2V0KCk7XG4gICAgICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5zZXQob2JqZWN0LCB3YXJuZWRLZXlzKTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIXdhcm5lZEtleXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMuYWRkKGtleSk7XG4gICAgICAgICAgICB0aGlzLmxvZ2dlci53YXJuKG1lc3NhZ2UsIG9iamVjdCk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0b2tlbikge1xuICAgIHJldHVybiBgWyR7YXR0cmlidXRlTmFtZX1+PVwiJHt0b2tlbn1cIl1gO1xufVxuXG5jbGFzcyBUYXJnZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyh0YXJnZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQodGFyZ2V0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXQsIHRhcmdldE5hbWUpID0+IHRhcmdldCB8fCB0aGlzLmZpbmRUYXJnZXQodGFyZ2V0TmFtZSkgfHwgdGhpcy5maW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldHMsIHRhcmdldE5hbWUpID0+IFtcbiAgICAgICAgICAgIC4uLnRhcmdldHMsXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgXSwgW10pO1xuICAgIH1cbiAgICBmaW5kVGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBmaW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVwcmVjYXRlKHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpLm1hcCgoZWxlbWVudCkgPT4gdGhpcy5kZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkpO1xuICAgIH1cbiAgICBnZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCB0YXJnZXREZXNjcmlwdG9yID0gYCR7dGhpcy5pZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9YDtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGUsIHRhcmdldERlc2NyaXB0b3IpO1xuICAgIH1cbiAgICBkZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkge1xuICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyIH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZTtcbiAgICAgICAgICAgIGNvbnN0IHJldmlzZWRBdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUoaWRlbnRpZmllcik7XG4gICAgICAgICAgICB0aGlzLmd1aWRlLndhcm4oZWxlbWVudCwgYHRhcmdldDoke3RhcmdldE5hbWV9YCwgYFBsZWFzZSByZXBsYWNlICR7YXR0cmlidXRlTmFtZX09XCIke2lkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1cIiB3aXRoICR7cmV2aXNlZEF0dHJpYnV0ZU5hbWV9PVwiJHt0YXJnZXROYW1lfVwiLiBgICtcbiAgICAgICAgICAgICAgICBgVGhlICR7YXR0cmlidXRlTmFtZX0gYXR0cmlidXRlIGlzIGRlcHJlY2F0ZWQgYW5kIHdpbGwgYmUgcmVtb3ZlZCBpbiBhIGZ1dHVyZSB2ZXJzaW9uIG9mIFN0aW11bHVzLmApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBlbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZ3VpZGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmd1aWRlO1xuICAgIH1cbn1cblxuY2xhc3MgT3V0bGV0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSwgY29udHJvbGxlckVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXJFbGVtZW50ID0gY29udHJvbGxlckVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXMob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKG91dGxldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0LCBvdXRsZXROYW1lKSA9PiBvdXRsZXQgfHwgdGhpcy5maW5kT3V0bGV0KG91dGxldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldHMsIG91dGxldE5hbWUpID0+IFsuLi5vdXRsZXRzLCAuLi50aGlzLmZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpXSwgW10pO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udHJvbGxlckVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBmaW5kT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKVxuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiBzZWxlY3RvciA/IHRoaXMuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSA6IFtdO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKVswXTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpO1xuICAgIH1cbiAgICBtYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyQXR0cmlidXRlID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUodGhpcy5zY29wZS5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgJiYgY29udHJvbGxlckF0dHJpYnV0ZS5zcGxpdChcIiBcIikuaW5jbHVkZXMob3V0bGV0TmFtZSk7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZSB7XG4gICAgY29uc3RydWN0b3Ioc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCBsb2dnZXIpIHtcbiAgICAgICAgdGhpcy50YXJnZXRzID0gbmV3IFRhcmdldFNldCh0aGlzKTtcbiAgICAgICAgdGhpcy5jbGFzc2VzID0gbmV3IENsYXNzTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmRhdGEgPSBuZXcgRGF0YU1hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5jb250YWluc0VsZW1lbnQgPSAoZWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQuY2xvc2VzdCh0aGlzLmNvbnRyb2xsZXJTZWxlY3RvcikgPT09IHRoaXMuZWxlbWVudDtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGlkZW50aWZpZXI7XG4gICAgICAgIHRoaXMuZ3VpZGUgPSBuZXcgR3VpZGUobG9nZ2VyKTtcbiAgICAgICAgdGhpcy5vdXRsZXRzID0gbmV3IE91dGxldFNldCh0aGlzLmRvY3VtZW50U2NvcGUsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gdGhpcy5lbGVtZW50IDogdGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maW5kKHRoaXMuY29udGFpbnNFbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBbXG4gICAgICAgICAgICAuLi4odGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gW3RoaXMuZWxlbWVudF0gOiBbXSksXG4gICAgICAgICAgICAuLi50aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbHRlcih0aGlzLmNvbnRhaW5zRWxlbWVudCksXG4gICAgICAgIF07XG4gICAgfVxuICAgIHF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJTZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgaXNEb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50ID09PSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBkb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5pc0RvY3VtZW50U2NvcGVcbiAgICAgICAgICAgID8gdGhpc1xuICAgICAgICAgICAgOiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgdGhpcy5pZGVudGlmaWVyLCB0aGlzLmd1aWRlLmxvZ2dlcik7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzY2hlbWEsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50LCBjb250ZW50OiBpZGVudGlmaWVyIH0gPSB0b2tlbjtcbiAgICAgICAgcmV0dXJuIHRoaXMucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLmZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgbGV0IHNjb3BlID0gc2NvcGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKCFzY29wZSkge1xuICAgICAgICAgICAgc2NvcGUgPSB0aGlzLmRlbGVnYXRlLmNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIuc2V0KGlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSAodGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpIHx8IDApICsgMTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50KTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVDb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9IHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50KSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQgLSAxKTtcbiAgICAgICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZURpc2Nvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCFzY29wZXNCeUlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LnNldChlbGVtZW50LCBzY29wZXNCeUlkZW50aWZpZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZXNCeUlkZW50aWZpZXI7XG4gICAgfVxufVxuXG5jbGFzcyBSb3V0ZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyID0gbmV3IFNjb3BlT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLnNjaGVtYSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGxvZ2dlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ubG9nZ2VyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBtb2R1bGVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIudmFsdWVzKCkpO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZXMucmVkdWNlKChjb250ZXh0cywgbW9kdWxlKSA9PiBjb250ZXh0cy5jb25jYXQobW9kdWxlLmNvbnRleHRzKSwgW10pO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy51bmxvYWRJZGVudGlmaWVyKGRlZmluaXRpb24uaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IG5ldyBNb2R1bGUodGhpcy5hcHBsaWNhdGlvbiwgZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICBjb25zdCBhZnRlckxvYWQgPSBkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5hZnRlckxvYWQ7XG4gICAgICAgIGlmIChhZnRlckxvYWQpIHtcbiAgICAgICAgICAgIGFmdGVyTG9hZC5jYWxsKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLCBkZWZpbml0aW9uLmlkZW50aWZpZXIsIHRoaXMuYXBwbGljYXRpb24pO1xuICAgICAgICB9XG4gICAgfVxuICAgIHVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICByZXR1cm4gbW9kdWxlLmNvbnRleHRzLmZpbmQoKGNvbnRleHQpID0+IGNvbnRleHQuZWxlbWVudCA9PSBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3BlID0gdGhpcy5zY29wZU9ic2VydmVyLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKHNjb3BlKSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuZWxlbWVudE1hdGNoZWRWYWx1ZShzY29wZS5lbGVtZW50LCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjb25zb2xlLmVycm9yKGBDb3VsZG4ndCBmaW5kIG9yIGNyZWF0ZSBzY29wZSBmb3IgaWRlbnRpZmllcjogXCIke2lkZW50aWZpZXJ9XCIgYW5kIGVsZW1lbnQ6YCwgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgcmV0dXJuIG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgdGhpcy5sb2dnZXIpO1xuICAgIH1cbiAgICBzY29wZUNvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5hZGQoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2NvcGVEaXNjb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZGVsZXRlKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5zZXQobW9kdWxlLmlkZW50aWZpZXIsIG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmRlbGV0ZShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRTY2hlbWEgPSB7XG4gICAgY29udHJvbGxlckF0dHJpYnV0ZTogXCJkYXRhLWNvbnRyb2xsZXJcIixcbiAgICBhY3Rpb25BdHRyaWJ1dGU6IFwiZGF0YS1hY3Rpb25cIixcbiAgICB0YXJnZXRBdHRyaWJ1dGU6IFwiZGF0YS10YXJnZXRcIixcbiAgICB0YXJnZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tdGFyZ2V0YCxcbiAgICBvdXRsZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIsIG91dGxldCkgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS0ke291dGxldH0tb3V0bGV0YCxcbiAgICBrZXlNYXBwaW5nczogT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHsgZW50ZXI6IFwiRW50ZXJcIiwgdGFiOiBcIlRhYlwiLCBlc2M6IFwiRXNjYXBlXCIsIHNwYWNlOiBcIiBcIiwgdXA6IFwiQXJyb3dVcFwiLCBkb3duOiBcIkFycm93RG93blwiLCBsZWZ0OiBcIkFycm93TGVmdFwiLCByaWdodDogXCJBcnJvd1JpZ2h0XCIsIGhvbWU6IFwiSG9tZVwiLCBlbmQ6IFwiRW5kXCIsIHBhZ2VfdXA6IFwiUGFnZVVwXCIsIHBhZ2VfZG93bjogXCJQYWdlRG93blwiIH0sIG9iamVjdEZyb21FbnRyaWVzKFwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXpcIi5zcGxpdChcIlwiKS5tYXAoKGMpID0+IFtjLCBjXSkpKSwgb2JqZWN0RnJvbUVudHJpZXMoXCIwMTIzNDU2Nzg5XCIuc3BsaXQoXCJcIikubWFwKChuKSA9PiBbbiwgbl0pKSksXG59O1xuZnVuY3Rpb24gb2JqZWN0RnJvbUVudHJpZXMoYXJyYXkpIHtcbiAgICByZXR1cm4gYXJyYXkucmVkdWNlKChtZW1vLCBbaywgdl0pID0+IChPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oe30sIG1lbW8pLCB7IFtrXTogdiB9KSksIHt9KTtcbn1cblxuY2xhc3MgQXBwbGljYXRpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQgPSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHNjaGVtYSA9IGRlZmF1bHRTY2hlbWEpIHtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBjb25zb2xlO1xuICAgICAgICB0aGlzLmRlYnVnID0gZmFsc2U7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgICAgICAgICAgIHRoaXMubG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIgPSBuZXcgRGlzcGF0Y2hlcih0aGlzKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIgPSBuZXcgUm91dGVyKHRoaXMpO1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0gT2JqZWN0LmFzc2lnbih7fSwgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKTtcbiAgICB9XG4gICAgc3RhdGljIHN0YXJ0KGVsZW1lbnQsIHNjaGVtYSkge1xuICAgICAgICBjb25zdCBhcHBsaWNhdGlvbiA9IG5ldyB0aGlzKGVsZW1lbnQsIHNjaGVtYSk7XG4gICAgICAgIGFwcGxpY2F0aW9uLnN0YXJ0KCk7XG4gICAgICAgIHJldHVybiBhcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgYXN5bmMgc3RhcnQoKSB7XG4gICAgICAgIGF3YWl0IGRvbVJlYWR5KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0XCIpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BwaW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdG9wKCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcFwiKTtcbiAgICB9XG4gICAgcmVnaXN0ZXIoaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yKSB7XG4gICAgICAgIHRoaXMubG9hZCh7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3RvciB9KTtcbiAgICB9XG4gICAgcmVnaXN0ZXJBY3Rpb25PcHRpb24obmFtZSwgZmlsdGVyKSB7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV0gPSBmaWx0ZXI7XG4gICAgfVxuICAgIGxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBkZWZpbml0aW9ucyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBkZWZpbml0aW9ucy5mb3JFYWNoKChkZWZpbml0aW9uKSA9PiB7XG4gICAgICAgICAgICBpZiAoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3Iuc2hvdWxkTG9hZCkge1xuICAgICAgICAgICAgICAgIHRoaXMucm91dGVyLmxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG4gICAgdW5sb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgaWRlbnRpZmllcnMuZm9yRWFjaCgoaWRlbnRpZmllcikgPT4gdGhpcy5yb3V0ZXIudW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLm1hcCgoY29udGV4dCkgPT4gY29udGV4dC5jb250cm9sbGVyKTtcbiAgICB9XG4gICAgZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMucm91dGVyLmdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGNvbnRleHQgPyBjb250ZXh0LmNvbnRyb2xsZXIgOiBudWxsO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZXJyb3IoYCVzXFxuXFxuJW9cXG5cXG4lb2AsIG1lc3NhZ2UsIGVycm9yLCBkZXRhaWwpO1xuICAgICAgICAoX2EgPSB3aW5kb3cub25lcnJvcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLmNhbGwod2luZG93LCBtZXNzYWdlLCBcIlwiLCAwLCAwLCBlcnJvcik7XG4gICAgfVxuICAgIGxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgYXBwbGljYXRpb246IHRoaXMgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBDb2xsYXBzZWQoYCR7aWRlbnRpZmllcn0gIyR7ZnVuY3Rpb25OYW1lfWApO1xuICAgICAgICB0aGlzLmxvZ2dlci5sb2coXCJkZXRhaWxzOlwiLCBPYmplY3QuYXNzaWduKHt9LCBkZXRhaWwpKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBFbmQoKTtcbiAgICB9XG59XG5mdW5jdGlvbiBkb21SZWFkeSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgICAgaWYgKGRvY3VtZW50LnJlYWR5U3RhdGUgPT0gXCJsb2FkaW5nXCIpIHtcbiAgICAgICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoXCJET01Db250ZW50TG9hZGVkXCIsICgpID0+IHJlc29sdmUoKSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXNvbHZlKCk7XG4gICAgICAgIH1cbiAgICB9KTtcbn1cblxuZnVuY3Rpb24gQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBjbGFzc2VzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiY2xhc3Nlc1wiKTtcbiAgICByZXR1cm4gY2xhc3Nlcy5yZWR1Y2UoKHByb3BlcnRpZXMsIGNsYXNzRGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGNsYXNzRGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oa2V5KSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2tleX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgeyBjbGFzc2VzIH0gPSB0aGlzO1xuICAgICAgICAgICAgICAgIGlmIChjbGFzc2VzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBjbGFzc2VzLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlID0gY2xhc3Nlcy5nZXRBdHRyaWJ1dGVOYW1lKGtleSk7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBhdHRyaWJ1dGUgXCIke2F0dHJpYnV0ZX1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtrZXl9Q2xhc3Nlc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5nZXRBbGwoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGtleSl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuaGFzKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgIHJldHVybiBvdXRsZXRzLnJlZHVjZSgocHJvcGVydGllcywgb3V0bGV0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihvdXRsZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgcmV0dXJuIGNvbnRyb2xsZXIuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xufVxuZnVuY3Rpb24gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICBsZXQgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgIGNvbnRyb2xsZXIuYXBwbGljYXRpb24ucm91dGVyLnByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICBjb25zdCBjYW1lbGl6ZWROYW1lID0gbmFtZXNwYWNlQ2FtZWxpemUobmFtZSk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldHMubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0c1xuICAgICAgICAgICAgICAgICAgICAgICAgLm1hcCgob3V0bGV0RWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc29sZS53YXJuKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgLCBvdXRsZXRFbGVtZW50KTtcbiAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5maWx0ZXIoKGNvbnRyb2xsZXIpID0+IGNvbnRyb2xsZXIpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRFbGVtZW50O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoY2FtZWxpemVkTmFtZSl9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdGFyZ2V0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcInRhcmdldHNcIik7XG4gICAgcmV0dXJuIHRhcmdldHMucmVkdWNlKChwcm9wZXJ0aWVzLCB0YXJnZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKHRhcmdldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB0YXJnZXQgPSB0aGlzLnRhcmdldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAodGFyZ2V0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0YXJnZXQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgdGFyZ2V0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIgY29udHJvbGxlcmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtuYW1lfVRhcmdldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdmFsdWVEZWZpbml0aW9uUGFpcnMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgXCJ2YWx1ZXNcIik7XG4gICAgY29uc3QgcHJvcGVydHlEZXNjcmlwdG9yTWFwID0ge1xuICAgICAgICB2YWx1ZURlc2NyaXB0b3JNYXA6IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChyZXN1bHQsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgdmFsdWVEZXNjcmlwdG9yID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh2YWx1ZURlc2NyaXB0b3Iua2V5KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocmVzdWx0LCB7IFthdHRyaWJ1dGVOYW1lXTogdmFsdWVEZXNjcmlwdG9yIH0pO1xuICAgICAgICAgICAgICAgIH0sIHt9KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbiAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChwcm9wZXJ0aWVzLCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIpKTtcbiAgICB9LCBwcm9wZXJ0eURlc2NyaXB0b3JNYXApO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcikge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcik7XG4gICAgY29uc3QgeyBrZXksIG5hbWUsIHJlYWRlcjogcmVhZCwgd3JpdGVyOiB3cml0ZSB9ID0gZGVmaW5pdGlvbjtcbiAgICByZXR1cm4ge1xuICAgICAgICBbbmFtZV06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGF0YS5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHJlYWQodmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRlZmluaXRpb24uZGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZXQodmFsdWUpIHtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuZGVsZXRlKGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuc2V0KGtleSwgd3JpdGUodmFsdWUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1gXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKGtleSkgfHwgZGVmaW5pdGlvbi5oYXNDdXN0b21EZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIoW3Rva2VuLCB0eXBlRGVmaW5pdGlvbl0sIGNvbnRyb2xsZXIpIHtcbiAgICByZXR1cm4gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbih7XG4gICAgICAgIGNvbnRyb2xsZXIsXG4gICAgICAgIHRva2VuLFxuICAgICAgICB0eXBlRGVmaW5pdGlvbixcbiAgICB9KTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQoY29uc3RhbnQpIHtcbiAgICBzd2l0Y2ggKGNvbnN0YW50KSB7XG4gICAgICAgIGNhc2UgQXJyYXk6XG4gICAgICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgICAgICBjYXNlIEJvb2xlYW46XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgTnVtYmVyOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgT2JqZWN0OlxuICAgICAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG4gICAgICAgIGNhc2UgU3RyaW5nOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGRlZmF1bHRWYWx1ZSkge1xuICAgIHN3aXRjaCAodHlwZW9mIGRlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjYXNlIFwiYm9vbGVhblwiOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIFwibnVtYmVyXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBcInN0cmluZ1wiOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxuICAgIGlmIChBcnJheS5pc0FycmF5KGRlZmF1bHRWYWx1ZSkpXG4gICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgaWYgKE9iamVjdC5wcm90b3R5cGUudG9TdHJpbmcuY2FsbChkZWZhdWx0VmFsdWUpID09PSBcIltvYmplY3QgT2JqZWN0XVwiKVxuICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlT2JqZWN0KHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0IH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGNvbnN0IGZ1bGxPYmplY3QgPSBoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seVR5cGUgPSBoYXNUeXBlICYmICFoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlEZWZhdWx0ID0gIWhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdChwYXlsb2FkLnR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgaWYgKG9ubHlUeXBlKVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG4gICAgaWYgKG9ubHlEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21EZWZhdWx0VmFsdWU7XG4gICAgaWYgKHR5cGVGcm9tT2JqZWN0ICE9PSB0eXBlRnJvbURlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0b2tlbn1gIDogdG9rZW47XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHNwZWNpZmllZCBkZWZhdWx0IHZhbHVlIGZvciB0aGUgU3RpbXVsdXMgVmFsdWUgXCIke3Byb3BlcnR5UGF0aH1cIiBtdXN0IG1hdGNoIHRoZSBkZWZpbmVkIHR5cGUgXCIke3R5cGVGcm9tT2JqZWN0fVwiLiBUaGUgcHJvdmlkZWQgZGVmYXVsdCB2YWx1ZSBvZiBcIiR7dHlwZU9iamVjdC5kZWZhdWx0fVwiIGlzIG9mIHR5cGUgXCIke3R5cGVGcm9tRGVmYXVsdFZhbHVlfVwiLmApO1xuICAgIH1cbiAgICBpZiAoZnVsbE9iamVjdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0geyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdDogdHlwZURlZmluaXRpb24gfTtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlT2JqZWN0KHR5cGVPYmplY3QpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlRnJvbUNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZSA9IHR5cGVGcm9tT2JqZWN0IHx8IHR5cGVGcm9tRGVmYXVsdFZhbHVlIHx8IHR5cGVGcm9tQ29uc3RhbnQ7XG4gICAgaWYgKHR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlO1xuICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3R5cGVEZWZpbml0aW9ufWAgOiB0b2tlbjtcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFVua25vd24gdmFsdWUgdHlwZSBcIiR7cHJvcGVydHlQYXRofVwiIGZvciBcIiR7dG9rZW59XCIgdmFsdWVgKTtcbn1cbmZ1bmN0aW9uIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pIHtcbiAgICBjb25zdCBjb25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGlmIChjb25zdGFudClcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRdO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJkZWZhdWx0XCIpO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJ0eXBlXCIpO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB0eXBlRGVmaW5pdGlvbjtcbiAgICBpZiAoaGFzRGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVPYmplY3QuZGVmYXVsdDtcbiAgICBpZiAoaGFzVHlwZSkge1xuICAgICAgICBjb25zdCB7IHR5cGUgfSA9IHR5cGVPYmplY3Q7XG4gICAgICAgIGNvbnN0IGNvbnN0YW50RnJvbVR5cGUgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGUpO1xuICAgICAgICBpZiAoY29uc3RhbnRGcm9tVHlwZSlcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50RnJvbVR5cGVdO1xuICAgIH1cbiAgICByZXR1cm4gdHlwZURlZmluaXRpb247XG59XG5mdW5jdGlvbiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBrZXkgPSBgJHtkYXNoZXJpemUodG9rZW4pfS12YWx1ZWA7XG4gICAgY29uc3QgdHlwZSA9IHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKTtcbiAgICByZXR1cm4ge1xuICAgICAgICB0eXBlLFxuICAgICAgICBrZXksXG4gICAgICAgIG5hbWU6IGNhbWVsaXplKGtleSksXG4gICAgICAgIGdldCBkZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbik7XG4gICAgICAgIH0sXG4gICAgICAgIGdldCBoYXNDdXN0b21EZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKSAhPT0gdW5kZWZpbmVkO1xuICAgICAgICB9LFxuICAgICAgICByZWFkZXI6IHJlYWRlcnNbdHlwZV0sXG4gICAgICAgIHdyaXRlcjogd3JpdGVyc1t0eXBlXSB8fCB3cml0ZXJzLmRlZmF1bHQsXG4gICAgfTtcbn1cbmNvbnN0IGRlZmF1bHRWYWx1ZXNCeVR5cGUgPSB7XG4gICAgZ2V0IGFycmF5KCkge1xuICAgICAgICByZXR1cm4gW107XG4gICAgfSxcbiAgICBib29sZWFuOiBmYWxzZSxcbiAgICBudW1iZXI6IDAsXG4gICAgZ2V0IG9iamVjdCgpIHtcbiAgICAgICAgcmV0dXJuIHt9O1xuICAgIH0sXG4gICAgc3RyaW5nOiBcIlwiLFxufTtcbmNvbnN0IHJlYWRlcnMgPSB7XG4gICAgYXJyYXkodmFsdWUpIHtcbiAgICAgICAgY29uc3QgYXJyYXkgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KGFycmF5KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcImFycmF5XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQoYXJyYXkpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFycmF5O1xuICAgIH0sXG4gICAgYm9vbGVhbih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gISh2YWx1ZSA9PSBcIjBcIiB8fCBTdHJpbmcodmFsdWUpLnRvTG93ZXJDYXNlKCkgPT0gXCJmYWxzZVwiKTtcbiAgICB9LFxuICAgIG51bWJlcih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gTnVtYmVyKHZhbHVlLnJlcGxhY2UoL18vZywgXCJcIikpO1xuICAgIH0sXG4gICAgb2JqZWN0KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG9iamVjdCA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAob2JqZWN0ID09PSBudWxsIHx8IHR5cGVvZiBvYmplY3QgIT0gXCJvYmplY3RcIiB8fCBBcnJheS5pc0FycmF5KG9iamVjdCkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJvYmplY3RcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChvYmplY3QpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIG9iamVjdDtcbiAgICB9LFxuICAgIHN0cmluZyh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfSxcbn07XG5jb25zdCB3cml0ZXJzID0ge1xuICAgIGRlZmF1bHQ6IHdyaXRlU3RyaW5nLFxuICAgIGFycmF5OiB3cml0ZUpTT04sXG4gICAgb2JqZWN0OiB3cml0ZUpTT04sXG59O1xuZnVuY3Rpb24gd3JpdGVKU09OKHZhbHVlKSB7XG4gICAgcmV0dXJuIEpTT04uc3RyaW5naWZ5KHZhbHVlKTtcbn1cbmZ1bmN0aW9uIHdyaXRlU3RyaW5nKHZhbHVlKSB7XG4gICAgcmV0dXJuIGAke3ZhbHVlfWA7XG59XG5cbmNsYXNzIENvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICB9XG4gICAgc3RhdGljIGdldCBzaG91bGRMb2FkKCkge1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9XG4gICAgc3RhdGljIGFmdGVyTG9hZChfaWRlbnRpZmllciwgX2FwcGxpY2F0aW9uKSB7XG4gICAgICAgIHJldHVybjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHRhcmdldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnRhcmdldHM7XG4gICAgfVxuICAgIGdldCBvdXRsZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzO1xuICAgIH1cbiAgICBnZXQgY2xhc3NlcygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY2xhc3NlcztcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxuICAgIGluaXRpYWxpemUoKSB7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc3BhdGNoKGV2ZW50TmFtZSwgeyB0YXJnZXQgPSB0aGlzLmVsZW1lbnQsIGRldGFpbCA9IHt9LCBwcmVmaXggPSB0aGlzLmlkZW50aWZpZXIsIGJ1YmJsZXMgPSB0cnVlLCBjYW5jZWxhYmxlID0gdHJ1ZSwgfSA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHR5cGUgPSBwcmVmaXggPyBgJHtwcmVmaXh9OiR7ZXZlbnROYW1lfWAgOiBldmVudE5hbWU7XG4gICAgICAgIGNvbnN0IGV2ZW50ID0gbmV3IEN1c3RvbUV2ZW50KHR5cGUsIHsgZGV0YWlsLCBidWJibGVzLCBjYW5jZWxhYmxlIH0pO1xuICAgICAgICB0YXJnZXQuZGlzcGF0Y2hFdmVudChldmVudCk7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG59XG5Db250cm9sbGVyLmJsZXNzaW5ncyA9IFtcbiAgICBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyxcbiAgICBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nLFxuXTtcbkNvbnRyb2xsZXIudGFyZ2V0cyA9IFtdO1xuQ29udHJvbGxlci5vdXRsZXRzID0gW107XG5Db250cm9sbGVyLnZhbHVlcyA9IHt9O1xuXG5leHBvcnQgeyBBcHBsaWNhdGlvbiwgQXR0cmlidXRlT2JzZXJ2ZXIsIENvbnRleHQsIENvbnRyb2xsZXIsIEVsZW1lbnRPYnNlcnZlciwgSW5kZXhlZE11bHRpbWFwLCBNdWx0aW1hcCwgU2VsZWN0b3JPYnNlcnZlciwgU3RyaW5nTWFwT2JzZXJ2ZXIsIFRva2VuTGlzdE9ic2VydmVyLCBWYWx1ZUxpc3RPYnNlcnZlciwgYWRkLCBkZWZhdWx0U2NoZW1hLCBkZWwsIGZldGNoLCBwcnVuZSB9O1xuIiwgIi8qKlxuICogTW9sbGllIE1vZHVsZSBcdTIwMTQgc2hhcmVkIGRlYnVnIGxvZ2dlciB1dGlsaXR5LlxuICpcbiAqIE1pcnJvcnMgU3RyaXBlJ3MgYHJlc291cmNlcy9idWlsZC9qcy9kZWJ1Zy5qc2AgKFBoYXNlIDUgbGVzc29uKTogcHJvZHVjdGlvbiBlc2J1aWxkIHN0cmlwc1xuICogbGl0ZXJhbCBgY29uc29sZS5sb2coLi4uKWAgY2FsbHMgdmlhIHRoZSBgcHVyZWAgb3B0aW9uLCBidXQgaW50ZW50aW9uYWwgZGlhZ25vc3RpY3Mgc2hvdWxkXG4gKiBzdGlsbCBiZSByZWFjaGFibGUgYmVoaW5kIGEgcnVudGltZSBmbGFnIHdpdGhvdXQgYSByZWRlcGxveS4gUm91dGluZyB0aGVtIHRocm91Z2ggYW4gYWxpYXNlZFxuICogYGNvbnNvbGVSZWYubG9nKC4uLilgIGNhbGwgKG5vdCB0aGUgbGl0ZXJhbCBgY29uc29sZS5sb2coLi4uKWAgdGV4dCkgbWVhbnMgZXNidWlsZCdzIGBwdXJlYFxuICogcGFzcyBjYW5ub3Qgc3RhdGljYWxseSBtYXRjaCBhbmQgc3RyaXAgaXQsIHdoaWxlIHRoZSBgaXNFbmFibGVkKClgIGd1YXJkIHN0aWxsIHNpbGVuY2VzIGl0IGJ5XG4gKiBkZWZhdWx0IGluIHByb2R1Y3Rpb24uIGBjb25zb2xlLmVycm9yKC4uLilgIGlzIG5ldmVyIHdyYXBwZWQgXHUyMDE0IGdlbnVpbmUgZmFpbHVyZXMgYWx3YXlzIGxvZy5cbiAqXG4gKiBAcGFyYW0geygpID0+IGJvb2xlYW59IGlzRW5hYmxlZCAtIFJldHVybnMgdHJ1ZSB3aGVuIGRlYnVnIG91dHB1dCBpcyB3YW50ZWQuXG4gKiBAcmV0dXJucyB7KC4uLmFyZ3M6IHVua25vd25bXSkgPT4gdm9pZH1cbiAqL1xuY29uc3QgY29uc29sZVJlZiA9IGdsb2JhbFRoaXMuY29uc29sZVxuXG5leHBvcnQgZnVuY3Rpb24gY3JlYXRlRGVidWdMb2dnZXIoaXNFbmFibGVkKSB7XG4gIHJldHVybiBmdW5jdGlvbiBkZWJ1ZyguLi5hcmdzKSB7XG4gICAgaWYgKCFpc0VuYWJsZWQoKSkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIGNvbnNvbGVSZWYubG9nKC4uLmFyZ3MpXG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSAnQGhvdHdpcmVkL3N0aW11bHVzJ1xuaW1wb3J0IHsgY3JlYXRlRGVidWdMb2dnZXIgfSBmcm9tICcuLi9kZWJ1Zy5qcydcblxuLyoqXG4gKiBNb2xsaWUgY2hlY2tvdXQgbWV0aG9kIHNlbGVjdG9yIChTcHJpbnQgNyBTdG9yeSA1KS5cbiAqXG4gKiBVbmxpa2UgU3RyaXBlJ3Mgb24tcGFnZSBQYXltZW50IEVsZW1lbnQsIE1vbGxpZSBoYXMgbm8gZW1iZWRkZWQgY2FyZCB3aWRnZXQ6IHRoZSBjdXN0b21lciBwaWNrc1xuICogYSBzcGVjaWZpYyBNb2xsaWUgbWV0aG9kIChpREVBTCwgY3JlZGl0IGNhcmQsIFx1MjAyNikgaGVyZSwgYW5kIHRoZSBhY3R1YWwgcmVkaXJlY3QgdG8gTW9sbGllJ3NcbiAqIGhvc3RlZCBjaGVja291dCBoYXBwZW5zIHRocm91Z2ggYSBjbGFzc2ljIHNlcnZlci1zaWRlIEhUVFAgZmxvdyBcdTIwMTQgc3VibWl0dGluZyB0aGUgc3RhbmRhcmRcbiAqIHBheW1lbnQtc2VsZWN0aW9uIGZvcm0gY2FsbHMgYE1vbGxpZVBheW1lbnRDb250cm9sbGVyOjpleGVjdXRlKClgIChleHRlbmRzIE9YSUQncyBjb3JlIHBheW1lbnRcbiAqIHN0ZXApLCB3aGljaCBjcmVhdGVzIHRoZSBNb2xsaWUgcGF5bWVudCBhbmQgaXNzdWVzIGEgYExvY2F0aW9uYCByZWRpcmVjdCBkaXJlY3RseS4gVGhlcmUgaXMgbm9cbiAqIGZldGNoL0pTT04gcm91bmQtdHJpcCB0byBpbnRlcmNlcHQgb24gdGhpcyBwYWdlLCBzbyB0aGlzIGNvbnRyb2xsZXIncyBqb2IgaXMgc2VsZWN0aW9uIFVYIG9ubHk6XG4gKiBoaWdobGlnaHRpbmcgdGhlIGNob3NlbiBtZXRob2QgYW5kIGd1YXJkaW5nIHRoZSBcIkNvbnRpbnVlXCIgYnV0dG9uIGFnYWluc3QgYSBkb3VibGUgc3VibWl0IHdoaWxlXG4gKiB0aGUgYnJvd3NlciBpcyBtaWQtbmF2aWdhdGlvbi5cbiAqXG4gKiBVc2FnZSBpbiBUd2lnIChzZWUgYHZpZXdzL3R3aWcvZXh0ZW5zaW9ucy90aGVtZXMvZGVmYXVsdC9wYWdlL2NoZWNrb3V0L3BheW1lbnQuaHRtbC50d2lnYCBhbmRcbiAqIGB2aWV3cy90d2lnL2Zyb250ZW5kL21vbGxpZV9tZXRob2RzLmh0bWwudHdpZ2ApOlxuICogICA8ZGl2IGRhdGEtY29udHJvbGxlcj1cIm1vbGxpZS1jaGVja291dFwiIGRhdGEtbW9sbGllLWNoZWNrb3V0LWRlYnVnLXZhbHVlPVwiZmFsc2VcIj5cbiAqICAgICA8bGFiZWwgZGF0YS1tb2xsaWUtY2hlY2tvdXQtdGFyZ2V0PVwibWV0aG9kXCI+XG4gKiAgICAgICA8aW5wdXQgdHlwZT1cInJhZGlvXCIgbmFtZT1cIm1vbGxpZV9tZXRob2RcIiBkYXRhLWFjdGlvbj1cImNoYW5nZS0+bW9sbGllLWNoZWNrb3V0I21ldGhvZFNlbGVjdGVkXCI+XG4gKiAgICAgPC9sYWJlbD5cbiAqICAgPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gWydtZXRob2QnXVxuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIGRlYnVnOiB7IHR5cGU6IEJvb2xlYW4sIGRlZmF1bHQ6IGZhbHNlIH0sXG4gIH1cblxuICBjb25uZWN0KCkge1xuICAgIHRoaXMuX2RlYnVnID0gY3JlYXRlRGVidWdMb2dnZXIoKCkgPT4gdGhpcy5kZWJ1Z1ZhbHVlKVxuICAgIHRoaXMuX2RlYnVnKCdNb2xsaWUgY2hlY2tvdXQgY29udHJvbGxlciBjb25uZWN0ZWQnLCB7IG1ldGhvZENvdW50OiB0aGlzLm1ldGhvZFRhcmdldHMubGVuZ3RoIH0pXG5cbiAgICB0aGlzLmhpZ2hsaWdodFNlbGVjdGVkKClcblxuICAgIHRoaXMuX2Zvcm0gPSB0aGlzLmVsZW1lbnQuY2xvc2VzdCgnZm9ybScpXG4gICAgdGhpcy5fb25TdWJtaXQgPSAoKSA9PiB0aGlzLmRpc2FibGVDb250aW51ZUJ1dHRvbigpXG4gICAgaWYgKHRoaXMuX2Zvcm0pIHtcbiAgICAgIHRoaXMuX2Zvcm0uYWRkRXZlbnRMaXN0ZW5lcignc3VibWl0JywgdGhpcy5fb25TdWJtaXQpXG4gICAgfVxuICB9XG5cbiAgZGlzY29ubmVjdCgpIHtcbiAgICBpZiAodGhpcy5fZm9ybSkge1xuICAgICAgdGhpcy5fZm9ybS5yZW1vdmVFdmVudExpc3RlbmVyKCdzdWJtaXQnLCB0aGlzLl9vblN1Ym1pdClcbiAgICB9XG4gIH1cblxuICBtZXRob2RTZWxlY3RlZChldmVudCkge1xuICAgIHRoaXMuX2RlYnVnKCdNb2xsaWUgbWV0aG9kIHNlbGVjdGVkJywgZXZlbnQudGFyZ2V0LnZhbHVlKVxuICAgIHRoaXMuaGlnaGxpZ2h0U2VsZWN0ZWQoKVxuICB9XG5cbiAgaGlnaGxpZ2h0U2VsZWN0ZWQoKSB7XG4gICAgdGhpcy5tZXRob2RUYXJnZXRzLmZvckVhY2goKGxhYmVsKSA9PiB7XG4gICAgICBjb25zdCBpbnB1dCA9IGxhYmVsLnF1ZXJ5U2VsZWN0b3IoJ2lucHV0W3R5cGU9XCJyYWRpb1wiXScpXG4gICAgICBsYWJlbC5jbGFzc0xpc3QudG9nZ2xlKCdzZWxlY3RlZCcsIEJvb2xlYW4oaW5wdXQgJiYgaW5wdXQuY2hlY2tlZCkpXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBHdWFyZHMgYWdhaW5zdCBhIGRvdWJsZS1jbGljayB3aGlsZSB0aGUgYnJvd3NlciBuYXZpZ2F0ZXMgYXdheSBhZnRlciB0aGUgY2xhc3NpYyBmb3JtIFBPU1RcbiAgICogKHRoZXJlIGlzIG5vIEFKQVggcmVzcG9uc2UgdG8gcmUtZW5hYmxlIHRoZSBidXR0b24gb24gXHUyMDE0IHRoZSBwYWdlIGlzIGFib3V0IHRvIHVubG9hZCkuXG4gICAqL1xuICBkaXNhYmxlQ29udGludWVCdXR0b24oKSB7XG4gICAgY29uc3QgYnV0dG9uID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignLmNvbC1sZy01IGJ1dHRvbltvbmNsaWNrKj1cInJlcXVlc3RTdWJtaXRcIl0nKVxuICAgIGlmICghYnV0dG9uKSB7XG4gICAgICByZXR1cm5cbiAgICB9XG4gICAgYnV0dG9uLmRpc2FibGVkID0gdHJ1ZVxuICAgIGJ1dHRvbi5jbGFzc0xpc3QuYWRkKCdpcy1sb2FkaW5nJylcbiAgfVxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tICdAaG90d2lyZWQvc3RpbXVsdXMnXG5pbXBvcnQgeyBjcmVhdGVEZWJ1Z0xvZ2dlciB9IGZyb20gJy4uL2RlYnVnLmpzJ1xuXG4vKipcbiAqIElGUkFNRS0wNCBcdTIwMTQgTW9sbGllIENvbXBvbmVudHMgaW5saW5lIGNhcmQgZW50cnkuXG4gKlxuICogTW9sbGllJ3MgaG9zdGVkIGNoZWNrb3V0IHBhZ2UgY2Fubm90IGJlIGZyYW1lZCAoWC1GcmFtZS1PcHRpb25zOiBERU5ZKSwgYnV0IE1vbGxpZSAqQ29tcG9uZW50cypcbiAqIFx1MjAxNCBjYXJkIGZpZWxkcyBzZXJ2ZWQgZnJvbSBqcy5tb2xsaWUuY29tIFx1MjAxNCBhcmUgZGVzaWduZWQgdG8gYmUgZW1iZWRkZWQuIFRoaXMgY29udHJvbGxlciBtb3VudHNcbiAqIHRob3NlIGZpZWxkcyBvbiB0aGUgb3JkZXIgcGFnZSwgYW5kIG9uIFwiUGxhY2Ugb3JkZXJcIiB0b2tlbml6ZXMgdGhlIGNhcmRcbiAqIChgbW9sbGllLmNyZWF0ZVRva2VuKClgKSBhbmQgaGFuZHMgdGhlIHNpbmdsZS11c2UgdG9rZW4gdG8gdGhlIHNlcnZlciB2aWEgYSBoaWRkZW4gZmllbGRcbiAqIChgbW9sbGllQ2FyZFRva2VuYCk7IHRoZSBzZXJ2ZXIgdGhlbiBjcmVhdGVzIGEgYGNyZWRpdGNhcmRgIHBheW1lbnQgd2l0aCB0aGF0IHRva2VuLiBDYXJkIGRhdGFcbiAqIG5ldmVyIHRvdWNoZXMgdGhlIHNob3AgRE9NIFx1MjAxNCBpdCBsaXZlcyBpbnNpZGUgTW9sbGllJ3MgaWZyYW1lcy5cbiAqXG4gKiBUaGUgY3VzdG9tZXIgcGlja3MgYSBzcGVjaWZpYyBNb2xsaWUgbWV0aG9kIGZyb20gYSByYWRpbyBsaXN0IChuYW1lPVwibW9sbGllTWV0aG9kXCIpLiBXaGVuIHRoZVxuICogY2hvc2VuIG1ldGhvZCBpcyBcImNyZWRpdGNhcmRcIiB0aGlzIGNvbnRyb2xsZXIgbW91bnRzIHRoZSBpbmxpbmUgY2FyZCBmaWVsZHMgYW5kIHRva2VuaXplcyBvblxuICogc3VibWl0OyBmb3IgYW55IG90aGVyIG1ldGhvZCBpdCBzdGF5cyBvdXQgb2YgdGhlIHdheSBhbmQgdGhlIG9yZGVyIGZvcm0gc3VibWl0cyB3aXRoIHRoZSBjaG9zZW5cbiAqIG1ldGhvZCAobW9sbGllTWV0aG9kIHJhZGlvIGlzIGZvcm0tYXNzb2NpYXRlZCksIHJlZGlyZWN0aW5nIHRvIE1vbGxpZSdzIGhvc3RlZCBwYWdlIGZvciBpdC5cbiAqXG4gKiBVc2FnZSAoc2VlIHBhZ2UvY2hlY2tvdXQvb3JkZXIuaHRtbC50d2lnIE1vbGxpZSBicmFuY2gpOlxuICogICA8ZGl2IGRhdGEtY29udHJvbGxlcj1cIm1vbGxpZS1jb21wb25lbnRzXCIgZGF0YS1tb2xsaWUtY29tcG9uZW50cy1wcm9maWxlLWlkLXZhbHVlPVwicGZsX1x1MjAyNlwiIFx1MjAyNj5cbiAqICAgICA8aW5wdXQgdHlwZT1cInJhZGlvXCIgbmFtZT1cIm1vbGxpZU1ldGhvZFwiIHZhbHVlPVwiY3JlZGl0Y2FyZFwiIGZvcm09XCJvcmRlckNvbmZpcm1BZ2JCb3R0b21cIlxuICogICAgICAgICAgICBkYXRhLWFjdGlvbj1cImNoYW5nZS0+bW9sbGllLWNvbXBvbmVudHMjZmxvd0NoYW5nZWRcIj5cbiAqICAgICA8ZGl2IGRhdGEtbW9sbGllLWNvbXBvbmVudHMtdGFyZ2V0PVwiZmllbGRzXCI+IFx1MjAyNiBjYXJkIGZpZWxkIG1vdW50IHBvaW50cyBcdTIwMjYgPC9kaXY+XG4gKiAgICAgPGlucHV0IHR5cGU9XCJoaWRkZW5cIiBuYW1lPVwibW9sbGllQ2FyZFRva2VuXCIgZGF0YS1tb2xsaWUtY29tcG9uZW50cy10YXJnZXQ9XCJ0b2tlblwiPlxuICogICA8L2Rpdj5cbiAqL1xuY29uc3QgTU9MTElFX0pTID0gJ2h0dHBzOi8vanMubW9sbGllLmNvbS92MS9tb2xsaWUuanMnXG5cbmNvbnN0IEZJRUxEUyA9IFsnY2FyZE51bWJlcicsICdjYXJkSG9sZGVyJywgJ2V4cGlyeURhdGUnLCAndmVyaWZpY2F0aW9uQ29kZSddXG5cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gWydjYXJkTnVtYmVyJywgJ2NhcmRIb2xkZXInLCAnZXhwaXJ5RGF0ZScsICd2ZXJpZmljYXRpb25Db2RlJywgJ2Vycm9yJywgJ3Rva2VuJywgJ2ZpZWxkcyddXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHJvZmlsZUlkOiBTdHJpbmcsXG4gICAgdGVzdG1vZGU6IHsgdHlwZTogQm9vbGVhbiwgZGVmYXVsdDogZmFsc2UgfSxcbiAgICBsb2NhbGU6IHsgdHlwZTogU3RyaW5nLCBkZWZhdWx0OiAnZW5fVVMnIH0sXG4gICAgLy8gSWQgb2YgdGhlIGNvcmUgb3JkZXIgZm9ybSB0aGUgXCJPcmRlciBub3dcIiBidXR0b24gc3VibWl0cyAoaW5kZXgucGhwP2NsPW9yZGVyJmZuYz1leGVjdXRlKS5cbiAgICBmb3JtSWQ6IHsgdHlwZTogU3RyaW5nLCBkZWZhdWx0OiAnb3JkZXJDb25maXJtQWdiQm90dG9tJyB9LFxuICAgIGRlYnVnOiB7IHR5cGU6IEJvb2xlYW4sIGRlZmF1bHQ6IGZhbHNlIH0sXG4gIH1cblxuICBjb25uZWN0KCkge1xuICAgIHRoaXMuX2RlYnVnID0gY3JlYXRlRGVidWdMb2dnZXIoKCkgPT4gdGhpcy5kZWJ1Z1ZhbHVlKVxuICAgIHRoaXMuX2NvbXBvbmVudHMgPSB7fVxuICAgIHRoaXMuX21vdW50aW5nID0gbnVsbFxuICAgIHRoaXMuX2luRmxpZ2h0ID0gZmFsc2VcbiAgICB0aGlzLl9vcmRlckJ1dHRvbiA9IG51bGxcbiAgICAvLyBNT0wtMTg6IGJyb3dzZXIgYmFjayBmcm9tIE1vbGxpZSByZXN0b3JlcyB0aGlzIHBhZ2UgZnJvbSB0aGUgYmZjYWNoZSB3aXRoIGl0cyBKUyBzdGF0ZVxuICAgIC8vIGludGFjdCAtIHRoZSBvcmRlciBidXR0b24gbXVzdCB1bmxvY2sgYWdhaW4gb3IgdGhlIHNob3BwZXIgY2Fubm90IG9yZGVyIGF0IGFsbC5cbiAgICB0aGlzLl9vblBhZ2VTaG93ID0gKGV2ZW50KSA9PiB7XG4gICAgICBpZiAoZXZlbnQucGVyc2lzdGVkKSB7XG4gICAgICAgIHRoaXMuX3JlbGVhc2VPcmRlckJ1dHRvbigpXG4gICAgICB9XG4gICAgfVxuICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIHRoaXMuX29uUGFnZVNob3cpXG4gICAgdGhpcy5fY2FyZE1vZGUgPSB0aGlzLl9yZWFkQ2FyZE1vZGUoKVxuICAgIHRoaXMuX3N5bmNNb2RlKClcbiAgICB0aGlzLl9kZWJ1ZygnTW9sbGllIENvbXBvbmVudHMgY29udHJvbGxlciBjb25uZWN0ZWQnLCB7IGNhcmRNb2RlOiB0aGlzLl9jYXJkTW9kZSB9KVxuICB9XG5cbiAgLyoqXG4gICAqIFRha2UgdGhlIG1vdW50ZWQgY29tcG9uZW50cyBkb3duIHdpdGggdGhlIGNvbnRyb2xsZXIuXG4gICAqXG4gICAqIFdpdGhvdXQgdGhpcywgYSBob3N0IHRoYXQgaXMgcmVtb3ZlZCBvciByZXBsYWNlZCAoT1BDIHN3YXBzIHRoZSBjaGVja291dFxuICAgKiBmb290ZXIncyBjb250ZW50IG9uIGV2ZXJ5IHBheW1lbnQtbWV0aG9kIGNoYW5nZSkgbGVhdmVzIGl0cyBjb21wb25lbnRzXG4gICAqIHJlZ2lzdGVyZWQgaW5zaWRlIE1vbGxpZSdzIGxpYnJhcnkgd2hpbGUgdGhlaXIgRE9NIGlzIGdvbmUuIFRoZSBsaWJyYXJ5IHRoZW5cbiAgICogZGVyZWZlcmVuY2VzIHRoZW0gb24gdGhlIG5leHQgZm9jdXMsIGJsdXIgb3Iga2V5c3Ryb2tlIFx1MjAxNFxuICAgKiBcIkNhbm5vdCByZWFkIHByb3BlcnRpZXMgb2YgdW5kZWZpbmVkIChyZWFkaW5nICdpc0xvYWRlZCcgLyAnc2V0TGFiZWwnIC9cbiAgICogJ3RvdWNoZWQnIC8gJ2ZvY3VzSW5wdXQnKVwiIFx1MjAxNCBhbmQgdGhlIGNhcmQgZmllbGRzIGNhbm5vdCBiZSBmaWxsZWQgaW4gYXQgYWxsLlxuICAgKi9cbiAgZGlzY29ubmVjdCgpIHtcbiAgICB3aW5kb3cucmVtb3ZlRXZlbnRMaXN0ZW5lcigncGFnZXNob3cnLCB0aGlzLl9vblBhZ2VTaG93KVxuICAgIGZvciAoY29uc3QgW25hbWUsIGNvbXBvbmVudF0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5fY29tcG9uZW50cyB8fCB7fSkpIHtcbiAgICAgIGlmIChjb21wb25lbnQgJiYgdHlwZW9mIGNvbXBvbmVudC51bm1vdW50ID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgY29tcG9uZW50LnVubW91bnQoKVxuICAgICAgICB9IGNhdGNoIChlKSB7XG4gICAgICAgICAgLyogYWxyZWFkeSBnb25lICovXG4gICAgICAgIH1cbiAgICAgIH1cbiAgICAgIHRoaXMuX2RlYnVnKCdNb2xsaWUgY29tcG9uZW50IHVubW91bnRlZCcsIHsgbmFtZSB9KVxuICAgIH1cbiAgICB0aGlzLl9jb21wb25lbnRzID0ge31cbiAgICB0aGlzLl9tb2xsaWUgPSBudWxsXG4gICAgdGhpcy5fbW91bnRpbmcgPSBudWxsXG4gIH1cblxuICAvKiogZGF0YS1hY3Rpb246IGNoYW5nZS0+bW9sbGllLWNvbXBvbmVudHMjZmxvd0NoYW5nZWQgb24gdGhlIG1ldGhvZCByYWRpb3MuICovXG4gIGZsb3dDaGFuZ2VkKCkge1xuICAgIHRoaXMuX2NhcmRNb2RlID0gdGhpcy5fcmVhZENhcmRNb2RlKClcbiAgICB0aGlzLl9zeW5jTW9kZSgpXG4gIH1cblxuICAvKiogQ2FyZCBtb2RlID0gdGhlIHNlbGVjdGVkIE1vbGxpZSBtZXRob2QgaXMgXCJjcmVkaXRjYXJkXCIgKGlubGluZSBDb21wb25lbnRzKS4gKi9cbiAgX3JlYWRDYXJkTW9kZSgpIHtcbiAgICBjb25zdCBjaGVja2VkID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignaW5wdXRbbmFtZT1cIm1vbGxpZU1ldGhvZFwiXTpjaGVja2VkJylcbiAgICByZXR1cm4gISFjaGVja2VkICYmIGNoZWNrZWQudmFsdWUgPT09ICdjcmVkaXRjYXJkJ1xuICB9XG5cbiAgX3N5bmNNb2RlKCkge1xuICAgIGlmICh0aGlzLmhhc0ZpZWxkc1RhcmdldCkge1xuICAgICAgdGhpcy5maWVsZHNUYXJnZXQuc3R5bGUuZGlzcGxheSA9IHRoaXMuX2NhcmRNb2RlID8gJycgOiAnbm9uZSdcbiAgICB9XG4gICAgaWYgKHRoaXMuX2NhcmRNb2RlKSB7XG4gICAgICB0aGlzLl9lbnN1cmVNb3VudGVkKClcbiAgICB9XG4gIH1cblxuICBfZW5zdXJlTW91bnRlZCgpIHtcbiAgICAvLyBSZS1lbnRyYW5jeSBndWFyZC4gVGhpcyBtZXRob2QgaXMgYXN5bmMgYW5kIG9ubHkgYXNzaWducyBgX21vbGxpZWAgYWZ0ZXJcbiAgICAvLyBhd2FpdGluZyBNb2xsaWUuanMsIHNvIHR3byBjYWxscyBpbiBxdWljayBzdWNjZXNzaW9uIFx1MjAxNCBjb25uZWN0KCkgdGhyb3VnaFxuICAgIC8vIF9zeW5jTW9kZSgpLCBwbHVzIGFueSBmbG93Q2hhbmdlZCgpIFx1MjAxNCBib3RoIHVzZWQgdG8gcGFzcyB0aGUgYF9tb2xsaWVgXG4gICAgLy8gY2hlY2sgYW5kIGVhY2ggbW91bnRlZCBhIEZVTEwgc2V0IG9mIGNvbXBvbmVudHMuIFRoZSBleHRyYSBzZXQgaXMgZGV0YWNoZWRcbiAgICAvLyBmcm9tIHRoZSBET00sIHNvIGEgcGVyLWNvbnRhaW5lciBpZnJhbWUgY291bnQgc3RpbGwgcmVhZHMgMSwgd2hpbGUgTW9sbGllJ3NcbiAgICAvLyBsaWJyYXJ5IGtlZXBzIGl0IHJlZ2lzdGVyZWQgYW5kIHRocm93cyBvbiB0aGUgZmlyc3QgaW50ZXJhY3Rpb24uXG4gICAgaWYgKHRoaXMuX21vdW50aW5nKSB7XG4gICAgICByZXR1cm4gdGhpcy5fbW91bnRpbmdcbiAgICB9XG5cbiAgICB0aGlzLl9tb3VudGluZyA9IHRoaXMuX21vdW50KCkuZmluYWxseSgoKSA9PiB7XG4gICAgICB0aGlzLl9tb3VudGluZyA9IG51bGxcbiAgICB9KVxuXG4gICAgcmV0dXJuIHRoaXMuX21vdW50aW5nXG4gIH1cblxuICBhc3luYyBfbW91bnQoKSB7XG4gICAgaWYgKHRoaXMuX21vbGxpZSkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIGlmICghdGhpcy5wcm9maWxlSWRWYWx1ZSkge1xuICAgICAgdGhpcy5fZGVidWcoJ25vIHByb2ZpbGUgaWQgY29uZmlndXJlZCBcdTIwMTQgY2Fubm90IG1vdW50IE1vbGxpZSBDb21wb25lbnRzJylcbiAgICAgIHJldHVyblxuICAgIH1cbiAgICBhd2FpdCB0aGlzLl9sb2FkTW9sbGllSnMoKVxuICAgIGlmICghd2luZG93Lk1vbGxpZSkge1xuICAgICAgdGhpcy5fc2hvd0Vycm9yKCdNb2xsaWUuanMgaXMgdW5hdmFpbGFibGUnKVxuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIHRoaXMuX21vbGxpZSA9IHdpbmRvdy5Nb2xsaWUodGhpcy5wcm9maWxlSWRWYWx1ZSwge1xuICAgICAgbG9jYWxlOiB0aGlzLmxvY2FsZVZhbHVlLFxuICAgICAgdGVzdG1vZGU6IHRoaXMudGVzdG1vZGVWYWx1ZSxcbiAgICB9KVxuICAgIGZvciAoY29uc3QgbmFtZSBvZiBGSUVMRFMpIHtcbiAgICAgIGNvbnN0IG1vdW50ID0gdGhpc1tgJHtuYW1lfVRhcmdldGBdID8gdGhpc1tgJHtuYW1lfVRhcmdldGBdIDogbnVsbFxuICAgICAgaWYgKCFtb3VudCkge1xuICAgICAgICBjb250aW51ZVxuICAgICAgfVxuICAgICAgY29uc3QgY29tcG9uZW50ID0gdGhpcy5fbW9sbGllLmNyZWF0ZUNvbXBvbmVudChuYW1lKVxuICAgICAgY29tcG9uZW50Lm1vdW50KG1vdW50KVxuICAgICAgY29tcG9uZW50LmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsIChldmVudCkgPT4gdGhpcy5fZmllbGRDaGFuZ2VkKG5hbWUsIGV2ZW50KSlcbiAgICAgIHRoaXMuX2NvbXBvbmVudHNbbmFtZV0gPSBjb21wb25lbnRcbiAgICB9XG4gICAgdGhpcy5fZGVidWcoJ01vbGxpZSBDb21wb25lbnRzIG1vdW50ZWQnLCBPYmplY3Qua2V5cyh0aGlzLl9jb21wb25lbnRzKSlcbiAgfVxuXG4gIC8qKlxuICAgKiBkYXRhLWFjdGlvbjogY2xpY2stPm1vbGxpZS1jb21wb25lbnRzI3BsYWNlT3JkZXIgb24gdGhlIG9yZGVyIGJ1dHRvbi5cbiAgICogUmVkaXJlY3QgZmxvdyBcdTIxOTIgc3VibWl0IHRoZSBjb3JlIG9yZGVyIGZvcm0gYXMtaXMuIENhcmQgZmxvdyBcdTIxOTIgdG9rZW5pemUgdGhlIGNhcmQsIHB1dCB0aGVcbiAgICogdG9rZW4gb24gdGhlIGhpZGRlbiBmaWVsZCwgdGhlbiBzdWJtaXQgdGhlIHNhbWUgZm9ybSAoc2VydmVyIGNyZWF0ZXMgdGhlIGNyZWRpdGNhcmQgcGF5bWVudCkuXG4gICAqL1xuICBhc3luYyBwbGFjZU9yZGVyKGV2ZW50KSB7XG4gICAgaWYgKGV2ZW50KSB7XG4gICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG4gICAgfVxuICAgIC8vIE1PTC0xODogb25lIHN1Ym1pc3Npb24gcGVyIGNsaWNrIGJ1cnN0LiBUaGUgc2VydmVyIHJlam9pbnMgYW4gYXR0ZW1wdCBhbHJlYWR5IGluIGZsaWdodCxcbiAgICAvLyBzbyB0aGlzIGlzIFVYIC0gdGhlIGJ1dHRvbiBsb2NrcyBhbmQgc2hvd3MgYSBsb2FkaW5nIHN0YXRlIHVudGlsIHRoZSBicm93c2VyIGhhcyBsZWZ0LlxuICAgIGlmICh0aGlzLl9pbkZsaWdodCkge1xuICAgICAgdGhpcy5fZGVidWcoJ29yZGVyIHN1Ym1pc3Npb24gYWxyZWFkeSBpbiBmbGlnaHQgXHUyMDE0IGNsaWNrIGlnbm9yZWQnKVxuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIGNvbnN0IGZvcm0gPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCh0aGlzLmZvcm1JZFZhbHVlKVxuICAgIGlmICghZm9ybSkge1xuICAgICAgdGhpcy5fc2hvd0Vycm9yKCdPcmRlciBmb3JtIG5vdCBmb3VuZC4nKVxuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIHRoaXMuX2xvY2tPcmRlckJ1dHRvbihldmVudCA/IGV2ZW50LmN1cnJlbnRUYXJnZXQgOiBudWxsKVxuXG4gICAgaWYgKCF0aGlzLl9jYXJkTW9kZSkge1xuICAgICAgdGhpcy5fc3VibWl0KGZvcm0pXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICB0aGlzLl9jbGVhckVycm9yKClcbiAgICBpZiAoIXRoaXMuX21vbGxpZSkge1xuICAgICAgYXdhaXQgdGhpcy5fZW5zdXJlTW91bnRlZCgpXG4gICAgfVxuICAgIGlmICghdGhpcy5fbW9sbGllKSB7XG4gICAgICB0aGlzLl9zaG93RXJyb3IoJ0NhcmQgZm9ybSBpcyBub3QgcmVhZHkuIFBsZWFzZSB0cnkgYWdhaW4uJylcbiAgICAgIHRoaXMuX3JlbGVhc2VPcmRlckJ1dHRvbigpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICB0cnkge1xuICAgICAgY29uc3QgeyB0b2tlbiwgZXJyb3IgfSA9IGF3YWl0IHRoaXMuX21vbGxpZS5jcmVhdGVUb2tlbigpXG4gICAgICBpZiAoZXJyb3IpIHtcbiAgICAgICAgdGhpcy5fc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UgfHwgJ1BsZWFzZSBjaGVjayB5b3VyIGNhcmQgZGV0YWlscy4nKVxuICAgICAgICB0aGlzLl9yZWxlYXNlT3JkZXJCdXR0b24oKVxuICAgICAgICByZXR1cm5cbiAgICAgIH1cbiAgICAgIGlmICh0aGlzLmhhc1Rva2VuVGFyZ2V0KSB7XG4gICAgICAgIHRoaXMudG9rZW5UYXJnZXQudmFsdWUgPSB0b2tlblxuICAgICAgfVxuICAgICAgdGhpcy5fZGVidWcoJ2NhcmQgdG9rZW5pemVkIFx1MjAxNCBzdWJtaXR0aW5nIG9yZGVyJylcbiAgICAgIHRoaXMuX3N1Ym1pdChmb3JtKVxuICAgIH0gY2F0Y2ggKGVycikge1xuICAgICAgdGhpcy5fZGVidWcoJ2NyZWF0ZVRva2VuIGZhaWxlZCcsIGVycilcbiAgICAgIHRoaXMuX3Nob3dFcnJvcignQ291bGQgbm90IHByb2Nlc3MgdGhlIGNhcmQuIFBsZWFzZSB0cnkgYWdhaW4uJylcbiAgICAgIHRoaXMuX3JlbGVhc2VPcmRlckJ1dHRvbigpXG4gICAgfVxuICB9XG5cbiAgX2xvY2tPcmRlckJ1dHRvbihidXR0b24pIHtcbiAgICB0aGlzLl9pbkZsaWdodCA9IHRydWVcbiAgICB0aGlzLl9vcmRlckJ1dHRvbiA9IGJ1dHRvbiBpbnN0YW5jZW9mIEhUTUxCdXR0b25FbGVtZW50ID8gYnV0dG9uIDogbnVsbFxuICAgIGlmICh0aGlzLl9vcmRlckJ1dHRvbikge1xuICAgICAgdGhpcy5fb3JkZXJCdXR0b24uZGlzYWJsZWQgPSB0cnVlXG4gICAgICB0aGlzLl9vcmRlckJ1dHRvbi5jbGFzc0xpc3QuYWRkKCdpcy1sb2FkaW5nJylcbiAgICB9XG4gIH1cblxuICAvKiogUmVsZWFzZWQgb25seSB3aGVuIHRoZSBzdWJtaXNzaW9uIGRpZCBub3QgbGVhdmUgdGhlIHBhZ2U6IHRva2VuaXNhdGlvbiBmYWlsZWQsIG9yIGJmY2FjaGUgcmVzdG9yZS4gKi9cbiAgX3JlbGVhc2VPcmRlckJ1dHRvbigpIHtcbiAgICB0aGlzLl9pbkZsaWdodCA9IGZhbHNlXG4gICAgaWYgKHRoaXMuX29yZGVyQnV0dG9uKSB7XG4gICAgICB0aGlzLl9vcmRlckJ1dHRvbi5kaXNhYmxlZCA9IGZhbHNlXG4gICAgICB0aGlzLl9vcmRlckJ1dHRvbi5jbGFzc0xpc3QucmVtb3ZlKCdpcy1sb2FkaW5nJylcbiAgICB9XG4gIH1cblxuICBfc3VibWl0KGZvcm0pIHtcbiAgICBmb3JtLnJlcXVlc3RTdWJtaXQgPyBmb3JtLnJlcXVlc3RTdWJtaXQoKSA6IGZvcm0uc3VibWl0KClcbiAgfVxuXG4gIF9maWVsZENoYW5nZWQobmFtZSwgZXZlbnQpIHtcbiAgICBpZiAoZXZlbnQgJiYgZXZlbnQuZXJyb3IgJiYgZXZlbnQudG91Y2hlZCkge1xuICAgICAgdGhpcy5fc2hvd0Vycm9yKGV2ZW50LmVycm9yKVxuICAgICAgcmV0dXJuXG4gICAgfVxuICAgIHRoaXMuX2NsZWFyRXJyb3IoKVxuICB9XG5cbiAgX2xvYWRNb2xsaWVKcygpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUsIHJlamVjdCkgPT4ge1xuICAgICAgaWYgKHdpbmRvdy5Nb2xsaWUpIHsgcmVzb2x2ZSgpOyByZXR1cm4gfVxuICAgICAgY29uc3QgZXhpc3RpbmcgPSBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKGBzY3JpcHRbc3JjPVwiJHtNT0xMSUVfSlN9XCJdYClcbiAgICAgIGlmIChleGlzdGluZykge1xuICAgICAgICBleGlzdGluZy5hZGRFdmVudExpc3RlbmVyKCdsb2FkJywgKCkgPT4gcmVzb2x2ZSgpKVxuICAgICAgICBleGlzdGluZy5hZGRFdmVudExpc3RlbmVyKCdlcnJvcicsICgpID0+IHJlamVjdChuZXcgRXJyb3IoJ0ZhaWxlZCB0byBsb2FkIE1vbGxpZS5qcycpKSlcbiAgICAgICAgcmV0dXJuXG4gICAgICB9XG4gICAgICBjb25zdCBzY3JpcHQgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdzY3JpcHQnKVxuICAgICAgc2NyaXB0LnNyYyA9IE1PTExJRV9KU1xuICAgICAgc2NyaXB0LmFzeW5jID0gdHJ1ZVxuICAgICAgc2NyaXB0Lm9ubG9hZCA9ICgpID0+IHJlc29sdmUoKVxuICAgICAgc2NyaXB0Lm9uZXJyb3IgPSAoKSA9PiByZWplY3QobmV3IEVycm9yKCdGYWlsZWQgdG8gbG9hZCBNb2xsaWUuanMnKSlcbiAgICAgIGRvY3VtZW50LmhlYWQuYXBwZW5kQ2hpbGQoc2NyaXB0KVxuICAgIH0pXG4gIH1cblxuICBfc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuZXJyb3JUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICBfY2xlYXJFcnJvcigpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC50ZXh0Q29udGVudCA9ICcnXG4gICAgICB0aGlzLmVycm9yVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICB9XG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSAnQGhvdHdpcmVkL3N0aW11bHVzJ1xuXG4vKipcbiAqIE1PTC0xOCBcdTIwMTQgdGhlIGNsYXNzaWMgXCJPcmRlciBub3dcIiBidXR0b24gKHJlZGlyZWN0IGZsb3csIGlubGluZSBjYXJkIGRpc2FibGVkKSBzdWJtaXRzIG9uY2UuXG4gKlxuICogQXBleCByZW5kZXJzIGA8YnV0dG9uIG9uY2xpY2s9XCJkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnb3JkZXJDb25maXJtQWdiQm90dG9tJykuc3VibWl0KCk7XCI+YDpcbiAqIGV2ZXJ5IGNsaWNrIGlzIGEgZnVsbCBQT1NUIHRvIGNsPW9yZGVyJmZuYz1leGVjdXRlLCBhbmQgYSBzaG9wcGVyJ3MgdHdvIHF1aWNrIGNsaWNrcyByZWFjaCBQSFBcbiAqIG9uZSBhZnRlciB0aGUgb3RoZXIuIFRoZSBzZXJ2ZXIgcmVqb2lucyB0aGUgYXR0ZW1wdCBhbHJlYWR5IGluIGZsaWdodCBzaW5jZSBNT0wtMTgsIHNvIHRoaXMgaXNcbiAqIFVYLCBub3QgdGhlIHNhZmV0eSBuZXQ6IHRoZSBidXR0b24gbG9ja3MgaXRzZWxmIGFuZCBzaG93cyBhIGxvYWRpbmcgc3RhdGUgYWZ0ZXIgdGhlIGZpcnN0IGNsaWNrLlxuICpcbiAqIFVzYWdlICh2aWV3cy90d2lnLy4uLi9wYWdlL2NoZWNrb3V0L29yZGVyLmh0bWwudHdpZywgTW9sbGllICsgY2xhc3NpYyBmbG93IG9ubHkpOlxuICogICA8YnV0dG9uIHR5cGU9XCJidXR0b25cIiBkYXRhLWNvbnRyb2xsZXI9XCJtb2xsaWUtcGxhY2Utb3JkZXJcIlxuICogICAgICAgICAgIGRhdGEtYWN0aW9uPVwiY2xpY2stPm1vbGxpZS1wbGFjZS1vcmRlciNzdWJtaXRcIlxuICogICAgICAgICAgIG9uY2xpY2s9XCJkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnb3JkZXJDb25maXJtQWdiQm90dG9tJykuc3VibWl0KCk7XCI+XG4gKlxuICogVGhlIGlubGluZSBgb25jbGlja2AgaXMgYXBleCdzIG93biBoYW5kbGVyIGFuZCBzdGF5cyBpbiB0aGUgbWFya3VwIGFzIHRoZSBuby1idW5kbGUgZmFsbGJhY2s7XG4gKiBvbmNlIHRoaXMgY29udHJvbGxlciBjb25uZWN0cyBpdCB0YWtlcyB0aGUgY2xpY2sgb3ZlciBhbmQgcmVtb3ZlcyBpdCwgc28gYSBjbGljayBuZXZlciBzdWJtaXRzXG4gKiB0d2ljZSBpbiBvbmUgdGljay5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICAvLyBJZCBvZiB0aGUgY29yZSBvcmRlciBmb3JtIChpbmRleC5waHA/Y2w9b3JkZXImZm5jPWV4ZWN1dGUpLlxuICAgIGZvcm1JZDogeyB0eXBlOiBTdHJpbmcsIGRlZmF1bHQ6ICdvcmRlckNvbmZpcm1BZ2JCb3R0b20nIH0sXG4gIH1cblxuICBjb25uZWN0KCkge1xuICAgIHRoaXMuX2luRmxpZ2h0ID0gZmFsc2VcbiAgICB0aGlzLmVsZW1lbnQucmVtb3ZlQXR0cmlidXRlKCdvbmNsaWNrJylcbiAgICAvLyBCcm93c2VyIGJhY2sgZnJvbSBNb2xsaWUgcmVzdG9yZXMgdGhpcyBwYWdlIGZyb20gdGhlIGJmY2FjaGUgd2l0aCBpdHMgSlMgc3RhdGUgaW50YWN0OlxuICAgIC8vIHRoZSBsb2NrIG11c3Qgb3BlbiBhZ2FpbiBvciB0aGUgc2hvcHBlciBjYW5ub3Qgb3JkZXIgYXQgYWxsLlxuICAgIHRoaXMuX29uUGFnZVNob3cgPSAoZXZlbnQpID0+IHtcbiAgICAgIGlmIChldmVudC5wZXJzaXN0ZWQpIHtcbiAgICAgICAgdGhpcy5fcmVsZWFzZSgpXG4gICAgICB9XG4gICAgfVxuICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIHRoaXMuX29uUGFnZVNob3cpXG4gIH1cblxuICBkaXNjb25uZWN0KCkge1xuICAgIHdpbmRvdy5yZW1vdmVFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIHRoaXMuX29uUGFnZVNob3cpXG4gIH1cblxuICBzdWJtaXQoZXZlbnQpIHtcbiAgICBpZiAoZXZlbnQpIHtcbiAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcbiAgICB9XG4gICAgaWYgKHRoaXMuX2luRmxpZ2h0KSB7XG4gICAgICByZXR1cm5cbiAgICB9XG4gICAgY29uc3QgZm9ybSA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKHRoaXMuZm9ybUlkVmFsdWUpXG4gICAgaWYgKCFmb3JtKSB7XG4gICAgICByZXR1cm5cbiAgICB9XG4gICAgdGhpcy5faW5GbGlnaHQgPSB0cnVlXG4gICAgdGhpcy5lbGVtZW50LmRpc2FibGVkID0gdHJ1ZVxuICAgIHRoaXMuZWxlbWVudC5jbGFzc0xpc3QuYWRkKCdpcy1sb2FkaW5nJylcbiAgICAvLyBBcGV4IHBhcml0eTogcGxhaW4gc3VibWl0KCksIG5vIGNvbnN0cmFpbnQgdmFsaWRhdGlvbiAtIHRoZSBzZXJ2ZXIgcmUtcmVuZGVycyB0aGUgQUdCIGVycm9yLlxuICAgIGZvcm0uc3VibWl0KClcbiAgfVxuXG4gIF9yZWxlYXNlKCkge1xuICAgIHRoaXMuX2luRmxpZ2h0ID0gZmFsc2VcbiAgICB0aGlzLmVsZW1lbnQuZGlzYWJsZWQgPSBmYWxzZVxuICAgIHRoaXMuZWxlbWVudC5jbGFzc0xpc3QucmVtb3ZlKCdpcy1sb2FkaW5nJylcbiAgfVxufVxuIiwgIi8qKlxuICogTW9sbGllIE1vZHVsZSBcdTIwMTQgSmF2YVNjcmlwdCBlbnRyeSBwb2ludC5cbiAqXG4gKiBJbml0aWFsaXplcyBTdGltdWx1cy5qcyBhbmQgcmVnaXN0ZXJzIHRoZSBzdG9yZWZyb250IGNoZWNrb3V0IGNvbnRyb2xsZXIuIE1pcnJvcnMgU3RyaXBlJ3NcbiAqIGByZXNvdXJjZXMvYnVpbGQvanMvYXBwLmpzYCBzdHJ1Y3R1cmUgKGVzYnVpbGQgKyBTdGltdWx1cyksIHNpbXBsaWZpZWQgZm9yIE1vbGxpZSdzIHNtYWxsZXJcbiAqIHN0b3JlZnJvbnQgc3VyZmFjZSAobm8gZW1iZWRkZWQgY2FyZCBlbGVtZW50IFx1MjAxNCBzZWUgbW9sbGllX2NoZWNrb3V0X2NvbnRyb2xsZXIuanMgZG9jYmxvY2spLlxuICovXG5pbXBvcnQgeyBBcHBsaWNhdGlvbiB9IGZyb20gJ0Bob3R3aXJlZC9zdGltdWx1cydcblxuaW1wb3J0IE1vbGxpZUNoZWNrb3V0Q29udHJvbGxlciBmcm9tICcuL2NvbnRyb2xsZXJzL21vbGxpZV9jaGVja291dF9jb250cm9sbGVyLmpzJ1xuaW1wb3J0IE1vbGxpZUNvbXBvbmVudHNDb250cm9sbGVyIGZyb20gJy4vY29udHJvbGxlcnMvbW9sbGllX2NvbXBvbmVudHNfY29udHJvbGxlci5qcydcbmltcG9ydCBNb2xsaWVQbGFjZU9yZGVyQ29udHJvbGxlciBmcm9tICcuL2NvbnRyb2xsZXJzL21vbGxpZV9wbGFjZV9vcmRlcl9jb250cm9sbGVyLmpzJ1xuaW1wb3J0IHsgY3JlYXRlRGVidWdMb2dnZXIgfSBmcm9tICcuL2RlYnVnLmpzJ1xuXG4vLyBSZXVzZSBhbiBhbHJlYWR5LXN0YXJ0ZWQgU3RpbXVsdXMgYXBwbGljYXRpb24gaWYgYW5vdGhlciBwYXltZW50IG1vZHVsZSdzIGJ1bmRsZSBzdGFydGVkIG9uZVxuLy8gb24gdGhpcyBwYWdlIChlLmcuIFN0cmlwZSdzIGZyb250ZW5kIGFsc28gbG9hZHMgb24gdGhlIHNoYXJlZCBvcmRlciBwYWdlKS4gUmVnaXN0ZXJpbmcgb250byB0aGVcbi8vIGxpdmUgYXBwIGNvbm5lY3RzIG91ciBjb250cm9sbGVycyBpbW1lZGlhdGVseTsgc3RhcnRpbmcgYSBzZWNvbmQgYXBwIHdvdWxkIGNsb2JiZXIgdGhlIGZpcnN0Llxud2luZG93LlN0aW11bHVzID0gd2luZG93LlN0aW11bHVzIHx8IEFwcGxpY2F0aW9uLnN0YXJ0KClcbndpbmRvdy5TdGltdWx1cy5yZWdpc3RlcignbW9sbGllLWNoZWNrb3V0JywgTW9sbGllQ2hlY2tvdXRDb250cm9sbGVyKVxud2luZG93LlN0aW11bHVzLnJlZ2lzdGVyKCdtb2xsaWUtY29tcG9uZW50cycsIE1vbGxpZUNvbXBvbmVudHNDb250cm9sbGVyKVxud2luZG93LlN0aW11bHVzLnJlZ2lzdGVyKCdtb2xsaWUtcGxhY2Utb3JkZXInLCBNb2xsaWVQbGFjZU9yZGVyQ29udHJvbGxlcilcblxuY29uc3QgbW9sbGllRGVidWdFbmFibGVkID0gKCkgPT4gd2luZG93Lm9Nb2xsaWU/LmRlYnVnID09PSB0cnVlXG5jb25zdCBkZWJ1ZyA9IGNyZWF0ZURlYnVnTG9nZ2VyKG1vbGxpZURlYnVnRW5hYmxlZClcblxuZGVidWcoJ01vbGxpZSBtb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnMnLCB3aW5kb3cuU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG4iXSwKICAibWFwcGluZ3MiOiAiOzs7Ozs7Ozs7QUFJQSxNQUFNLGdCQUFOLE1BQW9CO0FBQUEsSUFDaEIsWUFBWSxhQUFhLFdBQVcsY0FBYztBQUM5QyxXQUFLLGNBQWM7QUFDbkIsV0FBSyxZQUFZO0FBQ2pCLFdBQUssZUFBZTtBQUNwQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQUEsSUFDckM7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLFlBQVksaUJBQWlCLEtBQUssV0FBVyxNQUFNLEtBQUssWUFBWTtBQUFBLElBQzdFO0FBQUEsSUFDQSxhQUFhO0FBQ1QsV0FBSyxZQUFZLG9CQUFvQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyxrQkFBa0IsSUFBSSxPQUFPO0FBQUEsSUFDdEM7QUFBQSxJQUNBLG9CQUFvQixTQUFTO0FBQ3pCLFdBQUssa0JBQWtCLE9BQU8sT0FBTztBQUFBLElBQ3pDO0FBQUEsSUFDQSxZQUFZLE9BQU87QUFDZixZQUFNLGdCQUFnQixZQUFZLEtBQUs7QUFDdkMsaUJBQVcsV0FBVyxLQUFLLFVBQVU7QUFDakMsWUFBSSxjQUFjLDZCQUE2QjtBQUMzQztBQUFBLFFBQ0osT0FDSztBQUNELGtCQUFRLFlBQVksYUFBYTtBQUFBLFFBQ3JDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWM7QUFDVixhQUFPLEtBQUssa0JBQWtCLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxNQUFNLEtBQUssS0FBSyxpQkFBaUIsRUFBRSxLQUFLLENBQUMsTUFBTSxVQUFVO0FBQzVELGNBQU0sWUFBWSxLQUFLLE9BQU8sYUFBYSxNQUFNO0FBQ2pELGVBQU8sWUFBWSxhQUFhLEtBQUssWUFBWSxhQUFhLElBQUk7QUFBQSxNQUN0RSxDQUFDO0FBQUEsSUFDTDtBQUFBLEVBQ0o7QUFDQSxXQUFTLFlBQVksT0FBTztBQUN4QixRQUFJLGlDQUFpQyxPQUFPO0FBQ3hDLGFBQU87QUFBQSxJQUNYLE9BQ0s7QUFDRCxZQUFNLEVBQUUseUJBQXlCLElBQUk7QUFDckMsYUFBTyxPQUFPLE9BQU8sT0FBTztBQUFBLFFBQ3hCLDZCQUE2QjtBQUFBLFFBQzdCLDJCQUEyQjtBQUN2QixlQUFLLDhCQUE4QjtBQUNuQyxtQ0FBeUIsS0FBSyxJQUFJO0FBQUEsUUFDdEM7QUFBQSxNQUNKLENBQUM7QUFBQSxJQUNMO0FBQUEsRUFDSjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxhQUFhO0FBQ3JCLFdBQUssY0FBYztBQUNuQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQ2pDLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssZUFBZSxRQUFRLENBQUMsa0JBQWtCLGNBQWMsUUFBUSxDQUFDO0FBQUEsTUFDMUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLFVBQVU7QUFDZixhQUFLLGVBQWUsUUFBUSxDQUFDLGtCQUFrQixjQUFjLFdBQVcsQ0FBQztBQUFBLE1BQzdFO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxpQkFBaUI7QUFDakIsYUFBTyxNQUFNLEtBQUssS0FBSyxrQkFBa0IsT0FBTyxDQUFDLEVBQUUsT0FBTyxDQUFDLFdBQVcsUUFBUSxVQUFVLE9BQU8sTUFBTSxLQUFLLElBQUksT0FBTyxDQUFDLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUNoSTtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyw2QkFBNkIsT0FBTyxFQUFFLGlCQUFpQixPQUFPO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG9CQUFvQixTQUFTLHNCQUFzQixPQUFPO0FBQ3RELFdBQUssNkJBQTZCLE9BQU8sRUFBRSxvQkFBb0IsT0FBTztBQUN0RSxVQUFJO0FBQ0EsYUFBSyw4QkFBOEIsT0FBTztBQUFBLElBQ2xEO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsU0FBUyxDQUFDLEdBQUc7QUFDckMsV0FBSyxZQUFZLFlBQVlBLFFBQU8sU0FBUyxPQUFPLElBQUksTUFBTTtBQUFBLElBQ2xFO0FBQUEsSUFDQSw4QkFBOEIsU0FBUztBQUNuQyxZQUFNLGdCQUFnQixLQUFLLDZCQUE2QixPQUFPO0FBQy9ELFVBQUksQ0FBQyxjQUFjLFlBQVksR0FBRztBQUM5QixzQkFBYyxXQUFXO0FBQ3pCLGFBQUssNkJBQTZCLE9BQU87QUFBQSxNQUM3QztBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFlBQU0sRUFBRSxhQUFhLFdBQVcsYUFBYSxJQUFJO0FBQ2pELFlBQU0sbUJBQW1CLEtBQUssb0NBQW9DLFdBQVc7QUFDN0UsWUFBTSxXQUFXLEtBQUssU0FBUyxXQUFXLFlBQVk7QUFDdEQsdUJBQWlCLE9BQU8sUUFBUTtBQUNoQyxVQUFJLGlCQUFpQixRQUFRO0FBQ3pCLGFBQUssa0JBQWtCLE9BQU8sV0FBVztBQUFBLElBQ2pEO0FBQUEsSUFDQSw2QkFBNkIsU0FBUztBQUNsQyxZQUFNLEVBQUUsYUFBYSxXQUFXLGFBQWEsSUFBSTtBQUNqRCxhQUFPLEtBQUssbUJBQW1CLGFBQWEsV0FBVyxZQUFZO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG1CQUFtQixhQUFhLFdBQVcsY0FBYztBQUNyRCxZQUFNLG1CQUFtQixLQUFLLG9DQUFvQyxXQUFXO0FBQzdFLFlBQU0sV0FBVyxLQUFLLFNBQVMsV0FBVyxZQUFZO0FBQ3RELFVBQUksZ0JBQWdCLGlCQUFpQixJQUFJLFFBQVE7QUFDakQsVUFBSSxDQUFDLGVBQWU7QUFDaEIsd0JBQWdCLEtBQUssb0JBQW9CLGFBQWEsV0FBVyxZQUFZO0FBQzdFLHlCQUFpQixJQUFJLFVBQVUsYUFBYTtBQUFBLE1BQ2hEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLG9CQUFvQixhQUFhLFdBQVcsY0FBYztBQUN0RCxZQUFNLGdCQUFnQixJQUFJLGNBQWMsYUFBYSxXQUFXLFlBQVk7QUFDNUUsVUFBSSxLQUFLLFNBQVM7QUFDZCxzQkFBYyxRQUFRO0FBQUEsTUFDMUI7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0Esb0NBQW9DLGFBQWE7QUFDN0MsVUFBSSxtQkFBbUIsS0FBSyxrQkFBa0IsSUFBSSxXQUFXO0FBQzdELFVBQUksQ0FBQyxrQkFBa0I7QUFDbkIsMkJBQW1CLG9CQUFJLElBQUk7QUFDM0IsYUFBSyxrQkFBa0IsSUFBSSxhQUFhLGdCQUFnQjtBQUFBLE1BQzVEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFNBQVMsV0FBVyxjQUFjO0FBQzlCLFlBQU0sUUFBUSxDQUFDLFNBQVM7QUFDeEIsYUFBTyxLQUFLLFlBQVksRUFDbkIsS0FBSyxFQUNMLFFBQVEsQ0FBQyxRQUFRO0FBQ2xCLGNBQU0sS0FBSyxHQUFHLGFBQWEsR0FBRyxJQUFJLEtBQUssR0FBRyxHQUFHLEdBQUcsRUFBRTtBQUFBLE1BQ3RELENBQUM7QUFDRCxhQUFPLE1BQU0sS0FBSyxHQUFHO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQ0FBaUM7QUFBQSxJQUNuQyxLQUFLLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDbkIsVUFBSTtBQUNBLGNBQU0sZ0JBQWdCO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxRQUFRLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDdEIsVUFBSTtBQUNBLGNBQU0sZUFBZTtBQUN6QixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsS0FBSyxFQUFFLE9BQU8sT0FBTyxRQUFRLEdBQUc7QUFDNUIsVUFBSSxPQUFPO0FBQ1AsZUFBTyxZQUFZLE1BQU07QUFBQSxNQUM3QixPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQzFCLFdBQVMsNEJBQTRCLGtCQUFrQjtBQUNuRCxVQUFNLFNBQVMsaUJBQWlCLEtBQUs7QUFDckMsVUFBTSxVQUFVLE9BQU8sTUFBTSxpQkFBaUIsS0FBSyxDQUFDO0FBQ3BELFFBQUksWUFBWSxRQUFRLENBQUM7QUFDekIsUUFBSSxZQUFZLFFBQVEsQ0FBQztBQUN6QixRQUFJLGFBQWEsQ0FBQyxDQUFDLFdBQVcsU0FBUyxVQUFVLEVBQUUsU0FBUyxTQUFTLEdBQUc7QUFDcEUsbUJBQWEsSUFBSSxTQUFTO0FBQzFCLGtCQUFZO0FBQUEsSUFDaEI7QUFDQSxXQUFPO0FBQUEsTUFDSCxhQUFhLGlCQUFpQixRQUFRLENBQUMsQ0FBQztBQUFBLE1BQ3hDO0FBQUEsTUFDQSxjQUFjLFFBQVEsQ0FBQyxJQUFJLGtCQUFrQixRQUFRLENBQUMsQ0FBQyxJQUFJLENBQUM7QUFBQSxNQUM1RCxZQUFZLFFBQVEsQ0FBQztBQUFBLE1BQ3JCLFlBQVksUUFBUSxDQUFDO0FBQUEsTUFDckIsV0FBVyxRQUFRLENBQUMsS0FBSztBQUFBLElBQzdCO0FBQUEsRUFDSjtBQUNBLFdBQVMsaUJBQWlCLGlCQUFpQjtBQUN2QyxRQUFJLG1CQUFtQixVQUFVO0FBQzdCLGFBQU87QUFBQSxJQUNYLFdBQ1MsbUJBQW1CLFlBQVk7QUFDcEMsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBQ0EsV0FBUyxrQkFBa0IsY0FBYztBQUNyQyxXQUFPLGFBQ0YsTUFBTSxHQUFHLEVBQ1QsT0FBTyxDQUFDLFNBQVMsVUFBVSxPQUFPLE9BQU8sU0FBUyxFQUFFLENBQUMsTUFBTSxRQUFRLE1BQU0sRUFBRSxDQUFDLEdBQUcsQ0FBQyxLQUFLLEtBQUssS0FBSyxFQUFFLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNoSDtBQUNBLFdBQVMscUJBQXFCLGFBQWE7QUFDdkMsUUFBSSxlQUFlLFFBQVE7QUFDdkIsYUFBTztBQUFBLElBQ1gsV0FDUyxlQUFlLFVBQVU7QUFDOUIsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsV0FBUyxTQUFTLE9BQU87QUFDckIsV0FBTyxNQUFNLFFBQVEsdUJBQXVCLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxDQUFDO0FBQUEsRUFDL0U7QUFDQSxXQUFTLGtCQUFrQixPQUFPO0FBQzlCLFdBQU8sU0FBUyxNQUFNLFFBQVEsT0FBTyxHQUFHLEVBQUUsUUFBUSxPQUFPLEdBQUcsQ0FBQztBQUFBLEVBQ2pFO0FBQ0EsV0FBUyxXQUFXLE9BQU87QUFDdkIsV0FBTyxNQUFNLE9BQU8sQ0FBQyxFQUFFLFlBQVksSUFBSSxNQUFNLE1BQU0sQ0FBQztBQUFBLEVBQ3hEO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxNQUFNLFFBQVEsWUFBWSxDQUFDLEdBQUcsU0FBUyxJQUFJLEtBQUssWUFBWSxDQUFDLEVBQUU7QUFBQSxFQUMxRTtBQUNBLFdBQVMsU0FBUyxPQUFPO0FBQ3JCLFdBQU8sTUFBTSxNQUFNLFNBQVMsS0FBSyxDQUFDO0FBQUEsRUFDdEM7QUFFQSxXQUFTLFlBQVksUUFBUTtBQUN6QixXQUFPLFdBQVcsUUFBUSxXQUFXO0FBQUEsRUFDekM7QUFDQSxXQUFTLFlBQVksUUFBUSxVQUFVO0FBQ25DLFdBQU8sT0FBTyxVQUFVLGVBQWUsS0FBSyxRQUFRLFFBQVE7QUFBQSxFQUNoRTtBQUVBLE1BQU0sZUFBZSxDQUFDLFFBQVEsUUFBUSxPQUFPLE9BQU87QUFDcEQsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksU0FBUyxPQUFPLFlBQVksUUFBUTtBQUM1QyxXQUFLLFVBQVU7QUFDZixXQUFLLFFBQVE7QUFDYixXQUFLLGNBQWMsV0FBVyxlQUFlO0FBQzdDLFdBQUssWUFBWSxXQUFXLGFBQWEsOEJBQThCLE9BQU8sS0FBSyxNQUFNLG9CQUFvQjtBQUM3RyxXQUFLLGVBQWUsV0FBVyxnQkFBZ0IsQ0FBQztBQUNoRCxXQUFLLGFBQWEsV0FBVyxjQUFjLE1BQU0sb0JBQW9CO0FBQ3JFLFdBQUssYUFBYSxXQUFXLGNBQWMsTUFBTSxxQkFBcUI7QUFDdEUsV0FBSyxZQUFZLFdBQVcsYUFBYTtBQUN6QyxXQUFLLFNBQVM7QUFBQSxJQUNsQjtBQUFBLElBQ0EsT0FBTyxTQUFTLE9BQU8sUUFBUTtBQUMzQixhQUFPLElBQUksS0FBSyxNQUFNLFNBQVMsTUFBTSxPQUFPLDRCQUE0QixNQUFNLE9BQU8sR0FBRyxNQUFNO0FBQUEsSUFDbEc7QUFBQSxJQUNBLFdBQVc7QUFDUCxZQUFNLGNBQWMsS0FBSyxZQUFZLElBQUksS0FBSyxTQUFTLEtBQUs7QUFDNUQsWUFBTSxjQUFjLEtBQUssa0JBQWtCLElBQUksS0FBSyxlQUFlLEtBQUs7QUFDeEUsYUFBTyxHQUFHLEtBQUssU0FBUyxHQUFHLFdBQVcsR0FBRyxXQUFXLEtBQUssS0FBSyxVQUFVLElBQUksS0FBSyxVQUFVO0FBQUEsSUFDL0Y7QUFBQSxJQUNBLDBCQUEwQixPQUFPO0FBQzdCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsS0FBSyxVQUFVLE1BQU0sR0FBRztBQUN4QyxVQUFJLEtBQUssc0JBQXNCLE9BQU8sT0FBTyxHQUFHO0FBQzVDLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxpQkFBaUIsUUFBUSxPQUFPLENBQUMsUUFBUSxDQUFDLGFBQWEsU0FBUyxHQUFHLENBQUMsRUFBRSxDQUFDO0FBQzdFLFVBQUksQ0FBQyxnQkFBZ0I7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLENBQUMsWUFBWSxLQUFLLGFBQWEsY0FBYyxHQUFHO0FBQ2hELGNBQU0sZ0NBQWdDLEtBQUssU0FBUyxFQUFFO0FBQUEsTUFDMUQ7QUFDQSxhQUFPLEtBQUssWUFBWSxjQUFjLEVBQUUsWUFBWSxNQUFNLE1BQU0sSUFBSSxZQUFZO0FBQUEsSUFDcEY7QUFBQSxJQUNBLHVCQUF1QixPQUFPO0FBQzFCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsQ0FBQyxLQUFLLFNBQVM7QUFDL0IsVUFBSSxLQUFLLHNCQUFzQixPQUFPLE9BQU8sR0FBRztBQUM1QyxlQUFPO0FBQUEsTUFDWDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsQ0FBQztBQUNoQixZQUFNLFVBQVUsSUFBSSxPQUFPLFNBQVMsS0FBSyxVQUFVLGdCQUFnQixHQUFHO0FBQ3RFLGlCQUFXLEVBQUUsTUFBTSxNQUFNLEtBQUssTUFBTSxLQUFLLEtBQUssUUFBUSxVQUFVLEdBQUc7QUFDL0QsY0FBTSxRQUFRLEtBQUssTUFBTSxPQUFPO0FBQ2hDLGNBQU0sTUFBTSxTQUFTLE1BQU0sQ0FBQztBQUM1QixZQUFJLEtBQUs7QUFDTCxpQkFBTyxTQUFTLEdBQUcsQ0FBQyxJQUFJLFNBQVMsS0FBSztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLGtCQUFrQjtBQUNsQixhQUFPLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUNoRDtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sU0FBUztBQUNsQyxZQUFNLENBQUMsTUFBTSxNQUFNLEtBQUssS0FBSyxJQUFJLGFBQWEsSUFBSSxDQUFDLGFBQWEsUUFBUSxTQUFTLFFBQVEsQ0FBQztBQUMxRixhQUFPLE1BQU0sWUFBWSxRQUFRLE1BQU0sWUFBWSxRQUFRLE1BQU0sV0FBVyxPQUFPLE1BQU0sYUFBYTtBQUFBLElBQzFHO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQUEsSUFDdEIsR0FBRyxNQUFNO0FBQUEsSUFDVCxRQUFRLE1BQU07QUFBQSxJQUNkLE1BQU0sTUFBTTtBQUFBLElBQ1osU0FBUyxNQUFNO0FBQUEsSUFDZixPQUFPLENBQUMsTUFBTyxFQUFFLGFBQWEsTUFBTSxLQUFLLFdBQVcsVUFBVTtBQUFBLElBQzlELFFBQVEsTUFBTTtBQUFBLElBQ2QsVUFBVSxNQUFNO0FBQUEsRUFDcEI7QUFDQSxXQUFTLDhCQUE4QixTQUFTO0FBQzVDLFVBQU0sVUFBVSxRQUFRLFFBQVEsWUFBWTtBQUM1QyxRQUFJLFdBQVcsbUJBQW1CO0FBQzlCLGFBQU8sa0JBQWtCLE9BQU8sRUFBRSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxFQUNKO0FBQ0EsV0FBUyxNQUFNLFNBQVM7QUFDcEIsVUFBTSxJQUFJLE1BQU0sT0FBTztBQUFBLEVBQzNCO0FBQ0EsV0FBUyxTQUFTLE9BQU87QUFDckIsUUFBSTtBQUNBLGFBQU8sS0FBSyxNQUFNLEtBQUs7QUFBQSxJQUMzQixTQUNPLEtBQUs7QUFDUixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxTQUFTLFFBQVE7QUFDekIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxTQUFTO0FBQUEsSUFDbEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksZUFBZTtBQUNmLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLFlBQVksT0FBTztBQUNmLFlBQU0sY0FBYyxLQUFLLG1CQUFtQixLQUFLO0FBQ2pELFVBQUksS0FBSyxxQkFBcUIsS0FBSyxLQUFLLEtBQUssb0JBQW9CLFdBQVcsR0FBRztBQUMzRSxhQUFLLGdCQUFnQixXQUFXO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsS0FBSyxXQUFXLEtBQUssVUFBVTtBQUM5QyxVQUFJLE9BQU8sVUFBVSxZQUFZO0FBQzdCLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxJQUFJLE1BQU0sV0FBVyxLQUFLLE1BQU0sa0NBQWtDLEtBQUssVUFBVSxHQUFHO0FBQUEsSUFDOUY7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLFlBQU0sRUFBRSxRQUFRLElBQUksS0FBSztBQUN6QixZQUFNLEVBQUUsd0JBQXdCLElBQUksS0FBSyxRQUFRO0FBQ2pELFlBQU0sRUFBRSxXQUFXLElBQUksS0FBSztBQUM1QixVQUFJLFNBQVM7QUFDYixpQkFBVyxDQUFDLE1BQU0sS0FBSyxLQUFLLE9BQU8sUUFBUSxLQUFLLFlBQVksR0FBRztBQUMzRCxZQUFJLFFBQVEseUJBQXlCO0FBQ2pDLGdCQUFNLFNBQVMsd0JBQXdCLElBQUk7QUFDM0MsbUJBQVMsVUFBVSxPQUFPLEVBQUUsTUFBTSxPQUFPLE9BQU8sU0FBUyxXQUFXLENBQUM7QUFBQSxRQUN6RSxPQUNLO0FBQ0Q7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixhQUFPLE9BQU8sT0FBTyxPQUFPLEVBQUUsUUFBUSxLQUFLLE9BQU8sT0FBTyxDQUFDO0FBQUEsSUFDOUQ7QUFBQSxJQUNBLGdCQUFnQixPQUFPO0FBQ25CLFlBQU0sRUFBRSxRQUFRLGNBQWMsSUFBSTtBQUNsQyxVQUFJO0FBQ0EsYUFBSyxPQUFPLEtBQUssS0FBSyxZQUFZLEtBQUs7QUFDdkMsYUFBSyxRQUFRLGlCQUFpQixLQUFLLFlBQVksRUFBRSxPQUFPLFFBQVEsZUFBZSxRQUFRLEtBQUssV0FBVyxDQUFDO0FBQUEsTUFDNUcsU0FDT0EsUUFBTztBQUNWLGNBQU0sRUFBRSxZQUFZLFlBQVksU0FBUyxNQUFNLElBQUk7QUFDbkQsY0FBTSxTQUFTLEVBQUUsWUFBWSxZQUFZLFNBQVMsT0FBTyxNQUFNO0FBQy9ELGFBQUssUUFBUSxZQUFZQSxRQUFPLG9CQUFvQixLQUFLLE1BQU0sS0FBSyxNQUFNO0FBQUEsTUFDOUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsT0FBTztBQUN4QixZQUFNLGNBQWMsTUFBTTtBQUMxQixVQUFJLGlCQUFpQixpQkFBaUIsS0FBSyxPQUFPLDBCQUEwQixLQUFLLEdBQUc7QUFDaEYsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLGlCQUFpQixjQUFjLEtBQUssT0FBTyx1QkFBdUIsS0FBSyxHQUFHO0FBQzFFLGVBQU87QUFBQSxNQUNYO0FBQ0EsVUFBSSxLQUFLLFlBQVksYUFBYTtBQUM5QixlQUFPO0FBQUEsTUFDWCxXQUNTLHVCQUF1QixXQUFXLEtBQUssUUFBUSxTQUFTLFdBQVcsR0FBRztBQUMzRSxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsV0FBVztBQUFBLE1BQ2pELE9BQ0s7QUFDRCxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsS0FBSyxPQUFPLE9BQU87QUFBQSxNQUN6RDtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxrQkFBTixNQUFzQjtBQUFBLElBQ2xCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssdUJBQXVCLEVBQUUsWUFBWSxNQUFNLFdBQVcsTUFBTSxTQUFTLEtBQUs7QUFDL0UsV0FBSyxVQUFVO0FBQ2YsV0FBSyxVQUFVO0FBQ2YsV0FBSyxXQUFXO0FBQ2hCLFdBQUssV0FBVyxvQkFBSSxJQUFJO0FBQ3hCLFdBQUssbUJBQW1CLElBQUksaUJBQWlCLENBQUMsY0FBYyxLQUFLLGlCQUFpQixTQUFTLENBQUM7QUFBQSxJQUNoRztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLFNBQVM7QUFDZixhQUFLLFVBQVU7QUFDZixhQUFLLGlCQUFpQixRQUFRLEtBQUssU0FBUyxLQUFLLG9CQUFvQjtBQUNyRSxhQUFLLFFBQVE7QUFBQSxNQUNqQjtBQUFBLElBQ0o7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFVBQUksS0FBSyxTQUFTO0FBQ2QsYUFBSyxpQkFBaUIsV0FBVztBQUNqQyxhQUFLLFVBQVU7QUFBQSxNQUNuQjtBQUNBLGVBQVM7QUFDVCxVQUFJLENBQUMsS0FBSyxTQUFTO0FBQ2YsYUFBSyxpQkFBaUIsUUFBUSxLQUFLLFNBQVMsS0FBSyxvQkFBb0I7QUFDckUsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxjQUFNLFVBQVUsSUFBSSxJQUFJLEtBQUssb0JBQW9CLENBQUM7QUFDbEQsbUJBQVcsV0FBVyxNQUFNLEtBQUssS0FBSyxRQUFRLEdBQUc7QUFDN0MsY0FBSSxDQUFDLFFBQVEsSUFBSSxPQUFPLEdBQUc7QUFDdkIsaUJBQUssY0FBYyxPQUFPO0FBQUEsVUFDOUI7QUFBQSxRQUNKO0FBQ0EsbUJBQVcsV0FBVyxNQUFNLEtBQUssT0FBTyxHQUFHO0FBQ3ZDLGVBQUssV0FBVyxPQUFPO0FBQUEsUUFDM0I7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFVBQUksU0FBUyxRQUFRLGNBQWM7QUFDL0IsYUFBSyx1QkFBdUIsU0FBUyxRQUFRLFNBQVMsYUFBYTtBQUFBLE1BQ3ZFLFdBQ1MsU0FBUyxRQUFRLGFBQWE7QUFDbkMsYUFBSyxvQkFBb0IsU0FBUyxZQUFZO0FBQzlDLGFBQUssa0JBQWtCLFNBQVMsVUFBVTtBQUFBLE1BQzlDO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCLFNBQVMsZUFBZTtBQUMzQyxVQUFJLEtBQUssU0FBUyxJQUFJLE9BQU8sR0FBRztBQUM1QixZQUFJLEtBQUssU0FBUywyQkFBMkIsS0FBSyxhQUFhLE9BQU8sR0FBRztBQUNyRSxlQUFLLFNBQVMsd0JBQXdCLFNBQVMsYUFBYTtBQUFBLFFBQ2hFLE9BQ0s7QUFDRCxlQUFLLGNBQWMsT0FBTztBQUFBLFFBQzlCO0FBQUEsTUFDSixXQUNTLEtBQUssYUFBYSxPQUFPLEdBQUc7QUFDakMsYUFBSyxXQUFXLE9BQU87QUFBQSxNQUMzQjtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLGlCQUFXLFFBQVEsTUFBTSxLQUFLLEtBQUssR0FBRztBQUNsQyxjQUFNLFVBQVUsS0FBSyxnQkFBZ0IsSUFBSTtBQUN6QyxZQUFJLFNBQVM7QUFDVCxlQUFLLFlBQVksU0FBUyxLQUFLLGFBQWE7QUFBQSxRQUNoRDtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsT0FBTztBQUNyQixpQkFBVyxRQUFRLE1BQU0sS0FBSyxLQUFLLEdBQUc7QUFDbEMsY0FBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUk7QUFDekMsWUFBSSxXQUFXLEtBQUssZ0JBQWdCLE9BQU8sR0FBRztBQUMxQyxlQUFLLFlBQVksU0FBUyxLQUFLLFVBQVU7QUFBQSxRQUM3QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxhQUFhLFNBQVM7QUFDbEIsYUFBTyxLQUFLLFNBQVMsYUFBYSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxJQUNBLG9CQUFvQixPQUFPLEtBQUssU0FBUztBQUNyQyxhQUFPLEtBQUssU0FBUyxvQkFBb0IsSUFBSTtBQUFBLElBQ2pEO0FBQUEsSUFDQSxZQUFZLE1BQU0sV0FBVztBQUN6QixpQkFBVyxXQUFXLEtBQUssb0JBQW9CLElBQUksR0FBRztBQUNsRCxrQkFBVSxLQUFLLE1BQU0sT0FBTztBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLE1BQU07QUFDbEIsVUFBSSxLQUFLLFlBQVksS0FBSyxjQUFjO0FBQ3BDLGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLFNBQVM7QUFDckIsVUFBSSxRQUFRLGVBQWUsS0FBSyxRQUFRLGFBQWE7QUFDakQsZUFBTztBQUFBLE1BQ1gsT0FDSztBQUNELGVBQU8sS0FBSyxRQUFRLFNBQVMsT0FBTztBQUFBLE1BQ3hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsV0FBVyxTQUFTO0FBQ2hCLFVBQUksQ0FBQyxLQUFLLFNBQVMsSUFBSSxPQUFPLEdBQUc7QUFDN0IsWUFBSSxLQUFLLGdCQUFnQixPQUFPLEdBQUc7QUFDL0IsZUFBSyxTQUFTLElBQUksT0FBTztBQUN6QixjQUFJLEtBQUssU0FBUyxnQkFBZ0I7QUFDOUIsaUJBQUssU0FBUyxlQUFlLE9BQU87QUFBQSxVQUN4QztBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxTQUFTO0FBQ25CLFVBQUksS0FBSyxTQUFTLElBQUksT0FBTyxHQUFHO0FBQzVCLGFBQUssU0FBUyxPQUFPLE9BQU87QUFDNUIsWUFBSSxLQUFLLFNBQVMsa0JBQWtCO0FBQ2hDLGVBQUssU0FBUyxpQkFBaUIsT0FBTztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxlQUFlLFVBQVU7QUFDMUMsV0FBSyxnQkFBZ0I7QUFDckIsV0FBSyxXQUFXO0FBQ2hCLFdBQUssa0JBQWtCLElBQUksZ0JBQWdCLFNBQVMsSUFBSTtBQUFBLElBQzVEO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sSUFBSSxLQUFLLGFBQWE7QUFBQSxJQUNqQztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssZ0JBQWdCLE1BQU07QUFBQSxJQUMvQjtBQUFBLElBQ0EsTUFBTSxVQUFVO0FBQ1osV0FBSyxnQkFBZ0IsTUFBTSxRQUFRO0FBQUEsSUFDdkM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGdCQUFnQixLQUFLO0FBQUEsSUFDOUI7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGdCQUFnQixRQUFRO0FBQUEsSUFDakM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxnQkFBZ0I7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYSxTQUFTO0FBQ2xCLGFBQU8sUUFBUSxhQUFhLEtBQUssYUFBYTtBQUFBLElBQ2xEO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLFFBQVEsS0FBSyxhQUFhLElBQUksSUFBSSxDQUFDLElBQUksSUFBSSxDQUFDO0FBQ2xELFlBQU0sVUFBVSxNQUFNLEtBQUssS0FBSyxpQkFBaUIsS0FBSyxRQUFRLENBQUM7QUFDL0QsYUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLElBQy9CO0FBQUEsSUFDQSxlQUFlLFNBQVM7QUFDcEIsVUFBSSxLQUFLLFNBQVMseUJBQXlCO0FBQ3ZDLGFBQUssU0FBUyx3QkFBd0IsU0FBUyxLQUFLLGFBQWE7QUFBQSxNQUNyRTtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFVBQUksS0FBSyxTQUFTLDJCQUEyQjtBQUN6QyxhQUFLLFNBQVMsMEJBQTBCLFNBQVMsS0FBSyxhQUFhO0FBQUEsTUFDdkU7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsU0FBUyxlQUFlO0FBQzVDLFVBQUksS0FBSyxTQUFTLGdDQUFnQyxLQUFLLGlCQUFpQixlQUFlO0FBQ25GLGFBQUssU0FBUyw2QkFBNkIsU0FBUyxhQUFhO0FBQUEsTUFDckU7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsSUFBSSxLQUFLLEtBQUssT0FBTztBQUMxQixVQUFNLEtBQUssR0FBRyxFQUFFLElBQUksS0FBSztBQUFBLEVBQzdCO0FBQ0EsV0FBUyxJQUFJLEtBQUssS0FBSyxPQUFPO0FBQzFCLFVBQU0sS0FBSyxHQUFHLEVBQUUsT0FBTyxLQUFLO0FBQzVCLFVBQU0sS0FBSyxHQUFHO0FBQUEsRUFDbEI7QUFDQSxXQUFTLE1BQU0sS0FBSyxLQUFLO0FBQ3JCLFFBQUksU0FBUyxJQUFJLElBQUksR0FBRztBQUN4QixRQUFJLENBQUMsUUFBUTtBQUNULGVBQVMsb0JBQUksSUFBSTtBQUNqQixVQUFJLElBQUksS0FBSyxNQUFNO0FBQUEsSUFDdkI7QUFDQSxXQUFPO0FBQUEsRUFDWDtBQUNBLFdBQVMsTUFBTSxLQUFLLEtBQUs7QUFDckIsVUFBTSxTQUFTLElBQUksSUFBSSxHQUFHO0FBQzFCLFFBQUksVUFBVSxRQUFRLE9BQU8sUUFBUSxHQUFHO0FBQ3BDLFVBQUksT0FBTyxHQUFHO0FBQUEsSUFDbEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxXQUFOLE1BQWU7QUFBQSxJQUNYLGNBQWM7QUFDVixXQUFLLGNBQWMsb0JBQUksSUFBSTtBQUFBLElBQy9CO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxhQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksS0FBSyxDQUFDO0FBQUEsSUFDN0M7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULFlBQU0sT0FBTyxNQUFNLEtBQUssS0FBSyxZQUFZLE9BQU8sQ0FBQztBQUNqRCxhQUFPLEtBQUssT0FBTyxDQUFDLFFBQVEsUUFBUSxPQUFPLE9BQU8sTUFBTSxLQUFLLEdBQUcsQ0FBQyxHQUFHLENBQUMsQ0FBQztBQUFBLElBQzFFO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxZQUFNLE9BQU8sTUFBTSxLQUFLLEtBQUssWUFBWSxPQUFPLENBQUM7QUFDakQsYUFBTyxLQUFLLE9BQU8sQ0FBQyxNQUFNLFFBQVEsT0FBTyxJQUFJLE1BQU0sQ0FBQztBQUFBLElBQ3hEO0FBQUEsSUFDQSxJQUFJLEtBQUssT0FBTztBQUNaLFVBQUksS0FBSyxhQUFhLEtBQUssS0FBSztBQUFBLElBQ3BDO0FBQUEsSUFDQSxPQUFPLEtBQUssT0FBTztBQUNmLFVBQUksS0FBSyxhQUFhLEtBQUssS0FBSztBQUFBLElBQ3BDO0FBQUEsSUFDQSxJQUFJLEtBQUssT0FBTztBQUNaLFlBQU0sU0FBUyxLQUFLLFlBQVksSUFBSSxHQUFHO0FBQ3ZDLGFBQU8sVUFBVSxRQUFRLE9BQU8sSUFBSSxLQUFLO0FBQUEsSUFDN0M7QUFBQSxJQUNBLE9BQU8sS0FBSztBQUNSLGFBQU8sS0FBSyxZQUFZLElBQUksR0FBRztBQUFBLElBQ25DO0FBQUEsSUFDQSxTQUFTLE9BQU87QUFDWixZQUFNLE9BQU8sTUFBTSxLQUFLLEtBQUssWUFBWSxPQUFPLENBQUM7QUFDakQsYUFBTyxLQUFLLEtBQUssQ0FBQyxRQUFRLElBQUksSUFBSSxLQUFLLENBQUM7QUFBQSxJQUM1QztBQUFBLElBQ0EsZ0JBQWdCLEtBQUs7QUFDakIsWUFBTSxTQUFTLEtBQUssWUFBWSxJQUFJLEdBQUc7QUFDdkMsYUFBTyxTQUFTLE1BQU0sS0FBSyxNQUFNLElBQUksQ0FBQztBQUFBLElBQzFDO0FBQUEsSUFDQSxnQkFBZ0IsT0FBTztBQUNuQixhQUFPLE1BQU0sS0FBSyxLQUFLLFdBQVcsRUFDN0IsT0FBTyxDQUFDLENBQUMsTUFBTSxNQUFNLE1BQU0sT0FBTyxJQUFJLEtBQUssQ0FBQyxFQUM1QyxJQUFJLENBQUMsQ0FBQyxLQUFLLE9BQU8sTUFBTSxHQUFHO0FBQUEsSUFDcEM7QUFBQSxFQUNKO0FBMkJBLE1BQU0sbUJBQU4sTUFBdUI7QUFBQSxJQUNuQixZQUFZLFNBQVMsVUFBVSxVQUFVLFNBQVM7QUFDOUMsV0FBSyxZQUFZO0FBQ2pCLFdBQUssVUFBVTtBQUNmLFdBQUssa0JBQWtCLElBQUksZ0JBQWdCLFNBQVMsSUFBSTtBQUN4RCxXQUFLLFdBQVc7QUFDaEIsV0FBSyxtQkFBbUIsSUFBSSxTQUFTO0FBQUEsSUFDekM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxnQkFBZ0I7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxLQUFLO0FBQUEsSUFDaEI7QUFBQSxJQUNBLElBQUksU0FBUyxVQUFVO0FBQ25CLFdBQUssWUFBWTtBQUNqQixXQUFLLFFBQVE7QUFBQSxJQUNqQjtBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssZ0JBQWdCLE1BQU07QUFBQSxJQUMvQjtBQUFBLElBQ0EsTUFBTSxVQUFVO0FBQ1osV0FBSyxnQkFBZ0IsTUFBTSxRQUFRO0FBQUEsSUFDdkM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGdCQUFnQixLQUFLO0FBQUEsSUFDOUI7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGdCQUFnQixRQUFRO0FBQUEsSUFDakM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxnQkFBZ0I7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYSxTQUFTO0FBQ2xCLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsY0FBTSxVQUFVLFFBQVEsUUFBUSxRQUFRO0FBQ3hDLFlBQUksS0FBSyxTQUFTLHNCQUFzQjtBQUNwQyxpQkFBTyxXQUFXLEtBQUssU0FBUyxxQkFBcUIsU0FBUyxLQUFLLE9BQU87QUFBQSxRQUM5RTtBQUNBLGVBQU87QUFBQSxNQUNYLE9BQ0s7QUFDRCxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixNQUFNO0FBQ3RCLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsY0FBTSxRQUFRLEtBQUssYUFBYSxJQUFJLElBQUksQ0FBQyxJQUFJLElBQUksQ0FBQztBQUNsRCxjQUFNLFVBQVUsTUFBTSxLQUFLLEtBQUssaUJBQWlCLFFBQVEsQ0FBQyxFQUFFLE9BQU8sQ0FBQ0MsV0FBVSxLQUFLLGFBQWFBLE1BQUssQ0FBQztBQUN0RyxlQUFPLE1BQU0sT0FBTyxPQUFPO0FBQUEsTUFDL0IsT0FDSztBQUNELGVBQU8sQ0FBQztBQUFBLE1BQ1o7QUFBQSxJQUNKO0FBQUEsSUFDQSxlQUFlLFNBQVM7QUFDcEIsWUFBTSxFQUFFLFNBQVMsSUFBSTtBQUNyQixVQUFJLFVBQVU7QUFDVixhQUFLLGdCQUFnQixTQUFTLFFBQVE7QUFBQSxNQUMxQztBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFlBQU0sWUFBWSxLQUFLLGlCQUFpQixnQkFBZ0IsT0FBTztBQUMvRCxpQkFBVyxZQUFZLFdBQVc7QUFDOUIsYUFBSyxrQkFBa0IsU0FBUyxRQUFRO0FBQUEsTUFDNUM7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsU0FBUyxnQkFBZ0I7QUFDN0MsWUFBTSxFQUFFLFNBQVMsSUFBSTtBQUNyQixVQUFJLFVBQVU7QUFDVixjQUFNLFVBQVUsS0FBSyxhQUFhLE9BQU87QUFDekMsY0FBTSxnQkFBZ0IsS0FBSyxpQkFBaUIsSUFBSSxVQUFVLE9BQU87QUFDakUsWUFBSSxXQUFXLENBQUMsZUFBZTtBQUMzQixlQUFLLGdCQUFnQixTQUFTLFFBQVE7QUFBQSxRQUMxQyxXQUNTLENBQUMsV0FBVyxlQUFlO0FBQ2hDLGVBQUssa0JBQWtCLFNBQVMsUUFBUTtBQUFBLFFBQzVDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixTQUFTLFVBQVU7QUFDL0IsV0FBSyxTQUFTLGdCQUFnQixTQUFTLFVBQVUsS0FBSyxPQUFPO0FBQzdELFdBQUssaUJBQWlCLElBQUksVUFBVSxPQUFPO0FBQUEsSUFDL0M7QUFBQSxJQUNBLGtCQUFrQixTQUFTLFVBQVU7QUFDakMsV0FBSyxTQUFTLGtCQUFrQixTQUFTLFVBQVUsS0FBSyxPQUFPO0FBQy9ELFdBQUssaUJBQWlCLE9BQU8sVUFBVSxPQUFPO0FBQUEsSUFDbEQ7QUFBQSxFQUNKO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLFVBQVU7QUFDZixXQUFLLFlBQVksb0JBQUksSUFBSTtBQUN6QixXQUFLLG1CQUFtQixJQUFJLGlCQUFpQixDQUFDLGNBQWMsS0FBSyxpQkFBaUIsU0FBUyxDQUFDO0FBQUEsSUFDaEc7QUFBQSxJQUNBLFFBQVE7QUFDSixVQUFJLENBQUMsS0FBSyxTQUFTO0FBQ2YsYUFBSyxVQUFVO0FBQ2YsYUFBSyxpQkFBaUIsUUFBUSxLQUFLLFNBQVMsRUFBRSxZQUFZLE1BQU0sbUJBQW1CLEtBQUssQ0FBQztBQUN6RixhQUFLLFFBQVE7QUFBQSxNQUNqQjtBQUFBLElBQ0o7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssU0FBUztBQUNkLGFBQUssaUJBQWlCLFlBQVk7QUFDbEMsYUFBSyxpQkFBaUIsV0FBVztBQUNqQyxhQUFLLFVBQVU7QUFBQSxNQUNuQjtBQUFBLElBQ0o7QUFBQSxJQUNBLFVBQVU7QUFDTixVQUFJLEtBQUssU0FBUztBQUNkLG1CQUFXLGlCQUFpQixLQUFLLHFCQUFxQjtBQUNsRCxlQUFLLGlCQUFpQixlQUFlLElBQUk7QUFBQSxRQUM3QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsV0FBVztBQUN4QixVQUFJLEtBQUssU0FBUztBQUNkLG1CQUFXLFlBQVksV0FBVztBQUM5QixlQUFLLGdCQUFnQixRQUFRO0FBQUEsUUFDakM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLFVBQVU7QUFDdEIsWUFBTSxnQkFBZ0IsU0FBUztBQUMvQixVQUFJLGVBQWU7QUFDZixhQUFLLGlCQUFpQixlQUFlLFNBQVMsUUFBUTtBQUFBLE1BQzFEO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLGVBQWUsVUFBVTtBQUN0QyxZQUFNLE1BQU0sS0FBSyxTQUFTLDRCQUE0QixhQUFhO0FBQ25FLFVBQUksT0FBTyxNQUFNO0FBQ2IsWUFBSSxDQUFDLEtBQUssVUFBVSxJQUFJLGFBQWEsR0FBRztBQUNwQyxlQUFLLGtCQUFrQixLQUFLLGFBQWE7QUFBQSxRQUM3QztBQUNBLGNBQU0sUUFBUSxLQUFLLFFBQVEsYUFBYSxhQUFhO0FBQ3JELFlBQUksS0FBSyxVQUFVLElBQUksYUFBYSxLQUFLLE9BQU87QUFDNUMsZUFBSyxzQkFBc0IsT0FBTyxLQUFLLFFBQVE7QUFBQSxRQUNuRDtBQUNBLFlBQUksU0FBUyxNQUFNO0FBQ2YsZ0JBQU1DLFlBQVcsS0FBSyxVQUFVLElBQUksYUFBYTtBQUNqRCxlQUFLLFVBQVUsT0FBTyxhQUFhO0FBQ25DLGNBQUlBO0FBQ0EsaUJBQUssb0JBQW9CLEtBQUssZUFBZUEsU0FBUTtBQUFBLFFBQzdELE9BQ0s7QUFDRCxlQUFLLFVBQVUsSUFBSSxlQUFlLEtBQUs7QUFBQSxRQUMzQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsS0FBSyxlQUFlO0FBQ2xDLFVBQUksS0FBSyxTQUFTLG1CQUFtQjtBQUNqQyxhQUFLLFNBQVMsa0JBQWtCLEtBQUssYUFBYTtBQUFBLE1BQ3REO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sS0FBSyxVQUFVO0FBQ3hDLFVBQUksS0FBSyxTQUFTLHVCQUF1QjtBQUNyQyxhQUFLLFNBQVMsc0JBQXNCLE9BQU8sS0FBSyxRQUFRO0FBQUEsTUFDNUQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxvQkFBb0IsS0FBSyxlQUFlLFVBQVU7QUFDOUMsVUFBSSxLQUFLLFNBQVMscUJBQXFCO0FBQ25DLGFBQUssU0FBUyxvQkFBb0IsS0FBSyxlQUFlLFFBQVE7QUFBQSxNQUNsRTtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksc0JBQXNCO0FBQ3RCLGFBQU8sTUFBTSxLQUFLLElBQUksSUFBSSxLQUFLLHNCQUFzQixPQUFPLEtBQUssc0JBQXNCLENBQUMsQ0FBQztBQUFBLElBQzdGO0FBQUEsSUFDQSxJQUFJLHdCQUF3QjtBQUN4QixhQUFPLE1BQU0sS0FBSyxLQUFLLFFBQVEsVUFBVSxFQUFFLElBQUksQ0FBQyxjQUFjLFVBQVUsSUFBSTtBQUFBLElBQ2hGO0FBQUEsSUFDQSxJQUFJLHlCQUF5QjtBQUN6QixhQUFPLE1BQU0sS0FBSyxLQUFLLFVBQVUsS0FBSyxDQUFDO0FBQUEsSUFDM0M7QUFBQSxFQUNKO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxlQUFlLFVBQVU7QUFDMUMsV0FBSyxvQkFBb0IsSUFBSSxrQkFBa0IsU0FBUyxlQUFlLElBQUk7QUFDM0UsV0FBSyxXQUFXO0FBQ2hCLFdBQUssa0JBQWtCLElBQUksU0FBUztBQUFBLElBQ3hDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGtCQUFrQixNQUFNO0FBQUEsSUFDakM7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFdBQUssa0JBQWtCLE1BQU0sUUFBUTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxrQkFBa0IsUUFBUTtBQUFBLElBQ25DO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxrQkFBa0I7QUFBQSxJQUNsQztBQUFBLElBQ0Esd0JBQXdCLFNBQVM7QUFDN0IsV0FBSyxjQUFjLEtBQUsscUJBQXFCLE9BQU8sQ0FBQztBQUFBLElBQ3pEO0FBQUEsSUFDQSw2QkFBNkIsU0FBUztBQUNsQyxZQUFNLENBQUMsaUJBQWlCLGFBQWEsSUFBSSxLQUFLLHdCQUF3QixPQUFPO0FBQzdFLFdBQUssZ0JBQWdCLGVBQWU7QUFDcEMsV0FBSyxjQUFjLGFBQWE7QUFBQSxJQUNwQztBQUFBLElBQ0EsMEJBQTBCLFNBQVM7QUFDL0IsV0FBSyxnQkFBZ0IsS0FBSyxnQkFBZ0IsZ0JBQWdCLE9BQU8sQ0FBQztBQUFBLElBQ3RFO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsYUFBTyxRQUFRLENBQUMsVUFBVSxLQUFLLGFBQWEsS0FBSyxDQUFDO0FBQUEsSUFDdEQ7QUFBQSxJQUNBLGdCQUFnQixRQUFRO0FBQ3BCLGFBQU8sUUFBUSxDQUFDLFVBQVUsS0FBSyxlQUFlLEtBQUssQ0FBQztBQUFBLElBQ3hEO0FBQUEsSUFDQSxhQUFhLE9BQU87QUFDaEIsV0FBSyxTQUFTLGFBQWEsS0FBSztBQUNoQyxXQUFLLGdCQUFnQixJQUFJLE1BQU0sU0FBUyxLQUFLO0FBQUEsSUFDakQ7QUFBQSxJQUNBLGVBQWUsT0FBTztBQUNsQixXQUFLLFNBQVMsZUFBZSxLQUFLO0FBQ2xDLFdBQUssZ0JBQWdCLE9BQU8sTUFBTSxTQUFTLEtBQUs7QUFBQSxJQUNwRDtBQUFBLElBQ0Esd0JBQXdCLFNBQVM7QUFDN0IsWUFBTSxpQkFBaUIsS0FBSyxnQkFBZ0IsZ0JBQWdCLE9BQU87QUFDbkUsWUFBTSxnQkFBZ0IsS0FBSyxxQkFBcUIsT0FBTztBQUN2RCxZQUFNLHNCQUFzQixJQUFJLGdCQUFnQixhQUFhLEVBQUUsVUFBVSxDQUFDLENBQUMsZUFBZSxZQUFZLE1BQU0sQ0FBQyxlQUFlLGVBQWUsWUFBWSxDQUFDO0FBQ3hKLFVBQUksdUJBQXVCLElBQUk7QUFDM0IsZUFBTyxDQUFDLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxNQUNsQixPQUNLO0FBQ0QsZUFBTyxDQUFDLGVBQWUsTUFBTSxtQkFBbUIsR0FBRyxjQUFjLE1BQU0sbUJBQW1CLENBQUM7QUFBQSxNQUMvRjtBQUFBLElBQ0o7QUFBQSxJQUNBLHFCQUFxQixTQUFTO0FBQzFCLFlBQU0sZ0JBQWdCLEtBQUs7QUFDM0IsWUFBTSxjQUFjLFFBQVEsYUFBYSxhQUFhLEtBQUs7QUFDM0QsYUFBTyxpQkFBaUIsYUFBYSxTQUFTLGFBQWE7QUFBQSxJQUMvRDtBQUFBLEVBQ0o7QUFDQSxXQUFTLGlCQUFpQixhQUFhLFNBQVMsZUFBZTtBQUMzRCxXQUFPLFlBQ0YsS0FBSyxFQUNMLE1BQU0sS0FBSyxFQUNYLE9BQU8sQ0FBQyxZQUFZLFFBQVEsTUFBTSxFQUNsQyxJQUFJLENBQUMsU0FBUyxXQUFXLEVBQUUsU0FBUyxlQUFlLFNBQVMsTUFBTSxFQUFFO0FBQUEsRUFDN0U7QUFDQSxXQUFTLElBQUksTUFBTSxPQUFPO0FBQ3RCLFVBQU0sU0FBUyxLQUFLLElBQUksS0FBSyxRQUFRLE1BQU0sTUFBTTtBQUNqRCxXQUFPLE1BQU0sS0FBSyxFQUFFLE9BQU8sR0FBRyxDQUFDLEdBQUcsVUFBVSxDQUFDLEtBQUssS0FBSyxHQUFHLE1BQU0sS0FBSyxDQUFDLENBQUM7QUFBQSxFQUMzRTtBQUNBLFdBQVMsZUFBZSxNQUFNLE9BQU87QUFDakMsV0FBTyxRQUFRLFNBQVMsS0FBSyxTQUFTLE1BQU0sU0FBUyxLQUFLLFdBQVcsTUFBTTtBQUFBLEVBQy9FO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxlQUFlLFVBQVU7QUFDMUMsV0FBSyxvQkFBb0IsSUFBSSxrQkFBa0IsU0FBUyxlQUFlLElBQUk7QUFDM0UsV0FBSyxXQUFXO0FBQ2hCLFdBQUssc0JBQXNCLG9CQUFJLFFBQVE7QUFDdkMsV0FBSyx5QkFBeUIsb0JBQUksUUFBUTtBQUFBLElBQzlDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGtCQUFrQixNQUFNO0FBQUEsSUFDakM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGtCQUFrQixLQUFLO0FBQUEsSUFDaEM7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGtCQUFrQixRQUFRO0FBQUEsSUFDbkM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxrQkFBa0I7QUFBQSxJQUNsQztBQUFBLElBQ0EsSUFBSSxnQkFBZ0I7QUFDaEIsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxhQUFhLE9BQU87QUFDaEIsWUFBTSxFQUFFLFFBQVEsSUFBSTtBQUNwQixZQUFNLEVBQUUsTUFBTSxJQUFJLEtBQUsseUJBQXlCLEtBQUs7QUFDckQsVUFBSSxPQUFPO0FBQ1AsYUFBSyw2QkFBNkIsT0FBTyxFQUFFLElBQUksT0FBTyxLQUFLO0FBQzNELGFBQUssU0FBUyxvQkFBb0IsU0FBUyxLQUFLO0FBQUEsTUFDcEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxlQUFlLE9BQU87QUFDbEIsWUFBTSxFQUFFLFFBQVEsSUFBSTtBQUNwQixZQUFNLEVBQUUsTUFBTSxJQUFJLEtBQUsseUJBQXlCLEtBQUs7QUFDckQsVUFBSSxPQUFPO0FBQ1AsYUFBSyw2QkFBNkIsT0FBTyxFQUFFLE9BQU8sS0FBSztBQUN2RCxhQUFLLFNBQVMsc0JBQXNCLFNBQVMsS0FBSztBQUFBLE1BQ3REO0FBQUEsSUFDSjtBQUFBLElBQ0EseUJBQXlCLE9BQU87QUFDNUIsVUFBSSxjQUFjLEtBQUssb0JBQW9CLElBQUksS0FBSztBQUNwRCxVQUFJLENBQUMsYUFBYTtBQUNkLHNCQUFjLEtBQUssV0FBVyxLQUFLO0FBQ25DLGFBQUssb0JBQW9CLElBQUksT0FBTyxXQUFXO0FBQUEsTUFDbkQ7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsNkJBQTZCLFNBQVM7QUFDbEMsVUFBSSxnQkFBZ0IsS0FBSyx1QkFBdUIsSUFBSSxPQUFPO0FBQzNELFVBQUksQ0FBQyxlQUFlO0FBQ2hCLHdCQUFnQixvQkFBSSxJQUFJO0FBQ3hCLGFBQUssdUJBQXVCLElBQUksU0FBUyxhQUFhO0FBQUEsTUFDMUQ7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsV0FBVyxPQUFPO0FBQ2QsVUFBSTtBQUNBLGNBQU0sUUFBUSxLQUFLLFNBQVMsbUJBQW1CLEtBQUs7QUFDcEQsZUFBTyxFQUFFLE1BQU07QUFBQSxNQUNuQixTQUNPQyxRQUFPO0FBQ1YsZUFBTyxFQUFFLE9BQUFBLE9BQU07QUFBQSxNQUNuQjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsTUFBTSxrQkFBTixNQUFzQjtBQUFBLElBQ2xCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLG1CQUFtQixvQkFBSSxJQUFJO0FBQUEsSUFDcEM7QUFBQSxJQUNBLFFBQVE7QUFDSixVQUFJLENBQUMsS0FBSyxtQkFBbUI7QUFDekIsYUFBSyxvQkFBb0IsSUFBSSxrQkFBa0IsS0FBSyxTQUFTLEtBQUssaUJBQWlCLElBQUk7QUFDdkYsYUFBSyxrQkFBa0IsTUFBTTtBQUFBLE1BQ2pDO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxtQkFBbUI7QUFDeEIsYUFBSyxrQkFBa0IsS0FBSztBQUM1QixlQUFPLEtBQUs7QUFDWixhQUFLLHFCQUFxQjtBQUFBLE1BQzlCO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxrQkFBa0I7QUFDbEIsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxNQUFNLEtBQUssS0FBSyxpQkFBaUIsT0FBTyxDQUFDO0FBQUEsSUFDcEQ7QUFBQSxJQUNBLGNBQWMsUUFBUTtBQUNsQixZQUFNLFVBQVUsSUFBSSxRQUFRLEtBQUssU0FBUyxNQUFNO0FBQ2hELFdBQUssaUJBQWlCLElBQUksUUFBUSxPQUFPO0FBQ3pDLFdBQUssU0FBUyxpQkFBaUIsT0FBTztBQUFBLElBQzFDO0FBQUEsSUFDQSxpQkFBaUIsUUFBUTtBQUNyQixZQUFNLFVBQVUsS0FBSyxpQkFBaUIsSUFBSSxNQUFNO0FBQ2hELFVBQUksU0FBUztBQUNULGFBQUssaUJBQWlCLE9BQU8sTUFBTTtBQUNuQyxhQUFLLFNBQVMsb0JBQW9CLE9BQU87QUFBQSxNQUM3QztBQUFBLElBQ0o7QUFBQSxJQUNBLHVCQUF1QjtBQUNuQixXQUFLLFNBQVMsUUFBUSxDQUFDLFlBQVksS0FBSyxTQUFTLG9CQUFvQixTQUFTLElBQUksQ0FBQztBQUNuRixXQUFLLGlCQUFpQixNQUFNO0FBQUEsSUFDaEM7QUFBQSxJQUNBLG1CQUFtQixPQUFPO0FBQ3RCLFlBQU0sU0FBUyxPQUFPLFNBQVMsT0FBTyxLQUFLLE1BQU07QUFDakQsVUFBSSxPQUFPLGNBQWMsS0FBSyxZQUFZO0FBQ3RDLGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0Esb0JBQW9CLFNBQVMsUUFBUTtBQUNqQyxXQUFLLGNBQWMsTUFBTTtBQUFBLElBQzdCO0FBQUEsSUFDQSxzQkFBc0IsU0FBUyxRQUFRO0FBQ25DLFdBQUssaUJBQWlCLE1BQU07QUFBQSxJQUNoQztBQUFBLEVBQ0o7QUFFQSxNQUFNLGdCQUFOLE1BQW9CO0FBQUEsSUFDaEIsWUFBWSxTQUFTLFVBQVU7QUFDM0IsV0FBSyxVQUFVO0FBQ2YsV0FBSyxXQUFXO0FBQ2hCLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxJQUFJO0FBQ2pFLFdBQUsscUJBQXFCLEtBQUssV0FBVztBQUFBLElBQzlDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUM3QixXQUFLLHVDQUF1QztBQUFBLElBQ2hEO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSw0QkFBNEIsZUFBZTtBQUN2QyxVQUFJLGlCQUFpQixLQUFLLG9CQUFvQjtBQUMxQyxlQUFPLEtBQUssbUJBQW1CLGFBQWEsRUFBRTtBQUFBLE1BQ2xEO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLEtBQUssZUFBZTtBQUNsQyxZQUFNLGFBQWEsS0FBSyxtQkFBbUIsYUFBYTtBQUN4RCxVQUFJLENBQUMsS0FBSyxTQUFTLEdBQUcsR0FBRztBQUNyQixhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxLQUFLLFNBQVMsR0FBRyxDQUFDLEdBQUcsV0FBVyxPQUFPLFdBQVcsWUFBWSxDQUFDO0FBQUEsTUFDckg7QUFBQSxJQUNKO0FBQUEsSUFDQSxzQkFBc0IsT0FBTyxNQUFNLFVBQVU7QUFDekMsWUFBTSxhQUFhLEtBQUssdUJBQXVCLElBQUk7QUFDbkQsVUFBSSxVQUFVO0FBQ1Y7QUFDSixVQUFJLGFBQWEsTUFBTTtBQUNuQixtQkFBVyxXQUFXLE9BQU8sV0FBVyxZQUFZO0FBQUEsTUFDeEQ7QUFDQSxXQUFLLHNCQUFzQixNQUFNLE9BQU8sUUFBUTtBQUFBLElBQ3BEO0FBQUEsSUFDQSxvQkFBb0IsS0FBSyxlQUFlLFVBQVU7QUFDOUMsWUFBTSxhQUFhLEtBQUssdUJBQXVCLEdBQUc7QUFDbEQsVUFBSSxLQUFLLFNBQVMsR0FBRyxHQUFHO0FBQ3BCLGFBQUssc0JBQXNCLEtBQUssV0FBVyxPQUFPLEtBQUssU0FBUyxHQUFHLENBQUMsR0FBRyxRQUFRO0FBQUEsTUFDbkYsT0FDSztBQUNELGFBQUssc0JBQXNCLEtBQUssV0FBVyxPQUFPLFdBQVcsWUFBWSxHQUFHLFFBQVE7QUFBQSxNQUN4RjtBQUFBLElBQ0o7QUFBQSxJQUNBLHlDQUF5QztBQUNyQyxpQkFBVyxFQUFFLEtBQUssTUFBTSxjQUFjLE9BQU8sS0FBSyxLQUFLLGtCQUFrQjtBQUNyRSxZQUFJLGdCQUFnQixVQUFhLENBQUMsS0FBSyxXQUFXLEtBQUssSUFBSSxHQUFHLEdBQUc7QUFDN0QsZUFBSyxzQkFBc0IsTUFBTSxPQUFPLFlBQVksR0FBRyxNQUFTO0FBQUEsUUFDcEU7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLE1BQU0sVUFBVSxhQUFhO0FBQy9DLFlBQU0sb0JBQW9CLEdBQUcsSUFBSTtBQUNqQyxZQUFNLGdCQUFnQixLQUFLLFNBQVMsaUJBQWlCO0FBQ3JELFVBQUksT0FBTyxpQkFBaUIsWUFBWTtBQUNwQyxjQUFNLGFBQWEsS0FBSyx1QkFBdUIsSUFBSTtBQUNuRCxZQUFJO0FBQ0EsZ0JBQU0sUUFBUSxXQUFXLE9BQU8sUUFBUTtBQUN4QyxjQUFJLFdBQVc7QUFDZixjQUFJLGFBQWE7QUFDYix1QkFBVyxXQUFXLE9BQU8sV0FBVztBQUFBLFVBQzVDO0FBQ0Esd0JBQWMsS0FBSyxLQUFLLFVBQVUsT0FBTyxRQUFRO0FBQUEsUUFDckQsU0FDT0EsUUFBTztBQUNWLGNBQUlBLGtCQUFpQixXQUFXO0FBQzVCLFlBQUFBLE9BQU0sVUFBVSxtQkFBbUIsS0FBSyxRQUFRLFVBQVUsSUFBSSxXQUFXLElBQUksT0FBT0EsT0FBTSxPQUFPO0FBQUEsVUFDckc7QUFDQSxnQkFBTUE7QUFBQSxRQUNWO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksbUJBQW1CO0FBQ25CLFlBQU0sRUFBRSxtQkFBbUIsSUFBSTtBQUMvQixhQUFPLE9BQU8sS0FBSyxrQkFBa0IsRUFBRSxJQUFJLENBQUMsUUFBUSxtQkFBbUIsR0FBRyxDQUFDO0FBQUEsSUFDL0U7QUFBQSxJQUNBLElBQUkseUJBQXlCO0FBQ3pCLFlBQU0sY0FBYyxDQUFDO0FBQ3JCLGFBQU8sS0FBSyxLQUFLLGtCQUFrQixFQUFFLFFBQVEsQ0FBQyxRQUFRO0FBQ2xELGNBQU0sYUFBYSxLQUFLLG1CQUFtQixHQUFHO0FBQzlDLG9CQUFZLFdBQVcsSUFBSSxJQUFJO0FBQUEsTUFDbkMsQ0FBQztBQUNELGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxTQUFTLGVBQWU7QUFDcEIsWUFBTSxhQUFhLEtBQUssdUJBQXVCLGFBQWE7QUFDNUQsWUFBTSxnQkFBZ0IsTUFBTSxXQUFXLFdBQVcsSUFBSSxDQUFDO0FBQ3ZELGFBQU8sS0FBSyxTQUFTLGFBQWE7QUFBQSxJQUN0QztBQUFBLEVBQ0o7QUFFQSxNQUFNLGlCQUFOLE1BQXFCO0FBQUEsSUFDakIsWUFBWSxTQUFTLFVBQVU7QUFDM0IsV0FBSyxVQUFVO0FBQ2YsV0FBSyxXQUFXO0FBQ2hCLFdBQUssZ0JBQWdCLElBQUksU0FBUztBQUFBLElBQ3RDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssbUJBQW1CO0FBQ3pCLGFBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxLQUFLLGVBQWUsSUFBSTtBQUNyRixhQUFLLGtCQUFrQixNQUFNO0FBQUEsTUFDakM7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLG1CQUFtQjtBQUN4QixhQUFLLHFCQUFxQjtBQUMxQixhQUFLLGtCQUFrQixLQUFLO0FBQzVCLGVBQU8sS0FBSztBQUFBLE1BQ2hCO0FBQUEsSUFDSjtBQUFBLElBQ0EsYUFBYSxFQUFFLFNBQVMsU0FBUyxLQUFLLEdBQUc7QUFDckMsVUFBSSxLQUFLLE1BQU0sZ0JBQWdCLE9BQU8sR0FBRztBQUNyQyxhQUFLLGNBQWMsU0FBUyxJQUFJO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxlQUFlLEVBQUUsU0FBUyxTQUFTLEtBQUssR0FBRztBQUN2QyxXQUFLLGlCQUFpQixTQUFTLElBQUk7QUFBQSxJQUN2QztBQUFBLElBQ0EsY0FBYyxTQUFTLE1BQU07QUFDekIsVUFBSTtBQUNKLFVBQUksQ0FBQyxLQUFLLGNBQWMsSUFBSSxNQUFNLE9BQU8sR0FBRztBQUN4QyxhQUFLLGNBQWMsSUFBSSxNQUFNLE9BQU87QUFDcEMsU0FBQyxLQUFLLEtBQUssdUJBQXVCLFFBQVEsT0FBTyxTQUFTLFNBQVMsR0FBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLGdCQUFnQixTQUFTLElBQUksQ0FBQztBQUFBLE1BQ2xJO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFNBQVMsTUFBTTtBQUM1QixVQUFJO0FBQ0osVUFBSSxLQUFLLGNBQWMsSUFBSSxNQUFNLE9BQU8sR0FBRztBQUN2QyxhQUFLLGNBQWMsT0FBTyxNQUFNLE9BQU87QUFDdkMsU0FBQyxLQUFLLEtBQUssdUJBQXVCLFFBQVEsT0FBTyxTQUFTLFNBQVMsR0FBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLG1CQUFtQixTQUFTLElBQUksQ0FBQztBQUFBLE1BQ3JJO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCO0FBQ25CLGlCQUFXLFFBQVEsS0FBSyxjQUFjLE1BQU07QUFDeEMsbUJBQVcsV0FBVyxLQUFLLGNBQWMsZ0JBQWdCLElBQUksR0FBRztBQUM1RCxlQUFLLGlCQUFpQixTQUFTLElBQUk7QUFBQSxRQUN2QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLFFBQVEsS0FBSyxRQUFRLFVBQVU7QUFBQSxJQUMxQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLEVBQ0o7QUFFQSxXQUFTLGlDQUFpQyxhQUFhLGNBQWM7QUFDakUsVUFBTSxZQUFZLDJCQUEyQixXQUFXO0FBQ3hELFdBQU8sTUFBTSxLQUFLLFVBQVUsT0FBTyxDQUFDLFFBQVFDLGlCQUFnQjtBQUN4RCw4QkFBd0JBLGNBQWEsWUFBWSxFQUFFLFFBQVEsQ0FBQyxTQUFTLE9BQU8sSUFBSSxJQUFJLENBQUM7QUFDckYsYUFBTztBQUFBLElBQ1gsR0FBRyxvQkFBSSxJQUFJLENBQUMsQ0FBQztBQUFBLEVBQ2pCO0FBQ0EsV0FBUyxpQ0FBaUMsYUFBYSxjQUFjO0FBQ2pFLFVBQU0sWUFBWSwyQkFBMkIsV0FBVztBQUN4RCxXQUFPLFVBQVUsT0FBTyxDQUFDLE9BQU9BLGlCQUFnQjtBQUM1QyxZQUFNLEtBQUssR0FBRyx3QkFBd0JBLGNBQWEsWUFBWSxDQUFDO0FBQ2hFLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsMkJBQTJCLGFBQWE7QUFDN0MsVUFBTSxZQUFZLENBQUM7QUFDbkIsV0FBTyxhQUFhO0FBQ2hCLGdCQUFVLEtBQUssV0FBVztBQUMxQixvQkFBYyxPQUFPLGVBQWUsV0FBVztBQUFBLElBQ25EO0FBQ0EsV0FBTyxVQUFVLFFBQVE7QUFBQSxFQUM3QjtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sTUFBTSxRQUFRLFVBQVUsSUFBSSxhQUFhLENBQUM7QUFBQSxFQUNyRDtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sYUFBYSxPQUFPLEtBQUssVUFBVSxFQUFFLElBQUksQ0FBQyxRQUFRLENBQUMsS0FBSyxXQUFXLEdBQUcsQ0FBQyxDQUFDLElBQUksQ0FBQztBQUFBLEVBQ3hGO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFDbEMsV0FBSyx1QkFBdUIsSUFBSSxTQUFTO0FBQ3pDLFdBQUssc0JBQXNCLG9CQUFJLElBQUk7QUFDbkMsV0FBSyx1QkFBdUIsb0JBQUksSUFBSTtBQUFBLElBQ3hDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssa0JBQWtCLFFBQVEsQ0FBQyxlQUFlO0FBQzNDLGVBQUssK0JBQStCLFVBQVU7QUFDOUMsZUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLFFBQ25ELENBQUM7QUFDRCxhQUFLLFVBQVU7QUFDZixhQUFLLGtCQUFrQixRQUFRLENBQUMsWUFBWSxRQUFRLFFBQVEsQ0FBQztBQUFBLE1BQ2pFO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssb0JBQW9CLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQ2pFLFdBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQUEsSUFDdEU7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssU0FBUztBQUNkLGFBQUssVUFBVTtBQUNmLGFBQUsscUJBQXFCO0FBQzFCLGFBQUssc0JBQXNCO0FBQzNCLGFBQUssdUJBQXVCO0FBQUEsTUFDaEM7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0I7QUFDcEIsVUFBSSxLQUFLLG9CQUFvQixPQUFPLEdBQUc7QUFDbkMsYUFBSyxvQkFBb0IsUUFBUSxDQUFDLGFBQWEsU0FBUyxLQUFLLENBQUM7QUFDOUQsYUFBSyxvQkFBb0IsTUFBTTtBQUFBLE1BQ25DO0FBQUEsSUFDSjtBQUFBLElBQ0EseUJBQXlCO0FBQ3JCLFVBQUksS0FBSyxxQkFBcUIsT0FBTyxHQUFHO0FBQ3BDLGFBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsS0FBSyxDQUFDO0FBQy9ELGFBQUsscUJBQXFCLE1BQU07QUFBQSxNQUNwQztBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixTQUFTLFdBQVcsRUFBRSxXQUFXLEdBQUc7QUFDaEQsWUFBTSxTQUFTLEtBQUssVUFBVSxTQUFTLFVBQVU7QUFDakQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxjQUFjLFFBQVEsU0FBUyxVQUFVO0FBQUEsTUFDbEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxXQUFXLEVBQUUsV0FBVyxHQUFHO0FBQ2xELFlBQU0sU0FBUyxLQUFLLGlCQUFpQixTQUFTLFVBQVU7QUFDeEQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxpQkFBaUIsUUFBUSxTQUFTLFVBQVU7QUFBQSxNQUNyRDtBQUFBLElBQ0o7QUFBQSxJQUNBLHFCQUFxQixTQUFTLEVBQUUsV0FBVyxHQUFHO0FBQzFDLFlBQU0sV0FBVyxLQUFLLFNBQVMsVUFBVTtBQUN6QyxZQUFNLFlBQVksS0FBSyxVQUFVLFNBQVMsVUFBVTtBQUNwRCxZQUFNLHNCQUFzQixRQUFRLFFBQVEsSUFBSSxLQUFLLE9BQU8sbUJBQW1CLEtBQUssVUFBVSxHQUFHO0FBQ2pHLFVBQUksVUFBVTtBQUNWLGVBQU8sYUFBYSx1QkFBdUIsUUFBUSxRQUFRLFFBQVE7QUFBQSxNQUN2RSxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsVUFBVSxlQUFlO0FBQzdDLFlBQU0sYUFBYSxLQUFLLHFDQUFxQyxhQUFhO0FBQzFFLFVBQUksWUFBWTtBQUNaLGFBQUssZ0NBQWdDLFVBQVU7QUFBQSxNQUNuRDtBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixVQUFVLGVBQWU7QUFDbEQsWUFBTSxhQUFhLEtBQUsscUNBQXFDLGFBQWE7QUFDMUUsVUFBSSxZQUFZO0FBQ1osYUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLE1BQ25EO0FBQUEsSUFDSjtBQUFBLElBQ0EsMEJBQTBCLFVBQVUsZUFBZTtBQUMvQyxZQUFNLGFBQWEsS0FBSyxxQ0FBcUMsYUFBYTtBQUMxRSxVQUFJLFlBQVk7QUFDWixhQUFLLGdDQUFnQyxVQUFVO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFFBQVEsU0FBUyxZQUFZO0FBQ3ZDLFVBQUk7QUFDSixVQUFJLENBQUMsS0FBSyxxQkFBcUIsSUFBSSxZQUFZLE9BQU8sR0FBRztBQUNyRCxhQUFLLGNBQWMsSUFBSSxZQUFZLE1BQU07QUFDekMsYUFBSyxxQkFBcUIsSUFBSSxZQUFZLE9BQU87QUFDakQsU0FBQyxLQUFLLEtBQUssb0JBQW9CLElBQUksVUFBVSxPQUFPLFFBQVEsT0FBTyxTQUFTLFNBQVMsR0FBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLGdCQUFnQixRQUFRLFNBQVMsVUFBVSxDQUFDO0FBQUEsTUFDbEs7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsUUFBUSxTQUFTLFlBQVk7QUFDMUMsVUFBSTtBQUNKLFVBQUksS0FBSyxxQkFBcUIsSUFBSSxZQUFZLE9BQU8sR0FBRztBQUNwRCxhQUFLLGNBQWMsT0FBTyxZQUFZLE1BQU07QUFDNUMsYUFBSyxxQkFBcUIsT0FBTyxZQUFZLE9BQU87QUFDcEQsU0FBQyxLQUFLLEtBQUssb0JBQ04sSUFBSSxVQUFVLE9BQU8sUUFBUSxPQUFPLFNBQVMsU0FBUyxHQUFHLE1BQU0sTUFBTSxLQUFLLFNBQVMsbUJBQW1CLFFBQVEsU0FBUyxVQUFVLENBQUM7QUFBQSxNQUMzSTtBQUFBLElBQ0o7QUFBQSxJQUNBLHVCQUF1QjtBQUNuQixpQkFBVyxjQUFjLEtBQUsscUJBQXFCLE1BQU07QUFDckQsbUJBQVcsV0FBVyxLQUFLLHFCQUFxQixnQkFBZ0IsVUFBVSxHQUFHO0FBQ3pFLHFCQUFXLFVBQVUsS0FBSyxjQUFjLGdCQUFnQixVQUFVLEdBQUc7QUFDakUsaUJBQUssaUJBQWlCLFFBQVEsU0FBUyxVQUFVO0FBQUEsVUFDckQ7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdDQUFnQyxZQUFZO0FBQ3hDLFlBQU0sV0FBVyxLQUFLLG9CQUFvQixJQUFJLFVBQVU7QUFDeEQsVUFBSSxVQUFVO0FBQ1YsaUJBQVMsV0FBVyxLQUFLLFNBQVMsVUFBVTtBQUFBLE1BQ2hEO0FBQUEsSUFDSjtBQUFBLElBQ0EsK0JBQStCLFlBQVk7QUFDdkMsWUFBTSxXQUFXLEtBQUssU0FBUyxVQUFVO0FBQ3pDLFlBQU0sbUJBQW1CLElBQUksaUJBQWlCLFNBQVMsTUFBTSxVQUFVLE1BQU0sRUFBRSxXQUFXLENBQUM7QUFDM0YsV0FBSyxvQkFBb0IsSUFBSSxZQUFZLGdCQUFnQjtBQUN6RCx1QkFBaUIsTUFBTTtBQUFBLElBQzNCO0FBQUEsSUFDQSxnQ0FBZ0MsWUFBWTtBQUN4QyxZQUFNLGdCQUFnQixLQUFLLDJCQUEyQixVQUFVO0FBQ2hFLFlBQU0sb0JBQW9CLElBQUksa0JBQWtCLEtBQUssTUFBTSxTQUFTLGVBQWUsSUFBSTtBQUN2RixXQUFLLHFCQUFxQixJQUFJLFlBQVksaUJBQWlCO0FBQzNELHdCQUFrQixNQUFNO0FBQUEsSUFDNUI7QUFBQSxJQUNBLFNBQVMsWUFBWTtBQUNqQixhQUFPLEtBQUssTUFBTSxRQUFRLHlCQUF5QixVQUFVO0FBQUEsSUFDakU7QUFBQSxJQUNBLDJCQUEyQixZQUFZO0FBQ25DLGFBQU8sS0FBSyxNQUFNLE9BQU8sd0JBQXdCLEtBQUssWUFBWSxVQUFVO0FBQUEsSUFDaEY7QUFBQSxJQUNBLHFDQUFxQyxlQUFlO0FBQ2hELGFBQU8sS0FBSyxrQkFBa0IsS0FBSyxDQUFDLGVBQWUsS0FBSywyQkFBMkIsVUFBVSxNQUFNLGFBQWE7QUFBQSxJQUNwSDtBQUFBLElBQ0EsSUFBSSxxQkFBcUI7QUFDckIsWUFBTSxlQUFlLElBQUksU0FBUztBQUNsQyxXQUFLLE9BQU8sUUFBUSxRQUFRLENBQUMsV0FBVztBQUNwQyxjQUFNLGNBQWMsT0FBTyxXQUFXO0FBQ3RDLGNBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLGdCQUFRLFFBQVEsQ0FBQyxXQUFXLGFBQWEsSUFBSSxRQUFRLE9BQU8sVUFBVSxDQUFDO0FBQUEsTUFDM0UsQ0FBQztBQUNELGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLG9CQUFvQjtBQUNwQixhQUFPLEtBQUssbUJBQW1CLGdCQUFnQixLQUFLLFVBQVU7QUFBQSxJQUNsRTtBQUFBLElBQ0EsSUFBSSxpQ0FBaUM7QUFDakMsYUFBTyxLQUFLLG1CQUFtQixnQkFBZ0IsS0FBSyxVQUFVO0FBQUEsSUFDbEU7QUFBQSxJQUNBLElBQUksb0JBQW9CO0FBQ3BCLFlBQU0sY0FBYyxLQUFLO0FBQ3pCLGFBQU8sS0FBSyxPQUFPLFNBQVMsT0FBTyxDQUFDLFlBQVksWUFBWSxTQUFTLFFBQVEsVUFBVSxDQUFDO0FBQUEsSUFDNUY7QUFBQSxJQUNBLFVBQVUsU0FBUyxZQUFZO0FBQzNCLGFBQU8sQ0FBQyxDQUFDLEtBQUssVUFBVSxTQUFTLFVBQVUsS0FBSyxDQUFDLENBQUMsS0FBSyxpQkFBaUIsU0FBUyxVQUFVO0FBQUEsSUFDL0Y7QUFBQSxJQUNBLFVBQVUsU0FBUyxZQUFZO0FBQzNCLGFBQU8sS0FBSyxZQUFZLHFDQUFxQyxTQUFTLFVBQVU7QUFBQSxJQUNwRjtBQUFBLElBQ0EsaUJBQWlCLFNBQVMsWUFBWTtBQUNsQyxhQUFPLEtBQUssY0FBYyxnQkFBZ0IsVUFBVSxFQUFFLEtBQUssQ0FBQyxXQUFXLE9BQU8sWUFBWSxPQUFPO0FBQUEsSUFDckc7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxFQUNKO0FBRUEsTUFBTSxVQUFOLE1BQWM7QUFBQSxJQUNWLFlBQVksUUFBUSxPQUFPO0FBQ3ZCLFdBQUssbUJBQW1CLENBQUMsY0FBYyxTQUFTLENBQUMsTUFBTTtBQUNuRCxjQUFNLEVBQUUsWUFBWSxZQUFZLFFBQVEsSUFBSTtBQUM1QyxpQkFBUyxPQUFPLE9BQU8sRUFBRSxZQUFZLFlBQVksUUFBUSxHQUFHLE1BQU07QUFDbEUsYUFBSyxZQUFZLGlCQUFpQixLQUFLLFlBQVksY0FBYyxNQUFNO0FBQUEsTUFDM0U7QUFDQSxXQUFLLFNBQVM7QUFDZCxXQUFLLFFBQVE7QUFDYixXQUFLLGFBQWEsSUFBSSxPQUFPLHNCQUFzQixJQUFJO0FBQ3ZELFdBQUssa0JBQWtCLElBQUksZ0JBQWdCLE1BQU0sS0FBSyxVQUFVO0FBQ2hFLFdBQUssZ0JBQWdCLElBQUksY0FBYyxNQUFNLEtBQUssVUFBVTtBQUM1RCxXQUFLLGlCQUFpQixJQUFJLGVBQWUsTUFBTSxJQUFJO0FBQ25ELFdBQUssaUJBQWlCLElBQUksZUFBZSxNQUFNLElBQUk7QUFDbkQsVUFBSTtBQUNBLGFBQUssV0FBVyxXQUFXO0FBQzNCLGFBQUssaUJBQWlCLFlBQVk7QUFBQSxNQUN0QyxTQUNPRCxRQUFPO0FBQ1YsYUFBSyxZQUFZQSxRQUFPLHlCQUF5QjtBQUFBLE1BQ3JEO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssZ0JBQWdCLE1BQU07QUFDM0IsV0FBSyxjQUFjLE1BQU07QUFDekIsV0FBSyxlQUFlLE1BQU07QUFDMUIsV0FBSyxlQUFlLE1BQU07QUFDMUIsVUFBSTtBQUNBLGFBQUssV0FBVyxRQUFRO0FBQ3hCLGFBQUssaUJBQWlCLFNBQVM7QUFBQSxNQUNuQyxTQUNPQSxRQUFPO0FBQ1YsYUFBSyxZQUFZQSxRQUFPLHVCQUF1QjtBQUFBLE1BQ25EO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssZUFBZSxRQUFRO0FBQUEsSUFDaEM7QUFBQSxJQUNBLGFBQWE7QUFDVCxVQUFJO0FBQ0EsYUFBSyxXQUFXLFdBQVc7QUFDM0IsYUFBSyxpQkFBaUIsWUFBWTtBQUFBLE1BQ3RDLFNBQ09BLFFBQU87QUFDVixhQUFLLFlBQVlBLFFBQU8sMEJBQTBCO0FBQUEsTUFDdEQ7QUFDQSxXQUFLLGVBQWUsS0FBSztBQUN6QixXQUFLLGVBQWUsS0FBSztBQUN6QixXQUFLLGNBQWMsS0FBSztBQUN4QixXQUFLLGdCQUFnQixLQUFLO0FBQUEsSUFDOUI7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLFlBQVlBLFFBQU8sU0FBUyxTQUFTLENBQUMsR0FBRztBQUNyQyxZQUFNLEVBQUUsWUFBWSxZQUFZLFFBQVEsSUFBSTtBQUM1QyxlQUFTLE9BQU8sT0FBTyxFQUFFLFlBQVksWUFBWSxRQUFRLEdBQUcsTUFBTTtBQUNsRSxXQUFLLFlBQVksWUFBWUEsUUFBTyxTQUFTLE9BQU8sSUFBSSxNQUFNO0FBQUEsSUFDbEU7QUFBQSxJQUNBLGdCQUFnQixTQUFTLE1BQU07QUFDM0IsV0FBSyx1QkFBdUIsR0FBRyxJQUFJLG1CQUFtQixPQUFPO0FBQUEsSUFDakU7QUFBQSxJQUNBLG1CQUFtQixTQUFTLE1BQU07QUFDOUIsV0FBSyx1QkFBdUIsR0FBRyxJQUFJLHNCQUFzQixPQUFPO0FBQUEsSUFDcEU7QUFBQSxJQUNBLGdCQUFnQixRQUFRLFNBQVMsTUFBTTtBQUNuQyxXQUFLLHVCQUF1QixHQUFHLGtCQUFrQixJQUFJLENBQUMsbUJBQW1CLFFBQVEsT0FBTztBQUFBLElBQzVGO0FBQUEsSUFDQSxtQkFBbUIsUUFBUSxTQUFTLE1BQU07QUFDdEMsV0FBSyx1QkFBdUIsR0FBRyxrQkFBa0IsSUFBSSxDQUFDLHNCQUFzQixRQUFRLE9BQU87QUFBQSxJQUMvRjtBQUFBLElBQ0EsdUJBQXVCLGVBQWUsTUFBTTtBQUN4QyxZQUFNLGFBQWEsS0FBSztBQUN4QixVQUFJLE9BQU8sV0FBVyxVQUFVLEtBQUssWUFBWTtBQUM3QyxtQkFBVyxVQUFVLEVBQUUsR0FBRyxJQUFJO0FBQUEsTUFDbEM7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsTUFBTSxhQUFhO0FBQ3hCLFdBQU8sT0FBTyxhQUFhLHFCQUFxQixXQUFXLENBQUM7QUFBQSxFQUNoRTtBQUNBLFdBQVMsT0FBTyxhQUFhLFlBQVk7QUFDckMsVUFBTSxvQkFBb0IsT0FBTyxXQUFXO0FBQzVDLFVBQU0sbUJBQW1CLG9CQUFvQixZQUFZLFdBQVcsVUFBVTtBQUM5RSxXQUFPLGlCQUFpQixrQkFBa0IsV0FBVyxnQkFBZ0I7QUFDckUsV0FBTztBQUFBLEVBQ1g7QUFDQSxXQUFTLHFCQUFxQixhQUFhO0FBQ3ZDLFVBQU0sWUFBWSxpQ0FBaUMsYUFBYSxXQUFXO0FBQzNFLFdBQU8sVUFBVSxPQUFPLENBQUMsbUJBQW1CLGFBQWE7QUFDckQsWUFBTSxhQUFhLFNBQVMsV0FBVztBQUN2QyxpQkFBVyxPQUFPLFlBQVk7QUFDMUIsY0FBTSxhQUFhLGtCQUFrQixHQUFHLEtBQUssQ0FBQztBQUM5QywwQkFBa0IsR0FBRyxJQUFJLE9BQU8sT0FBTyxZQUFZLFdBQVcsR0FBRyxDQUFDO0FBQUEsTUFDdEU7QUFDQSxhQUFPO0FBQUEsSUFDWCxHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLG9CQUFvQixXQUFXLFlBQVk7QUFDaEQsV0FBTyxXQUFXLFVBQVUsRUFBRSxPQUFPLENBQUMsa0JBQWtCLFFBQVE7QUFDNUQsWUFBTSxhQUFhLHNCQUFzQixXQUFXLFlBQVksR0FBRztBQUNuRSxVQUFJLFlBQVk7QUFDWixlQUFPLE9BQU8sa0JBQWtCLEVBQUUsQ0FBQyxHQUFHLEdBQUcsV0FBVyxDQUFDO0FBQUEsTUFDekQ7QUFDQSxhQUFPO0FBQUEsSUFDWCxHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLHNCQUFzQixXQUFXLFlBQVksS0FBSztBQUN2RCxVQUFNLHNCQUFzQixPQUFPLHlCQUF5QixXQUFXLEdBQUc7QUFDMUUsVUFBTSxrQkFBa0IsdUJBQXVCLFdBQVc7QUFDMUQsUUFBSSxDQUFDLGlCQUFpQjtBQUNsQixZQUFNLGFBQWEsT0FBTyx5QkFBeUIsWUFBWSxHQUFHLEVBQUU7QUFDcEUsVUFBSSxxQkFBcUI7QUFDckIsbUJBQVcsTUFBTSxvQkFBb0IsT0FBTyxXQUFXO0FBQ3ZELG1CQUFXLE1BQU0sb0JBQW9CLE9BQU8sV0FBVztBQUFBLE1BQzNEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBQ0EsTUFBTSxjQUFjLE1BQU07QUFDdEIsUUFBSSxPQUFPLE9BQU8seUJBQXlCLFlBQVk7QUFDbkQsYUFBTyxDQUFDLFdBQVcsQ0FBQyxHQUFHLE9BQU8sb0JBQW9CLE1BQU0sR0FBRyxHQUFHLE9BQU8sc0JBQXNCLE1BQU0sQ0FBQztBQUFBLElBQ3RHLE9BQ0s7QUFDRCxhQUFPLE9BQU87QUFBQSxJQUNsQjtBQUFBLEVBQ0osR0FBRztBQUNILE1BQU0sVUFBVSxNQUFNO0FBQ2xCLGFBQVMsa0JBQWtCLGFBQWE7QUFDcEMsZUFBUyxXQUFXO0FBQ2hCLGVBQU8sUUFBUSxVQUFVLGFBQWEsV0FBVyxVQUFVO0FBQUEsTUFDL0Q7QUFDQSxlQUFTLFlBQVksT0FBTyxPQUFPLFlBQVksV0FBVztBQUFBLFFBQ3RELGFBQWEsRUFBRSxPQUFPLFNBQVM7QUFBQSxNQUNuQyxDQUFDO0FBQ0QsY0FBUSxlQUFlLFVBQVUsV0FBVztBQUM1QyxhQUFPO0FBQUEsSUFDWDtBQUNBLGFBQVMsdUJBQXVCO0FBQzVCLFlBQU0sSUFBSSxXQUFZO0FBQ2xCLGFBQUssRUFBRSxLQUFLLElBQUk7QUFBQSxNQUNwQjtBQUNBLFlBQU0sSUFBSSxrQkFBa0IsQ0FBQztBQUM3QixRQUFFLFVBQVUsSUFBSSxXQUFZO0FBQUEsTUFBRTtBQUM5QixhQUFPLElBQUksRUFBRTtBQUFBLElBQ2pCO0FBQ0EsUUFBSTtBQUNBLDJCQUFxQjtBQUNyQixhQUFPO0FBQUEsSUFDWCxTQUNPQSxRQUFPO0FBQ1YsYUFBTyxDQUFDLGdCQUFnQixNQUFNLGlCQUFpQixZQUFZO0FBQUEsTUFDM0Q7QUFBQSxJQUNKO0FBQUEsRUFDSixHQUFHO0FBRUgsV0FBUyxnQkFBZ0IsWUFBWTtBQUNqQyxXQUFPO0FBQUEsTUFDSCxZQUFZLFdBQVc7QUFBQSxNQUN2Qix1QkFBdUIsTUFBTSxXQUFXLHFCQUFxQjtBQUFBLElBQ2pFO0FBQUEsRUFDSjtBQUVBLE1BQU0sU0FBTixNQUFhO0FBQUEsSUFDVCxZQUFZLGFBQWEsWUFBWTtBQUNqQyxXQUFLLGNBQWM7QUFDbkIsV0FBSyxhQUFhLGdCQUFnQixVQUFVO0FBQzVDLFdBQUssa0JBQWtCLG9CQUFJLFFBQVE7QUFDbkMsV0FBSyxvQkFBb0Isb0JBQUksSUFBSTtBQUFBLElBQ3JDO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssV0FBVztBQUFBLElBQzNCO0FBQUEsSUFDQSxJQUFJLHdCQUF3QjtBQUN4QixhQUFPLEtBQUssV0FBVztBQUFBLElBQzNCO0FBQUEsSUFDQSxJQUFJLFdBQVc7QUFDWCxhQUFPLE1BQU0sS0FBSyxLQUFLLGlCQUFpQjtBQUFBLElBQzVDO0FBQUEsSUFDQSx1QkFBdUIsT0FBTztBQUMxQixZQUFNLFVBQVUsS0FBSyxxQkFBcUIsS0FBSztBQUMvQyxXQUFLLGtCQUFrQixJQUFJLE9BQU87QUFDbEMsY0FBUSxRQUFRO0FBQUEsSUFDcEI7QUFBQSxJQUNBLDBCQUEwQixPQUFPO0FBQzdCLFlBQU0sVUFBVSxLQUFLLGdCQUFnQixJQUFJLEtBQUs7QUFDOUMsVUFBSSxTQUFTO0FBQ1QsYUFBSyxrQkFBa0IsT0FBTyxPQUFPO0FBQ3JDLGdCQUFRLFdBQVc7QUFBQSxNQUN2QjtBQUFBLElBQ0o7QUFBQSxJQUNBLHFCQUFxQixPQUFPO0FBQ3hCLFVBQUksVUFBVSxLQUFLLGdCQUFnQixJQUFJLEtBQUs7QUFDNUMsVUFBSSxDQUFDLFNBQVM7QUFDVixrQkFBVSxJQUFJLFFBQVEsTUFBTSxLQUFLO0FBQ2pDLGFBQUssZ0JBQWdCLElBQUksT0FBTyxPQUFPO0FBQUEsTUFDM0M7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFdBQU4sTUFBZTtBQUFBLElBQ1gsWUFBWSxPQUFPO0FBQ2YsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLElBQUksTUFBTTtBQUNOLGFBQU8sS0FBSyxLQUFLLElBQUksS0FBSyxXQUFXLElBQUksQ0FBQztBQUFBLElBQzlDO0FBQUEsSUFDQSxJQUFJLE1BQU07QUFDTixhQUFPLEtBQUssT0FBTyxJQUFJLEVBQUUsQ0FBQztBQUFBLElBQzlCO0FBQUEsSUFDQSxPQUFPLE1BQU07QUFDVCxZQUFNLGNBQWMsS0FBSyxLQUFLLElBQUksS0FBSyxXQUFXLElBQUksQ0FBQyxLQUFLO0FBQzVELGFBQU8sU0FBUyxXQUFXO0FBQUEsSUFDL0I7QUFBQSxJQUNBLGlCQUFpQixNQUFNO0FBQ25CLGFBQU8sS0FBSyxLQUFLLHVCQUF1QixLQUFLLFdBQVcsSUFBSSxDQUFDO0FBQUEsSUFDakU7QUFBQSxJQUNBLFdBQVcsTUFBTTtBQUNiLGFBQU8sR0FBRyxJQUFJO0FBQUEsSUFDbEI7QUFBQSxJQUNBLElBQUksT0FBTztBQUNQLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxVQUFOLE1BQWM7QUFBQSxJQUNWLFlBQVksT0FBTztBQUNmLFdBQUssUUFBUTtBQUFBLElBQ2pCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLEtBQUs7QUFDTCxZQUFNLE9BQU8sS0FBSyx1QkFBdUIsR0FBRztBQUM1QyxhQUFPLEtBQUssUUFBUSxhQUFhLElBQUk7QUFBQSxJQUN6QztBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixZQUFNLE9BQU8sS0FBSyx1QkFBdUIsR0FBRztBQUM1QyxXQUFLLFFBQVEsYUFBYSxNQUFNLEtBQUs7QUFDckMsYUFBTyxLQUFLLElBQUksR0FBRztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLEtBQUs7QUFDTCxZQUFNLE9BQU8sS0FBSyx1QkFBdUIsR0FBRztBQUM1QyxhQUFPLEtBQUssUUFBUSxhQUFhLElBQUk7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTyxLQUFLO0FBQ1IsVUFBSSxLQUFLLElBQUksR0FBRyxHQUFHO0FBQ2YsY0FBTSxPQUFPLEtBQUssdUJBQXVCLEdBQUc7QUFDNUMsYUFBSyxRQUFRLGdCQUFnQixJQUFJO0FBQ2pDLGVBQU87QUFBQSxNQUNYLE9BQ0s7QUFDRCxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLHVCQUF1QixLQUFLO0FBQ3hCLGFBQU8sUUFBUSxLQUFLLFVBQVUsSUFBSSxVQUFVLEdBQUcsQ0FBQztBQUFBLElBQ3BEO0FBQUEsRUFDSjtBQUVBLE1BQU0sUUFBTixNQUFZO0FBQUEsSUFDUixZQUFZLFFBQVE7QUFDaEIsV0FBSyxxQkFBcUIsb0JBQUksUUFBUTtBQUN0QyxXQUFLLFNBQVM7QUFBQSxJQUNsQjtBQUFBLElBQ0EsS0FBSyxRQUFRLEtBQUssU0FBUztBQUN2QixVQUFJLGFBQWEsS0FBSyxtQkFBbUIsSUFBSSxNQUFNO0FBQ25ELFVBQUksQ0FBQyxZQUFZO0FBQ2IscUJBQWEsb0JBQUksSUFBSTtBQUNyQixhQUFLLG1CQUFtQixJQUFJLFFBQVEsVUFBVTtBQUFBLE1BQ2xEO0FBQ0EsVUFBSSxDQUFDLFdBQVcsSUFBSSxHQUFHLEdBQUc7QUFDdEIsbUJBQVcsSUFBSSxHQUFHO0FBQ2xCLGFBQUssT0FBTyxLQUFLLFNBQVMsTUFBTTtBQUFBLE1BQ3BDO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLDRCQUE0QixlQUFlLE9BQU87QUFDdkQsV0FBTyxJQUFJLGFBQWEsTUFBTSxLQUFLO0FBQUEsRUFDdkM7QUFFQSxNQUFNLFlBQU4sTUFBZ0I7QUFBQSxJQUNaLFlBQVksT0FBTztBQUNmLFdBQUssUUFBUTtBQUFBLElBQ2pCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssS0FBSyxVQUFVLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsUUFBUSxhQUFhO0FBQ2pCLGFBQU8sWUFBWSxPQUFPLENBQUMsUUFBUSxlQUFlLFVBQVUsS0FBSyxXQUFXLFVBQVUsS0FBSyxLQUFLLGlCQUFpQixVQUFVLEdBQUcsTUFBUztBQUFBLElBQzNJO0FBQUEsSUFDQSxXQUFXLGFBQWE7QUFDcEIsYUFBTyxZQUFZLE9BQU8sQ0FBQyxTQUFTLGVBQWU7QUFBQSxRQUMvQyxHQUFHO0FBQUEsUUFDSCxHQUFHLEtBQUssZUFBZSxVQUFVO0FBQUEsUUFDakMsR0FBRyxLQUFLLHFCQUFxQixVQUFVO0FBQUEsTUFDM0MsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUNUO0FBQUEsSUFDQSxXQUFXLFlBQVk7QUFDbkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsYUFBTyxLQUFLLE1BQU0sWUFBWSxRQUFRO0FBQUEsSUFDMUM7QUFBQSxJQUNBLGVBQWUsWUFBWTtBQUN2QixZQUFNLFdBQVcsS0FBSyx5QkFBeUIsVUFBVTtBQUN6RCxhQUFPLEtBQUssTUFBTSxnQkFBZ0IsUUFBUTtBQUFBLElBQzlDO0FBQUEsSUFDQSx5QkFBeUIsWUFBWTtBQUNqQyxZQUFNLGdCQUFnQixLQUFLLE9BQU8sd0JBQXdCLEtBQUssVUFBVTtBQUN6RSxhQUFPLDRCQUE0QixlQUFlLFVBQVU7QUFBQSxJQUNoRTtBQUFBLElBQ0EsaUJBQWlCLFlBQVk7QUFDekIsWUFBTSxXQUFXLEtBQUssK0JBQStCLFVBQVU7QUFDL0QsYUFBTyxLQUFLLFVBQVUsS0FBSyxNQUFNLFlBQVksUUFBUSxHQUFHLFVBQVU7QUFBQSxJQUN0RTtBQUFBLElBQ0EscUJBQXFCLFlBQVk7QUFDN0IsWUFBTSxXQUFXLEtBQUssK0JBQStCLFVBQVU7QUFDL0QsYUFBTyxLQUFLLE1BQU0sZ0JBQWdCLFFBQVEsRUFBRSxJQUFJLENBQUMsWUFBWSxLQUFLLFVBQVUsU0FBUyxVQUFVLENBQUM7QUFBQSxJQUNwRztBQUFBLElBQ0EsK0JBQStCLFlBQVk7QUFDdkMsWUFBTSxtQkFBbUIsR0FBRyxLQUFLLFVBQVUsSUFBSSxVQUFVO0FBQ3pELGFBQU8sNEJBQTRCLEtBQUssT0FBTyxpQkFBaUIsZ0JBQWdCO0FBQUEsSUFDcEY7QUFBQSxJQUNBLFVBQVUsU0FBUyxZQUFZO0FBQzNCLFVBQUksU0FBUztBQUNULGNBQU0sRUFBRSxXQUFXLElBQUk7QUFDdkIsY0FBTSxnQkFBZ0IsS0FBSyxPQUFPO0FBQ2xDLGNBQU0sdUJBQXVCLEtBQUssT0FBTyx3QkFBd0IsVUFBVTtBQUMzRSxhQUFLLE1BQU0sS0FBSyxTQUFTLFVBQVUsVUFBVSxJQUFJLGtCQUFrQixhQUFhLEtBQUssVUFBVSxJQUFJLFVBQVUsVUFBVSxvQkFBb0IsS0FBSyxVQUFVLFVBQy9JLGFBQWEsK0VBQStFO0FBQUEsTUFDM0c7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFlBQU4sTUFBZ0I7QUFBQSxJQUNaLFlBQVksT0FBTyxtQkFBbUI7QUFDbEMsV0FBSyxRQUFRO0FBQ2IsV0FBSyxvQkFBb0I7QUFBQSxJQUM3QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxZQUFZO0FBQ1osYUFBTyxLQUFLLEtBQUssVUFBVSxLQUFLO0FBQUEsSUFDcEM7QUFBQSxJQUNBLFFBQVEsYUFBYTtBQUNqQixhQUFPLFlBQVksT0FBTyxDQUFDLFFBQVEsZUFBZSxVQUFVLEtBQUssV0FBVyxVQUFVLEdBQUcsTUFBUztBQUFBLElBQ3RHO0FBQUEsSUFDQSxXQUFXLGFBQWE7QUFDcEIsYUFBTyxZQUFZLE9BQU8sQ0FBQyxTQUFTLGVBQWUsQ0FBQyxHQUFHLFNBQVMsR0FBRyxLQUFLLGVBQWUsVUFBVSxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsSUFDM0c7QUFBQSxJQUNBLHlCQUF5QixZQUFZO0FBQ2pDLFlBQU0sZ0JBQWdCLEtBQUssT0FBTyx3QkFBd0IsS0FBSyxZQUFZLFVBQVU7QUFDckYsYUFBTyxLQUFLLGtCQUFrQixhQUFhLGFBQWE7QUFBQSxJQUM1RDtBQUFBLElBQ0EsV0FBVyxZQUFZO0FBQ25CLFlBQU0sV0FBVyxLQUFLLHlCQUF5QixVQUFVO0FBQ3pELFVBQUk7QUFDQSxlQUFPLEtBQUssWUFBWSxVQUFVLFVBQVU7QUFBQSxJQUNwRDtBQUFBLElBQ0EsZUFBZSxZQUFZO0FBQ3ZCLFlBQU0sV0FBVyxLQUFLLHlCQUF5QixVQUFVO0FBQ3pELGFBQU8sV0FBVyxLQUFLLGdCQUFnQixVQUFVLFVBQVUsSUFBSSxDQUFDO0FBQUEsSUFDcEU7QUFBQSxJQUNBLFlBQVksVUFBVSxZQUFZO0FBQzlCLFlBQU0sV0FBVyxLQUFLLE1BQU0sY0FBYyxRQUFRO0FBQ2xELGFBQU8sU0FBUyxPQUFPLENBQUMsWUFBWSxLQUFLLGVBQWUsU0FBUyxVQUFVLFVBQVUsQ0FBQyxFQUFFLENBQUM7QUFBQSxJQUM3RjtBQUFBLElBQ0EsZ0JBQWdCLFVBQVUsWUFBWTtBQUNsQyxZQUFNLFdBQVcsS0FBSyxNQUFNLGNBQWMsUUFBUTtBQUNsRCxhQUFPLFNBQVMsT0FBTyxDQUFDLFlBQVksS0FBSyxlQUFlLFNBQVMsVUFBVSxVQUFVLENBQUM7QUFBQSxJQUMxRjtBQUFBLElBQ0EsZUFBZSxTQUFTLFVBQVUsWUFBWTtBQUMxQyxZQUFNLHNCQUFzQixRQUFRLGFBQWEsS0FBSyxNQUFNLE9BQU8sbUJBQW1CLEtBQUs7QUFDM0YsYUFBTyxRQUFRLFFBQVEsUUFBUSxLQUFLLG9CQUFvQixNQUFNLEdBQUcsRUFBRSxTQUFTLFVBQVU7QUFBQSxJQUMxRjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFFBQU4sTUFBTSxPQUFNO0FBQUEsSUFDUixZQUFZLFFBQVEsU0FBUyxZQUFZLFFBQVE7QUFDN0MsV0FBSyxVQUFVLElBQUksVUFBVSxJQUFJO0FBQ2pDLFdBQUssVUFBVSxJQUFJLFNBQVMsSUFBSTtBQUNoQyxXQUFLLE9BQU8sSUFBSSxRQUFRLElBQUk7QUFDNUIsV0FBSyxrQkFBa0IsQ0FBQ0UsYUFBWTtBQUNoQyxlQUFPQSxTQUFRLFFBQVEsS0FBSyxrQkFBa0IsTUFBTSxLQUFLO0FBQUEsTUFDN0Q7QUFDQSxXQUFLLFNBQVM7QUFDZCxXQUFLLFVBQVU7QUFDZixXQUFLLGFBQWE7QUFDbEIsV0FBSyxRQUFRLElBQUksTUFBTSxNQUFNO0FBQzdCLFdBQUssVUFBVSxJQUFJLFVBQVUsS0FBSyxlQUFlLE9BQU87QUFBQSxJQUM1RDtBQUFBLElBQ0EsWUFBWSxVQUFVO0FBQ2xCLGFBQU8sS0FBSyxRQUFRLFFBQVEsUUFBUSxJQUFJLEtBQUssVUFBVSxLQUFLLGNBQWMsUUFBUSxFQUFFLEtBQUssS0FBSyxlQUFlO0FBQUEsSUFDakg7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLGFBQU87QUFBQSxRQUNILEdBQUksS0FBSyxRQUFRLFFBQVEsUUFBUSxJQUFJLENBQUMsS0FBSyxPQUFPLElBQUksQ0FBQztBQUFBLFFBQ3ZELEdBQUcsS0FBSyxjQUFjLFFBQVEsRUFBRSxPQUFPLEtBQUssZUFBZTtBQUFBLE1BQy9EO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxVQUFVO0FBQ3BCLGFBQU8sTUFBTSxLQUFLLEtBQUssUUFBUSxpQkFBaUIsUUFBUSxDQUFDO0FBQUEsSUFDN0Q7QUFBQSxJQUNBLElBQUkscUJBQXFCO0FBQ3JCLGFBQU8sNEJBQTRCLEtBQUssT0FBTyxxQkFBcUIsS0FBSyxVQUFVO0FBQUEsSUFDdkY7QUFBQSxJQUNBLElBQUksa0JBQWtCO0FBQ2xCLGFBQU8sS0FBSyxZQUFZLFNBQVM7QUFBQSxJQUNyQztBQUFBLElBQ0EsSUFBSSxnQkFBZ0I7QUFDaEIsYUFBTyxLQUFLLGtCQUNOLE9BQ0EsSUFBSSxPQUFNLEtBQUssUUFBUSxTQUFTLGlCQUFpQixLQUFLLFlBQVksS0FBSyxNQUFNLE1BQU07QUFBQSxJQUM3RjtBQUFBLEVBQ0o7QUFFQSxNQUFNLGdCQUFOLE1BQW9CO0FBQUEsSUFDaEIsWUFBWSxTQUFTLFFBQVEsVUFBVTtBQUNuQyxXQUFLLFVBQVU7QUFDZixXQUFLLFNBQVM7QUFDZCxXQUFLLFdBQVc7QUFDaEIsV0FBSyxvQkFBb0IsSUFBSSxrQkFBa0IsS0FBSyxTQUFTLEtBQUsscUJBQXFCLElBQUk7QUFDM0YsV0FBSyw4QkFBOEIsb0JBQUksUUFBUTtBQUMvQyxXQUFLLHVCQUF1QixvQkFBSSxRQUFRO0FBQUEsSUFDNUM7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGtCQUFrQixNQUFNO0FBQUEsSUFDakM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGtCQUFrQixLQUFLO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksc0JBQXNCO0FBQ3RCLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLG1CQUFtQixPQUFPO0FBQ3RCLFlBQU0sRUFBRSxTQUFTLFNBQVMsV0FBVyxJQUFJO0FBQ3pDLGFBQU8sS0FBSyxrQ0FBa0MsU0FBUyxVQUFVO0FBQUEsSUFDckU7QUFBQSxJQUNBLGtDQUFrQyxTQUFTLFlBQVk7QUFDbkQsWUFBTSxxQkFBcUIsS0FBSyxrQ0FBa0MsT0FBTztBQUN6RSxVQUFJLFFBQVEsbUJBQW1CLElBQUksVUFBVTtBQUM3QyxVQUFJLENBQUMsT0FBTztBQUNSLGdCQUFRLEtBQUssU0FBUyxtQ0FBbUMsU0FBUyxVQUFVO0FBQzVFLDJCQUFtQixJQUFJLFlBQVksS0FBSztBQUFBLE1BQzVDO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLG9CQUFvQixTQUFTLE9BQU87QUFDaEMsWUFBTSxrQkFBa0IsS0FBSyxxQkFBcUIsSUFBSSxLQUFLLEtBQUssS0FBSztBQUNyRSxXQUFLLHFCQUFxQixJQUFJLE9BQU8sY0FBYztBQUNuRCxVQUFJLGtCQUFrQixHQUFHO0FBQ3JCLGFBQUssU0FBUyxlQUFlLEtBQUs7QUFBQSxNQUN0QztBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixTQUFTLE9BQU87QUFDbEMsWUFBTSxpQkFBaUIsS0FBSyxxQkFBcUIsSUFBSSxLQUFLO0FBQzFELFVBQUksZ0JBQWdCO0FBQ2hCLGFBQUsscUJBQXFCLElBQUksT0FBTyxpQkFBaUIsQ0FBQztBQUN2RCxZQUFJLGtCQUFrQixHQUFHO0FBQ3JCLGVBQUssU0FBUyxrQkFBa0IsS0FBSztBQUFBLFFBQ3pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGtDQUFrQyxTQUFTO0FBQ3ZDLFVBQUkscUJBQXFCLEtBQUssNEJBQTRCLElBQUksT0FBTztBQUNyRSxVQUFJLENBQUMsb0JBQW9CO0FBQ3JCLDZCQUFxQixvQkFBSSxJQUFJO0FBQzdCLGFBQUssNEJBQTRCLElBQUksU0FBUyxrQkFBa0I7QUFBQSxNQUNwRTtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUVBLE1BQU0sU0FBTixNQUFhO0FBQUEsSUFDVCxZQUFZLGFBQWE7QUFDckIsV0FBSyxjQUFjO0FBQ25CLFdBQUssZ0JBQWdCLElBQUksY0FBYyxLQUFLLFNBQVMsS0FBSyxRQUFRLElBQUk7QUFDdEUsV0FBSyxxQkFBcUIsSUFBSSxTQUFTO0FBQ3ZDLFdBQUssc0JBQXNCLG9CQUFJLElBQUk7QUFBQSxJQUN2QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxzQkFBc0I7QUFDdEIsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxNQUFNLEtBQUssS0FBSyxvQkFBb0IsT0FBTyxDQUFDO0FBQUEsSUFDdkQ7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sS0FBSyxRQUFRLE9BQU8sQ0FBQyxVQUFVLFdBQVcsU0FBUyxPQUFPLE9BQU8sUUFBUSxHQUFHLENBQUMsQ0FBQztBQUFBLElBQ3pGO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxjQUFjLE1BQU07QUFBQSxJQUM3QjtBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssY0FBYyxLQUFLO0FBQUEsSUFDNUI7QUFBQSxJQUNBLGVBQWUsWUFBWTtBQUN2QixXQUFLLGlCQUFpQixXQUFXLFVBQVU7QUFDM0MsWUFBTSxTQUFTLElBQUksT0FBTyxLQUFLLGFBQWEsVUFBVTtBQUN0RCxXQUFLLGNBQWMsTUFBTTtBQUN6QixZQUFNLFlBQVksV0FBVyxzQkFBc0I7QUFDbkQsVUFBSSxXQUFXO0FBQ1gsa0JBQVUsS0FBSyxXQUFXLHVCQUF1QixXQUFXLFlBQVksS0FBSyxXQUFXO0FBQUEsTUFDNUY7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsWUFBWTtBQUN6QixZQUFNLFNBQVMsS0FBSyxvQkFBb0IsSUFBSSxVQUFVO0FBQ3RELFVBQUksUUFBUTtBQUNSLGFBQUssaUJBQWlCLE1BQU07QUFBQSxNQUNoQztBQUFBLElBQ0o7QUFBQSxJQUNBLGtDQUFrQyxTQUFTLFlBQVk7QUFDbkQsWUFBTSxTQUFTLEtBQUssb0JBQW9CLElBQUksVUFBVTtBQUN0RCxVQUFJLFFBQVE7QUFDUixlQUFPLE9BQU8sU0FBUyxLQUFLLENBQUMsWUFBWSxRQUFRLFdBQVcsT0FBTztBQUFBLE1BQ3ZFO0FBQUEsSUFDSjtBQUFBLElBQ0EsNkNBQTZDLFNBQVMsWUFBWTtBQUM5RCxZQUFNLFFBQVEsS0FBSyxjQUFjLGtDQUFrQyxTQUFTLFVBQVU7QUFDdEYsVUFBSSxPQUFPO0FBQ1AsYUFBSyxjQUFjLG9CQUFvQixNQUFNLFNBQVMsS0FBSztBQUFBLE1BQy9ELE9BQ0s7QUFDRCxnQkFBUSxNQUFNLGtEQUFrRCxVQUFVLGtCQUFrQixPQUFPO0FBQUEsTUFDdkc7QUFBQSxJQUNKO0FBQUEsSUFDQSxZQUFZRixRQUFPLFNBQVMsUUFBUTtBQUNoQyxXQUFLLFlBQVksWUFBWUEsUUFBTyxTQUFTLE1BQU07QUFBQSxJQUN2RDtBQUFBLElBQ0EsbUNBQW1DLFNBQVMsWUFBWTtBQUNwRCxhQUFPLElBQUksTUFBTSxLQUFLLFFBQVEsU0FBUyxZQUFZLEtBQUssTUFBTTtBQUFBLElBQ2xFO0FBQUEsSUFDQSxlQUFlLE9BQU87QUFDbEIsV0FBSyxtQkFBbUIsSUFBSSxNQUFNLFlBQVksS0FBSztBQUNuRCxZQUFNLFNBQVMsS0FBSyxvQkFBb0IsSUFBSSxNQUFNLFVBQVU7QUFDNUQsVUFBSSxRQUFRO0FBQ1IsZUFBTyx1QkFBdUIsS0FBSztBQUFBLE1BQ3ZDO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLE9BQU87QUFDckIsV0FBSyxtQkFBbUIsT0FBTyxNQUFNLFlBQVksS0FBSztBQUN0RCxZQUFNLFNBQVMsS0FBSyxvQkFBb0IsSUFBSSxNQUFNLFVBQVU7QUFDNUQsVUFBSSxRQUFRO0FBQ1IsZUFBTywwQkFBMEIsS0FBSztBQUFBLE1BQzFDO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxRQUFRO0FBQ2xCLFdBQUssb0JBQW9CLElBQUksT0FBTyxZQUFZLE1BQU07QUFDdEQsWUFBTSxTQUFTLEtBQUssbUJBQW1CLGdCQUFnQixPQUFPLFVBQVU7QUFDeEUsYUFBTyxRQUFRLENBQUMsVUFBVSxPQUFPLHVCQUF1QixLQUFLLENBQUM7QUFBQSxJQUNsRTtBQUFBLElBQ0EsaUJBQWlCLFFBQVE7QUFDckIsV0FBSyxvQkFBb0IsT0FBTyxPQUFPLFVBQVU7QUFDakQsWUFBTSxTQUFTLEtBQUssbUJBQW1CLGdCQUFnQixPQUFPLFVBQVU7QUFDeEUsYUFBTyxRQUFRLENBQUMsVUFBVSxPQUFPLDBCQUEwQixLQUFLLENBQUM7QUFBQSxJQUNyRTtBQUFBLEVBQ0o7QUFFQSxNQUFNLGdCQUFnQjtBQUFBLElBQ2xCLHFCQUFxQjtBQUFBLElBQ3JCLGlCQUFpQjtBQUFBLElBQ2pCLGlCQUFpQjtBQUFBLElBQ2pCLHlCQUF5QixDQUFDLGVBQWUsUUFBUSxVQUFVO0FBQUEsSUFDM0QseUJBQXlCLENBQUMsWUFBWSxXQUFXLFFBQVEsVUFBVSxJQUFJLE1BQU07QUFBQSxJQUM3RSxhQUFhLE9BQU8sT0FBTyxPQUFPLE9BQU8sRUFBRSxPQUFPLFNBQVMsS0FBSyxPQUFPLEtBQUssVUFBVSxPQUFPLEtBQUssSUFBSSxXQUFXLE1BQU0sYUFBYSxNQUFNLGFBQWEsT0FBTyxjQUFjLE1BQU0sUUFBUSxLQUFLLE9BQU8sU0FBUyxVQUFVLFdBQVcsV0FBVyxHQUFHLGtCQUFrQiw2QkFBNkIsTUFBTSxFQUFFLEVBQUUsSUFBSSxDQUFDLE1BQU0sQ0FBQyxHQUFHLENBQUMsQ0FBQyxDQUFDLENBQUMsR0FBRyxrQkFBa0IsYUFBYSxNQUFNLEVBQUUsRUFBRSxJQUFJLENBQUMsTUFBTSxDQUFDLEdBQUcsQ0FBQyxDQUFDLENBQUMsQ0FBQztBQUFBLEVBQ2pZO0FBQ0EsV0FBUyxrQkFBa0IsT0FBTztBQUM5QixXQUFPLE1BQU0sT0FBTyxDQUFDLE1BQU0sQ0FBQyxHQUFHLENBQUMsTUFBTyxPQUFPLE9BQU8sT0FBTyxPQUFPLENBQUMsR0FBRyxJQUFJLEdBQUcsRUFBRSxDQUFDLENBQUMsR0FBRyxFQUFFLENBQUMsR0FBSSxDQUFDLENBQUM7QUFBQSxFQUNsRztBQUVBLE1BQU0sY0FBTixNQUFrQjtBQUFBLElBQ2QsWUFBWSxVQUFVLFNBQVMsaUJBQWlCLFNBQVMsZUFBZTtBQUNwRSxXQUFLLFNBQVM7QUFDZCxXQUFLLFFBQVE7QUFDYixXQUFLLG1CQUFtQixDQUFDLFlBQVksY0FBYyxTQUFTLENBQUMsTUFBTTtBQUMvRCxZQUFJLEtBQUssT0FBTztBQUNaLGVBQUssb0JBQW9CLFlBQVksY0FBYyxNQUFNO0FBQUEsUUFDN0Q7QUFBQSxNQUNKO0FBQ0EsV0FBSyxVQUFVO0FBQ2YsV0FBSyxTQUFTO0FBQ2QsV0FBSyxhQUFhLElBQUksV0FBVyxJQUFJO0FBQ3JDLFdBQUssU0FBUyxJQUFJLE9BQU8sSUFBSTtBQUM3QixXQUFLLDBCQUEwQixPQUFPLE9BQU8sQ0FBQyxHQUFHLDhCQUE4QjtBQUFBLElBQ25GO0FBQUEsSUFDQSxPQUFPLE1BQU0sU0FBUyxRQUFRO0FBQzFCLFlBQU0sY0FBYyxJQUFJLEtBQUssU0FBUyxNQUFNO0FBQzVDLGtCQUFZLE1BQU07QUFDbEIsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLE1BQU0sUUFBUTtBQUNWLFlBQU0sU0FBUztBQUNmLFdBQUssaUJBQWlCLGVBQWUsVUFBVTtBQUMvQyxXQUFLLFdBQVcsTUFBTTtBQUN0QixXQUFLLE9BQU8sTUFBTTtBQUNsQixXQUFLLGlCQUFpQixlQUFlLE9BQU87QUFBQSxJQUNoRDtBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssaUJBQWlCLGVBQWUsVUFBVTtBQUMvQyxXQUFLLFdBQVcsS0FBSztBQUNyQixXQUFLLE9BQU8sS0FBSztBQUNqQixXQUFLLGlCQUFpQixlQUFlLE1BQU07QUFBQSxJQUMvQztBQUFBLElBQ0EsU0FBUyxZQUFZLHVCQUF1QjtBQUN4QyxXQUFLLEtBQUssRUFBRSxZQUFZLHNCQUFzQixDQUFDO0FBQUEsSUFDbkQ7QUFBQSxJQUNBLHFCQUFxQixNQUFNLFFBQVE7QUFDL0IsV0FBSyx3QkFBd0IsSUFBSSxJQUFJO0FBQUEsSUFDekM7QUFBQSxJQUNBLEtBQUssU0FBUyxNQUFNO0FBQ2hCLFlBQU0sY0FBYyxNQUFNLFFBQVEsSUFBSSxJQUFJLE9BQU8sQ0FBQyxNQUFNLEdBQUcsSUFBSTtBQUMvRCxrQkFBWSxRQUFRLENBQUMsZUFBZTtBQUNoQyxZQUFJLFdBQVcsc0JBQXNCLFlBQVk7QUFDN0MsZUFBSyxPQUFPLGVBQWUsVUFBVTtBQUFBLFFBQ3pDO0FBQUEsTUFDSixDQUFDO0FBQUEsSUFDTDtBQUFBLElBQ0EsT0FBTyxTQUFTLE1BQU07QUFDbEIsWUFBTSxjQUFjLE1BQU0sUUFBUSxJQUFJLElBQUksT0FBTyxDQUFDLE1BQU0sR0FBRyxJQUFJO0FBQy9ELGtCQUFZLFFBQVEsQ0FBQyxlQUFlLEtBQUssT0FBTyxpQkFBaUIsVUFBVSxDQUFDO0FBQUEsSUFDaEY7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPLFNBQVMsSUFBSSxDQUFDLFlBQVksUUFBUSxVQUFVO0FBQUEsSUFDbkU7QUFBQSxJQUNBLHFDQUFxQyxTQUFTLFlBQVk7QUFDdEQsWUFBTSxVQUFVLEtBQUssT0FBTyxrQ0FBa0MsU0FBUyxVQUFVO0FBQ2pGLGFBQU8sVUFBVSxRQUFRLGFBQWE7QUFBQSxJQUMxQztBQUFBLElBQ0EsWUFBWUEsUUFBTyxTQUFTLFFBQVE7QUFDaEMsVUFBSTtBQUNKLFdBQUssT0FBTyxNQUFNO0FBQUE7QUFBQTtBQUFBO0FBQUEsS0FBa0IsU0FBU0EsUUFBTyxNQUFNO0FBQzFELE9BQUMsS0FBSyxPQUFPLGFBQWEsUUFBUSxPQUFPLFNBQVMsU0FBUyxHQUFHLEtBQUssUUFBUSxTQUFTLElBQUksR0FBRyxHQUFHQSxNQUFLO0FBQUEsSUFDdkc7QUFBQSxJQUNBLG9CQUFvQixZQUFZLGNBQWMsU0FBUyxDQUFDLEdBQUc7QUFDdkQsZUFBUyxPQUFPLE9BQU8sRUFBRSxhQUFhLEtBQUssR0FBRyxNQUFNO0FBQ3BELFdBQUssT0FBTyxlQUFlLEdBQUcsVUFBVSxLQUFLLFlBQVksRUFBRTtBQUMzRCxXQUFLLE9BQU8sSUFBSSxZQUFZLE9BQU8sT0FBTyxDQUFDLEdBQUcsTUFBTSxDQUFDO0FBQ3JELFdBQUssT0FBTyxTQUFTO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBQ0EsV0FBUyxXQUFXO0FBQ2hCLFdBQU8sSUFBSSxRQUFRLENBQUMsWUFBWTtBQUM1QixVQUFJLFNBQVMsY0FBYyxXQUFXO0FBQ2xDLGlCQUFTLGlCQUFpQixvQkFBb0IsTUFBTSxRQUFRLENBQUM7QUFBQSxNQUNqRSxPQUNLO0FBQ0QsZ0JBQVE7QUFBQSxNQUNaO0FBQUEsSUFDSixDQUFDO0FBQUEsRUFDTDtBQUVBLFdBQVMsd0JBQXdCLGFBQWE7QUFDMUMsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLG9CQUFvQjtBQUNuRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDZCQUE2QixlQUFlLENBQUM7QUFBQSxJQUNsRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDZCQUE2QixLQUFLO0FBQ3ZDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxHQUFHLE9BQU8sR0FBRztBQUFBLFFBQ2IsTUFBTTtBQUNGLGdCQUFNLEVBQUUsUUFBUSxJQUFJO0FBQ3BCLGNBQUksUUFBUSxJQUFJLEdBQUcsR0FBRztBQUNsQixtQkFBTyxRQUFRLElBQUksR0FBRztBQUFBLFVBQzFCLE9BQ0s7QUFDRCxrQkFBTSxZQUFZLFFBQVEsaUJBQWlCLEdBQUc7QUFDOUMsa0JBQU0sSUFBSSxNQUFNLHNCQUFzQixTQUFTLEdBQUc7QUFBQSxVQUN0RDtBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsR0FBRyxTQUFTLEdBQUc7QUFBQSxRQUNmLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsT0FBTyxHQUFHO0FBQUEsUUFDbEM7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLE1BQU0sV0FBVyxHQUFHLENBQUMsT0FBTyxHQUFHO0FBQUEsUUFDNUIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxJQUFJLEdBQUc7QUFBQSxRQUMvQjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMseUJBQXlCLGFBQWE7QUFDM0MsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLHFCQUFxQjtBQUNwRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDhCQUE4QixnQkFBZ0IsQ0FBQztBQUFBLElBQ3BGLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsb0JBQW9CLFlBQVksU0FBUyxZQUFZO0FBQzFELFdBQU8sV0FBVyxZQUFZLHFDQUFxQyxTQUFTLFVBQVU7QUFBQSxFQUMxRjtBQUNBLFdBQVMscUNBQXFDLFlBQVksU0FBUyxZQUFZO0FBQzNFLFFBQUksbUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUMxRSxRQUFJO0FBQ0EsYUFBTztBQUNYLGVBQVcsWUFBWSxPQUFPLDZDQUE2QyxTQUFTLFVBQVU7QUFDOUYsdUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUN0RSxRQUFJO0FBQ0EsYUFBTztBQUFBLEVBQ2Y7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFVBQU0sZ0JBQWdCLGtCQUFrQixJQUFJO0FBQzVDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxhQUFhLFFBQVEsR0FBRztBQUFBLFFBQ3hCLE1BQU07QUFDRixnQkFBTSxnQkFBZ0IsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUM1QyxnQkFBTSxXQUFXLEtBQUssUUFBUSx5QkFBeUIsSUFBSTtBQUMzRCxjQUFJLGVBQWU7QUFDZixrQkFBTSxtQkFBbUIscUNBQXFDLE1BQU0sZUFBZSxJQUFJO0FBQ3ZGLGdCQUFJO0FBQ0EscUJBQU87QUFDWCxrQkFBTSxJQUFJLE1BQU0sZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxHQUFHO0FBQUEsVUFDN0k7QUFDQSxnQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsUUFDL0s7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxTQUFTLEdBQUc7QUFBQSxRQUN6QixNQUFNO0FBQ0YsZ0JBQU0sVUFBVSxLQUFLLFFBQVEsUUFBUSxJQUFJO0FBQ3pDLGNBQUksUUFBUSxTQUFTLEdBQUc7QUFDcEIsbUJBQU8sUUFDRixJQUFJLENBQUMsa0JBQWtCO0FBQ3hCLG9CQUFNLG1CQUFtQixxQ0FBcUMsTUFBTSxlQUFlLElBQUk7QUFDdkYsa0JBQUk7QUFDQSx1QkFBTztBQUNYLHNCQUFRLEtBQUssZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxLQUFLLGFBQWE7QUFBQSxZQUN6SixDQUFDLEVBQ0ksT0FBTyxDQUFDLGVBQWUsVUFBVTtBQUFBLFVBQzFDO0FBQ0EsaUJBQU8sQ0FBQztBQUFBLFFBQ1o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxlQUFlLEdBQUc7QUFBQSxRQUMvQixNQUFNO0FBQ0YsZ0JBQU0sZ0JBQWdCLEtBQUssUUFBUSxLQUFLLElBQUk7QUFDNUMsZ0JBQU0sV0FBVyxLQUFLLFFBQVEseUJBQXlCLElBQUk7QUFDM0QsY0FBSSxlQUFlO0FBQ2YsbUJBQU87QUFBQSxVQUNYLE9BQ0s7QUFDRCxrQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsVUFDL0s7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLGFBQWEsZ0JBQWdCLEdBQUc7QUFBQSxRQUNoQyxNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLFFBQVEsSUFBSTtBQUFBLFFBQ3BDO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxNQUFNLFdBQVcsYUFBYSxDQUFDLFFBQVEsR0FBRztBQUFBLFFBQ3ZDLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsSUFBSSxJQUFJO0FBQUEsUUFDaEM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLHlCQUF5QixhQUFhO0FBQzNDLFVBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLFdBQU8sUUFBUSxPQUFPLENBQUMsWUFBWSxxQkFBcUI7QUFDcEQsYUFBTyxPQUFPLE9BQU8sWUFBWSw4QkFBOEIsZ0JBQWdCLENBQUM7QUFBQSxJQUNwRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxJQUFJLFFBQVEsR0FBRztBQUFBLFFBQ2YsTUFBTTtBQUNGLGdCQUFNLFNBQVMsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUNyQyxjQUFJLFFBQVE7QUFDUixtQkFBTztBQUFBLFVBQ1gsT0FDSztBQUNELGtCQUFNLElBQUksTUFBTSwyQkFBMkIsSUFBSSxVQUFVLEtBQUssVUFBVSxjQUFjO0FBQUEsVUFDMUY7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLElBQUksU0FBUyxHQUFHO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxRQUFRLElBQUk7QUFBQSxRQUNwQztBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxRQUFRLEdBQUc7QUFBQSxRQUM5QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLElBQUksSUFBSTtBQUFBLFFBQ2hDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyx3QkFBd0IsYUFBYTtBQUMxQyxVQUFNLHVCQUF1QixpQ0FBaUMsYUFBYSxRQUFRO0FBQ25GLFVBQU0sd0JBQXdCO0FBQUEsTUFDMUIsb0JBQW9CO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLHFCQUFxQixPQUFPLENBQUMsUUFBUSx3QkFBd0I7QUFDaEUsa0JBQU0sa0JBQWtCLHlCQUF5QixxQkFBcUIsS0FBSyxVQUFVO0FBQ3JGLGtCQUFNLGdCQUFnQixLQUFLLEtBQUssdUJBQXVCLGdCQUFnQixHQUFHO0FBQzFFLG1CQUFPLE9BQU8sT0FBTyxRQUFRLEVBQUUsQ0FBQyxhQUFhLEdBQUcsZ0JBQWdCLENBQUM7QUFBQSxVQUNyRSxHQUFHLENBQUMsQ0FBQztBQUFBLFFBQ1Q7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUNBLFdBQU8scUJBQXFCLE9BQU8sQ0FBQyxZQUFZLHdCQUF3QjtBQUNwRSxhQUFPLE9BQU8sT0FBTyxZQUFZLGlDQUFpQyxtQkFBbUIsQ0FBQztBQUFBLElBQzFGLEdBQUcscUJBQXFCO0FBQUEsRUFDNUI7QUFDQSxXQUFTLGlDQUFpQyxxQkFBcUIsWUFBWTtBQUN2RSxVQUFNLGFBQWEseUJBQXlCLHFCQUFxQixVQUFVO0FBQzNFLFVBQU0sRUFBRSxLQUFLLE1BQU0sUUFBUSxNQUFNLFFBQVEsTUFBTSxJQUFJO0FBQ25ELFdBQU87QUFBQSxNQUNILENBQUMsSUFBSSxHQUFHO0FBQUEsUUFDSixNQUFNO0FBQ0YsZ0JBQU0sUUFBUSxLQUFLLEtBQUssSUFBSSxHQUFHO0FBQy9CLGNBQUksVUFBVSxNQUFNO0FBQ2hCLG1CQUFPLEtBQUssS0FBSztBQUFBLFVBQ3JCLE9BQ0s7QUFDRCxtQkFBTyxXQUFXO0FBQUEsVUFDdEI7QUFBQSxRQUNKO0FBQUEsUUFDQSxJQUFJLE9BQU87QUFDUCxjQUFJLFVBQVUsUUFBVztBQUNyQixpQkFBSyxLQUFLLE9BQU8sR0FBRztBQUFBLFVBQ3hCLE9BQ0s7QUFDRCxpQkFBSyxLQUFLLElBQUksS0FBSyxNQUFNLEtBQUssQ0FBQztBQUFBLFVBQ25DO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxFQUFFLEdBQUc7QUFBQSxRQUN4QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxLQUFLLElBQUksR0FBRyxLQUFLLFdBQVc7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLFdBQVMseUJBQXlCLENBQUMsT0FBTyxjQUFjLEdBQUcsWUFBWTtBQUNuRSxXQUFPLHlDQUF5QztBQUFBLE1BQzVDO0FBQUEsTUFDQTtBQUFBLE1BQ0E7QUFBQSxJQUNKLENBQUM7QUFBQSxFQUNMO0FBQ0EsV0FBUyx1QkFBdUIsVUFBVTtBQUN0QyxZQUFRLFVBQVU7QUFBQSxNQUNkLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxNQUNYLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLElBQ2Y7QUFBQSxFQUNKO0FBQ0EsV0FBUyxzQkFBc0IsY0FBYztBQUN6QyxZQUFRLE9BQU8sY0FBYztBQUFBLE1BQ3pCLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxJQUNmO0FBQ0EsUUFBSSxNQUFNLFFBQVEsWUFBWTtBQUMxQixhQUFPO0FBQ1gsUUFBSSxPQUFPLFVBQVUsU0FBUyxLQUFLLFlBQVksTUFBTTtBQUNqRCxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMscUJBQXFCLFNBQVM7QUFDbkMsVUFBTSxFQUFFLFlBQVksT0FBTyxXQUFXLElBQUk7QUFDMUMsVUFBTSxVQUFVLFlBQVksV0FBVyxJQUFJO0FBQzNDLFVBQU0sYUFBYSxZQUFZLFdBQVcsT0FBTztBQUNqRCxVQUFNLGFBQWEsV0FBVztBQUM5QixVQUFNLFdBQVcsV0FBVyxDQUFDO0FBQzdCLFVBQU0sY0FBYyxDQUFDLFdBQVc7QUFDaEMsVUFBTSxpQkFBaUIsdUJBQXVCLFdBQVcsSUFBSTtBQUM3RCxVQUFNLHVCQUF1QixzQkFBc0IsUUFBUSxXQUFXLE9BQU87QUFDN0UsUUFBSTtBQUNBLGFBQU87QUFDWCxRQUFJO0FBQ0EsYUFBTztBQUNYLFFBQUksbUJBQW1CLHNCQUFzQjtBQUN6QyxZQUFNLGVBQWUsYUFBYSxHQUFHLFVBQVUsSUFBSSxLQUFLLEtBQUs7QUFDN0QsWUFBTSxJQUFJLE1BQU0sdURBQXVELFlBQVksa0NBQWtDLGNBQWMscUNBQXFDLFdBQVcsT0FBTyxpQkFBaUIsb0JBQW9CLElBQUk7QUFBQSxJQUN2TztBQUNBLFFBQUk7QUFDQSxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMseUJBQXlCLFNBQVM7QUFDdkMsVUFBTSxFQUFFLFlBQVksT0FBTyxlQUFlLElBQUk7QUFDOUMsVUFBTSxhQUFhLEVBQUUsWUFBWSxPQUFPLFlBQVksZUFBZTtBQUNuRSxVQUFNLGlCQUFpQixxQkFBcUIsVUFBVTtBQUN0RCxVQUFNLHVCQUF1QixzQkFBc0IsY0FBYztBQUNqRSxVQUFNLG1CQUFtQix1QkFBdUIsY0FBYztBQUM5RCxVQUFNLE9BQU8sa0JBQWtCLHdCQUF3QjtBQUN2RCxRQUFJO0FBQ0EsYUFBTztBQUNYLFVBQU0sZUFBZSxhQUFhLEdBQUcsVUFBVSxJQUFJLGNBQWMsS0FBSztBQUN0RSxVQUFNLElBQUksTUFBTSx1QkFBdUIsWUFBWSxVQUFVLEtBQUssU0FBUztBQUFBLEVBQy9FO0FBQ0EsV0FBUywwQkFBMEIsZ0JBQWdCO0FBQy9DLFVBQU0sV0FBVyx1QkFBdUIsY0FBYztBQUN0RCxRQUFJO0FBQ0EsYUFBTyxvQkFBb0IsUUFBUTtBQUN2QyxVQUFNLGFBQWEsWUFBWSxnQkFBZ0IsU0FBUztBQUN4RCxVQUFNLFVBQVUsWUFBWSxnQkFBZ0IsTUFBTTtBQUNsRCxVQUFNLGFBQWE7QUFDbkIsUUFBSTtBQUNBLGFBQU8sV0FBVztBQUN0QixRQUFJLFNBQVM7QUFDVCxZQUFNLEVBQUUsS0FBSyxJQUFJO0FBQ2pCLFlBQU0sbUJBQW1CLHVCQUF1QixJQUFJO0FBQ3BELFVBQUk7QUFDQSxlQUFPLG9CQUFvQixnQkFBZ0I7QUFBQSxJQUNuRDtBQUNBLFdBQU87QUFBQSxFQUNYO0FBQ0EsV0FBUyx5Q0FBeUMsU0FBUztBQUN2RCxVQUFNLEVBQUUsT0FBTyxlQUFlLElBQUk7QUFDbEMsVUFBTSxNQUFNLEdBQUcsVUFBVSxLQUFLLENBQUM7QUFDL0IsVUFBTSxPQUFPLHlCQUF5QixPQUFPO0FBQzdDLFdBQU87QUFBQSxNQUNIO0FBQUEsTUFDQTtBQUFBLE1BQ0EsTUFBTSxTQUFTLEdBQUc7QUFBQSxNQUNsQixJQUFJLGVBQWU7QUFDZixlQUFPLDBCQUEwQixjQUFjO0FBQUEsTUFDbkQ7QUFBQSxNQUNBLElBQUksd0JBQXdCO0FBQ3hCLGVBQU8sc0JBQXNCLGNBQWMsTUFBTTtBQUFBLE1BQ3JEO0FBQUEsTUFDQSxRQUFRLFFBQVEsSUFBSTtBQUFBLE1BQ3BCLFFBQVEsUUFBUSxJQUFJLEtBQUssUUFBUTtBQUFBLElBQ3JDO0FBQUEsRUFDSjtBQUNBLE1BQU0sc0JBQXNCO0FBQUEsSUFDeEIsSUFBSSxRQUFRO0FBQ1IsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsU0FBUztBQUFBLElBQ1QsUUFBUTtBQUFBLElBQ1IsSUFBSSxTQUFTO0FBQ1QsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsUUFBUTtBQUFBLEVBQ1o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLE1BQU0sT0FBTztBQUNULFlBQU0sUUFBUSxLQUFLLE1BQU0sS0FBSztBQUM5QixVQUFJLENBQUMsTUFBTSxRQUFRLEtBQUssR0FBRztBQUN2QixjQUFNLElBQUksVUFBVSx5REFBeUQsS0FBSyxjQUFjLHNCQUFzQixLQUFLLENBQUMsR0FBRztBQUFBLE1BQ25JO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFFBQVEsT0FBTztBQUNYLGFBQU8sRUFBRSxTQUFTLE9BQU8sT0FBTyxLQUFLLEVBQUUsWUFBWSxLQUFLO0FBQUEsSUFDNUQ7QUFBQSxJQUNBLE9BQU8sT0FBTztBQUNWLGFBQU8sT0FBTyxNQUFNLFFBQVEsTUFBTSxFQUFFLENBQUM7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTyxPQUFPO0FBQ1YsWUFBTSxTQUFTLEtBQUssTUFBTSxLQUFLO0FBQy9CLFVBQUksV0FBVyxRQUFRLE9BQU8sVUFBVSxZQUFZLE1BQU0sUUFBUSxNQUFNLEdBQUc7QUFDdkUsY0FBTSxJQUFJLFVBQVUsMERBQTBELEtBQUssY0FBYyxzQkFBc0IsTUFBTSxDQUFDLEdBQUc7QUFBQSxNQUNySTtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxPQUFPLE9BQU87QUFDVixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLFNBQVM7QUFBQSxJQUNULE9BQU87QUFBQSxJQUNQLFFBQVE7QUFBQSxFQUNaO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxLQUFLLFVBQVUsS0FBSztBQUFBLEVBQy9CO0FBQ0EsV0FBUyxZQUFZLE9BQU87QUFDeEIsV0FBTyxHQUFHLEtBQUs7QUFBQSxFQUNuQjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxTQUFTO0FBQ2pCLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxXQUFXLGFBQWE7QUFDcEIsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLE9BQU8sVUFBVSxhQUFhLGNBQWM7QUFDeEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxhQUFhO0FBQUEsSUFDYjtBQUFBLElBQ0EsVUFBVTtBQUFBLElBQ1Y7QUFBQSxJQUNBLGFBQWE7QUFBQSxJQUNiO0FBQUEsSUFDQSxTQUFTLFdBQVcsRUFBRSxTQUFTLEtBQUssU0FBUyxTQUFTLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxVQUFVLE1BQU0sYUFBYSxLQUFNLElBQUksQ0FBQyxHQUFHO0FBQzNILFlBQU0sT0FBTyxTQUFTLEdBQUcsTUFBTSxJQUFJLFNBQVMsS0FBSztBQUNqRCxZQUFNLFFBQVEsSUFBSSxZQUFZLE1BQU0sRUFBRSxRQUFRLFNBQVMsV0FBVyxDQUFDO0FBQ25FLGFBQU8sY0FBYyxLQUFLO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUNBLGFBQVcsWUFBWTtBQUFBLElBQ25CO0FBQUEsSUFDQTtBQUFBLElBQ0E7QUFBQSxJQUNBO0FBQUEsRUFDSjtBQUNBLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsU0FBUyxDQUFDOzs7QUNuL0VyQixNQUFNLGFBQWEsV0FBVztBQUV2QixXQUFTLGtCQUFrQixXQUFXO0FBQzNDLFdBQU8sU0FBU0csVUFBUyxNQUFNO0FBQzdCLFVBQUksQ0FBQyxVQUFVLEdBQUc7QUFDaEI7QUFBQSxNQUNGO0FBQ0EsaUJBQVcsSUFBSSxHQUFHLElBQUk7QUFBQSxJQUN4QjtBQUFBLEVBQ0Y7OztBQ0NBLE1BQU8scUNBQVAsY0FBNkIsV0FBVztBQUFBLElBTXRDLFVBQVU7QUFDUixXQUFLLFNBQVMsa0JBQWtCLE1BQU0sS0FBSyxVQUFVO0FBQ3JELFdBQUssT0FBTyx3Q0FBd0MsRUFBRSxhQUFhLEtBQUssY0FBYyxPQUFPLENBQUM7QUFFOUYsV0FBSyxrQkFBa0I7QUFFdkIsV0FBSyxRQUFRLEtBQUssUUFBUSxRQUFRLE1BQU07QUFDeEMsV0FBSyxZQUFZLE1BQU0sS0FBSyxzQkFBc0I7QUFDbEQsVUFBSSxLQUFLLE9BQU87QUFDZCxhQUFLLE1BQU0saUJBQWlCLFVBQVUsS0FBSyxTQUFTO0FBQUEsTUFDdEQ7QUFBQSxJQUNGO0FBQUEsSUFFQSxhQUFhO0FBQ1gsVUFBSSxLQUFLLE9BQU87QUFDZCxhQUFLLE1BQU0sb0JBQW9CLFVBQVUsS0FBSyxTQUFTO0FBQUEsTUFDekQ7QUFBQSxJQUNGO0FBQUEsSUFFQSxlQUFlLE9BQU87QUFDcEIsV0FBSyxPQUFPLDBCQUEwQixNQUFNLE9BQU8sS0FBSztBQUN4RCxXQUFLLGtCQUFrQjtBQUFBLElBQ3pCO0FBQUEsSUFFQSxvQkFBb0I7QUFDbEIsV0FBSyxjQUFjLFFBQVEsQ0FBQyxVQUFVO0FBQ3BDLGNBQU0sUUFBUSxNQUFNLGNBQWMscUJBQXFCO0FBQ3ZELGNBQU0sVUFBVSxPQUFPLFlBQVksUUFBUSxTQUFTLE1BQU0sT0FBTyxDQUFDO0FBQUEsTUFDcEUsQ0FBQztBQUFBLElBQ0g7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsd0JBQXdCO0FBQ3RCLFlBQU0sU0FBUyxTQUFTLGNBQWMsNENBQTRDO0FBQ2xGLFVBQUksQ0FBQyxRQUFRO0FBQ1g7QUFBQSxNQUNGO0FBQ0EsYUFBTyxXQUFXO0FBQ2xCLGFBQU8sVUFBVSxJQUFJLFlBQVk7QUFBQSxJQUNuQztBQUFBLEVBQ0Y7QUFoREUsZ0JBREssb0NBQ0UsV0FBVSxDQUFDLFFBQVE7QUFDMUIsZ0JBRkssb0NBRUUsVUFBUztBQUFBLElBQ2QsT0FBTyxFQUFFLE1BQU0sU0FBUyxTQUFTLE1BQU07QUFBQSxFQUN6Qzs7O0FDREYsTUFBTSxZQUFZO0FBRWxCLE1BQU0sU0FBUyxDQUFDLGNBQWMsY0FBYyxjQUFjLGtCQUFrQjtBQUU1RSxNQUFPLHVDQUFQLGNBQTZCLFdBQVc7QUFBQSxJQVd0QyxVQUFVO0FBQ1IsV0FBSyxTQUFTLGtCQUFrQixNQUFNLEtBQUssVUFBVTtBQUNyRCxXQUFLLGNBQWMsQ0FBQztBQUNwQixXQUFLLFlBQVk7QUFDakIsV0FBSyxZQUFZO0FBQ2pCLFdBQUssZUFBZTtBQUdwQixXQUFLLGNBQWMsQ0FBQyxVQUFVO0FBQzVCLFlBQUksTUFBTSxXQUFXO0FBQ25CLGVBQUssb0JBQW9CO0FBQUEsUUFDM0I7QUFBQSxNQUNGO0FBQ0EsYUFBTyxpQkFBaUIsWUFBWSxLQUFLLFdBQVc7QUFDcEQsV0FBSyxZQUFZLEtBQUssY0FBYztBQUNwQyxXQUFLLFVBQVU7QUFDZixXQUFLLE9BQU8sMENBQTBDLEVBQUUsVUFBVSxLQUFLLFVBQVUsQ0FBQztBQUFBLElBQ3BGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVlBLGFBQWE7QUFDWCxhQUFPLG9CQUFvQixZQUFZLEtBQUssV0FBVztBQUN2RCxpQkFBVyxDQUFDLE1BQU0sU0FBUyxLQUFLLE9BQU8sUUFBUSxLQUFLLGVBQWUsQ0FBQyxDQUFDLEdBQUc7QUFDdEUsWUFBSSxhQUFhLE9BQU8sVUFBVSxZQUFZLFlBQVk7QUFDeEQsY0FBSTtBQUNGLHNCQUFVLFFBQVE7QUFBQSxVQUNwQixTQUFTLEdBQUc7QUFBQSxVQUVaO0FBQUEsUUFDRjtBQUNBLGFBQUssT0FBTyw4QkFBOEIsRUFBRSxLQUFLLENBQUM7QUFBQSxNQUNwRDtBQUNBLFdBQUssY0FBYyxDQUFDO0FBQ3BCLFdBQUssVUFBVTtBQUNmLFdBQUssWUFBWTtBQUFBLElBQ25CO0FBQUE7QUFBQSxJQUdBLGNBQWM7QUFDWixXQUFLLFlBQVksS0FBSyxjQUFjO0FBQ3BDLFdBQUssVUFBVTtBQUFBLElBQ2pCO0FBQUE7QUFBQSxJQUdBLGdCQUFnQjtBQUNkLFlBQU0sVUFBVSxTQUFTLGNBQWMsb0NBQW9DO0FBQzNFLGFBQU8sQ0FBQyxDQUFDLFdBQVcsUUFBUSxVQUFVO0FBQUEsSUFDeEM7QUFBQSxJQUVBLFlBQVk7QUFDVixVQUFJLEtBQUssaUJBQWlCO0FBQ3hCLGFBQUssYUFBYSxNQUFNLFVBQVUsS0FBSyxZQUFZLEtBQUs7QUFBQSxNQUMxRDtBQUNBLFVBQUksS0FBSyxXQUFXO0FBQ2xCLGFBQUssZUFBZTtBQUFBLE1BQ3RCO0FBQUEsSUFDRjtBQUFBLElBRUEsaUJBQWlCO0FBT2YsVUFBSSxLQUFLLFdBQVc7QUFDbEIsZUFBTyxLQUFLO0FBQUEsTUFDZDtBQUVBLFdBQUssWUFBWSxLQUFLLE9BQU8sRUFBRSxRQUFRLE1BQU07QUFDM0MsYUFBSyxZQUFZO0FBQUEsTUFDbkIsQ0FBQztBQUVELGFBQU8sS0FBSztBQUFBLElBQ2Q7QUFBQSxJQUVBLE1BQU0sU0FBUztBQUNiLFVBQUksS0FBSyxTQUFTO0FBQ2hCO0FBQUEsTUFDRjtBQUNBLFVBQUksQ0FBQyxLQUFLLGdCQUFnQjtBQUN4QixhQUFLLE9BQU8sZ0VBQTJEO0FBQ3ZFO0FBQUEsTUFDRjtBQUNBLFlBQU0sS0FBSyxjQUFjO0FBQ3pCLFVBQUksQ0FBQyxPQUFPLFFBQVE7QUFDbEIsYUFBSyxXQUFXLDBCQUEwQjtBQUMxQztBQUFBLE1BQ0Y7QUFDQSxXQUFLLFVBQVUsT0FBTyxPQUFPLEtBQUssZ0JBQWdCO0FBQUEsUUFDaEQsUUFBUSxLQUFLO0FBQUEsUUFDYixVQUFVLEtBQUs7QUFBQSxNQUNqQixDQUFDO0FBQ0QsaUJBQVcsUUFBUSxRQUFRO0FBQ3pCLGNBQU0sUUFBUSxLQUFLLEdBQUcsSUFBSSxRQUFRLElBQUksS0FBSyxHQUFHLElBQUksUUFBUSxJQUFJO0FBQzlELFlBQUksQ0FBQyxPQUFPO0FBQ1Y7QUFBQSxRQUNGO0FBQ0EsY0FBTSxZQUFZLEtBQUssUUFBUSxnQkFBZ0IsSUFBSTtBQUNuRCxrQkFBVSxNQUFNLEtBQUs7QUFDckIsa0JBQVUsaUJBQWlCLFVBQVUsQ0FBQyxVQUFVLEtBQUssY0FBYyxNQUFNLEtBQUssQ0FBQztBQUMvRSxhQUFLLFlBQVksSUFBSSxJQUFJO0FBQUEsTUFDM0I7QUFDQSxXQUFLLE9BQU8sNkJBQTZCLE9BQU8sS0FBSyxLQUFLLFdBQVcsQ0FBQztBQUFBLElBQ3hFO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBT0EsTUFBTSxXQUFXLE9BQU87QUFDdEIsVUFBSSxPQUFPO0FBQ1QsY0FBTSxlQUFlO0FBQUEsTUFDdkI7QUFHQSxVQUFJLEtBQUssV0FBVztBQUNsQixhQUFLLE9BQU8seURBQW9EO0FBQ2hFO0FBQUEsTUFDRjtBQUNBLFlBQU0sT0FBTyxTQUFTLGVBQWUsS0FBSyxXQUFXO0FBQ3JELFVBQUksQ0FBQyxNQUFNO0FBQ1QsYUFBSyxXQUFXLHVCQUF1QjtBQUN2QztBQUFBLE1BQ0Y7QUFDQSxXQUFLLGlCQUFpQixRQUFRLE1BQU0sZ0JBQWdCLElBQUk7QUFFeEQsVUFBSSxDQUFDLEtBQUssV0FBVztBQUNuQixhQUFLLFFBQVEsSUFBSTtBQUNqQjtBQUFBLE1BQ0Y7QUFFQSxXQUFLLFlBQVk7QUFDakIsVUFBSSxDQUFDLEtBQUssU0FBUztBQUNqQixjQUFNLEtBQUssZUFBZTtBQUFBLE1BQzVCO0FBQ0EsVUFBSSxDQUFDLEtBQUssU0FBUztBQUNqQixhQUFLLFdBQVcsMkNBQTJDO0FBQzNELGFBQUssb0JBQW9CO0FBQ3pCO0FBQUEsTUFDRjtBQUVBLFVBQUk7QUFDRixjQUFNLEVBQUUsT0FBTyxPQUFBQyxPQUFNLElBQUksTUFBTSxLQUFLLFFBQVEsWUFBWTtBQUN4RCxZQUFJQSxRQUFPO0FBQ1QsZUFBSyxXQUFXQSxPQUFNLFdBQVcsaUNBQWlDO0FBQ2xFLGVBQUssb0JBQW9CO0FBQ3pCO0FBQUEsUUFDRjtBQUNBLFlBQUksS0FBSyxnQkFBZ0I7QUFDdkIsZUFBSyxZQUFZLFFBQVE7QUFBQSxRQUMzQjtBQUNBLGFBQUssT0FBTyx3Q0FBbUM7QUFDL0MsYUFBSyxRQUFRLElBQUk7QUFBQSxNQUNuQixTQUFTLEtBQUs7QUFDWixhQUFLLE9BQU8sc0JBQXNCLEdBQUc7QUFDckMsYUFBSyxXQUFXLCtDQUErQztBQUMvRCxhQUFLLG9CQUFvQjtBQUFBLE1BQzNCO0FBQUEsSUFDRjtBQUFBLElBRUEsaUJBQWlCLFFBQVE7QUFDdkIsV0FBSyxZQUFZO0FBQ2pCLFdBQUssZUFBZSxrQkFBa0Isb0JBQW9CLFNBQVM7QUFDbkUsVUFBSSxLQUFLLGNBQWM7QUFDckIsYUFBSyxhQUFhLFdBQVc7QUFDN0IsYUFBSyxhQUFhLFVBQVUsSUFBSSxZQUFZO0FBQUEsTUFDOUM7QUFBQSxJQUNGO0FBQUE7QUFBQSxJQUdBLHNCQUFzQjtBQUNwQixXQUFLLFlBQVk7QUFDakIsVUFBSSxLQUFLLGNBQWM7QUFDckIsYUFBSyxhQUFhLFdBQVc7QUFDN0IsYUFBSyxhQUFhLFVBQVUsT0FBTyxZQUFZO0FBQUEsTUFDakQ7QUFBQSxJQUNGO0FBQUEsSUFFQSxRQUFRLE1BQU07QUFDWixXQUFLLGdCQUFnQixLQUFLLGNBQWMsSUFBSSxLQUFLLE9BQU87QUFBQSxJQUMxRDtBQUFBLElBRUEsY0FBYyxNQUFNLE9BQU87QUFDekIsVUFBSSxTQUFTLE1BQU0sU0FBUyxNQUFNLFNBQVM7QUFDekMsYUFBSyxXQUFXLE1BQU0sS0FBSztBQUMzQjtBQUFBLE1BQ0Y7QUFDQSxXQUFLLFlBQVk7QUFBQSxJQUNuQjtBQUFBLElBRUEsZ0JBQWdCO0FBQ2QsYUFBTyxJQUFJLFFBQVEsQ0FBQyxTQUFTLFdBQVc7QUFDdEMsWUFBSSxPQUFPLFFBQVE7QUFBRSxrQkFBUTtBQUFHO0FBQUEsUUFBTztBQUN2QyxjQUFNLFdBQVcsU0FBUyxjQUFjLGVBQWUsU0FBUyxJQUFJO0FBQ3BFLFlBQUksVUFBVTtBQUNaLG1CQUFTLGlCQUFpQixRQUFRLE1BQU0sUUFBUSxDQUFDO0FBQ2pELG1CQUFTLGlCQUFpQixTQUFTLE1BQU0sT0FBTyxJQUFJLE1BQU0sMEJBQTBCLENBQUMsQ0FBQztBQUN0RjtBQUFBLFFBQ0Y7QUFDQSxjQUFNLFNBQVMsU0FBUyxjQUFjLFFBQVE7QUFDOUMsZUFBTyxNQUFNO0FBQ2IsZUFBTyxRQUFRO0FBQ2YsZUFBTyxTQUFTLE1BQU0sUUFBUTtBQUM5QixlQUFPLFVBQVUsTUFBTSxPQUFPLElBQUksTUFBTSwwQkFBMEIsQ0FBQztBQUNuRSxpQkFBUyxLQUFLLFlBQVksTUFBTTtBQUFBLE1BQ2xDLENBQUM7QUFBQSxJQUNIO0FBQUEsSUFFQSxXQUFXLFNBQVM7QUFDbEIsVUFBSSxLQUFLLGdCQUFnQjtBQUN2QixhQUFLLFlBQVksY0FBYztBQUMvQixhQUFLLFlBQVksTUFBTSxVQUFVO0FBQUEsTUFDbkM7QUFBQSxJQUNGO0FBQUEsSUFFQSxjQUFjO0FBQ1osVUFBSSxLQUFLLGdCQUFnQjtBQUN2QixhQUFLLFlBQVksY0FBYztBQUMvQixhQUFLLFlBQVksTUFBTSxVQUFVO0FBQUEsTUFDbkM7QUFBQSxJQUNGO0FBQUEsRUFDRjtBQWxQRSxnQkFESyxzQ0FDRSxXQUFVLENBQUMsY0FBYyxjQUFjLGNBQWMsb0JBQW9CLFNBQVMsU0FBUyxRQUFRO0FBQzFHLGdCQUZLLHNDQUVFLFVBQVM7QUFBQSxJQUNkLFdBQVc7QUFBQSxJQUNYLFVBQVUsRUFBRSxNQUFNLFNBQVMsU0FBUyxNQUFNO0FBQUEsSUFDMUMsUUFBUSxFQUFFLE1BQU0sUUFBUSxTQUFTLFFBQVE7QUFBQTtBQUFBLElBRXpDLFFBQVEsRUFBRSxNQUFNLFFBQVEsU0FBUyx3QkFBd0I7QUFBQSxJQUN6RCxPQUFPLEVBQUUsTUFBTSxTQUFTLFNBQVMsTUFBTTtBQUFBLEVBQ3pDOzs7QUNwQkYsTUFBTyx3Q0FBUCxjQUE2QixXQUFXO0FBQUEsSUFNdEMsVUFBVTtBQUNSLFdBQUssWUFBWTtBQUNqQixXQUFLLFFBQVEsZ0JBQWdCLFNBQVM7QUFHdEMsV0FBSyxjQUFjLENBQUMsVUFBVTtBQUM1QixZQUFJLE1BQU0sV0FBVztBQUNuQixlQUFLLFNBQVM7QUFBQSxRQUNoQjtBQUFBLE1BQ0Y7QUFDQSxhQUFPLGlCQUFpQixZQUFZLEtBQUssV0FBVztBQUFBLElBQ3REO0FBQUEsSUFFQSxhQUFhO0FBQ1gsYUFBTyxvQkFBb0IsWUFBWSxLQUFLLFdBQVc7QUFBQSxJQUN6RDtBQUFBLElBRUEsT0FBTyxPQUFPO0FBQ1osVUFBSSxPQUFPO0FBQ1QsY0FBTSxlQUFlO0FBQUEsTUFDdkI7QUFDQSxVQUFJLEtBQUssV0FBVztBQUNsQjtBQUFBLE1BQ0Y7QUFDQSxZQUFNLE9BQU8sU0FBUyxlQUFlLEtBQUssV0FBVztBQUNyRCxVQUFJLENBQUMsTUFBTTtBQUNUO0FBQUEsTUFDRjtBQUNBLFdBQUssWUFBWTtBQUNqQixXQUFLLFFBQVEsV0FBVztBQUN4QixXQUFLLFFBQVEsVUFBVSxJQUFJLFlBQVk7QUFFdkMsV0FBSyxPQUFPO0FBQUEsSUFDZDtBQUFBLElBRUEsV0FBVztBQUNULFdBQUssWUFBWTtBQUNqQixXQUFLLFFBQVEsV0FBVztBQUN4QixXQUFLLFFBQVEsVUFBVSxPQUFPLFlBQVk7QUFBQSxJQUM1QztBQUFBLEVBQ0Y7QUE3Q0UsZ0JBREssdUNBQ0UsVUFBUztBQUFBO0FBQUEsSUFFZCxRQUFRLEVBQUUsTUFBTSxRQUFRLFNBQVMsd0JBQXdCO0FBQUEsRUFDM0Q7OztBQ05GLFNBQU8sV0FBVyxPQUFPLFlBQVksWUFBWSxNQUFNO0FBQ3ZELFNBQU8sU0FBUyxTQUFTLG1CQUFtQixrQ0FBd0I7QUFDcEUsU0FBTyxTQUFTLFNBQVMscUJBQXFCLG9DQUEwQjtBQUN4RSxTQUFPLFNBQVMsU0FBUyxzQkFBc0IscUNBQTBCO0FBRXpFLE1BQU0scUJBQXFCLE1BQUc7QUF0QjlCO0FBc0JpQyx5QkFBTyxZQUFQLG1CQUFnQixXQUFVO0FBQUE7QUFDM0QsTUFBTSxRQUFRLGtCQUFrQixrQkFBa0I7QUFFbEQsUUFBTSx3REFBd0QsT0FBTyxTQUFTLE9BQU8sbUJBQW1COyIsCiAgIm5hbWVzIjogWyJlcnJvciIsICJtYXRjaCIsICJvbGRWYWx1ZSIsICJlcnJvciIsICJjb25zdHJ1Y3RvciIsICJlbGVtZW50IiwgImRlYnVnIiwgImVycm9yIl0KfQo=
