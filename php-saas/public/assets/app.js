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

  var statusMove = target.closest('[data-purchase-status-move]');
  if (statusMove) {
    event.preventDefault();
    movePurchaseStatusRow(statusMove.closest('[data-purchase-status-row]'), statusMove.getAttribute('data-purchase-status-move') || '');
  }

  var statusDelete = target.closest('[data-purchase-status-delete]');
  if (statusDelete) {
    event.preventDefault();
    deletePurchaseStatusRow(statusDelete.closest('[data-purchase-status-row]'));
  }

  var statusAdd = target.closest('[data-purchase-status-add]');
  if (statusAdd) {
    event.preventDefault();
    addPurchaseStatusRow(statusAdd.closest('[data-purchase-status-editor]'));
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

  if (target instanceof HTMLInputElement && target.matches('[data-image-file-input]')) {
    handleDrawerImageFileChange(target);
  }

  if (!(target instanceof HTMLSelectElement)) return;

  var form = target.closest('.auto-submit-source');
  if (form instanceof HTMLFormElement) {
    ensureCsrfField(form);
    form.submit();
  }

  if (target.matches('[data-source-status-source]')) {
    syncPurchaseStatusForSource(target);
  }

  if (target.matches('[data-order-source-filter]')) {
    syncOrderStatusFilter(target);
  }

  if (target.matches('[data-batch-status-source]')) {
    syncBatchStatusForSource(target);
  }
});

