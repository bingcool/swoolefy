(function (window) {
  'use strict';

  var common = window.CronAdminCommon;

  window.CronAdminTasks = {
    template: '#tpl-tasks',
    data: function () {
      return {
        items: [],
        total: 0,
        loading: false,
        nodes: [],
        groups: [],
        sel: [],
        query: { page: 1, pageSize: 20, keyword: '', status: '', groupId: '', nodeId: '', execType: '' },
        commandTip: { visible: false, text: '', x: 0, y: 0, flipX: false }
      };
    },
    created: function () {
      this.loadNodes();
      this.loadGroups();
      this.load();
    },
    mounted: function () {
      window.addEventListener('resize', this.relayoutTable);
    },
    beforeDestroy: function () {
      window.removeEventListener('resize', this.relayoutTable);
      this.hideCommandTip();
    },
    computed: {
      groupOptions: function () {
        return common.buildGroupOptions(this.groups, this.nodes);
      },
      filterGroupSelected: function () {
        return common.isGroupIdSelected(this.query.groupId);
      },
      filteredNodes: function () {
        return common.filterNodesByGroupId(this.nodes, this.query.groupId);
      },
      commandTipStyle: function () {
        var tip = this.commandTip;
        return {
          left: tip.x + 'px',
          top: tip.y + 'px',
          transform: tip.flipX ? 'translate(-100%, 8px)' : 'translate(12px, 12px)'
        };
      }
    },
    methods: {
      relayoutTable: function () {
        var table = this.$refs.taskTable;
        if (table && typeof table.doLayout === 'function') table.doLayout();
      },
      loadNodes: async function () {
        try {
          var d = await common.api('/nodes');
          this.nodes = (d && d.list) || [];
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      loadGroups: async function () {
        try {
          var d = await common.api('/node-groups');
          this.groups = (d && d.list) || [];
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      onFilterGroupChange: function () {
        this.query.nodeId = '';
      },
      load: async function () {
        this.loading = true;
        try {
          var qs = new URLSearchParams();
          var self = this;
          Object.keys(this.query).forEach(function (k) {
            if (self.query[k] !== '' && self.query[k] !== null) qs.set(k, self.query[k]);
          });
          var d = await common.api('/tasks?' + qs.toString());
          var rows = common.extractListRows(d);
          this.items = rows.map(function (row) {
            row = Object.assign({}, row || {});
            if (!row.groupName && row.group_name) row.groupName = row.group_name;
            if ((row.groupId === undefined || row.groupId === null) && row.group_id != null) {
              row.groupId = row.group_id;
            }
            if (!row.nodeName && row.node_name) row.nodeName = row.node_name;
            if ((row.nodeId === undefined || row.nodeId === null) && row.node_id != null) {
              row.nodeId = row.node_id;
            }
            if (row.command == null || row.command === '') {
              row.command = row.Command || row.cron_command || '';
            }
            return row;
          });
          this.total = common.extractListTotal(d, this.items);
          var self = this;
          this.$nextTick(function () {
            self.relayoutTable();
          });
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
      toggle: async function (row, nextStatus) {
        var status = nextStatus === undefined ? (row.status === 1 ? 0 : 1) : Number(nextStatus);
        try {
          await common.confirmTaskStatusChange(this, status, row.name);
          await common.api('/tasks/status', { method: 'PUT', body: { id: row.id, status: status } });
          common.toastTaskStatus(this, status, row.name);
          this.load();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
          this.$forceUpdate();
        }
      },
      runOnce: async function (row) {
        try {
          await common.confirmRunOnce(this, row.name);
          var d = await common.api('/tasks/run', { method: 'POST', body: { id: row.id } });
          this.$message.success((d && d.message) || '已入队');
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      },
      dup: async function (row) {
        try {
          await common.api('/tasks/duplicate', { method: 'POST', body: { id: row.id } });
          this.$message.success('已复制为禁用副本');
          this.load();
        } catch (e) {
          common.toastErr(this, e);
        }
      },
      remove: async function (row) {
        try {
          await common.confirmDelete(this, '确认删除 ' + row.name + '？');
          await common.api('/tasks?id=' + encodeURIComponent(row.id), {
            method: 'DELETE',
            body: { id: Number(row.id) }
          });
          this.$message.success('已删除');
          this.load();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      },
      batch: async function (status) {
        try {
          await common.confirmTaskStatusChange(this, status, this.sel.map(function (x) { return x.name; }));
          await common.api('/tasks/batch-status', {
            method: 'PUT',
            body: { ids: this.sel.map(function (x) { return x.id; }), status: status }
          });
          common.toastTaskStatus(this, status, this.sel.map(function (x) { return x.name; }));
          this.load();
        } catch (e) {
          if (e !== 'cancel') common.toastErr(this, e);
        }
      },
      formatNextRun: function (row) {
        return common.formatNextRunAt(row);
      },
      commandOf: function (row) {
        if (!row) return '';
        var cmd = row.command;
        if (cmd == null || cmd === '') cmd = row.Command;
        if (cmd == null || cmd === '') cmd = row.cron_command;
        if (cmd == null || cmd === undefined) return '';
        return String(cmd).trim();
      },
      commandPreview: function (row) {
        var cmd = this.commandOf(row);
        if (!cmd) return '-';
        if (cmd.length <= 20) return cmd;
        return cmd.slice(0, 20) + '...';
      },
      isCommandTruncated: function (row) {
        return this.commandOf(row).length > 20;
      },
      showCommandTip: function (e, row) {
        if (!this.isCommandTruncated(row)) {
          this.hideCommandTip();
          return;
        }
        this.commandTip.visible = true;
        this.commandTip.text = this.commandOf(row);
        this.moveCommandTip(e);
      },
      moveCommandTip: function (e) {
        if (!this.commandTip.visible || !e) return;
        this.commandTip.x = e.clientX;
        this.commandTip.y = e.clientY;
        this.commandTip.flipX = e.clientX > (typeof window !== 'undefined' ? window.innerWidth * 0.6 : 800);
      },
      hideCommandTip: function () {
        this.commandTip.visible = false;
        this.commandTip.text = '';
      },
      groupNameOf: function (row) {
        if (!row) return '-';
        var name = row.groupName || row.group_name;
        if (name) return name;
        var nid = Number(row.nodeId || row.node_id || 0);
        var nodes = this.nodes || [];
        for (var i = 0; i < nodes.length; i++) {
          if (Number(nodes[i].id) === nid) {
            var nname = nodes[i].groupName || nodes[i].group_name;
            if (nname) return nname;
            break;
          }
        }
        var gid = Number(row.groupId || row.group_id || 0);
        if (!gid) return '-';
        var groups = this.groups || [];
        for (var j = 0; j < groups.length; j++) {
          if (Number(groups[j].id) === gid) {
            return groups[j].groupName || groups[j].group_name || '-';
          }
        }
        return '-';
      },
      nodeNameOf: function (row) {
        if (!row) return '-';
        var name = row.nodeName || row.node_name;
        if (name) return name;
        var nid = Number(row.nodeId || row.node_id || 0);
        var nodes = this.nodes || [];
        for (var i = 0; i < nodes.length; i++) {
          if (Number(nodes[i].id) === nid) {
            var nname = nodes[i].nodeName || nodes[i].node_name;
            if (nname) return nname;
            break;
          }
        }
        return nid ? String(nid) : '-';
      }
    }
  };
})(window);
