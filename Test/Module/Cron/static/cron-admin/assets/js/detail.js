(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminDetail = {
    template: '#tpl-detail',
    data: function () {
      return { row: {}, stats: null, loading: false };
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
      load: async function () {
        this.loading = true;
        try {
          this.row = await common.api('/tasks/detail?id=' + this.id);
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
