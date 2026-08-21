(function (window, document, common) {
  'use strict';

  if (!common) {
    return;
  }

  var state = {
    taskId: common.getQueryNumber('taskId', 1),
    page: 1,
    pageSize: 20,
    total: 0
  };

  function renderRows(items) {
    var body = document.querySelector('#execution-table-body');
    if (!body) {
      return;
    }
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="9"><div class="empty">暂无执行记录</div></td></tr>';
      return;
    }

    body.innerHTML = items.map(function (row) {
      return [
        '<tr>',
        '<td>' + common.escapeHtml(row.execBatchId || '-') + '</td>',
        '<td>' + common.statusBadge(row.statusName || row.status || 'unknown') + '</td>',
        '<td>' + common.escapeHtml(String(row.pid || '-')) + '</td>',
        '<td>' + common.escapeHtml(common.formatDate(row.startedAt || row.createdAt || '-')) + '</td>',
        '<td>' + common.escapeHtml(common.formatDate(row.finishedAt || row.updatedAt || '-')) + '</td>',
        '<td>' + common.escapeHtml(common.formatDuration(row.durationMs || 0)) + '</td>',
        '<td>' + common.escapeHtml(row.exitCode === null || row.exitCode === undefined ? '-' : String(row.exitCode)) + '</td>',
        '<td>' + common.escapeHtml(row.message || '-') + '</td>',
        '<td><div class="execution-row-actions"><button data-act="view-log" data-batch="' + common.escapeHtml(row.execBatchId || '') + '">查看日志</button></div></td>',
        '</tr>'
      ].join('');
    }).join('');
  }

  function loadExecutions() {
    var taskIdInput = document.querySelector('#taskId');
    var statusInput = document.querySelector('#status');
    var batchInput = document.querySelector('#execBatchId');
    var query = {
      taskId: Number(taskIdInput.value || state.taskId || 1),
      page: state.page,
      pageSize: state.pageSize,
      status: statusInput.value || '',
      execBatchId: batchInput.value.trim()
    };
    state.taskId = query.taskId;
    var body = document.querySelector('#execution-table-body');
    body.innerHTML = '<tr><td colspan="9"><div class="empty">加载中...</div></td></tr>';

    return common.request(common.API.executions + '?' + common.toQuery(query))
      .then(function (data) {
        var list = (data && data.list) || [];
        state.total = Number((data && data.total) || 0);
        renderRows(list);
        common.renderPagination('#execution-pagination', state.page, state.pageSize, state.total, function (nextPage) {
          state.page = nextPage;
          loadExecutions();
        });
      })
      .catch(function (err) {
        common.showToast(err.message || String(err), 'error');
      });
  }

  function filterExecutions() {
    state.page = 1;
    loadExecutions();
  }

  function viewExecutionLog(execBatchId) {
    if (!execBatchId) {
      common.showToast('缺少 execBatchId', 'error');
      return;
    }
    window.location.href = '/cron-admin/log.html?taskId=' + encodeURIComponent(state.taskId) + '&execBatchId=' + encodeURIComponent(execBatchId);
  }

  function bindEvents() {
    var queryBtn = document.querySelector('#query-execution-btn');
    queryBtn.addEventListener('click', function () {
      filterExecutions();
    });

    var table = document.querySelector('#execution-table-body');
    table.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target.getAttribute('data-act') === 'view-log') {
        viewExecutionLog(target.getAttribute('data-batch') || '');
      }
    });
  }

  function initExecutionPage() {
    var taskIdInput = document.querySelector('#taskId');
    if (!taskIdInput) {
      return;
    }
    taskIdInput.value = state.taskId;
    bindEvents();
    loadExecutions();
  }

  window.CronAdminExecution = {
    loadExecutions: loadExecutions,
    filterExecutions: filterExecutions,
    viewExecutionLog: viewExecutionLog,
    initExecutionPage: initExecutionPage
  };

  document.addEventListener('DOMContentLoaded', initExecutionPage);
})(window, document, window.CronAdminCommon);
