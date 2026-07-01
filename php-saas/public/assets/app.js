document.addEventListener('click', function (event) {
  var target = event.target;
  if (!(target instanceof HTMLElement)) return;

  var more = target.closest('.more-btn');
  if (more) {
    var adv = document.getElementById(more.getAttribute('data-adv') || '');
    if (adv) {
      var on = adv.classList.toggle('show');
      more.classList.toggle('on', on);
      var text = more.firstChild;
      if (text) text.textContent = on ? '收起筛选 ' : '更多筛选 ';
    }
  }

  var detail = target.closest('.detail-toggle');
  if (detail) {
    var detailOn = detail.classList.toggle('on');
    detail.textContent = detailOn ? '收起详情' : '展开详情';
    var page = detail.closest('.order-page');
    if (page) {
      page.querySelectorAll('.sec-b.table-hidden, .sec-c').forEach(function (table) {
        table.classList.toggle('show', detailOn);
      });
    }
  }

  var actionToggle = target.closest('[data-toggle-actions]');
  if (actionToggle) {
    var panelId = actionToggle.getAttribute('data-toggle-actions') || '';
    var actionPanel = document.getElementById(panelId);
    if (actionPanel) {
      var actionsOn = actionPanel.classList.toggle('show');
      actionToggle.classList.toggle('on', actionsOn);
      actionToggle.setAttribute('aria-expanded', actionsOn ? 'true' : 'false');
    }
  }

  var selectionToggle = target.closest('[data-toggle-selection]');
  if (selectionToggle) {
    toggleOrderSelection(selectionToggle.closest('.order-page'));
  }

  var trigger = target.closest('[data-toggle-logs]');
  if (trigger) {
    var id = trigger.getAttribute('data-toggle-logs');
    var panel = document.getElementById(id || '');
    if (panel) {
      var on = panel.classList.toggle('show');
      trigger.classList.toggle('on', on);
    }
  }

  var editorTrigger = target.closest('[data-open-editor]');
  if (editorTrigger) {
    openEditor(editorTrigger.getAttribute('data-open-editor') || '');
  }

  var editorClose = target.closest('[data-close-editor]');
  if (editorClose) {
    closeEditor(editorClose.getAttribute('data-close-editor') || '');
  }

  var settingsTab = target.closest('[data-settings-tab]');
  if (settingsTab) {
    event.preventDefault();
    activateSettingsPane(settingsTab.getAttribute('data-settings-tab') || '', true);
  }

  if (target.classList.contains('drawer-backdrop')) {
    closeAllEditors();
  }
});

document.addEventListener('change', function (event) {
  var target = event.target;

  if (target instanceof HTMLInputElement && (target.classList.contains('order-check') || target.classList.contains('item-check'))) {
    var row = target.closest('.item-row');
    if (row) row.classList.toggle('row-selected', target.checked);
    refreshOrderSelection(target.closest('.order-page'));
  }

  if (!(target instanceof HTMLSelectElement)) return;

  var form = target.closest('.auto-submit-source');
  if (form instanceof HTMLFormElement) {
    form.submit();
  }
});

document.addEventListener('submit', function (event) {
  var form = event.target;
  if (!(form instanceof HTMLFormElement) || (form.id !== 'send-jp-form' && form.id !== 'xizhen-delivery-form')) return;

  if (!form.querySelector('input[data-selection-copy="1"]')) {
    event.preventDefault();
    alert(form.id === 'send-jp-form' ? '请先勾选要处理的订单' : '请先选中要导出的订单');
    return;
  }

  if (form.id === 'send-jp-form' && !confirm('确定要修改采购状态为【已发日本】吗?')) {
    event.preventDefault();
  }
});

