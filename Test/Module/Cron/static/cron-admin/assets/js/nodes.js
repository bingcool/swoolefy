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
        var nodeName = this.form.nodeName ? String(this.form.nodeName).trim() : '';
        var nodeIp = this.form.nodeIp ? String(this.form.nodeIp).trim() : '';
        if (!nodeName || !nodeIp) {
          this.$message.warning('请填写节点名称和IP');
          return;
        }
        try {
          var payload = Object.assign({}, this.form, {
            nodeName: nodeName,
            nodeIp: nodeIp
          });
          if (this.form.id) {
            await common.api('/nodes', { method: 'PUT', body: payload });
          } else {
            await common.api('/nodes', { method: 'POST', body: payload });
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
