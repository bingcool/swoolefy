window.onload = function() {
  //<editor-fold desc="Changeable Configuration Block">

  // the following lines will be replaced by docker/configurator, when it runs in a docker-container
  window.ui = SwaggerUIBundle({
    url: "http://127.0.0.1/openapi-test.yaml",
    dom_id: '#swagger-ui',
    deepLinking: true,
    presets: [
      SwaggerUIBundle.presets.apis,
      SwaggerUIStandalonePreset
    ],
    plugins: [
      SwaggerUIBundle.plugins.DownloadUrl
    ],
    layout: "StandaloneLayout",
    // 展示tag的搜索框.搜索API接口时，可以使用ctl+f打开页面的搜索
    filter: true,
    // -1展开所有的接口.
    defaultModelsExpandDepth: 1
  });

  //</editor-fold>
};
