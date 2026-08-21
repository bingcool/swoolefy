(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminNodes = {
    template: '#tpl-nodes',
    data: function () {
      return {
        items: [],
        loading: false,
        dlg: false,
        form: { id: 0, nodeName: '', nodeIp: '', remark: '' }
      };
    },
    created: function () {
      this.load();
    },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await common.api('/nodes');
          this.items = (d && d.list) || [];
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.loading = false;
        }
      },
      open: function (row) {
        this.form = row
          ? { id: row.id, nodeName: row.nodeName, nodeIp: row.nodeIp, remark: row.remark }
          : { id: 0, nodeName: '', nodeIp: '', remark: '' };
        this.dlg = true;
      },
      save: async function () {
        try {
          if (this.form.id) {
            await common.api('/nodes', { method: 'PUT', body: this.form });
          } else {
            await common.api('/nodes', { method: 'POST', body: this.form });
          }
          this.dlg = false;
          this.$message.success('已保存');
          this.load();
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      remove: async function (row) {
        try {
          await this.$confirm('确认删除节点 ' + row.nodeName + '？');
          await common.api('/nodes', { method: 'DELETE', body: { id: row.id } });
          this.$message.success('已删除');
          this.load();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      }
    }
  };
})(window);
