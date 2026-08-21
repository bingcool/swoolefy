(function (window, document) {
  'use strict';

  var API_BASE = '/api/v1';
  var API = {
    cronTask: API_BASE + '/tasks',
    taskStatus: API_BASE + '/tasks/status',
    taskRun: API_BASE + '/tasks/run',
    taskPreview: API_BASE + '/tasks/expression/preview',
    nodes: API_BASE + '/nodes',
    executions: API_BASE + '/tasks/logs',
    executionDetail: API_BASE + '/tasks/execution'
  };

  function request(url, options) {
    var opts = options || {};
    var headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    if (opts.body && typeof opts.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    return fetch(url, Object.assign({}, opts, { headers: headers }))
      .then(function (res) {
        return res.json().then(function (json) {
          if (!res.ok || (json && json.code !== undefined && json.code !== 0)) {
            var message = (json && (json.msg || json.message)) || ('HTTP ' + res.status);
            throw new Error(message);
          }
          return json ? json.data : null;
        });
      });
  }

  function toQuery(params) {
    var qs = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value !== undefined && value !== null && value !== '') {
        qs.set(key, String(value));
      }
    });
    return qs.toString();
  }

  function getQuery(name, fallback) {
    var value = new URLSearchParams(window.location.search).get(name);
    if (value === null || value === '') {
      return fallback;
    }
    return value;
  }

  function getQueryNumber(name, fallback) {
    var value = Number(getQuery(name, ''));
    if (!value || Number.isNaN(value)) {
      return fallback;
    }
    return value;
  }

  function escapeHtml(input) {
    return String(input === undefined || input === null ? '' : input)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatDate(value) {
    if (!value) {
      return '-';
    }
    if (typeof value === 'number') {
      var d = new Date(value * 1000);
      if (Number.isNaN(d.getTime())) {
        return '-';
      }
      return d.toLocaleString();
    }
    return String(value);
  }

  function formatDuration(value) {
    var num = Number(value || 0);
    if (!Number.isFinite(num)) {
      return '0ms';
    }
    return num + 'ms';
  }

  function statusBadge(status) {
    var text = String(status || 'unknown');
    var type = 'tag-plain';
    if (text === 'success') {
      type = 'tag-success';
    } else if (text === 'failed' || text === 'timeout' || text === 'cancelled') {
      type = 'tag-failed';
    } else if (text === 'running' || text === 'pending') {
      type = 'tag-running';
    }
    return '<span class="tag ' + type + '">' + escapeHtml(text) + '</span>';
  }

  function showToast(message, type) {
    var wrap = document.querySelector('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    var node = document.createElement('div');
    node.className = 'toast ' + (type || '');
    node.textContent = message;
    wrap.appendChild(node);
    window.setTimeout(function () {
      if (node.parentNode) {
        node.parentNode.removeChild(node);
      }
    }, 2400);
  }

  function bindTopNav(activePath) {
    var links = document.querySelectorAll('.nav-links a');
    links.forEach(function (link) {
      if (link.getAttribute('href') === activePath) {
        link.classList.add('active');
      }
    });
  }

  function renderPagination(target, page, pageSize, total, onChange) {
    var container = typeof target === 'string' ? document.querySelector(target) : target;
    if (!container) {
      return;
    }
    var p = Number(page || 1);
    var size = Number(pageSize || 20);
    var t = Number(total || 0);
    var maxPage = Math.max(1, Math.ceil(t / size));
    container.innerHTML = '';

    var info = document.createElement('span');
    info.textContent = '共 ' + t + ' 条';
    container.appendChild(info);

    var prevBtn = document.createElement('button');
    prevBtn.textContent = '上一页';
    prevBtn.disabled = p <= 1;
    prevBtn.addEventListener('click', function () {
      onChange(p - 1);
    });
    container.appendChild(prevBtn);

    var pageNode = document.createElement('span');
    pageNode.textContent = p + ' / ' + maxPage;
    container.appendChild(pageNode);

    var nextBtn = document.createElement('button');
    nextBtn.textContent = '下一页';
    nextBtn.disabled = p >= maxPage;
    nextBtn.addEventListener('click', function () {
      onChange(p + 1);
    });
    container.appendChild(nextBtn);
  }

  window.CronAdminCommon = {
    API: API,
    request: request,
    toQuery: toQuery,
    getQuery: getQuery,
    getQueryNumber: getQueryNumber,
    escapeHtml: escapeHtml,
    formatDate: formatDate,
    formatDuration: formatDuration,
    statusBadge: statusBadge,
    showToast: showToast,
    bindTopNav: bindTopNav,
    renderPagination: renderPagination
  };
})(window, document);
