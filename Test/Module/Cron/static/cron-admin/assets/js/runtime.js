(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminRuntime = {
    template: '#tpl-runtime',
    data: function () {
      return {
        overview: {
          scheduler: { jobs: 0, enabled: 0, running: 0 },
          sync: { lastSuccessAt: null, lastErrorAt: null, processLocal: true },
          nodes: { online: 0, offline: 0 },
          note: ''
        }
      };
    },
    created: function () {
      this.load();
    },
    methods: {
      load: async function () {
        try {
          this.overview = await common.api('/runtime/overview');
        } catch (e) {
          common.toastErr(this, e);
        }
      }
    }
  };
})(window);