function refreshOrderSelection(page) {
  if (!(page instanceof HTMLElement)) return;
  var selectable = page.querySelectorAll('.order-check, .item-check');
  var checked = page.querySelectorAll('.order-check:checked, .item-check:checked').length;
  var label = page.querySelector('.tbar-count strong:first-child');
  if (label) label.textContent = String(checked);
  var toggle = page.querySelector('[data-toggle-selection]');
  if (toggle) {
    var allSelected = selectable.length > 0 && checked === selectable.length;
    toggle.classList.toggle('on', allSelected);
    toggle.textContent = allSelected ? '取消全选' : '全选';
  }
  syncSelectionForm(page, 'order-export-form');
  syncSelectionForm(page, 'xizhen-delivery-form');
  syncSelectionForm(page, 'send-jp-form');
  syncSelectionForm(page, 'order-logistics-form');
}

function toggleOrderSelection(page) {
  if (!(page instanceof HTMLElement)) return;
  var selectable = page.querySelectorAll('.order-check, .item-check');
  var checked = page.querySelectorAll('.order-check:checked, .item-check:checked').length;
  var nextChecked = !(selectable.length > 0 && checked === selectable.length);
  selectable.forEach(function (checkbox) {
    if (!(checkbox instanceof HTMLInputElement)) return;
    checkbox.checked = nextChecked;
    var row = checkbox.closest('.item-row');
    if (row) row.classList.toggle('row-selected', nextChecked);
  });
  refreshOrderSelection(page);
}

function syncSelectionForm(page, formId) {
  var form = document.getElementById(formId);
  if (!(form instanceof HTMLFormElement)) return;
  form.querySelectorAll('input[data-selection-copy="1"]').forEach(function (input) {
    input.remove();
  });
  page.querySelectorAll('.order-check:checked, .item-check:checked').forEach(function (checkbox) {
    if (!(checkbox instanceof HTMLInputElement)) return;
    var clone = document.createElement('input');
    clone.type = 'hidden';
    clone.name = checkbox.name;
    clone.value = checkbox.value;
    clone.setAttribute('data-selection-copy', '1');
    form.appendChild(clone);
  });
}

document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    closeAllEditors();
  }
});

function openEditor(id) {
  var drawer = document.getElementById(id);
  if (!drawer) return;
  closeAllEditors();
  ensureBackdrop().classList.add('show');
  drawer.classList.add('show');
  drawer.setAttribute('aria-hidden', 'false');
  document.body.classList.add('drawer-open');
}

function closeEditor(id) {
  var drawer = document.getElementById(id);
  if (drawer) {
    drawer.classList.remove('show');
    drawer.setAttribute('aria-hidden', 'true');
  }
  if (!document.querySelector('.editor-drawer.show')) {
    var backdrop = document.querySelector('.drawer-backdrop');
    if (backdrop) backdrop.classList.remove('show');
    document.body.classList.remove('drawer-open');
  }
}

function closeAllEditors() {
  document.querySelectorAll('.editor-drawer.show').forEach(function (drawer) {
    drawer.classList.remove('show');
    drawer.setAttribute('aria-hidden', 'true');
  });
  var backdrop = document.querySelector('.drawer-backdrop');
  if (backdrop) backdrop.classList.remove('show');
  document.body.classList.remove('drawer-open');
}

function ensureBackdrop() {
  var backdrop = document.querySelector('.drawer-backdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.className = 'drawer-backdrop';
    document.body.appendChild(backdrop);
  }
  return backdrop;
}

