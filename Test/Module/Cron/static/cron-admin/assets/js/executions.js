(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminExecutions = {
    template: '#tpl-executions',
    data: function () {
      return {
        items: [],
        total: 0,
        loading: false,
        dlg: false,
        execDetail: null,
        query: { taskId: '', taskName: '', page: 1, pageSize: 20, execBatchId: '', status: '', execType: '', triggerType: '', startTime: '', endTime: '', executionTimeRange: [] }
      };
    },
    created: function () {
      if (this.$route.query.taskId) {
        this.query.taskId = String(this.$route.query.taskId);
      }
      this.syncExecutionTimeRange();
      this.load();
    },
    watch: {
      '$route.query.taskId': function (val) {
        this.query.taskId = val ? String(val) : '';
        this.query.page = 1;
        this.load();
      }
    },
    methods: {
      syncExecutionTimeRange: function () {
        var startTime = String(this.query.startTime || '').trim();
        var endTime = String(this.query.endTime || '').trim();
        if (startTime && endTime) {
          this.query.executionTimeRange = [startTime, endTime];
          return;
        }
        this.query.executionTimeRange = [];
        this.query.startTime = '';
        this.query.endTime = '';
      },
      syncStartEndFromRange: function () {
        var range = this.query.executionTimeRange;
        if (Array.isArray(range) && range.length === 2) {
          this.query.startTime = String(range[0] || '').trim();
          this.query.endTime = String(range[1] || '').trim();
          return;
        }
        this.query.startTime = '';
        this.query.endTime = '';
      },
      normalizeStatus: function (row) {
        var raw = String((row && (row.statusName || row.status)) || '').trim().toLowerCase();
        if (raw === 'success' || raw === 'succeeded' || raw === 'ok' || raw === '成功') return 'success';
        if (raw === 'failed' || raw === 'failure' || raw === 'error' || raw === '失败') return 'failed';
        if (raw === 'timeout' || raw === 'timed_out' || raw === '超时') return 'timeout';
        if (raw === 'cancelled' || raw === 'canceled' || raw === '取消') return 'cancelled';
        if (raw === 'running' || raw === 'processing' || raw === '执行中') return 'running';
        if (raw === 'skipped' || raw === 'skip' || raw === '跳过') return 'skipped';
        return 'default';
      },
      statusClass: function (row) {
        return 'status-' + this.normalizeStatus(row);
      },
      statusText: function (row) {
        var raw = String((row && (row.statusName || row.status)) || '').trim().toLowerCase();
        if (raw === 'pending') return '注册定时任务';
        var key = this.normalizeStatus(row);
        var map = {
          success: '成功',
          failed: '失败',
          timeout: '超时',
          cancelled: '取消',
          running: '执行中',
          skipped: '跳过',
          default: row && (row.statusName || row.status) ? String(row.statusName || row.status) : '未知'
        };
        return map[key];
      },
      formatDurationMs: function (ms) {
        return common.formatDurationMs(ms);
      },
      triggerTypeText: function (row) {
        var triggerType = Number(row && row.triggerType);
        if (triggerType === 1) return '定时';
        if (triggerType === 2) return '手动执行';
        return '未知';
      },
      validateTimeRange: function () {
        var range = this.query.executionTimeRange;
        if (!Array.isArray(range) || range.length === 0) {
          return true;
        }
        if (range.length !== 2) {
          this.$message.warning('执行时间筛选需同时选择开始执行时间和结束执行时间');
          return false;
        }
        return true;
      },
      load: async function () {
        this.syncStartEndFromRange();
        if (!this.validateTimeRange()) return;
        this.loading = true;
        try {
          var qs = new URLSearchParams({
            page: this.query.page,
            pageSize: this.query.pageSize
          });
          var taskId = String(this.query.taskId || '').trim();
          if (taskId) qs.set('taskId', taskId);
          var taskName = String(this.query.taskName || '').trim();
          if (taskName) qs.set('taskName', taskName);
          if (this.query.execBatchId) qs.set('execBatchId', this.query.execBatchId);
          if (this.query.status) qs.set('status', this.query.status);
          if (this.query.execType !== '' && this.query.execType !== null && this.query.execType !== undefined) {
            qs.set('execType', String(this.query.execType));
          }
          if (this.query.triggerType !== '' && this.query.triggerType !== null && this.query.triggerType !== undefined) {
            qs.set('triggerType', String(this.query.triggerType));
          }
          var startTime = String(this.query.startTime || '').trim();
          if (startTime) qs.set('startTime', startTime);
          var endTime = String(this.query.endTime || '').trim();
          if (endTime) qs.set('endTime', endTime);
          var d = await common.api('/tasks/logs?' + qs.toString());
          this.items = (d && d.list) || [];
          this.total = (d && d.total) || 0;
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.loading = false;
        }
      },
      search: function () {
        this.syncStartEndFromRange();
        if (!this.validateTimeRange()) return;
        this.query.page = 1;
        this.load();
      },
      detail: async function (row) {
        try {
          var logId = row && row.id ? ('&logId=' + encodeURIComponent(String(row.id))) : '';
          this.execDetail = await common.api(
            '/tasks/execution?id=' + encodeURIComponent(row.cronId)
              + '&execBatchId=' + encodeURIComponent(row.execBatchId)
              + logId
          );
          this.dlg = true;
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      viewLog: function (row) {
        this.$router.push({
          path: '/executions/log',
          query: { taskId: row.cronId, execBatchId: row.execBatchId, logId: row.id }
        });
      }
    }
  };

  window.CronAdminExecutionLog = {
    template: '#tpl-execution-log',
    data: function () {
      return { detail: null, loading: false };
    },
    computed: {
      taskId: function () {
        return this.$route.query.taskId || '';
      },
      execBatchId: function () {
        return this.$route.query.execBatchId || '';
      },
      logId: function () {
        return this.$route.query.logId || '';
      }
    },
    created: function () {
      this.load();
    },
    methods: {
      formatDurationMs: function (ms) {
        return common.formatDurationMs(ms);
      },
      load: async function () {
        if (!this.taskId || !this.execBatchId) {
          this.$message.warning('缺少 taskId 或 execBatchId');
          return;
        }
        this.loading = true;
        try {
          var logId = String(this.logId || '').trim();
          var logIdQuery = logId ? ('&logId=' + encodeURIComponent(logId)) : '';
          this.detail = await common.api(
            '/tasks/execution?id=' + encodeURIComponent(this.taskId)
              + '&execBatchId=' + encodeURIComponent(this.execBatchId)
              + logIdQuery
          );
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.loading = false;
        }
      },
      refresh: function () {
        this.load();
      },
      back: function () {
        var q = this.taskId ? { taskId: this.taskId } : {};
        this.$router.push({ path: '/executions', query: q });
      },
      download: function () {
        if (!this.detail) return;
        var content = [
          '[taskId]', String(this.taskId), '',
          '[execBatchId]', String(this.execBatchId), '',
          '[logId]', String(this.logId || ''), '',
          '[stdout]', this.detail.stdout || this.detail.message || '', '',
          '[stderr]', this.detail.stderr || '', '',
          '[taskItem]', JSON.stringify(this.detail.taskItem || {}, null, 2)
        ].join('\n');
        var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'cron-log-' + this.taskId + '-' + this.execBatchId + '.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
      }
    }
  };
})(window);
