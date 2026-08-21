(function (window, document, common) {
  'use strict';

  if (!common) {
    return;
  }

  var taskId = common.getQueryNumber('taskId', 0);
  var execBatchId = common.getQuery('execBatchId', '');

  function setText(selector, value) {
    var node = document.querySelector(selector);
    if (!node) {
      return;
    }
    node.textContent = value;
  }

  function setPre(selector, value) {
    var node = document.querySelector(selector);
    if (!node) {
      return;
    }
    node.textContent = value || '-';
  }

  function loadStdout(detail) {
    setPre('#stdout-log', detail.stdout || detail.message || '');
  }

  function loadStderr(detail) {
    setPre('#stderr-log', detail.stderr || (detail.status === 'failed' ? (detail.message || '') : ''));
  }

  function fillMeta(detail) {
    setText('#meta-task-id', String(detail.taskId || taskId || '-'));
    setText('#meta-batch', detail.execBatchId || execBatchId || '-');
    var statusNode = document.querySelector('#meta-status');
    if (statusNode) {
      statusNode.innerHTML = common.statusBadge(detail.status || 'unknown');
    }
    setText('#meta-pid', String(detail.pid || '-'));
    setText('#meta-started', detail.startedAt || '-');
    setText('#meta-finished', detail.finishedAt || '-');
    setText('#meta-duration', common.formatDuration(detail.durationMs || 0));
    setText('#meta-exit-code', detail.exitCode === null || detail.exitCode === undefined ? '-' : String(detail.exitCode));
    setText('#meta-http-status', detail.httpStatus === null || detail.httpStatus === undefined ? '-' : String(detail.httpStatus));
    setText('#meta-log-size', String((detail.message || '').length) + ' bytes');
    setPre('#task-item', JSON.stringify(detail.taskItem || {}, null, 2));
    setPre('#raw-message', detail.message || '-');
  }

  function loadDetail() {
    if (!taskId || !execBatchId) {
      common.showToast('缺少 taskId 或 execBatchId', 'error');
      return Promise.resolve();
    }
    var query = common.toQuery({
      id: taskId,
      execBatchId: execBatchId
    });
    return common.request(common.API.executionDetail + '?' + query)
      .then(function (detail) {
        fillMeta(detail || {});
        loadStdout(detail || {});
        loadStderr(detail || {});
      })
      .catch(function (err) {
        common.showToast(err.message || String(err), 'error');
      });
  }

  function refreshLog() {
    return loadDetail();
  }

  function downloadLog() {
    if (!taskId || !execBatchId) {
      common.showToast('缺少任务参数', 'error');
      return;
    }
    var content = [
      '[taskId]',
      String(taskId),
      '',
      '[execBatchId]',
      String(execBatchId),
      '',
      '[stdout]',
      document.querySelector('#stdout-log').textContent || '',
      '',
      '[stderr]',
      document.querySelector('#stderr-log').textContent || ''
    ].join('\n');
    var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'cron-log-' + taskId + '-' + execBatchId + '.txt';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
  }

  function bindEvents() {
    var refreshBtn = document.querySelector('#refresh-log-btn');
    var downloadBtn = document.querySelector('#download-log-btn');
    var backBtn = document.querySelector('#back-execution-btn');
    refreshBtn.addEventListener('click', function () {
      refreshLog();
    });
    downloadBtn.addEventListener('click', function () {
      downloadLog();
    });
    backBtn.addEventListener('click', function () {
      window.location.href = '/cron-admin/execution.html?taskId=' + encodeURIComponent(taskId || 1);
    });
  }

  function initLogPage() {
    if (!document.querySelector('#stdout-log')) {
      return;
    }
    bindEvents();
    loadDetail();
  }

  window.CronAdminLog = {
    loadStdout: loadStdout,
    loadStderr: loadStderr,
    refreshLog: refreshLog,
    downloadLog: downloadLog,
    initLogPage: initLogPage
  };

  document.addEventListener('DOMContentLoaded', initLogPage);
})(window, document, window.CronAdminCommon);
