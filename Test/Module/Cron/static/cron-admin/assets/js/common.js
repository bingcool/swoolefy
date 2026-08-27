(function (window) {
  'use strict';

  var API = '/api/v1';

  async function api(path, options) {
    options = options || {};
    var headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    if (options.body && typeof options.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    var res = await fetch(API + path, Object.assign({}, options, { headers: headers }));
    var json = await res.json();
    if (!res.ok || (json.code !== undefined && json.code !== 0)) {
      throw new Error(json.msg || json.message || ('HTTP ' + res.status));
    }
    return json.data;
  }

  function toastErr(vm, err) {
    vm.$message.error((err && err.message) || String(err));
  }

  function formatTaskNames(names) {
    var list = Array.isArray(names) ? names : [names];
    return list.map(function (name) {
      return '「' + String(name == null ? '' : name) + '」';
    }).join('、');
  }

  var CONFIRM_TONES = {
    success: { type: 'success', cls: 'cron-confirm-success' },
    warning: { type: 'warning', cls: 'cron-confirm-warning' },
    danger: { type: 'error', cls: 'cron-confirm-danger' },
    info: { type: 'info', cls: 'cron-confirm-info' }
  };

  function confirmDialog(vm, message, tone, title) {
    var preset = CONFIRM_TONES[tone] || CONFIRM_TONES.info;
    var options = {
      type: preset.type,
      customClass: 'cron-confirm-dialog ' + preset.cls,
      confirmButtonClass: 'cron-confirm-ok'
    };
    if (title) {
      return vm.$confirm(message, title, options);
    }
    return vm.$confirm(message, options);
  }

  function confirmTaskStatusChange(vm, status, names) {
    var enable = Number(status) === 1;
    var action = enable ? '启用' : '禁用';
    return confirmDialog(vm, '确认' + action + '任务' + formatTaskNames(names) + '？', enable ? 'success' : 'warning');
  }

  function confirmDelete(vm, message) {
    return confirmDialog(vm, message, 'danger');
  }

  function confirmRunOnce(vm, names) {
    var label = formatTaskNames(names);
    return confirmDialog(
      vm,
      '确认手动执行任务' + label + '？任务将立即入队执行。',
      'info',
      '手动执行'
    ).then(function () {
      return confirmDialog(
        vm,
        '再次确认要立即执行' + label + '？',
        'warning',
        '再次确认'
      );
    });
  }

  function toastTaskStatus(vm, status, names) {
    var label = Array.isArray(names) ? names.join('、') : String(names || '');
    var msg = Number(status) === 1 ? '已启用[' + label + ']' : '已禁用[' + label + ']';
    if (Number(status) === 1) {
      vm.$message.success(msg);
    } else {
      vm.$message.warning(msg);
    }
  }

  function formatDurationMs(ms) {
    if (ms === null || ms === undefined || ms === '') return '-';
    var n = Number(ms);
    if (!isFinite(n)) return '-';
    if (n === 0) return '0';
    if (n > 100) return n + 'ms(约' + (n / 1000).toFixed(1) + 's)';
    return n + 'ms';
  }

  function formatNextRunAt(row) {
    if (!row) return '-';
    var ts = row.nextRunAt;
    if (ts !== null && ts !== undefined && ts !== '' && Number(ts) > 0) {
      var d = new Date(Number(ts) * 1000);
      if (!isNaN(d.getTime())) {
        function p(n) { return String(n).padStart(2, '0'); }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
          + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
      }
    }
    return row.nextRunAtAt || '-';
  }

  function parseJsonOrNull(text, label) {
    if (!text || !String(text).trim()) return null;
    try {
      return JSON.parse(text);
    } catch (e) {
      throw new Error(label + ' JSON 无效');
    }
  }

  function detectExprType(expression) {
    var expr = String(expression || '').trim();
    if (/^\d+$/.test(expr)) return 'interval';
    return 'cron';
  }

  function isGroupIdSelected(groupId) {
    return groupId !== '' && groupId !== null && groupId !== undefined;
  }

  function filterNodesByGroupId(nodes, groupId) {
    if (!isGroupIdSelected(groupId)) return [];
    var gid = Number(groupId);
    if (Number.isNaN(gid)) return [];
    return (nodes || []).filter(function (n) {
      var nid = n.groupId || 0;
      return gid === -1 ? nid === 0 : nid === gid;
    });
  }

  function buildGroupOptions(groups, nodes) {
    var list = (groups || []).slice();
    var hasUngrouped = (nodes || []).some(function (n) { return !n.groupId; });
    if (hasUngrouped) {
      list = [{ id: -1, groupName: '未分组' }].concat(list);
    }
    return list;
  }

  window.CronAdminCommon = {
    API: API,
    api: api,
    toastErr: toastErr,
    confirmDialog: confirmDialog,
    confirmTaskStatusChange: confirmTaskStatusChange,
    confirmDelete: confirmDelete,
    confirmRunOnce: confirmRunOnce,
    toastTaskStatus: toastTaskStatus,
    formatDurationMs: formatDurationMs,
    formatNextRunAt: formatNextRunAt,
    parseJsonOrNull: parseJsonOrNull,
    detectExprType: detectExprType,
    isGroupIdSelected: isGroupIdSelected,
    filterNodesByGroupId: filterNodesByGroupId,
    buildGroupOptions: buildGroupOptions
  };
})(window);
