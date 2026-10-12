layui.define(["jquery", "toast"], function (exports) {
  "use strict";
  var $ = layui.jquery;
  var toast = layui.toast;

  var instances = {};

  var bdEditor = {
    instances: instances,

    config: {
      uploadUrl: (typeof Config !== "undefined" && Config.upload && Config.upload.uploadurl) 
        ? Config.upload.uploadurl 
        : "/admin/ajax/upload",
      minHeight: 360,
    },

    /**
     * 渲染富文本编辑器
     * @param {string|HTMLElement|jQuery} elem 选择器或元素
     * @param {object} customConfig 自定义配置
     */
    render: function (elem, customConfig) {
      if (typeof window.wangEditor === "undefined") {
        console.warn("[bdEditor] 未找到 wangEditor 核心库，请先加载 index.min.js");
        return null;
      }

      var $elem = $(elem);
      if ($elem.length === 0) return null;

      var that = this;
      var results = [];

      $elem.each(function () {
        var $textarea = $(this);
        var id = $textarea.attr("id");
        if (!id) {
          id = "bd-editor-" + Math.random().toString(36).substring(2, 9);
          $textarea.attr("id", id);
        }

        // 如果已经渲染过，先销毁旧实例
        if (instances[id]) {
          that.destroy(id);
        }

        var opt = $.extend({}, that.config, customConfig || {});
        var initialHtml = $textarea.val() || "";

        // 创建包裹容器
        var wrapperId = "bd-editor-wrap-" + id;
        var toolbarId = "bd-editor-toolbar-" + id;
        var textId = "bd-editor-text-" + id;

        var htmlWrapper = [
          '<div class="bd-editor-box" id="' + wrapperId + '">',
          '  <div class="bd-editor-toolbar" id="' + toolbarId + '"></div>',
          '  <div class="bd-editor-content" id="' + textId + '" style="min-height:' + opt.minHeight + 'px;"></div>',
          '</div>'
        ].join("");

        $textarea.hide().after(htmlWrapper);

        var editorConfig = {
          placeholder: $textarea.attr("placeholder") || "请输入内容...",
          html: initialHtml,
          autoFocus: false,
          MENU_CONF: {},
          onChange: function (editor) {
            var html = editor.getHtml();
            // wangEditor 空白内容为 <p><br></p>，视为空字符串
            if (html === "<p><br></p>") {
              html = "";
            }
            $textarea.val(html).trigger("change");
          }
        };

        // 配置图片上传（对接后台 /admin/ajax/upload）
        editorConfig.MENU_CONF["uploadImage"] = {
          async customUpload(file, insertFn) {
            var formData = new FormData();
            formData.append("file", file);
            if (typeof Config !== "undefined" && Config.token) {
              formData.append("__token__", Config.token);
            }

            $.ajax({
              url: opt.uploadUrl,
              type: "POST",
              data: formData,
              processData: false,
              contentType: false,
              dataType: "json",
              headers: {
                "X-CSRF-TOKEN": (typeof Config !== "undefined" && Config.token) ? Config.token : ""
              },
              success: function (res) {
                if (res.code === 1 || res.code === 0 && res.data && res.data.url) {
                  var url = res.data.fullurl || res.data.url;
                  insertFn(url, file.name, url);
                  toast.success({ message: "图片上传成功" });
                } else {
                  toast.error({ message: res.msg || "图片上传失败" });
                }
              },
              error: function () {
                toast.error({ message: "网络或服务异常，图片上传失败" });
              }
            });
          }
        };

        // 配置视频上传
        editorConfig.MENU_CONF["uploadVideo"] = {
          async customUpload(file, insertFn) {
            var formData = new FormData();
            formData.append("file", file);
            if (typeof Config !== "undefined" && Config.token) {
              formData.append("__token__", Config.token);
            }

            $.ajax({
              url: opt.uploadUrl,
              type: "POST",
              data: formData,
              processData: false,
              contentType: false,
              dataType: "json",
              headers: {
                "X-CSRF-TOKEN": (typeof Config !== "undefined" && Config.token) ? Config.token : ""
              },
              success: function (res) {
                if (res.code === 1 || res.code === 0 && res.data && res.data.url) {
                  var url = res.data.fullurl || res.data.url;
                  insertFn(url);
                  toast.success({ message: "视频上传成功" });
                } else {
                  toast.error({ message: res.msg || "视频上传失败" });
                }
              },
              error: function () {
                toast.error({ message: "网络或服务异常，视频上传失败" });
              }
            });
          }
        };

        // 创建编辑器与工具栏
        try {
          var editor = window.wangEditor.createEditor({
            selector: "#" + textId,
            html: initialHtml,
            config: editorConfig,
            mode: "default"
          });

          var toolbar = window.wangEditor.createToolbar({
            editor: editor,
            selector: "#" + toolbarId,
            config: {},
            mode: "default"
          });

          instances[id] = {
            id: id,
            editor: editor,
            toolbar: toolbar,
            $textarea: $textarea,
            wrapperId: wrapperId
          };

          results.push(instances[id]);
        } catch (e) {
          console.error("[bdEditor] 渲染失败:", e);
        }
      });

      return results.length === 1 ? results[0] : results;
    },

    /**
     * 同步所有编辑器内容至 textarea
     */
    syncAll: function () {
      for (var id in instances) {
        if (instances.hasOwnProperty(id)) {
          var item = instances[id];
          if (item && item.editor && item.$textarea) {
            var html = item.editor.getHtml();
            if (html === "<p><br></p>") {
              html = "";
            }
            item.$textarea.val(html);
          }
        }
      }
    },

    /**
     * 获取指定编辑器 HTML
     */
    getHtml: function (id) {
      if (instances[id] && instances[id].editor) {
        var html = instances[id].editor.getHtml();
        return html === "<p><br></p>" ? "" : html;
      }
      return "";
    },

    /**
     * 设置指定编辑器 HTML
     */
    setHtml: function (id, html) {
      if (instances[id] && instances[id].editor) {
        instances[id].editor.setHtml(html || "");
        instances[id].$textarea.val(html || "");
      }
    },

    /**
     * 销毁指定编辑器
     */
    destroy: function (id) {
      if (instances[id]) {
        try {
          if (instances[id].editor) instances[id].editor.destroy();
        } catch (e) {}
        $("#" + instances[id].wrapperId).remove();
        instances[id].$textarea.show();
        delete instances[id];
      }
    }
  };

  exports("bdEditor", bdEditor);
});
