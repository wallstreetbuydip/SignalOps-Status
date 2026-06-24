(() => {
  const themeToggle = document.querySelector("[data-theme-toggle]");
  const themeLabel = document.querySelector("[data-theme-label]");

  function setTheme(theme) {
    const next = theme === "light" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    if (themeLabel) {
      themeLabel.textContent = next === "light" ? "Theme: Light" : "Theme: Dark";
    }
    if (themeToggle) {
      themeToggle.setAttribute("aria-pressed", next === "light" ? "true" : "false");
    }
    try {
      localStorage.setItem("signalops-theme", next);
    } catch (error) {
      // Ignore unavailable localStorage in locked-down browsers.
    }
  }

  themeToggle?.addEventListener("click", () => {
    setTheme(document.documentElement.dataset.theme === "light" ? "dark" : "light");
  });
  setTheme(document.documentElement.dataset.theme || "dark");

  function makeFormatter(options) {
    const timeZone = document.documentElement.dataset.timezone || "America/Toronto";
    try {
      return new Intl.DateTimeFormat("en-CA", { timeZone, ...options });
    } catch (error) {
      return new Intl.DateTimeFormat("en-CA", { timeZone: "UTC", ...options });
    }
  }

  const clockFormatter = makeFormatter({
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
    timeZoneName: "short",
  });
  const localFormatter = makeFormatter({
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZoneName: "short",
  });

  function partsMap(formatter, date) {
    return formatter.formatToParts(date).reduce((acc, part) => {
      if (part.type !== "literal") {
        acc[part.type] = part.value;
      }
      return acc;
    }, {});
  }

  function formatClock(date) {
    const parts = partsMap(clockFormatter, date);
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second} ${parts.timeZoneName}`;
  }

  function formatLocal(date) {
    const parts = partsMap(localFormatter, date);
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute} ${parts.timeZoneName}`;
  }

  function formatRelative(date) {
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) {
      return `${seconds}s ago`;
    }
    if (seconds < 3600) {
      return `${Math.floor(seconds / 60)}m ago`;
    }
    if (seconds < 86400) {
      return `${Math.floor(seconds / 3600)}h ago`;
    }
    return `${Math.floor(seconds / 86400)}d ago`;
  }

  function refreshLiveClocks() {
    const now = new Date();
    document.querySelectorAll("[data-live-clock]").forEach((node) => {
      node.textContent = formatClock(now);
    });
  }

  function refreshLocalTimes() {
    document.querySelectorAll("[data-local-time]").forEach((node) => {
      const value = node.getAttribute("data-local-time");
      const date = value ? new Date(value) : null;
      if (!date || Number.isNaN(date.getTime())) {
        return;
      }
      node.textContent = formatLocal(date);
    });
  }

  function refreshRelativeTimes() {
    document.querySelectorAll("[data-relative-time]").forEach((node) => {
      const value = node.getAttribute("data-relative-time");
      const date = value ? new Date(value) : null;
      if (!date || Number.isNaN(date.getTime())) {
        return;
      }
      node.textContent = formatRelative(date);
    });
  }

  refreshLiveClocks();
  refreshLocalTimes();
  refreshRelativeTimes();
  window.setInterval(refreshLiveClocks, 1000);
  window.setInterval(refreshRelativeTimes, 15000);

  function initMap(map) {
    const stage = map.querySelector("[data-map-stage]");
    const svg = map.querySelector(".globe-svg");
    const zoomIn = map.querySelector("[data-map-zoom-in]");
    const zoomOut = map.querySelector("[data-map-zoom-out]");
    const reset = map.querySelector("[data-map-reset]");
    const nodes = [...map.querySelectorAll(".globe-node")];
    const focusButtons = [...document.querySelectorAll("[data-latency-filter]")];
    const linkRows = [...document.querySelectorAll("[data-link-row]")];
    const linkMarks = [...map.querySelectorAll("[data-link-from][data-link-to]")];
    const focusTitle = document.querySelector("[data-latency-focus-title]");
    const focusCount = document.querySelector("[data-latency-focus-count]");
    if (!stage || !svg) {
      return;
    }

    const viewBox = { width: 1000, height: 620 };
    const state = { scale: 1, x: 0, y: 0 };
    const limits = { min: 0.9, max: 2.75 };
    let drag = null;

    const nodeLabels = nodes.reduce((labels, node) => {
      const key = node.dataset.nodeKey || "";
      const label = (node.getAttribute("aria-label") || key).split(",")[0].trim();
      if (key) {
        labels[key] = label || key;
      }
      return labels;
    }, {});

    function clamp(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }

    function apply() {
      stage.setAttribute("transform", `translate(${state.x.toFixed(2)} ${state.y.toFixed(2)}) scale(${state.scale.toFixed(3)})`);
    }

    function pointFromEvent(event) {
      const rect = svg.getBoundingClientRect();
      return {
        x: ((event.clientX - rect.left) / rect.width) * viewBox.width,
        y: ((event.clientY - rect.top) / rect.height) * viewBox.height,
      };
    }

    function zoomAt(nextScale, center) {
      const scale = clamp(nextScale, limits.min, limits.max);
      const ratio = scale / state.scale;
      state.x = center.x - (center.x - state.x) * ratio;
      state.y = center.y - (center.y - state.y) * ratio;
      state.scale = scale;
      apply();
    }

    function centerPoint() {
      return { x: viewBox.width / 2, y: viewBox.height / 2 };
    }

    function linkMatches(element, key) {
      return key === "all" || element.dataset.linkFrom === key || element.dataset.linkTo === key;
    }

    function setActiveNode(nextNode) {
      const activeKey = nextNode?.dataset.nodeKey || "all";
      nodes.forEach((node) => {
        const isActive = node === nextNode;
        node.classList.toggle("is-active", isActive);
        node.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
      focusButtons.forEach((button) => {
        const isActive = button.dataset.latencyFilter === activeKey;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-pressed", isActive ? "true" : "false");
      });

      let visibleCount = 0;
      linkRows.forEach((row) => {
        const visible = linkMatches(row, activeKey);
        row.classList.toggle("is-hidden", !visible);
        if (visible) {
          visibleCount += 1;
        }
      });
      linkMarks.forEach((mark) => mark.classList.toggle("is-muted", !linkMatches(mark, activeKey)));
      if (focusTitle) {
        focusTitle.textContent = activeKey === "all" ? "All Latency Links" : `${nodeLabels[activeKey] || activeKey} Links`;
      }
      if (focusCount) {
        focusCount.textContent = `${visibleCount} ${visibleCount === 1 ? "path" : "paths"}`;
      }
    }

    function setActiveKey(key) {
      if (key === "all") {
        setActiveNode(null);
        return;
      }
      setActiveNode(nodes.find((item) => item.dataset.nodeKey === key) || null);
    }

    nodes.forEach((node) => {
      node.addEventListener("click", (event) => {
        event.stopPropagation();
        setActiveNode(node);
      });
      node.addEventListener("keydown", (event) => {
        if (event.key !== "Enter" && event.key !== " ") {
          return;
        }
        event.preventDefault();
        setActiveNode(node);
      });
    });

    focusButtons.forEach((button) => button.addEventListener("click", () => setActiveKey(button.dataset.latencyFilter || "all")));
    zoomIn?.addEventListener("click", () => zoomAt(state.scale * 1.22, centerPoint()));
    zoomOut?.addEventListener("click", () => zoomAt(state.scale / 1.22, centerPoint()));
    reset?.addEventListener("click", () => {
      state.scale = 1;
      state.x = 0;
      state.y = 0;
      setActiveKey("web");
      apply();
    });

    map.addEventListener("wheel", (event) => {
      if (!event.ctrlKey && !event.metaKey) {
        return;
      }
      event.preventDefault();
      zoomAt(state.scale * (event.deltaY > 0 ? 0.9 : 1.1), pointFromEvent(event));
    }, { passive: false });

    map.addEventListener("pointerdown", (event) => {
      if (event.target.closest(".map-toolbar") || event.target.closest(".globe-node")) {
        return;
      }
      map.setPointerCapture(event.pointerId);
      map.classList.add("is-dragging");
      drag = { id: event.pointerId, clientX: event.clientX, clientY: event.clientY };
    });

    map.addEventListener("pointermove", (event) => {
      if (!drag || drag.id !== event.pointerId) {
        return;
      }
      const rect = svg.getBoundingClientRect();
      state.x += ((event.clientX - drag.clientX) / rect.width) * viewBox.width;
      state.y += ((event.clientY - drag.clientY) / rect.height) * viewBox.height;
      drag.clientX = event.clientX;
      drag.clientY = event.clientY;
      apply();
    });

    function stopDrag(event) {
      if (!drag || drag.id !== event.pointerId) {
        return;
      }
      drag = null;
      map.classList.remove("is-dragging");
    }

    map.addEventListener("pointerup", stopDrag);
    map.addEventListener("pointercancel", stopDrag);
    setActiveKey(nodes.some((node) => node.dataset.nodeKey === "web") ? "web" : (nodes[0]?.dataset.nodeKey || "all"));
    apply();
  }

  const map = document.querySelector("[data-network-map]");
  if (!map) {
    return;
  }

  let mapInitialized = false;
  const startMap = () => {
    if (mapInitialized) {
      return;
    }
    mapInitialized = true;
    initMap(map);
  };

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }
      observer.disconnect();
      startMap();
    }, { rootMargin: "160px 0px" });
    observer.observe(map);
  } else if ("requestIdleCallback" in window) {
    window.requestIdleCallback(startMap, { timeout: 600 });
  } else {
    window.setTimeout(startMap, 120);
  }
})();
