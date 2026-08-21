(function (window) {
  'use strict';

  var router = new VueRouter({
    routes: [
      { path: '/', redirect: '/dashboard' },
      { path: '/dashboard', component: window.CronAdminDashboard, meta: { title: 'Dashboard', subtitle: '任务、今日执行与节点心跳聚合', breadcrumb: 'Dashboard' } },
      { path: '/tasks', component: window.CronAdminTasks, meta: { title: '计划任务', subtitle: '配置写入 cron_task，Worker Polling 后生效', breadcrumb: '计划任务' } },
      { path: '/tasks/create', component: window.CronAdminEditor, meta: { title: '创建计划任务', subtitle: '配置任务调度规则、执行方式以及运行策略', breadcrumb: '计划任务 / 创建任务' } },
      { path: '/tasks/edit/:id', component: window.CronAdminEditor, meta: { title: '编辑计划任务', subtitle: '配置任务调度规则、执行方式以及运行策略', breadcrumb: '计划任务 / 编辑任务' } },
      { path: '/tasks/detail/:id', component: window.CronAdminDetail, meta: { title: '任务详情', subtitle: '', breadcrumb: '计划任务 / 任务详情' } },
      { path: '/executions', component: window.CronAdminExecutions, meta: { title: '执行记录', subtitle: '按任务、状态、批次过滤', breadcrumb: '执行记录' } },
      { path: '/executions/log', component: window.CronAdminExecutionLog, meta: { title: '执行日志', subtitle: '单次执行详情、stdout/stderr 与下载', breadcrumb: '执行记录 / 执行日志' } },
      { path: '/nodes', component: window.CronAdminNodes, meta: { title: 'Cron Nodes', subtitle: 'Agent 节点管理与心跳状态', breadcrumb: 'Cron Nodes' } },
      { path: '/runtime', component: window.CronAdminRuntime, meta: { title: 'Runtime', subtitle: 'Cron Worker 运行时聚合概览', breadcrumb: 'Runtime' } }
    ]
  });

  function redirectLegacyUrls() {
    var path = window.location.pathname;
    var search = window.location.search;
    var hash = window.location.hash;
    if (path.indexOf('/cron-admin/task.html') !== -1) {
      var id = (new URLSearchParams(search)).get('id');
      window.location.replace('/cron-admin' + (id ? '#/tasks/edit/' + id : '#/tasks/create'));
      return true;
    }
    if (path.indexOf('/cron-admin/execution.html') !== -1) {
      var taskId = (new URLSearchParams(search)).get('taskId');
      window.location.replace('/cron-admin#/executions' + (taskId ? '?taskId=' + encodeURIComponent(taskId) : ''));
      return true;
    }
    if (path.indexOf('/cron-admin/log.html') !== -1) {
      var params = new URLSearchParams(search);
      window.location.replace('/cron-admin#/executions/log?taskId=' + encodeURIComponent(params.get('taskId') || '')
        + '&execBatchId=' + encodeURIComponent(params.get('execBatchId') || ''));
      return true;
    }
    if (path.indexOf('/cron-admin/index.html') !== -1 && !hash) {
      window.location.replace('/cron-admin#/tasks');
      return true;
    }
    return false;
  }

  if (redirectLegacyUrls()) {
    return;
  }

  new Vue({
    el: '#app',
    router: router,
    computed: {
      breadcrumbPath: function () {
        return (this.$route.meta && this.$route.meta.breadcrumb) || '';
      },
      pageTitle: function () {
        return (this.$route.meta && this.$route.meta.title) || '';
      },
      pageSubtitle: function () {
        return (this.$route.meta && this.$route.meta.subtitle) || '';
      }
    }
  });
})(window);