function activateSettingsPane(key, syncHash) {
  var layout = document.querySelector('[data-settings-layout]');
  if (!(layout instanceof HTMLElement)) return;

  var tabs = Array.prototype.slice.call(layout.querySelectorAll('[data-settings-tab]'));
  var panes = Array.prototype.slice.call(layout.querySelectorAll('[data-settings-pane]'));
  if (tabs.length === 0 || panes.length === 0) return;

  var targetKey = key;
  var hasTarget = tabs.some(function (tab) {
    return tab.getAttribute('data-settings-tab') === targetKey;
  });
  if (!hasTarget) targetKey = tabs[0].getAttribute('data-settings-tab') || '';

  tabs.forEach(function (tab) {
    var active = tab.getAttribute('data-settings-tab') === targetKey;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  panes.forEach(function (pane) {
    var active = pane.getAttribute('data-settings-pane') === targetKey;
    pane.classList.toggle('active', active);
    pane.hidden = !active;
  });

  if (syncHash && targetKey !== '') {
    var nextHash = '#' + targetKey;
    if (window.location.hash !== nextHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', nextHash);
    }
  }
}

function initSettingsPane() {
  if (!document.querySelector('[data-settings-layout]')) return;
  activateSettingsPane((window.location.hash || '').replace(/^#/, ''), false);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initSettingsPane);
} else {
  initSettingsPane();
}

window.addEventListener('hashchange', initSettingsPane);

(function () {
  var contextRow = null;
  var mailColumnMeta = {
    star: { css: '--kefu-star-w', min: 30, max: 120 },
    from: { css: '--kefu-from-w', min: 90, max: 360 },
    tags: { css: '--kefu-tags-w', min: 90, max: 300 },
    date: { css: '--kefu-date-w', min: 80, max: 240 }
  };
  var mailColumnStoreKey = 'kefu_mail_column_widths';

  document.addEventListener('change', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLInputElement)) return;

    if (target.id === 'kefu-select-all') {
      setAllMailRows(target.checked);
      refreshMailSelection();
      return;
    }

    if (target.classList.contains('kefu-row-check')) {
      var row = target.closest('.kefu-mail-row');
      if (row) row.classList.toggle('selected', target.checked);
      refreshMailSelection();
    }
  });

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

    var star = target.closest('.kefu-star');
    if (star) {
      event.preventDefault();
      event.stopPropagation();
      var row = star.closest('.kefu-mail-row');
      if (row) {
        selectOnlyMailRow(row);
        submitMailAction(star.classList.contains('on') ? 'unimportant' : 'important');
      }
      return;
    }

    var menuButton = target.closest('.kefu-context-menu button[data-mail-action]');
    if (menuButton) {
      var action = menuButton.getAttribute('data-mail-action') || '';
      handleMailContextAction(action);
      hideMailContextMenu();
      return;
    }

    if (!target.closest('.kefu-context-menu')) {
      hideMailContextMenu();
    }
  });

  document.addEventListener('contextmenu', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var row = target.closest('.kefu-mail-row');
    if (!row) return;

    event.preventDefault();
    contextRow = row;
    var checked = row.querySelector('.kefu-row-check');
    if (checked instanceof HTMLInputElement && !checked.checked) {
      selectOnlyMailRow(row);
    }
    showMailContextMenu(event.clientX, event.clientY);
  });

  document.addEventListener('mousedown', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var handle = target.closest('.kefu-col-resizer');
    if (!handle) return;

    var cell = handle.closest('[data-mail-resize]');
    var main = handle.closest('.kefu-mail-main');
    if (!(cell instanceof HTMLElement) || !(main instanceof HTMLElement)) return;

    var key = cell.getAttribute('data-mail-resize') || '';
    var meta = mailColumnMeta[key];
    if (!meta) return;

    event.preventDefault();
    event.stopPropagation();

    var startX = event.clientX;
    var startWidth = cell.getBoundingClientRect().width;
    document.body.classList.add('kefu-col-resizing');
    var head = cell.closest('.kefu-list-head');
    if (head) head.classList.add('resizing');

    function onMove(moveEvent) {
      var next = clampMailColumn(startWidth + moveEvent.clientX - startX, meta.min, meta.max);
      main.style.setProperty(meta.css, next + 'px');
    }

    function onUp(upEvent) {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      document.body.classList.remove('kefu-col-resizing');
      if (head) head.classList.remove('resizing');
      var finalWidth = clampMailColumn(startWidth + upEvent.clientX - startX, meta.min, meta.max);
      saveMailColumnWidth(key, finalWidth);
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });

  document.addEventListener('dblclick', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var handle = target.closest('.kefu-col-resizer');
    if (!handle) return;
    var cell = handle.closest('[data-mail-resize]');
    var main = handle.closest('.kefu-mail-main');
    if (!(cell instanceof HTMLElement) || !(main instanceof HTMLElement)) return;
    var key = cell.getAttribute('data-mail-resize') || '';
    var meta = mailColumnMeta[key];
    if (!meta) return;
    event.preventDefault();
    main.style.removeProperty(meta.css);
    saveMailColumnWidth(key, null);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') hideMailContextMenu();
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || form.id !== 'mail-bulk-form') return;

    var selected = selectedMailCount();
    if (selected === 0) {
      event.preventDefault();
      alert('请先选择邮件');
      return;
    }

    var submitter = event.submitter;
    var formaction = submitter instanceof HTMLElement ? (submitter.getAttribute('formaction') || '') : '';
    if (formaction.indexOf('/mail/move') >= 0) {
      var targetFolder = form.querySelector('select[name="target_folder_id"]');
      if (!(targetFolder instanceof HTMLSelectElement) || targetFolder.value === '0') {
        event.preventDefault();
        alert('请选择要移动到的文件夹');
      }
    }
  });

  function setAllMailRows(checked) {
    document.querySelectorAll('.kefu-row-check').forEach(function (input) {
      if (!(input instanceof HTMLInputElement)) return;
      input.checked = checked;
      var row = input.closest('.kefu-mail-row');
      if (row) row.classList.toggle('selected', checked);
    });
  }

  function selectOnlyMailRow(row) {
    setAllMailRows(false);
    var input = row.querySelector('.kefu-row-check');
    if (input instanceof HTMLInputElement) {
      input.checked = true;
      row.classList.add('selected');
    }
    refreshMailSelection();
  }

  function refreshMailSelection() {
    var checks = Array.prototype.slice.call(document.querySelectorAll('.kefu-row-check'));
    var selected = selectedMailCount();

    var count = document.getElementById('kefu-selected-count');
    if (count) count.textContent = String(selected);

    var all = document.getElementById('kefu-select-all');
    if (all instanceof HTMLInputElement) {
      all.checked = checks.length > 0 && selected === checks.length;
      all.indeterminate = selected > 0 && selected < checks.length;
    }
  }

  function selectedMailCount() {
    return document.querySelectorAll('.kefu-row-check:checked').length;
  }

  function submitMailAction(action) {
    var form = document.getElementById('mail-bulk-form');
    if (!(form instanceof HTMLFormElement)) return;

    var selected = selectedMailCount();
    if (selected === 0) {
      alert('请先选择邮件');
      return;
    }

    if (action === 'delete' && !confirm('确认软删除选中的邮件？')) {
      return;
    }

    var actionSelect = form.querySelector('select[name="action"]');
    if (actionSelect instanceof HTMLSelectElement) {
      actionSelect.value = action;
    }
    form.action = '/mail/action';
    form.submit();
  }

  function handleMailContextAction(action) {
    if (!contextRow) return;

    if (action === 'open') {
      var link = contextRow.querySelector('.kefu-row-main');
      if (link instanceof HTMLAnchorElement) window.location.href = link.href;
      return;
    }

    if (action === 'move') {
      var moveSelect = document.querySelector('#mail-bulk-form select[name="target_folder_id"]');
      if (moveSelect instanceof HTMLSelectElement) {
        moveSelect.focus();
        moveSelect.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      return;
    }

    submitMailAction(action);
  }

  function showMailContextMenu(x, y) {
    var menu = document.getElementById('kefu-mail-context');
    if (!(menu instanceof HTMLElement)) return;

    menu.classList.add('show');
    menu.setAttribute('aria-hidden', 'false');
    var rect = menu.getBoundingClientRect();
    var left = Math.min(x, window.innerWidth - rect.width - 8);
    var top = Math.min(y, window.innerHeight - rect.height - 8);
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }

  function hideMailContextMenu() {
    var menu = document.getElementById('kefu-mail-context');
    if (!(menu instanceof HTMLElement)) return;
    menu.classList.remove('show');
    menu.setAttribute('aria-hidden', 'true');
  }

  function clampMailColumn(value, min, max) {
    return Math.max(min, Math.min(max, Math.round(value)));
  }

  function readMailColumnWidths() {
    try {
      var raw = localStorage.getItem(mailColumnStoreKey);
      var parsed = raw ? JSON.parse(raw) : {};
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function saveMailColumnWidth(key, width) {
    var saved = readMailColumnWidths();
    if (width === null) {
      delete saved[key];
    } else {
      saved[key] = width;
    }
    try {
      localStorage.setItem(mailColumnStoreKey, JSON.stringify(saved));
    } catch (error) {
      // 忽略浏览器本地存储不可用的情况。
    }
  }

  function applySavedMailColumnWidths() {
    var main = document.querySelector('.kefu-mail-main');
    if (!(main instanceof HTMLElement)) return;
    var saved = readMailColumnWidths();
    Object.keys(mailColumnMeta).forEach(function (key) {
      var meta = mailColumnMeta[key];
      var value = parseInt(saved[key], 10);
      if (!Number.isFinite(value)) return;
      main.style.setProperty(meta.css, clampMailColumn(value, meta.min, meta.max) + 'px');
    });
  }

  function initMailInteractions() {
    applySavedMailColumnWidths();
    refreshMailSelection();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMailInteractions);
  } else {
    initMailInteractions();
  }
})();

