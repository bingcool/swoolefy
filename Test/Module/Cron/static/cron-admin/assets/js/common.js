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

  function toastTaskStatus(vm, status, names) {
    var label = Array.isArray(names) ? names.join('、') : String(names || '');
    var msg = Number(status) === 1 ? '已启用[' + label + ']' : '已禁用[' + label + ']';
    if (Number(status) === 1) {
      vm.$message.success(msg);
    } else {
      vm.$message.warning(msg);
    }
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

  window.CronAdminCommon = {
    API: API,
    api: api,
    toastErr: toastErr,
    toastTaskStatus: toastTaskStatus,
    formatNextRunAt: formatNextRunAt,
    parseJsonOrNull: parseJsonOrNull,
    detectExprType: detectExprType
  };
})(window);
