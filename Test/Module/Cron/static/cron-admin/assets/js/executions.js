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
        query: { taskId: '', page: 1, pageSize: 20, execBatchId: '', status: '' }
      };
    },
    created: function () {
      if (this.$route.query.taskId) {
        this.query.taskId = String(this.$route.query.taskId);
      }
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
      load: async function () {
        this.loading = true;
        try {
          var qs = new URLSearchParams({
            page: this.query.page,
            pageSize: this.query.pageSize
          });
          var taskId = String(this.query.taskId || '').trim();
          if (taskId) qs.set('taskId', taskId);
          if (this.query.execBatchId) qs.set('execBatchId', this.query.execBatchId);
          if (this.query.status) qs.set('status', this.query.status);
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
        this.query.page = 1;
        this.load();
      },
      detail: async function (row) {
        try {
          this.execDetail = await common.api(
            '/tasks/execution?id=' + row.cronId + '&execBatchId=' + encodeURIComponent(row.execBatchId)
          );
          this.dlg = true;
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      viewLog: function (row) {
        this.$router.push({
          path: '/executions/log',
          query: { taskId: row.cronId, execBatchId: row.execBatchId }
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
      }
    },
    created: function () {
      this.load();
    },
    methods: {
      load: async function () {
        if (!this.taskId || !this.execBatchId) {
          this.$message.warning('缺少 taskId 或 execBatchId');
          return;
        }
        this.loading = true;
        try {
          this.detail = await common.api(
            '/tasks/execution?id=' + encodeURIComponent(this.taskId)
              + '&execBatchId=' + encodeURIComponent(this.execBatchId)
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
