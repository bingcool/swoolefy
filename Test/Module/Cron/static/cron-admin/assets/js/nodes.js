(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  function emptyNodeForm() {
    return { id: 0, groupId: null, nodeName: '', nodeIp: '', remark: '' };
  }

  function emptyGroupForm() {
    return { id: 0, groupName: '', remark: '' };
  }

  window.CronAdminNodes = {
    template: '#tpl-nodes',
    data: function () {
      return {
        activeTab: 'nodes',
        items: [],
        groups: [],
        loading: false,
        groupLoading: false,
        dlg: false,
        groupDlg: false,
        form: emptyNodeForm(),
        groupForm: emptyGroupForm()
      };
    },
    created: function () {
      this.load();
      this.loadGroups();
    },
    methods: {
      onTabClick: function (tab) {
        var name = (tab && tab.name) ? tab.name : this.activeTab;
        if (name === 'groups') {
          this.loadGroups();
        } else {
          this.load();
        }
      },
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
      loadGroups: async function () {
        this.groupLoading = true;
        try {
          var d = await common.api('/node-groups');
          this.groups = (d && d.list) || [];
        } catch (e) {
          common.toastErr(this, e);
        } finally {
          this.groupLoading = false;
        }
      },
      openNode: async function (row) {
        if (!this.groups.length) {
          await this.loadGroups();
        }
        if (!row && !this.groups.length) {
          this.$message.warning('请先在「节点分组」页创建分组');
          this.activeTab = 'groups';
          return;
        }
        this.form = row
          ? {
            id: row.id,
            groupId: row.groupId || null,
            nodeName: row.nodeName,
            nodeIp: row.nodeIp,
            remark: row.remark
          }
          : emptyNodeForm();
        this.dlg = true;
      },
      saveNode: async function () {
        var nodeName = this.form.nodeName ? String(this.form.nodeName).trim() : '';
        var nodeIp = this.form.nodeIp ? String(this.form.nodeIp).trim() : '';
        var groupId = Number(this.form.groupId || 0);
        if (!groupId) {
          this.$message.warning('请选择所属分组');
          return;
        }
        if (!nodeName || !nodeIp) {
          this.$message.warning('请填写节点名称和IP');
          return;
        }
        try {
          var payload = {
            id: this.form.id,
            groupId: groupId,
            nodeName: nodeName,
            nodeIp: nodeIp,
            remark: this.form.remark
          };
          if (this.form.id) {
            await common.api('/nodes', { method: 'PUT', body: payload });
          } else {
            await common.api('/nodes', { method: 'POST', body: payload });
          }
          this.dlg = false;
          this.$message.success('已保存');
          this.load();
          this.loadGroups();
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      removeNode: async function (row) {
        try {
          await common.confirmDelete(this, '确认删除节点 ' + row.nodeName + '？');
          await common.api('/nodes', { method: 'DELETE', body: { id: row.id } });
          this.$message.success('已删除');
          this.load();
          this.loadGroups();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      },
      openGroup: function (row) {
        this.groupForm = row
          ? { id: row.id, groupName: row.groupName, remark: row.remark }
          : emptyGroupForm();
        this.groupDlg = true;
      },
      saveGroup: async function () {
        var groupName = this.groupForm.groupName ? String(this.groupForm.groupName).trim() : '';
        if (!groupName) {
          this.$message.warning('请填写分组名称');
          return;
        }
        try {
          var payload = {
            id: this.groupForm.id,
            groupName: groupName,
            remark: this.groupForm.remark
          };
          if (this.groupForm.id) {
            await common.api('/node-groups', { method: 'PUT', body: payload });
          } else {
            await common.api('/node-groups', { method: 'POST', body: payload });
          }
          this.groupDlg = false;
          this.$message.success('已保存');
          this.loadGroups();
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      removeGroup: async function (row) {
        try {
          await common.confirmDelete(this, '确认删除分组 ' + row.groupName + '？');
          await common.api('/node-groups', { method: 'DELETE', body: { id: row.id } });
          this.$message.success('已删除');
          this.loadGroups();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      }
    }
  };
})(window);