document.addEventListener('submit', function (event) {
  var form = event.target;
  if (form instanceof HTMLFormElement && form.id === 'purchase-status-form') {
    serializePurchaseStatuses(form);
    return;
  }

  if (form instanceof HTMLFormElement && form.matches('.drawer-image-upload-form')) {
    event.preventDefault();
    submitDrawerImageForm(form);
    return;
  }

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
  ensureCsrfField(form);
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

document.addEventListener('paste', function (event) {
  var target = event.target;
  if (!(target instanceof HTMLElement)) return;

  var pasteTarget = target.closest('[data-image-paste-input], [data-image-paste-area]');
  if (!(pasteTarget instanceof HTMLElement)) return;

  var area = pasteTarget.matches('[data-image-paste-area]')
    ? pasteTarget
    : pasteTarget.closest('[data-image-paste-area]');
  if (!(area instanceof HTMLElement)) return;

  var file = imageFileFromClipboard(event);
  if (!file) return;

  event.preventDefault();
  readDrawerImageFile(area, file, 'paste');
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

function findDrawerImageAreaByForm(form) {
  if (!(form instanceof HTMLFormElement) || form.id === '') return null;
  var areas = document.querySelectorAll('[data-image-paste-area]');
  for (var i = 0; i < areas.length; i++) {
    var area = areas[i];
    if (!(area instanceof HTMLElement)) continue;
    if (area.getAttribute('data-paste-target') === form.id) {
      return area;
    }
    var deleteForm = drawerImageDeleteFormForArea(area);
    if (deleteForm instanceof HTMLFormElement && deleteForm.id === form.id) {
      return area;
    }
  }
  return null;
}

function drawerImageFormHasPayload(form) {
  var area = findDrawerImageAreaByForm(form);
  if (!(area instanceof HTMLElement)) return true;

  var fileInput = area.querySelector('[data-image-file-input]');
  var base64Input = area.querySelector('[data-image-base64]');
  var hasFile = fileInput instanceof HTMLInputElement && fileInput.files && fileInput.files.length > 0;
  var hasPasted = base64Input instanceof HTMLTextAreaElement && base64Input.value.trim() !== '';
  return hasFile || hasPasted;
}

function imageFileFromClipboard(event) {
  var clipboard = event.clipboardData || (event.originalEvent && event.originalEvent.clipboardData);
  if (!clipboard || !clipboard.items) return null;

  for (var i = 0; i < clipboard.items.length; i++) {
    var item = clipboard.items[i];
    if (!item || item.kind !== 'file') continue;
    var file = item.getAsFile();
    if (file && file.type && file.type.indexOf('image/') === 0) {
      return file;
    }
  }
  return null;
}

function handleDrawerImageFileChange(input) {
  var area = input.closest('[data-image-paste-area]');
  if (!(area instanceof HTMLElement) || !input.files || input.files.length === 0) return;
  var file = input.files[0];
  if (!file || !file.type || file.type.indexOf('image/') !== 0) return;

  var base64Input = area.querySelector('[data-image-base64]');
  if (base64Input instanceof HTMLTextAreaElement) {
    base64Input.value = '';
  }
  readDrawerImageFile(area, file, 'file');
}

function readDrawerImageFile(area, file, mode) {
  var reader = new FileReader();
  reader.onload = function (loadEvent) {
    var result = loadEvent.target ? loadEvent.target.result : '';
    if (typeof result !== 'string' || result === '') return;
    setDrawerImagePreview(area, result, mode);
  };
  reader.readAsDataURL(file);
}

function submitDrawerImageForm(form) {
  var action = form.getAttribute('action') || '';
  var area = findDrawerImageAreaByForm(form);
  if (!(area instanceof HTMLElement)) return;

  if (action === '/orders/images/upload' && !drawerImageFormHasPayload(form)) {
    alert('请先选择或粘贴图片');
    return;
  }
  if (action === '/orders/images/delete' && !confirm('确定削除图片？')) {
    return;
  }

  setDrawerImageBusy(area, true);
  fetch(action, {
    method: 'POST',
    body: new FormData(form),
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'same-origin'
  }).then(function (response) {
    return response.json().catch(function () {
      return { ok: false, message: '图片操作失败。' };
    }).then(function (payload) {
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || '图片操作失败。');
      }
      return payload;
    });
  }).then(function (payload) {
    if (action === '/orders/images/delete') {
      clearDrawerImagePreview(area);
    } else {
      setDrawerImageSaved(area, payload.path || '');
    }
  }).catch(function (error) {
    alert(error && error.message ? error.message : '图片操作失败。');
  }).finally(function () {
    setDrawerImageBusy(area, false);
  });
}

function setDrawerImageBusy(area, busy) {
  area.classList.toggle('is-saving', busy);
  area.querySelectorAll('.drawer-image-actions button').forEach(function (button) {
    if (button instanceof HTMLButtonElement) {
      button.disabled = busy;
    }
  });
}

function setDrawerImageSaved(area, path) {
  if (path !== '') {
    var preview = area.querySelector('.preview-image');
    if (preview instanceof HTMLImageElement) {
      preview.src = path;
    }
    var pathNode = area.querySelector('.drawer-image-path');
    if (!(pathNode instanceof HTMLElement)) {
      pathNode = document.createElement('div');
      pathNode.className = 'drawer-image-path';
      var controls = area.querySelector('.drawer-image-controls');
      if (controls) {
        area.insertBefore(pathNode, controls);
      } else {
        area.appendChild(pathNode);
      }
    }
    pathNode.textContent = path;
  }
  ensureDrawerImageDeleteButton(area);
  resetDrawerImageInputs(area, '已保存');
}

function clearDrawerImagePreview(area) {
  area.querySelectorAll('.preview-image, .drawer-image-path').forEach(function (node) {
    node.remove();
  });

  if (!area.querySelector('.drawer-image-empty')) {
    var empty = document.createElement('div');
    empty.className = 'drawer-image-empty';
    empty.textContent = '暂无图片';
    var controls = area.querySelector('.drawer-image-controls');
    if (controls) {
      area.insertBefore(empty, controls);
    } else {
      area.appendChild(empty);
    }
  }
  resetDrawerImageInputs(area, '');
  area.classList.remove('has-image');
  var deleteButton = area.querySelector('[data-image-delete-button]');
  if (deleteButton) {
    deleteButton.remove();
  }
}

function resetDrawerImageInputs(area, pasteText) {
  var fileInput = area.querySelector('[data-image-file-input]');
  if (fileInput instanceof HTMLInputElement) {
    fileInput.value = '';
  }
  var base64Input = area.querySelector('[data-image-base64]');
  if (base64Input instanceof HTMLTextAreaElement) {
    base64Input.value = '';
  }
  var pasteInput = area.querySelector('[data-image-paste-input]');
  if (pasteInput instanceof HTMLInputElement) {
    pasteInput.value = pasteText;
    pasteInput.classList.toggle('has-image', pasteText !== '');
  }
}

function drawerImageDeleteFormForArea(area) {
  var uploadFormId = area.getAttribute('data-paste-target') || '';
  if (uploadFormId === '') return null;
  var deleteFormId = uploadFormId.replace('drawer-image-', 'drawer-image-delete-');
  var form = document.getElementById(deleteFormId);
  return form instanceof HTMLFormElement ? form : null;
}

function ensureDrawerImageDeleteButton(area) {
  if (area.querySelector('[data-image-delete-button]')) return;
  var deleteForm = drawerImageDeleteFormForArea(area);
  var actions = area.querySelector('.drawer-image-actions');
  if (!(deleteForm instanceof HTMLFormElement) || !(actions instanceof HTMLElement)) return;

  var button = document.createElement('button');
  button.className = 'btn btn-xs danger';
  button.type = 'submit';
  button.setAttribute('form', deleteForm.id);
  button.setAttribute('data-image-delete-button', '');
  button.textContent = '削除';
  actions.appendChild(button);
}

function setDrawerImagePreview(area, src, mode) {
  if (!(area instanceof HTMLElement)) return;

  var base64Input = area.querySelector('[data-image-base64]');
  if (base64Input instanceof HTMLTextAreaElement) {
    base64Input.value = mode === 'paste' ? src : '';
  }

  if (mode === 'paste') {
    var fileInput = area.querySelector('[data-image-file-input]');
    if (fileInput instanceof HTMLInputElement) {
      fileInput.value = '';
    }
  }

  area.querySelectorAll('.drawer-image-empty, .drawer-image-path').forEach(function (node) {
    node.remove();
  });

  var preview = area.querySelector('.preview-image');
  if (!(preview instanceof HTMLImageElement)) {
    preview = document.createElement('img');
    preview.className = 'preview-image';
    preview.alt = '';
    var controls = area.querySelector('.drawer-image-controls');
    if (controls) {
      area.insertBefore(preview, controls);
    } else {
      area.appendChild(preview);
    }
  }
  preview.src = src;

  var pasteInput = area.querySelector('[data-image-paste-input]');
  if (pasteInput instanceof HTMLInputElement) {
    pasteInput.value = mode === 'paste' ? '已粘贴图片，点击提交保存' : '已选择图片，点击提交保存';
    pasteInput.classList.add('has-image');
  }
  area.classList.add('has-image');
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

function csrfToken() {
  var meta = document.querySelector('meta[name="csrf-token"]');
  return meta instanceof HTMLMetaElement ? meta.content : '';
}

function ensureCsrfField(form) {
  if (!(form instanceof HTMLFormElement)) return;
  if ((form.method || '').toLowerCase() !== 'post') return;
  if (form.querySelector('input[name="_token"]')) return;
  var token = csrfToken();
  if (token === '') return;
  var input = document.createElement('input');
  input.type = 'hidden';
  input.name = '_token';
  input.value = token;
  form.appendChild(input);
}

function parseStatusOptions(value) {
  try {
    var parsed = JSON.parse(value || '{}');
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}

function optionValuesForSource(options, source) {
  var key = source === 'jp_stock' || source === 'cn_purchase' || source === 'pending' ? source : 'all';
  var values = options[key] || [];
  return Array.isArray(values) ? values.map(function (value) {
    if (value && typeof value === 'object') {
      return {
        value: value.value === undefined || value.value === null ? '' : String(value.value),
        label: value.label === undefined || value.label === null ? '' : String(value.label),
        disabled: Boolean(value.disabled)
      };
    }
    return String(value);
  }).filter(function (value) {
    return typeof value === 'string' ? value !== '' : value.label !== '';
  }) : [];
}

function replaceStatusSelectOptions(select, values, current, includeAllOption, preserveMissing, blankText) {
  if (!(select instanceof HTMLSelectElement)) return;
  var selected = current == null ? select.value : String(current);
  var originalValues = values.map(function (value) {
    return typeof value === 'string' ? value : String(value.value || '');
  }).filter(Boolean);
  select.replaceChildren();

  if (includeAllOption) {
    var blank = document.createElement('option');
    blank.value = '';
    blank.textContent = blankText || '全部状态';
    select.appendChild(blank);

    if (blankText === '— 待处理订单 —') {
      var all = document.createElement('option');
      all.value = '__ALL__';
      all.textContent = '全部订单';
      select.appendChild(all);
    }
  }

  if (preserveMissing && selected !== '' && selected !== '__ALL__' && originalValues.indexOf(selected) < 0) {
    values = [selected].concat(values);
  }

  values.forEach(function (value) {
    var option = document.createElement('option');
    if (value && typeof value === 'object') {
      option.value = value.value || '';
      option.textContent = value.label || value.value || '';
      option.disabled = Boolean(value.disabled);
    } else {
      option.value = value;
      option.textContent = value;
    }
    select.appendChild(option);
  });

  if (selected !== '' && selected !== '__ALL__' && (preserveMissing || originalValues.indexOf(selected) >= 0)) {
    select.value = selected;
  } else if (selected === '__ALL__' && includeAllOption) {
    select.value = '__ALL__';
  } else {
    select.value = includeAllOption ? '' : (originalValues[0] || '');
  }
}

function syncPurchaseStatusForSource(sourceSelect) {
  if (!(sourceSelect instanceof HTMLSelectElement)) return;
  var form = sourceSelect.closest('form');
  if (!(form instanceof HTMLFormElement)) return;
  var statusSelect = form.querySelector('[data-source-status-target]');
  if (!(statusSelect instanceof HTMLSelectElement)) return;
  var options = parseStatusOptions(statusSelect.getAttribute('data-status-options') || '{}');
  replaceStatusSelectOptions(statusSelect, optionValuesForSource(options, sourceSelect.value), statusSelect.value, false, false, '');
}

function syncOrderStatusFilter(sourceSelect) {
  if (!(sourceSelect instanceof HTMLSelectElement)) return;
  var form = sourceSelect.closest('form');
  if (!(form instanceof HTMLFormElement)) return;
  var statusSelect = form.querySelector('[data-order-status-filter]');
  if (!(statusSelect instanceof HTMLSelectElement)) return;
  var options = parseStatusOptions(statusSelect.getAttribute('data-status-options') || '{}');
  statusSelect.disabled = false;
  statusSelect.title = '';
  replaceStatusSelectOptions(statusSelect, optionValuesForSource(options, sourceSelect.value), statusSelect.value, true, false, '全部状态');
}

function syncBatchStatusForSource(sourceSelect) {
  if (!(sourceSelect instanceof HTMLSelectElement)) return;
  var formId = sourceSelect.getAttribute('form') || '';
  var form = formId ? document.getElementById(formId) : sourceSelect.closest('form');
  if (!(form instanceof HTMLFormElement)) return;
  var statusSelect = document.querySelector('[data-batch-status-target][form="' + form.id + '"]');
  if (!(statusSelect instanceof HTMLSelectElement)) return;
  var options = parseStatusOptions(statusSelect.getAttribute('data-status-options') || '{}');
  var hasSpecificSource = sourceSelect.value === 'jp_stock' || sourceSelect.value === 'cn_purchase' || sourceSelect.value === 'pending';
  statusSelect.disabled = !hasSpecificSource;
  statusSelect.title = hasSpecificSource ? '' : '请先选择状态适用货源地';
  replaceStatusSelectOptions(statusSelect, optionValuesForSource(options, sourceSelect.value), statusSelect.value, true, false, hasSpecificSource ? '选择状态' : '请先选择货源');
}

function initSettingsPane() {
  if (!document.querySelector('[data-settings-layout]')) return;
  activateSettingsPane((window.location.hash || '').replace(/^#/, ''), false);
}

function movePurchaseStatusRow(row, direction) {
  if (!(row instanceof HTMLElement)) return;
  if (direction === 'up' && row.previousElementSibling) {
    row.parentElement.insertBefore(row, row.previousElementSibling);
  }
  if (direction === 'down' && row.nextElementSibling) {
    row.parentElement.insertBefore(row.nextElementSibling, row);
  }
}

function deletePurchaseStatusRow(row) {
  if (!(row instanceof HTMLElement) || row.getAttribute('data-locked') === '1') return;
  row.remove();
}

function addPurchaseStatusRow(editor) {
  if (!(editor instanceof HTMLElement)) return;
  var input = editor.querySelector('[data-purchase-status-new]');
  var list = editor.querySelector('[data-purchase-status-list]');
  if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) return;
  var value = input.value.trim();
  if (value === '') {
    input.focus();
    return;
  }
  var duplicate = Array.prototype.some.call(list.querySelectorAll('[data-purchase-status-name]'), function (field) {
    return field instanceof HTMLInputElement && field.value.trim() === value;
  });
  if (duplicate) {
    alert('状态名称已存在');
    input.focus();
    return;
  }

  var row = document.createElement('div');
  row.className = 'purchase-status-row';
  row.setAttribute('data-purchase-status-row', '');
  row.setAttribute('data-locked', '0');
  row.innerHTML = [
    '<input class="purchase-status-name" maxlength="32" data-purchase-status-name>',
    '<div class="purchase-status-actions">',
    '<button class="btn-xs" type="button" data-purchase-status-move="up">上移</button>',
    '<button class="btn-xs" type="button" data-purchase-status-move="down">下移</button>',
    '<button class="btn-xs danger-text" type="button" data-purchase-status-delete>删除</button>',
    '</div>'
  ].join('');
  var nameInput = row.querySelector('[data-purchase-status-name]');
  if (nameInput instanceof HTMLInputElement) nameInput.value = value;
  list.appendChild(row);
  input.value = '';
  if (nameInput instanceof HTMLInputElement) nameInput.focus();
}

function serializePurchaseStatuses(form) {
  document.querySelectorAll('[data-purchase-status-editor]').forEach(function (editor) {
    if (!(editor instanceof HTMLElement)) return;
    var key = editor.getAttribute('data-purchase-status-editor') || '';
    var output = form.querySelector('[data-purchase-status-json="' + key + '"]');
    var list = editor.querySelector('[data-purchase-status-list]');
    if (!(output instanceof HTMLInputElement) || !(list instanceof HTMLElement)) return;
    var statuses = Array.prototype.map.call(list.querySelectorAll('[data-purchase-status-name]'), function (field) {
      return field instanceof HTMLInputElement ? field.value.trim() : '';
    }).filter(function (value) {
      return value !== '';
    });
    output.value = JSON.stringify(statuses);
  });
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
    ensureCsrfField(form);
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
  function parseStoreApiJson(value, fallback) {
    try {
      var parsed = JSON.parse(value || '');
      return parsed && typeof parsed === 'object' ? parsed : fallback;
    } catch (error) {
      return fallback;
    }
  }

  function fieldValue(values, key) {
    if (!values || typeof values !== 'object') return '';
    var value = values[key];
    return value === undefined || value === null ? '' : String(value);
  }

  function renderStoreApiFields(form) {
    var platformSelect = form.querySelector('[data-store-api-platform]');
    var target = form.querySelector('[data-store-api-field-list]');
    if (!(platformSelect instanceof HTMLSelectElement) || !(target instanceof HTMLElement)) return;

    var definitions = parseStoreApiJson(form.getAttribute('data-store-api-definitions') || '{}', {});
    var values = parseStoreApiJson(form.getAttribute('data-store-api-values') || '{}', {});
    var fields = definitions[platformSelect.value] || [];
    target.replaceChildren();

    if (!fields.length) {
      var empty = document.createElement('div');
      empty.className = 'store-api-empty';
      empty.textContent = '该平台暂无需API配置';
      target.appendChild(empty);
      return;
    }

    fields.forEach(function (field) {
      if (!field || typeof field !== 'object') return;

      var label = document.createElement('label');
      label.className = 'store-api-field';

      var title = document.createElement('span');
      title.textContent = field.label || field.key || '';
      label.appendChild(title);

      var input = document.createElement('input');
      input.name = 'api_fields[' + (field.key || '') + ']';
      input.value = fieldValue(values, field.key || '');
      input.autocomplete = 'off';
      label.appendChild(input);

      if (field.hint) {
        var hint = document.createElement('small');
        hint.textContent = field.hint;
        label.appendChild(hint);
      }

      target.appendChild(label);
    });
  }

  function initStoreApiForms() {
    document.querySelectorAll('[data-store-api-form]').forEach(function (form) {
      if (!(form instanceof HTMLFormElement)) return;
      renderStoreApiFields(form);
      var platformSelect = form.querySelector('[data-store-api-platform]');
      if (platformSelect instanceof HTMLSelectElement) {
        platformSelect.addEventListener('change', function () {
          form.setAttribute('data-store-api-values', '{}');
          renderStoreApiFields(form);
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStoreApiForms);
  } else {
    initStoreApiForms();
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

(function () {
  var urls = [
    'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/jpy.json',
    'https://latest.currency-api.pages.dev/v1/currencies/jpy.json'
  ];

  function pad(number) {
    return String(number).padStart(2, '0');
  }

  function currentTimeText() {
    var now = new Date();
    return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' +
      pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
  }

  function fetchRate(index) {
    if (index >= urls.length) return Promise.reject(new Error('rate fetch failed'));
    return fetch(urls[index], { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) throw new Error('rate response ' + response.status);
        return response.json();
      })
      .then(function (data) {
        var rate = data && data.jpy ? Number(data.jpy.cny) : 0;
        if (!rate || rate <= 0) throw new Error('rate payload invalid');
        return rate;
      })
      .catch(function () {
        return fetchRate(index + 1);
      });
  }

  function refreshDashboardRate() {
    var card = document.querySelector('[data-realtime-rate-card]');
    if (!(card instanceof HTMLElement)) return;
    var value = card.querySelector('[data-realtime-rate-value]');
    var meta = card.querySelector('[data-realtime-rate-meta]');
    var error = card.querySelector('[data-realtime-rate-error]');

    fetchRate(0).then(function (rate) {
      if (value) {
        value.textContent = rate.toFixed(6);
        value.classList.remove('is-fallback');
      }
      if (meta) meta.textContent = '1 JPY = CNY · FawazCurrencyAPI · ' + currentTimeText();
      if (error) error.remove();
    }).catch(function () {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshDashboardRate);
  } else {
    refreshDashboardRate();
  }
})();
