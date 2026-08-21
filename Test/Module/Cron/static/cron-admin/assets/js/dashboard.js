(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminDashboard = {
    template: '#tpl-dashboard',
    data: function () {
      return {
        overview: {
          tasks: { total: 0, enabled: 0, disabled: 0 },
          executions: { today: 0, success: 0, failed: 0, skipped: 0, timeout: 0, cancelled: 0 },
          nodes: { total: 0, online: 0, offline: 0 }
        },
        trend: [],
        range: '24h'
      };
    },
    created: function () {
      this.load();
      this.loadTrend();
    },
    methods: {
      load: async function () {
        try {
          this.overview = await common.api('/dashboard/overview');
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      loadTrend: async function () {
        try {
          this.trend = await common.api('/dashboard/execution-trend?range=' + this.range) || [];
        } catch (e) {
          common.toastErr(this, e);
        }
      }
    }
  };
})(window);
