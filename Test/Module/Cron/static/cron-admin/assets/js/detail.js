(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminDetail = {
    template: '#tpl-detail',
    data: function () {
      return {
        row: {},
        stats: null,
        loading: false,
        cronBetweenItems: [],
        cronSkipItems: []
      };
    },
    computed: {
      id: function () {
        return this.$route.params.id;
      }
    },
    created: function () {
      this.load();
    },
    methods: {
      parseRangeSource: function (source) {
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
            return [];
          }
        }
      },
      normalizeRangeItems: function (source) {
        var ranges = this.parseRangeSource(source);
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
          } else if (typeof item === 'string') {
            var parts = item.split('-');
            if (parts.length >= 2) {
              start = parts[0];
              end = parts.slice(1).join('-');
            }
          }
          start = start == null ? '' : String(start).trim();
          end = end == null ? '' : String(end).trim();
          if (!start && !end) return acc;
          acc.push({ start: start || '-', end: end || '-' });
          return acc;
        }, []);
      },
      load: async function () {
        this.loading = true;
        try {
          this.row = await common.api('/tasks/detail?id=' + this.id);
          this.cronBetweenItems = this.normalizeRangeItems(
            this.row.cronBetween != null ? this.row.cronBetween : this.row.cron_between
          );
          this.cronSkipItems = this.normalizeRangeItems(
            this.row.cronSkip != null ? this.row.cronSkip : this.row.cron_skip
          );
          this.stats = await common.api('/tasks/stats?taskId=' + this.id);
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.loading = false;
        }
      },
      runOnce: async function () {
        try {
          var d = await common.api('/tasks/run', { method: 'POST', body: { id: Number(this.id) } });
          this.$message.success((d && d.message) || '已入队');
        } catch (e) {
          common.toastErr(this, e);
        }
      }
    }
  };
})(window);