(function () {
  var preview = null;
  var previewImage = null;

  function ensureOrderImagePreview() {
    if (preview && previewImage) return;

    preview = document.createElement('div');
    preview.className = 'order-image-preview';
    preview.setAttribute('aria-hidden', 'true');

    previewImage = document.createElement('img');
    previewImage.alt = '';
    preview.appendChild(previewImage);
    document.body.appendChild(preview);
  }

  function moveOrderImagePreview(event) {
    if (!(preview instanceof HTMLElement)) return;

    var rect = preview.getBoundingClientRect();
    var gap = 18;
    var left = event.clientX + gap;
    var top = event.clientY + gap;

    if (left + rect.width > window.innerWidth - 8) {
      left = event.clientX - rect.width - gap;
    }
    if (top + rect.height > window.innerHeight - 8) {
      top = event.clientY - rect.height - gap;
    }

    preview.style.left = Math.max(8, left) + 'px';
    preview.style.top = Math.max(8, top) + 'px';
  }

  function showOrderImagePreview(link, event) {
    ensureOrderImagePreview();
    if (!(preview instanceof HTMLElement) || !(previewImage instanceof HTMLImageElement)) return;

    var src = link.getAttribute('data-preview-src') || link.getAttribute('href') || '';
    if (src === '' || src.indexOf('/assets/no-image.svg') >= 0) return;

    previewImage.src = src;
    preview.classList.add('show');
    preview.setAttribute('aria-hidden', 'false');
    moveOrderImagePreview(event);
  }

  function hideOrderImagePreview() {
    if (!(preview instanceof HTMLElement)) return;
    preview.classList.remove('show');
    preview.setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('mouseover', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

    var link = target.closest('.order-image-link');
    if (link instanceof HTMLElement) {
      showOrderImagePreview(link, event);
    }
  });

  document.addEventListener('mousemove', function (event) {
    if (preview instanceof HTMLElement && preview.classList.contains('show')) {
      moveOrderImagePreview(event);
    }
  });

  document.addEventListener('mouseout', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;

    var link = target.closest('.order-image-link');
    if (!(link instanceof HTMLElement)) return;
    if (event.relatedTarget instanceof Node && link.contains(event.relatedTarget)) return;

    hideOrderImagePreview();
  });
})();
