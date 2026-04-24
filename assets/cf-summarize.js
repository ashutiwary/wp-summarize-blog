/* global cfsData */
(function () {
  'use strict';

  var REGEN_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" ' +
    'fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>' +
    '<path d="M3 3v5h5"/>' +
    '</svg>';

  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function createPanel(wrap) {
    var panel = document.createElement('div');
    panel.className = 'cfs-panel';
    panel.setAttribute('aria-live', 'polite');

    var inner = document.createElement('div');
    inner.className = 'cfs-panel-inner';

    var card = document.createElement('div');
    card.className = 'cfs-panel-card';

    var header = document.createElement('div');
    header.className = 'cfs-panel-header';

    var title = document.createElement('span');
    title.className = 'cfs-panel-title';
    title.textContent = 'Article Overview';

    var regenBtn = document.createElement('button');
    regenBtn.className = 'cfs-regen-btn';
    regenBtn.setAttribute('type', 'button');
    regenBtn.setAttribute('title', 'Regenerate summary');
    regenBtn.setAttribute('aria-label', 'Regenerate summary');
    regenBtn.innerHTML = REGEN_SVG;

    header.appendChild(title);
    header.appendChild(regenBtn);

    var body = document.createElement('div');
    body.className = 'cfs-panel-body';

    card.appendChild(header);
    card.appendChild(body);
    inner.appendChild(card);
    panel.appendChild(inner);
    wrap.appendChild(panel);

    return { panel: panel, body: body, regenBtn: regenBtn };
  }

  function showSkeleton(body) {
    body.innerHTML = '';

    var skeleton = document.createElement('div');
    skeleton.className = 'cfs-skeleton';
    skeleton.setAttribute('aria-label', 'Loading summary…');
    skeleton.setAttribute('aria-busy', 'true');

    var kpLabel = document.createElement('div');
    kpLabel.className = 'cfs-skeleton-label';
    skeleton.appendChild(kpLabel);

    var list = document.createElement('ul');
    list.className = 'cfs-skeleton-list';
    var widths = ['92%', '78%', '85%'];
    widths.forEach(function (w) {
      var li = document.createElement('li');
      li.className = 'cfs-skeleton-line';
      li.style.width = w;
      list.appendChild(li);
    });
    skeleton.appendChild(list);

    var concLabel = document.createElement('div');
    concLabel.className = 'cfs-skeleton-label';
    skeleton.appendChild(concLabel);

    var block = document.createElement('div');
    block.className = 'cfs-skeleton-block';
    for (var i = 0; i < 2; i++) {
      var row = document.createElement('div');
      row.className = 'cfs-skeleton-line';
      row.style.width = i === 1 ? '70%' : '100%';
      block.appendChild(row);
    }
    skeleton.appendChild(block);

    body.appendChild(skeleton);
  }

  function renderResult(wrap, body, result) {
    var keyPoints = result.key_points && Array.isArray(result.key_points) ? result.key_points : [];
    var conclusion = result.conclusion || '';
    body.innerHTML = '';

    if (keyPoints.length > 0) {
      var kpLabel = document.createElement('p');
      kpLabel.className = 'cfs-section-label';
      kpLabel.textContent = 'Key Points';
      body.appendChild(kpLabel);

      var ul = document.createElement('ul');
      ul.className = 'cfs-key-points';
      keyPoints.forEach(function (point) {
        var li = document.createElement('li');
        li.textContent = point;
        ul.appendChild(li);
      });
      body.appendChild(ul);
    }

    if (conclusion) {
      var concLabel = document.createElement('p');
      concLabel.className = 'cfs-section-label';
      concLabel.textContent = 'Conclusion';
      body.appendChild(concLabel);

      var p = document.createElement('p');
      p.className = 'cfs-conclusion';
      p.textContent = conclusion;

      body.appendChild(p);
    }
  }

  function fetchSummary(wrap, postId, nonce, force) {
    var body = wrap._cfsBody;
    var regenBtn = wrap._cfsRegenBtn;

    showSkeleton(body);
    if (regenBtn) { regenBtn.disabled = true; regenBtn.classList.add('cfs-regen-btn--spinning'); }

    fetch(cfsData.restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfsData.nonce,
      },
      body: JSON.stringify({ post_id: parseInt(postId, 10), nonce: nonce, force_refresh: !!force }),
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        wrap._cfsLoaded = true;
        if (regenBtn) { regenBtn.disabled = false; regenBtn.classList.remove('cfs-regen-btn--spinning'); }

        if (!result.ok) {
          var errMsg =
            result.data && result.data.message
              ? result.data.message
              : 'An error occurred. Please try again.';
          body.innerHTML = '<p class="cfs-error">' + escapeHtml(errMsg) + '</p>';
          return;
        }

        renderResult(wrap, body, result.data);
      })
      .catch(function () {
        wrap._cfsLoaded = false;
        if (regenBtn) { regenBtn.disabled = false; regenBtn.classList.remove('cfs-regen-btn--spinning'); }
        body.innerHTML = '<p class="cfs-error">Network error. Please try again.</p>';
      });
  }

  function handleClick(e) {
    var btn = e.currentTarget;
    var wrap = btn.closest('.cfs-wrap');

    if (!wrap) { return; }

    if (!wrap._cfsPanel) {
      var created = createPanel(wrap);
      wrap._cfsPanel    = created.panel;
      wrap._cfsBody     = created.body;
      wrap._cfsRegenBtn = created.regenBtn;
      wrap._cfsLoaded   = false;

      var postId = btn.dataset.postId;
      var nonce  = btn.dataset.nonce;

      created.regenBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        fetchSummary(wrap, postId, nonce, true);
      });
    }

    var panel = wrap._cfsPanel;

    if (panel.classList.contains('cfs-open')) {
      panel.classList.remove('cfs-open');
      btn.setAttribute('aria-expanded', 'false');
      return;
    }

    panel.classList.add('cfs-open');
    btn.setAttribute('aria-expanded', 'true');

    if (wrap._cfsLoaded) { return; }

    fetchSummary(wrap, btn.dataset.postId, btn.dataset.nonce, false);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cfs-btn').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
      btn.addEventListener('click', handleClick);
    });
  });
})();
