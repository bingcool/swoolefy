(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminEditor = {
    template: '#tpl-editor',
    data: function () {
      return {
        nodes: [],
        saving: false,
        exprType: 'interval',
        intervalSec: 15,
        cronExpr: '*/5 * * * *',
        headersText: '',
        bodyText: '',
        betweenItems: [],
        skipItems: [],
        preview: { valid: true, description: '', nextRuns: [] },
        form: {
          name: '',
          nodeId: null,
          description: '',
          expression: '15',
          command: '',
          execType: 1,
          status: 1,
          withBlockLapping: 1,
          retry: 0,
          httpMethod: 'GET',
          httpRequestTimeOut: 30,
          httpHeaders: null,
          httpBody: null,
          cronBetween: null,
          cronSkip: null
        }
      };
    },
    computed: {
      isEdit: function () {
        return !!this.$route.params.id;
      },
      nodeLabel: function () {
        var self = this;
        var n = this.nodes.find(function (x) { return x.id === self.form.nodeId; });
        return n ? n.nodeName : '-';
      },
      pageActions: function () {
        return true;
      }
    },
    watch: {
      'form.name': function () { this.syncPreviewMeta(); },
      'form.description': function () { this.syncPreviewMeta(); },
      'form.nodeId': function () { this.syncPreviewMeta(); },
      'form.withBlockLapping': function () { this.syncPreviewMeta(); },
      'form.status': function () { this.syncPreviewMeta(); },
      'form.execType': function () { this.syncPreviewMeta(); },
      intervalSec: function () { this.previewExpr(); },
      cronExpr: function () { this.previewExpr(); }
    },
    created: function () {
      this.init();
    },
    methods: {
      syncPreviewMeta: function () {},
      init: async function () {
        var taskDetail = null;
        try {
          var reqs = [common.api('/nodes')];
          if (this.isEdit) {
            reqs.push(common.api('/tasks/detail?id=' + this.$route.params.id));
          }
          var results = await Promise.all(reqs);
          var d = results[0];
          taskDetail = results[1] || null;
          this.nodes = (d && d.list) || [];
          if (!this.form.nodeId && this.nodes[0]) this.form.nodeId = this.nodes[0].id;
        } catch (e) {
          common.toastErr(this, e);
        }
        if (this.isEdit) {
          try {
            var row = taskDetail;
            if (!row) {
              row = await common.api('/tasks/detail?id=' + this.$route.params.id);
            }
            this.form = Object.assign(this.form, row);
            this.exprType = row.expressionType || common.detectExprType(row.expression);
            if (this.exprType === 'interval') {
              this.intervalSec = parseInt(row.expression, 10) || 15;
            } else {
              this.cronExpr = row.expression;
            }
            this.headersText = row.httpHeaders ? JSON.stringify(row.httpHeaders, null, 2) : '';
            this.bodyText = row.httpBody ? JSON.stringify(row.httpBody, null, 2) : '';
            this.betweenItems = this.deserializeRangeItems(
              row.cronBetween != null ? row.cronBetween : row.cron_between,
              'cron_between'
            );
            this.skipItems = this.deserializeRangeItems(
              row.cronSkip != null ? row.cronSkip : row.cron_skip,
              'cron_skip'
            );
          } catch (e) {
            common.toastErr(this, e);
          }
        }
        this.previewExpr();
      },
      setExprType: function (t) {
        this.exprType = t;
        this.previewExpr();
      },
      setExecType: function (type) {
        this.form.execType = type === 'http' ? 2 : 1;
      },
      addBetweenItem: function () {
        this.betweenItems.push({ start: '', end: '' });
      },
      removeBetweenItem: function (index) {
        this.betweenItems.splice(index, 1);
      },
      addSkipItem: function () {
        this.skipItems.push({ start: '', end: '' });
      },
      removeSkipItem: function (index) {
        this.skipItems.splice(index, 1);
      },
      parseRangeSource: function (source, label) {
        if (source === null || source === undefined || source === '') return [];
        if (Array.isArray(source)) return source;
        if (typeof source === 'object') return [source];
        if (typeof source !== 'string') return [];
        var text = source.trim();
        if (!text) return [];
        try {
          return JSON.parse(text);
        } catch (e) {
          var relaxed = text
            .replace(/([{,]\s*)(start|end)\s*:/g, '$1"$2":')
            .replace(/'/g, '"');
          try {
            return JSON.parse(relaxed);
          } catch (e2) {
            throw new Error(label + ' 格式无效，请使用数组结构');
          }
        }
      },
      deserializeRangeItems: function (source, label) {
        var ranges = [];
        try {
          ranges = this.parseRangeSource(source, label);
        } catch (e) {
          this.$message.warning(e.message);
          return [];
        }
        if (!Array.isArray(ranges)) return [];
        return ranges.reduce(function (acc, item) {
          var start = '';
          var end = '';
          if (Array.isArray(item)) {
            start = item[0];
            end = item[1];
          } else if (item && typeof item === 'object') {
            start = item.start;
            end = item.end;
          }
          start = start == null ? '' : String(start).trim();
          end = end == null ? '' : String(end).trim();
          if (!start && !end) return acc;
          acc.push({ start: start, end: end });
          return acc;
        }, []);
      },
      serializeRangeItems: function (items, label) {
        var normalized = [];
        (items || []).forEach(function (item, idx) {
          var start = item && item.start != null ? String(item.start).trim() : '';
          var end = item && item.end != null ? String(item.end).trim() : '';
          if (!start && !end) return;
          if (!start || !end) {
            throw new Error(label + ' 第 ' + (idx + 1) + ' 项 start/end 不能为空');
          }
          normalized.push({ start: start, end: end });
        });
        return normalized;
      },
      syncExpr: function () {
        this.form.expression = this.exprType === 'interval'
          ? String(this.intervalSec || 15)
          : (this.cronExpr || '');
      },
      previewExpr: async function () {
        this.syncExpr();
        try {
          this.preview = await common.api('/tasks/expression/preview', {
            method: 'POST',
            body: { expression: this.form.expression }
          });
        } catch (e) {
          this.preview = { valid: false, description: e.message, nextRuns: [] };
        }
      },
      save: async function () {
        this.syncExpr();
        if (!this.form.name || !this.form.command || !this.form.nodeId) {
          this.$message.warning('请填写名称、节点与 Command/URL');
          return;
        }
        if (!this.preview.valid) {
          this.$message.warning('请先修正表达式');
          return;
        }
        try {
          var payload = Object.assign({}, this.form, {
            httpHeaders: common.parseJsonOrNull(this.headersText, 'Headers'),
            httpBody: common.parseJsonOrNull(this.bodyText, 'Body'),
            cronBetween: this.serializeRangeItems(this.betweenItems, 'cron_between'),
            cronSkip: this.serializeRangeItems(this.skipItems, 'cron_skip')
          });
          this.saving = true;
          if (this.isEdit) {
            await common.api('/tasks', { method: 'PUT', body: payload });
          } else {
            await common.api('/tasks', { method: 'POST', body: payload });
          }
          this.$message.success('已保存');
          this.$router.push('/tasks');
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.saving = false;
        }
      },
      remove: async function () {
        try {
          await common.confirmDelete(this, '确认删除任务？');
          await common.api('/tasks?id=' + encodeURIComponent(this.form.id), {
            method: 'DELETE',
            body: { id: Number(this.form.id) }
          });
          this.$router.push('/tasks');
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      },
      cancel: function () {
        this.$router.push('/tasks');
      }
    }
  };
})(window);
