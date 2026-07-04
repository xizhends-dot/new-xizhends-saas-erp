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
    requestOptions.headers = Object.assign({ 'X-Requested-With': 'fetch' }, requestOptions.headers || {});
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

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

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
})();
