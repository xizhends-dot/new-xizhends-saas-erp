(function () {
  function ajaxUrl(path, values) {
    var pageParams = new URLSearchParams(window.location.search);
    var tenant = pageParams.get('tenant');
    var query = new URLSearchParams();
    if (tenant) query.set('tenant', tenant);
    Object.keys(values || {}).forEach(function (key) {
      query.set(key, values[key]);
    });
    var text = query.toString();
    return text ? path + '?' + text : path;
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta instanceof HTMLMetaElement ? meta.content : '';
  }

  function requestJson(url, options) {
    var requestOptions = Object.assign({}, options || {});
    requestOptions.headers = Object.assign({ 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, requestOptions.headers || {});
    if ((requestOptions.method || '').toUpperCase() === 'POST') {
      requestOptions.headers['X-CSRF-Token'] = csrfToken();
    }

    return fetch(url, Object.assign({
      credentials: 'same-origin'
    }, requestOptions)).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || !payload.ok) throw payload;
        return payload;
      });
    });
  }

  var priceQuotePopover = null;
  var priceQuoteTrigger = null;
  var priceQuoteTimer = null;
  var priceQuoteHideTimer = null;

  function ensurePriceQuotePopover() {
    if (priceQuotePopover) return priceQuotePopover;
    priceQuotePopover = document.createElement('div');
    priceQuotePopover.className = 'price-quote-popover';
    priceQuotePopover.hidden = true;
    priceQuotePopover.addEventListener('mouseenter', function () {
      if (priceQuoteHideTimer) window.clearTimeout(priceQuoteHideTimer);
    });
    priceQuotePopover.addEventListener('mouseleave', function () {
      schedulePriceQuoteHide();
    });
    priceQuotePopover.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) return;
      var recalc = target.closest('[data-price-quote-recalc]');
      if (!recalc || !priceQuoteTrigger) return;
      event.preventDefault();
      loadPriceQuote(priceQuoteTrigger, priceQuoteValues(priceQuotePopover));
    });
    document.body.appendChild(priceQuotePopover);
    return priceQuotePopover;
  }

  function numericText(value, decimals) {
    var number = parseFloat(value);
    if (!Number.isFinite(number)) number = 0;
    return number.toFixed(decimals);
  }

  function sourceLabel(source) {
    if (source === 'actual_com_amount') return '订单实际国际运费';
    if (source === 'override') return '手动输入';
    return '租户默认运费';
  }

  function appendField(form, labelText, name, value, suffix) {
    var label = document.createElement('label');
    var span = document.createElement('span');
    var input = document.createElement('input');
    var suffixSpan = document.createElement('span');
    span.textContent = labelText;
    input.type = 'number';
    input.step = name === 'sale_price' ? '1' : '0.01';
    input.setAttribute('data-price-quote-field', name);
    input.value = String(value == null ? '' : value);
    suffixSpan.className = 'price-quote-suffix';
    suffixSpan.textContent = suffix;
    label.appendChild(span);
    label.appendChild(input);
    label.appendChild(suffixSpan);
    form.appendChild(label);
  }

  function appendMetric(container, labelText, valueText, className) {
    var row = document.createElement('div');
    var label = document.createElement('span');
    var value = document.createElement('strong');
    row.className = 'price-quote-metric';
    label.textContent = labelText;
    value.textContent = valueText;
    if (className) value.className = className;
    row.appendChild(label);
    row.appendChild(value);
    container.appendChild(row);
  }

  function renderPriceQuote(payload) {
    var popover = ensurePriceQuotePopover();
    var quote = payload && payload.quote ? payload.quote : {};
    popover.replaceChildren();

    var header = document.createElement('div');
    header.className = 'price-quote-head';
    var title = document.createElement('strong');
    title.textContent = '核价';
    var meta = document.createElement('span');
    meta.textContent = [quote.order_no || '', quote.item_code || ''].filter(Boolean).join(' · ');
    header.appendChild(title);
    header.appendChild(meta);
    popover.appendChild(header);

    var form = document.createElement('div');
    form.className = 'price-quote-form';
    appendField(form, '售价', 'sale_price', quote.sale_price == null ? 0 : quote.sale_price, '円');
    appendField(form, '运费', 'shipping', quote.shipping == null ? 0 : quote.shipping, '￥');
    appendField(form, '扣点', 'deduction', quote.deduction == null ? 0 : quote.deduction, '%');
    appendField(form, '成本', 'cost', quote.cost == null ? 0 : quote.cost, '￥');
    popover.appendChild(form);

    var metrics = document.createElement('div');
    metrics.className = 'price-quote-metrics';
    var profit = parseFloat(quote.profit == null ? 0 : quote.profit);
    appendMetric(metrics, '成交收入', '￥' + numericText(quote.actual_income, 2));
    appendMetric(metrics, '成本合计', '￥' + numericText(quote.total_cost, 2));
    appendMetric(metrics, '预计利润', '￥' + numericText(quote.profit, 2), profit >= 0 ? 'positive' : 'negative');
    appendMetric(metrics, '利润率', numericText(quote.profit_rate, 2) + '%', profit >= 0 ? 'positive' : 'negative');
    popover.appendChild(metrics);

    var foot = document.createElement('div');
    foot.className = 'price-quote-foot';
    var note = document.createElement('span');
    note.textContent = '汇率 ' + numericText(quote.exchange_rate, 4) + ' · ' + sourceLabel(String(quote.shipping_source || ''));
    var button = document.createElement('button');
    button.className = 'btn-xs';
    button.type = 'button';
    button.setAttribute('data-price-quote-recalc', '1');
    button.textContent = '重新计算';
    foot.appendChild(note);
    foot.appendChild(button);
    popover.appendChild(foot);
  }

  function renderPriceQuoteMessage(message) {
    var popover = ensurePriceQuotePopover();
    popover.replaceChildren();
    var body = document.createElement('div');
    body.className = 'price-quote-message';
    body.textContent = message;
    popover.appendChild(body);
  }

  function priceQuoteValues(popover) {
    var values = {};
    popover.querySelectorAll('[data-price-quote-field]').forEach(function (input) {
      if (!(input instanceof HTMLInputElement)) return;
      values[input.getAttribute('data-price-quote-field') || ''] = input.value;
    });
    return values;
  }

  function loadPriceQuote(trigger, overrides) {
    var itemId = trigger.getAttribute('data-item-id') || '';
    if (itemId === '') return;
    var values = Object.assign({ item_id: itemId }, overrides || {});
    renderPriceQuoteMessage('核价中...');
    positionPriceQuote(trigger);
    requestJson(ajaxUrl('/orders/ajax/price-quote', values))
      .then(function (payload) {
        renderPriceQuote(payload);
        positionPriceQuote(trigger);
      })
      .catch(function (payload) {
        renderPriceQuoteMessage(payload.message || '核价失败');
        positionPriceQuote(trigger);
      });
  }

  function positionPriceQuote(trigger) {
    var popover = ensurePriceQuotePopover();
    var rect = trigger.getBoundingClientRect();
    popover.hidden = false;
    popover.classList.add('show');
    var width = popover.offsetWidth || 320;
    var height = popover.offsetHeight || 240;
    var left = Math.min(Math.max(12, rect.left), Math.max(12, window.innerWidth - width - 12));
    var top = rect.bottom + 8;
    if (top + height > window.innerHeight - 12) {
      top = Math.max(12, rect.top - height - 8);
    }
    popover.style.left = left + 'px';
    popover.style.top = top + 'px';
  }

  function showPriceQuote(trigger) {
    if (priceQuoteHideTimer) window.clearTimeout(priceQuoteHideTimer);
    priceQuoteTrigger = trigger;
    loadPriceQuote(trigger);
  }

  function schedulePriceQuote(trigger) {
    if (priceQuoteTimer) window.clearTimeout(priceQuoteTimer);
    priceQuoteTimer = window.setTimeout(function () {
      showPriceQuote(trigger);
    }, 160);
  }

  function schedulePriceQuoteHide() {
    if (priceQuoteTimer) window.clearTimeout(priceQuoteTimer);
    if (priceQuoteHideTimer) window.clearTimeout(priceQuoteHideTimer);
    priceQuoteHideTimer = window.setTimeout(function () {
      if (!priceQuotePopover) return;
      priceQuotePopover.classList.remove('show');
      priceQuotePopover.hidden = true;
      priceQuoteTrigger = null;
    }, 220);
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

    var quoteTrigger = target.closest('[data-price-quote-trigger]');
    if (quoteTrigger) {
      event.preventDefault();
      showPriceQuote(quoteTrigger);
      return;
    }
    if (priceQuotePopover && !priceQuotePopover.hidden && !priceQuotePopover.contains(target)) {
      schedulePriceQuoteHide();
    }

    var rowReload = target.closest('[data-order-row-reload]');
    if (rowReload) {
      event.preventDefault();
      var block = rowReload.closest('.order-block');
      var orderId = rowReload.getAttribute('data-order-row-reload') || '';
      if (!block || orderId === '') return;
      rowReload.classList.add('loading');
      requestJson(ajaxUrl('/orders/ajax/row', { id: orderId }))
        .then(function (payload) {
          if (payload.html) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = payload.html;
            var next = wrapper.firstElementChild;
            if (next) block.replaceWith(next);
          }
        })
        .catch(function (payload) { alert(payload.message || '刷新订单行失败'); })
        .finally(function () { rowReload.classList.remove('loading'); });
    }

    var detailReload = target.closest('[data-order-detail-reload]');
    if (detailReload) {
      event.preventDefault();
      var targetId = detailReload.getAttribute('data-target') || '';
      var panel = document.getElementById(targetId);
      var detailOrderId = detailReload.getAttribute('data-order-detail-reload') || '';
      if (!panel || detailOrderId === '') return;
      detailReload.classList.add('loading');
      requestJson(ajaxUrl('/orders/ajax/detail', { id: detailOrderId }))
        .then(function (payload) { if (payload.html) panel.innerHTML = payload.html; })
        .catch(function (payload) { alert(payload.message || '刷新订单详情失败'); })
        .finally(function () { detailReload.classList.remove('loading'); });
    }

    var logisticsReload = target.closest('[data-logistics-reload]');
    if (logisticsReload) {
      event.preventDefault();
      var logisticsOrderId = logisticsReload.getAttribute('data-logistics-reload') || '';
      if (logisticsOrderId === '') return;
      logisticsReload.classList.add('loading');
      requestJson(ajaxUrl('/orders/ajax/logistics', { id: logisticsOrderId }))
        .then(function (payload) {
          alert(payload.message || '物流状态已刷新');
        })
        .catch(function (payload) { alert(payload.message || '刷新物流失败'); })
        .finally(function () { logisticsReload.classList.remove('loading'); });
    }

    var reviewToggle = target.closest('[data-review-toggle]');
    if (reviewToggle) {
      event.preventDefault();
      var reviewOrderId = reviewToggle.getAttribute('data-order-id') || '';
      var field = reviewToggle.getAttribute('data-review-toggle') || 'review_invited';
      if (reviewOrderId === '') return;
      var body = new URLSearchParams();
      body.set('order_id', reviewOrderId);
      body.set('field', field);
      requestJson(ajaxUrl('/orders/ajax/review'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
      }).then(function (payload) {
        var enabled = field === 'reviewed' ? payload.reviewed : payload.review_invited;
        reviewToggle.classList.toggle('on', !!enabled);
        reviewToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      }).catch(function (payload) {
        alert(payload.message || '更新评价状态失败');
      });
    }
  });

  document.addEventListener('mouseover', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var trigger = target.closest('[data-price-quote-trigger]');
    if (!trigger) return;
    schedulePriceQuote(trigger);
  });

  document.addEventListener('mouseout', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var trigger = target.closest('[data-price-quote-trigger]');
    if (!trigger) return;
    var related = event.relatedTarget;
    if (related instanceof Node && (trigger.contains(related) || (priceQuotePopover && priceQuotePopover.contains(related)))) {
      return;
    }
    schedulePriceQuoteHide();
  });

  document.addEventListener('focusin', function (event) {
    var target = event.target;
    if (target instanceof HTMLElement && target.matches('[data-price-quote-trigger]')) {
      showPriceQuote(target);
    }
  });

  window.addEventListener('scroll', function () {
    if (priceQuoteTrigger && priceQuotePopover && !priceQuotePopover.hidden) {
      positionPriceQuote(priceQuoteTrigger);
    }
  }, true);
})();
