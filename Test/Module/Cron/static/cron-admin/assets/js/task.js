(function (window, document, common) {
  'use strict';

  if (!common) {
    return;
  }

  var state = {
    page: 1,
    pageSize: 20,
    total: 0
  };

  function parseJsonOrNull(text, label) {
    if (!text || !String(text).trim()) {
      return null;
    }
    try {
      return JSON.parse(text);
    } catch (err) {
      throw new Error(label + ' JSON 无效');
    }
  }

  function fillTaskTable(items) {
    var body = document.querySelector('#task-table-body');
    if (!body) {
      return;
    }
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="10"><div class="empty">暂无任务</div></td></tr>';
      return;
    }

    body.innerHTML = items.map(function (row) {
      var isEnabled = row.status === 1;
      var execType = row.execType === 2 ? 'HTTP' : 'Shell';
      var nextRun = row.nextRunAtAt || common.formatDate(row.nextRunAt);
      return [
        '<tr>',
        '<td>' + row.id + '</td>',
        '<td>' + common.escapeHtml(row.name || '-') + '</td>',
        '<td><span class="mono">' + common.escapeHtml(row.expression || '-') + '</span></td>',
        '<td>' + common.escapeHtml(row.expressionType || '-') + '</td>',
        '<td>' + common.escapeHtml(execType) + '</td>',
        '<td><button',
        ' type="button"',
        ' class="status-switch' + (isEnabled ? ' is-on' : ' is-off') + '"',
        ' role="switch"',
        ' aria-checked="' + (isEnabled ? 'true' : 'false') + '"',
        ' aria-label="' + (isEnabled ? '禁用任务' : '启用任务') + '"',
        ' title="' + (isEnabled ? '点击禁用任务' : '点击启用任务') + '"',
        ' data-act="toggle"',
        ' data-id="' + row.id + '"',
        ' data-status="' + row.status + '">',
        '<span class="status-switch-track"><span class="status-switch-thumb"></span></span>',
        '<span class="status-switch-text">' + (isEnabled ? '启用' : '禁用') + '</span>',
        '</button></td>',
        '<td>' + common.escapeHtml(String(row.nodeId || '-')) + '</td>',
        '<td>' + common.escapeHtml(nextRun || '-') + '</td>',
        '<td>' + common.escapeHtml(row.updatedAt || '-') + '</td>',
        '<td><div class="task-row-actions">',
        '<button class="task-action-btn btn-detail" title="查看任务详情" aria-label="查看任务详情" data-act="detail" data-id="' + row.id + '">详情</button>',
        '<button class="task-action-btn btn-edit" title="编辑任务" aria-label="编辑任务" data-act="edit" data-id="' + row.id + '">编辑</button>',
        '<button class="task-action-btn btn-run" title="手动执行任务" aria-label="手动执行任务" data-act="run" data-id="' + row.id + '">手动执行</button>',
        '<button class="task-action-btn btn-log" title="查看任务日志" aria-label="查看任务日志" data-act="exec" data-id="' + row.id + '">查看日志</button>',
        '<button class="task-action-btn btn-copy" title="复制任务" aria-label="复制任务" data-act="copy" data-id="' + row.id + '">复制</button>',
        '<button class="task-action-btn btn-delete" title="删除任务" aria-label="删除任务" data-act="delete" data-id="' + row.id + '">删除</button>',
        '</div></td>',
        '</tr>'
      ].join('');
    }).join('');
  }

  function loadTasks() {
    var query = {
      page: state.page,
      pageSize: state.pageSize,
      keyword: document.querySelector('#keyword') ? document.querySelector('#keyword').value.trim() : '',
      status: document.querySelector('#status') ? document.querySelector('#status').value : '',
      execType: document.querySelector('#execType') ? document.querySelector('#execType').value : ''
    };
    var taskListWrap = document.querySelector('#task-table-body');
    if (!taskListWrap) {
      return Promise.resolve();
    }
    taskListWrap.innerHTML = '<tr><td colspan="10"><div class="empty">加载中...</div></td></tr>';
    return common
      .request(common.API.cronTask + '?' + common.toQuery(query))
      .then(function (data) {
        var items = data.items || data.list || [];
        state.total = Number(data.total || 0);
        fillTaskTable(items);
        common.renderPagination('#task-pagination', state.page, state.pageSize, state.total, function (nextPage) {
          state.page = nextPage;
          loadTasks();
        });
      })
      .catch(function (err) {
        common.showToast(err.message || String(err), 'error');
      });
  }

  function createTask(payload) {
    return common.request(common.API.cronTask, {
      method: 'POST',
      body: payload
    });
  }

  function updateTask(payload) {
    return common.request(common.API.cronTask, {
      method: 'PUT',
      body: payload
    });
  }

  function deleteTask(id) {
    return common.request(common.API.cronTask + '?id=' + encodeURIComponent(id), {
      method: 'DELETE',
      body: { id: Number(id) }
    });
  }

  function enableTask(id) {
    return common.request(common.API.taskStatus, {
      method: 'PUT',
      body: { id: Number(id), status: 1 }
    });
  }

  function disableTask(id) {
    return common.request(common.API.taskStatus, {
      method: 'PUT',
      body: { id: Number(id), status: 0 }
    });
  }

  function runTask(id) {
    return common.request(common.API.taskRun, {
      method: 'POST',
      body: { id: Number(id) }
    });
  }

  function duplicateTask(id) {
    return common.request(common.API.cronTask + '/duplicate', {
      method: 'POST',
      body: { id: Number(id) }
    });
  }

  function bindTaskListEvents() {
    var queryBtn = document.querySelector('#query-btn');
    if (queryBtn) {
      queryBtn.addEventListener('click', function () {
        state.page = 1;
        loadTasks();
      });
    }

    var createBtn = document.querySelector('#create-btn');
    if (createBtn) {
      createBtn.addEventListener('click', function () {
        window.location.href = '/cron-admin/task.html';
      });
    }

    var table = document.querySelector('#task-table-body');
    if (!table) {
      return;
    }
    table.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      var action = target.getAttribute('data-act');
      var id = Number(target.getAttribute('data-id') || 0);
      if (!action || !id) {
        return;
      }

      if (action === 'edit') {
        window.location.href = '/cron-admin/task.html?id=' + id;
        return;
      }
      if (action === 'detail') {
        window.location.href = '/cron-admin.html#/tasks/detail/' + id;
        return;
      }
      if (action === 'exec') {
        window.location.href = '/cron-admin/execution.html?taskId=' + id;
        return;
      }
      if (action === 'run') {
        runTask(id)
          .then(function () {
            common.showToast('已入队执行', 'success');
          })
          .catch(function (err) {
            common.showToast(err.message || String(err), 'error');
          });
        return;
      }
      if (action === 'toggle') {
        var status = Number(target.getAttribute('data-status') || 0);
        var req = status === 1 ? disableTask(id) : enableTask(id);
        req
          .then(function () {
            common.showToast('状态已更新', 'success');
            loadTasks();
          })
          .catch(function (err) {
            common.showToast(err.message || String(err), 'error');
          });
        return;
      }
      if (action === 'copy') {
        duplicateTask(id)
          .then(function () {
            common.showToast('已复制为禁用副本', 'success');
            loadTasks();
          })
          .catch(function (err) {
            common.showToast(err.message || String(err), 'error');
          });
        return;
      }
      if (action === 'delete') {
        if (!window.confirm('确认删除任务 #' + id + ' 吗？')) {
          return;
        }
        deleteTask(id)
          .then(function () {
            common.showToast('任务已删除', 'success');
            loadTasks();
          })
          .catch(function (err) {
            common.showToast(err.message || String(err), 'error');
          });
      }
    });
  }

  function loadNodeOptions() {
    return common.request(common.API.nodes).then(function (data) {
      var list = (data && data.list) || [];
      var select = document.querySelector('#nodeId');
      if (!select) {
        return list;
      }
      select.innerHTML = list.map(function (node) {
        return '<option value="' + node.id + '">' + common.escapeHtml(node.nodeName + ' (' + node.nodeIp + ')') + '</option>';
      }).join('');
      return list;
    });
  }

  function loadTaskDetail(id) {
    return common.request(common.API.cronTask + '/detail?id=' + encodeURIComponent(id));
  }

  function previewExpression() {
    var expression = document.querySelector('#expression').value.trim();
    var descNode = document.querySelector('#preview-desc');
    var listNode = document.querySelector('#preview-runs');
    if (!expression || !descNode || !listNode) {
      return;
    }
    common.request(common.API.taskPreview, {
      method: 'POST',
      body: { expression: expression }
    }).then(function (data) {
      descNode.textContent = data.valid ? '表达式有效：' + (data.description || '') : '表达式无效：' + (data.description || '');
      var runs = data.nextRuns || [];
      listNode.innerHTML = runs.length
        ? runs.map(function (time) { return '<li>' + common.escapeHtml(time) + '</li>'; }).join('')
        : '<li>-</li>';
    }).catch(function (err) {
      descNode.textContent = '表达式无效：' + (err.message || String(err));
      listNode.innerHTML = '<li>-</li>';
    });
  }

  function fillTaskForm(row, isEdit) {
    document.querySelector('#page-title').textContent = isEdit ? '编辑任务' : '创建任务';
    document.querySelector('#task-name').value = row.name || '';
    document.querySelector('#nodeId').value = row.nodeId || '';
    document.querySelector('#expression').value = row.expression || '';
    document.querySelector('#execType').value = row.execType || 1;
    document.querySelector('#command').value = row.command || '';
    document.querySelector('#description').value = row.description || '';
    document.querySelector('#retry').value = row.retry || 0;
    document.querySelector('#withBlockLapping').value = row.withBlockLapping || 0;
    document.querySelector('#statusSwitch').value = row.status === 0 ? 0 : 1;
    document.querySelector('#httpMethod').value = row.httpMethod || 'GET';
    document.querySelector('#httpRequestTimeOut').value = row.httpRequestTimeOut || 30;
    document.querySelector('#httpHeaders').value = row.httpHeaders ? JSON.stringify(row.httpHeaders, null, 2) : '';
    document.querySelector('#httpBody').value = row.httpBody ? JSON.stringify(row.httpBody, null, 2) : '';
    document.querySelector('#cronBetween').value = row.cronBetween && row.cronBetween.length ? JSON.stringify(row.cronBetween, null, 2) : '';
    document.querySelector('#cronSkip').value = row.cronSkip && row.cronSkip.length ? JSON.stringify(row.cronSkip, null, 2) : '';
  }

  function bindTaskForm() {
    var saveBtn = document.querySelector('#save-task-btn');
    if (!saveBtn) {
      return;
    }
    var id = common.getQueryNumber('id', 0);

    document.querySelector('#cancel-task-btn').addEventListener('click', function () {
      window.location.href = '/cron-admin/index.html';
    });

    document.querySelector('#preview-btn').addEventListener('click', function () {
      previewExpression();
    });

    document.querySelector('#execType').addEventListener('change', function () {
      var isHttp = Number(this.value) === 2;
      var httpSection = document.querySelectorAll('.http-only');
      httpSection.forEach(function (el) {
        el.style.display = isHttp ? 'flex' : 'none';
      });
    });

    saveBtn.addEventListener('click', function () {
      var payload;
      try {
        payload = {
          id: id || undefined,
          name: document.querySelector('#task-name').value.trim(),
          nodeId: Number(document.querySelector('#nodeId').value || 0),
          expression: document.querySelector('#expression').value.trim(),
          execType: Number(document.querySelector('#execType').value || 1),
          command: document.querySelector('#command').value.trim(),
          description: document.querySelector('#description').value.trim(),
          retry: Number(document.querySelector('#retry').value || 0),
          withBlockLapping: Number(document.querySelector('#withBlockLapping').value || 0),
          status: Number(document.querySelector('#statusSwitch').value || 1),
          httpMethod: document.querySelector('#httpMethod').value || 'GET',
          httpRequestTimeOut: Number(document.querySelector('#httpRequestTimeOut').value || 30),
          httpHeaders: parseJsonOrNull(document.querySelector('#httpHeaders').value, 'HTTP Headers'),
          httpBody: parseJsonOrNull(document.querySelector('#httpBody').value, 'HTTP Body'),
          cronBetween: parseJsonOrNull(document.querySelector('#cronBetween').value, 'cron_between'),
          cronSkip: parseJsonOrNull(document.querySelector('#cronSkip').value, 'cron_skip')
        };
      } catch (err) {
        common.showToast(err.message || String(err), 'error');
        return;
      }
      if (!payload.name || !payload.nodeId || !payload.expression || !payload.command) {
        common.showToast('请填写名称、节点、表达式和命令/URL', 'error');
        return;
      }

      var req = id ? updateTask(payload) : createTask(payload);
      req.then(function () {
        common.showToast('任务已保存', 'success');
        window.setTimeout(function () {
          window.location.href = '/cron-admin/index.html';
        }, 300);
      }).catch(function (err) {
        common.showToast(err.message || String(err), 'error');
      });
    });

    loadNodeOptions()
      .then(function () {
        if (!id) {
          previewExpression();
          return;
        }
        return loadTaskDetail(id).then(function (row) {
          fillTaskForm(row || {}, true);
          previewExpression();
          var ev = new Event('change');
          document.querySelector('#execType').dispatchEvent(ev);
        });
      })
      .catch(function (err) {
        common.showToast(err.message || String(err), 'error');
      });
  }

  function initTaskListPage() {
    if (!document.querySelector('#task-table-body')) {
      return;
    }
    bindTaskListEvents();
    loadTasks();
  }

  function initTaskFormPage() {
    if (!document.querySelector('#save-task-btn')) {
      return;
    }
    bindTaskForm();
  }

  window.CronAdminTask = {
    loadTasks: loadTasks,
    createTask: createTask,
    updateTask: updateTask,
    deleteTask: deleteTask,
    enableTask: enableTask,
    disableTask: disableTask,
    runTask: runTask,
    duplicateTask: duplicateTask,
    initTaskListPage: initTaskListPage,
    initTaskFormPage: initTaskFormPage
  };

  document.addEventListener('DOMContentLoaded', function () {
    initTaskListPage();
    initTaskFormPage();
  });
})(window, document, window.CronAdminCommon);
