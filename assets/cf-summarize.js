/* global cfsData */
(function () {
  "use strict";

  /**
   * Minimal HTML-escape helper for error messages.
   *
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    return str
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  /**
   * Build the inline accordion panel inside a .cfs-wrap element.
   * Structure:
   *   .cfs-panel (grid animation container)
   *     .cfs-panel-inner (overflow:hidden, grid item)
   *       .cfs-panel-card (visual card — background, border, padding)
   *         .cfs-panel-header
   *         .cfs-panel-body
   *
   * @param {HTMLElement} wrap
   * @returns {{ panel: HTMLElement, body: HTMLElement }}
   */
  function createPanel(wrap) {
    const panel = document.createElement("div");
    panel.className = "cfs-panel";
    panel.setAttribute("aria-live", "polite");

    const inner = document.createElement("div");
    inner.className = "cfs-panel-inner";

    const card = document.createElement("div");
    card.className = "cfs-panel-card";

    // Header row: title
    const header = document.createElement("div");
    header.className = "cfs-panel-header";

    const title = document.createElement("span");
    title.className = "cfs-panel-title";
    title.textContent = "Article Overview";

    header.appendChild(title);

    // Body: spinner replaced by summary text after fetch
    const body = document.createElement("div");
    body.className = "cfs-panel-body";

    card.appendChild(header);
    card.appendChild(body);
    inner.appendChild(card);
    panel.appendChild(inner);
    wrap.appendChild(panel);

    return { panel, body };
  }

  /**
   * Render a loading spinner inside the panel body.
   *
   * @param {HTMLElement} body
   */
  function showSpinner(body) {
    const spinner = document.createElement("div");
    spinner.className = "cfs-spinner";
    spinner.setAttribute("aria-label", "Loading\u2026");
    body.innerHTML = "";
    body.appendChild(spinner);
  }

  /**
   * Handle click on a .cfs-btn button.
   * Toggles the inline panel open/closed; fetches on first open.
   *
   * @param {MouseEvent} e
   */
  function handleClick(e) {
    const btn = /** @type {HTMLButtonElement} */ (e.currentTarget);
    const wrap = /** @type {HTMLElement|null} */ (btn.closest(".cfs-wrap"));

    if (!wrap) {
      return;
    }

    // Create panel lazily on first click.
    if (!wrap._cfsPanel) {
      const { panel, body } = createPanel(wrap);
      wrap._cfsPanel = panel;
      wrap._cfsBody = body;
      wrap._cfsLoaded = false;
    }

    const panel = wrap._cfsPanel;
    const body = wrap._cfsBody;

    // Toggle: close if already open.
    if (panel.classList.contains("cfs-open")) {
      panel.classList.remove("cfs-open");
      btn.setAttribute("aria-expanded", "false");
      return;
    }

    // Open the panel.
    panel.classList.add("cfs-open");
    btn.setAttribute("aria-expanded", "true");

    // Summary already fetched — just reveal the cached DOM.
    if (wrap._cfsLoaded) {
      return;
    }

    // ── First open: fetch summary from REST API ────────────────────────
    showSpinner(body);

    const postId = btn.dataset.postId;
    const nonce = btn.dataset.nonce;

    fetch(cfsData.restUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": cfsData.nonce,
      },
      body: JSON.stringify({ post_id: parseInt(postId, 10), nonce: nonce }),
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        wrap._cfsLoaded = true;

        if (!result.ok) {
          const errMsg =
            result.data && result.data.message
              ? result.data.message
              : "An error occurred. Please try again.";
          body.innerHTML =
            '<p class="cfs-error">' + escapeHtml(errMsg) + "</p>";
          return;
        }

        const keyPoints =
          result.data && Array.isArray(result.data.key_points)
            ? result.data.key_points
            : [];
        const conclusion =
          result.data && result.data.conclusion ? result.data.conclusion : "";
        const cached = result.data && result.data.cached;

        body.innerHTML = "";

        // Key points section
        if (keyPoints.length > 0) {
          const kpLabel = document.createElement("p");
          kpLabel.className = "cfs-section-label";
          kpLabel.textContent = "Key Points";
          body.appendChild(kpLabel);

          const ul = document.createElement("ul");
          ul.className = "cfs-key-points";
          keyPoints.forEach(function (point) {
            const li = document.createElement("li");
            li.textContent = point;
            ul.appendChild(li);
          });
          body.appendChild(ul);
        }

        // Conclusion section
        if (conclusion) {
          const concLabel = document.createElement("p");
          concLabel.className = "cfs-section-label";
          concLabel.textContent = "Conclusion";
          body.appendChild(concLabel);

          const p = document.createElement("p");
          p.className = "cfs-conclusion";
          p.textContent = conclusion;

          if (cached) {
            const badge = document.createElement("span");
            badge.className = "cfs-cached-badge";
            badge.textContent = "Cached";
            p.appendChild(badge);
          }

          body.appendChild(p);
        }
      })
      .catch(function () {
        body.innerHTML =
          '<p class="cfs-error">Network error. Please try again.</p>';
      });
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".cfs-btn").forEach(function (btn) {
      btn.setAttribute("aria-expanded", "false");
      btn.addEventListener("click", handleClick);
    });
  });
})();
