(() => {
  const themeToggle = document.querySelector('[data-theme-toggle]');
  const themeLabel = document.querySelector('[data-theme-label]');

  function setTheme(theme) {
    const next = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    if (themeLabel) themeLabel.textContent = next === 'light' ? 'Theme: Light' : 'Theme: Dark';
    if (themeToggle) themeToggle.setAttribute('aria-pressed', next === 'light' ? 'true' : 'false');
    try {
      localStorage.setItem('signalops-theme', next);
    } catch (error) {
      // Ignore unavailable localStorage in locked-down browsers.
    }
  }

  themeToggle?.addEventListener('click', () => {
    setTheme(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light');
  });
  setTheme(document.documentElement.dataset.theme || 'dark');

  const map = document.querySelector('[data-network-map]');
  if (!map) return;

  const stage = map.querySelector('[data-map-stage]');
  const svg = map.querySelector('.globe-svg');
  const zoomIn = map.querySelector('[data-map-zoom-in]');
  const zoomOut = map.querySelector('[data-map-zoom-out]');
  const reset = map.querySelector('[data-map-reset]');
  const nodes = [...map.querySelectorAll('.globe-node')];
  const focusButtons = [...document.querySelectorAll('[data-latency-filter]')];
  const linkRows = [...document.querySelectorAll('[data-link-row]')];
  const linkMarks = [...map.querySelectorAll('[data-link-from][data-link-to]')];
  const focusTitle = document.querySelector('[data-latency-focus-title]');
  const focusCount = document.querySelector('[data-latency-focus-count]');
  if (!stage || !svg) return;

  const viewBox = { width: 1000, height: 620 };
  const state = { scale: 1, x: 0, y: 0 };
  const limits = { min: 0.9, max: 2.75 };
  let drag = null;
  let activeKey = '';

  const nodeLabels = nodes.reduce((labels, node) => {
    const key = node.dataset.nodeKey || '';
    const label = (node.getAttribute('aria-label') || key).split(',')[0].trim();
    if (key) labels[key] = label || key;
    return labels;
  }, {});

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function apply() {
    stage.setAttribute('transform', `translate(${state.x.toFixed(2)} ${state.y.toFixed(2)}) scale(${state.scale.toFixed(3)})`);
  }

  function pointFromEvent(event) {
    const rect = svg.getBoundingClientRect();
    return { x: ((event.clientX - rect.left) / rect.width) * viewBox.width, y: ((event.clientY - rect.top) / rect.height) * viewBox.height };
  }

  function zoomAt(nextScale, center) {
    const scale = clamp(nextScale, limits.min, limits.max);
    const ratio = scale / state.scale;
    state.x = center.x - (center.x - state.x) * ratio;
    state.y = center.y - (center.y - state.y) * ratio;
    state.scale = scale;
    apply();
  }

  function linkMatches(element, key) {
    return key === 'all' || element.dataset.linkFrom === key || element.dataset.linkTo === key;
  }

  function setActiveNode(nextNode) {
    activeKey = nextNode?.dataset.nodeKey || 'all';
    nodes.forEach((node) => {
      const isActive = node === nextNode;
      node.classList.toggle('is-active', isActive);
      node.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
    focusButtons.forEach((button) => {
      const isActive = button.dataset.latencyFilter === activeKey;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    let visibleCount = 0;
    linkRows.forEach((row) => {
      const visible = linkMatches(row, activeKey);
      row.classList.toggle('is-hidden', !visible);
      if (visible) visibleCount += 1;
    });
    linkMarks.forEach((mark) => mark.classList.toggle('is-muted', !linkMatches(mark, activeKey)));
    if (focusTitle) focusTitle.textContent = activeKey === 'all' ? 'All Latency Links' : `${nodeLabels[activeKey] || activeKey} Links`;
    if (focusCount) focusCount.textContent = `${visibleCount} ${visibleCount === 1 ? 'path' : 'paths'}`;
  }

  function setActiveKey(key) {
    if (key === 'all') {
      setActiveNode(null);
      return;
    }
    setActiveNode(nodes.find((item) => item.dataset.nodeKey === key) || null);
  }

  nodes.forEach((node) => {
    node.addEventListener('click', (event) => {
      event.stopPropagation();
      setActiveNode(node);
    });
    node.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      setActiveNode(node);
    });
  });

  focusButtons.forEach((button) => button.addEventListener('click', () => setActiveKey(button.dataset.latencyFilter || 'all')));
  zoomIn?.addEventListener('click', () => zoomAt(state.scale * 1.22, { x: viewBox.width / 2, y: viewBox.height / 2 }));
  zoomOut?.addEventListener('click', () => zoomAt(state.scale / 1.22, { x: viewBox.width / 2, y: viewBox.height / 2 }));
  reset?.addEventListener('click', () => {
    state.scale = 1;
    state.x = 0;
    state.y = 0;
    setActiveKey('web');
    apply();
  });

  map.addEventListener('wheel', (event) => {
    event.preventDefault();
    zoomAt(state.scale * (event.deltaY > 0 ? 0.9 : 1.1), pointFromEvent(event));
  }, { passive: false });

  map.addEventListener('pointerdown', (event) => {
    if (event.target.closest('.map-toolbar') || event.target.closest('.globe-node')) return;
    map.setPointerCapture(event.pointerId);
    map.classList.add('is-dragging');
    drag = { id: event.pointerId, clientX: event.clientX, clientY: event.clientY };
  });

  map.addEventListener('pointermove', (event) => {
    if (!drag || drag.id !== event.pointerId) return;
    const rect = svg.getBoundingClientRect();
    state.x += ((event.clientX - drag.clientX) / rect.width) * viewBox.width;
    state.y += ((event.clientY - drag.clientY) / rect.height) * viewBox.height;
    drag.clientX = event.clientX;
    drag.clientY = event.clientY;
    apply();
  });

  function stopDrag(event) {
    if (!drag || drag.id !== event.pointerId) return;
    drag = null;
    map.classList.remove('is-dragging');
  }

  map.addEventListener('pointerup', stopDrag);
  map.addEventListener('pointercancel', stopDrag);
  setActiveKey(nodes.some((node) => node.dataset.nodeKey === 'web') ? 'web' : (nodes[0]?.dataset.nodeKey || 'all'));
  apply();
})();
