# VideoCollector - 视频采集插件

在 Typecho 后台从苹果 CMS 标准采集 API 搜索影视资源，一键生成 `[play]` 短代码嵌入多分集视频播放器；前台支持 ArtPlayer 与 Iframe 两种播放模式。

- **版本**：1.0.10
- **作者**：xiao
- **兼容**：Typecho 1.2 / 1.3

## 功能特性

- **后台采集**：文章 / 页面编辑页侧栏提供「视频采集」搜索框，输入关键词即可搜索采集站资源，支持分页浏览、多平台（`vod_play_from`）选择
- **一键插入**：选中资源后自动生成 `[play]` 短代码（每集 `标题$地址` 一行）并附「简介」段落，标题为空时自动填充影片名
- **双播放模式**：
  - **Iframe 模式**（默认）：嵌入第三方解析器播放页，16:9 自适应
  - **ArtPlayer 模式**：ArtPlayer 5.4.0 + hls.js 1.7.1 + flv.js 1.6.2（npmmirror 固定版本），支持 m3u8 / flv / mp4，带下一集按钮、倍速、画中画、记忆播放进度
- **多分集支持**：分集标签按钮切换，标题溢出时悬停滚动显示完整标题；切换时更新浏览器标签页标题
- **性能优化**：
  - ArtPlayer 懒加载：进入视口（200px 边距）才初始化，避免列表页多播放器同时拉流
  - 不自动播放：进入页面一律手动播放，避免多播放器同时出声
  - Iframe 切集采用销毁重建（非改 src）+ 250ms 防抖，避免内存叠加崩溃
- **防盗链处理**：前台注入 `<meta name="referrer" content="same-origin">`，跨域视频请求不带 Referer 绕过防盗链，同时保留站内评论所需 Referer
- **PJAX 兼容**：内置 jquery-pjax 与原生 pjax 事件绑定，切页前销毁播放器与 hls/flv 解码器防止泄漏；PJAX 主题加载完成后调用 `initVideoCollectors()` 即可
- **插件链安全**：播放器 HTML 以占位符保护，避免被 Markdown / AutoP 破坏；代码块内演示的短代码原样显示；摘要同样渲染播放器

## 安装

1. 将 `VideoCollector` 文件夹复制到 `usr/plugins/` 目录
2. 后台「控制台 → 插件」中启用

## 插件配置

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| 采集API地址 | `https://caiji.xgzyapi.com/api.php/provide/vod/?ac=detail&wd=` | 苹果 CMS 标准 JSON API，`wd=` 后自动追加搜索关键词；插件会统一规范 `ac=detail`、`wd`、`pg` 参数 |
| 视频播放方式 | Iframe | `ArtPlayer`：加载 JSON 解析地址返回的视频直链播放（要求返回的 JSON 中 `url` 键值为视频地址）；`Iframe`：嵌入第三方播放器页面（要求返回的是播放器页面 URL） |
| Json解析地址 | 示例地址 | ArtPlayer 模式使用的视频解析地址前缀（可选），如 `json.php?url=` |
| Iframe解析地址 | 示例地址 | Iframe 模式使用的解析地址前缀（可选），如 `iframe.php?url=` |

## 使用方法

### 后台采集

1. 编辑文章 / 页面时，在侧栏「视频采集」中输入关键词并搜索
2. 点击搜索结果选择资源；若资源有多个播放平台，先选择平台
3. 播放地址与简介自动插入编辑器，同时自动填充文章标题

### 短代码

```
基本用法：
[play]
视频URL1
视频URL2
[/play]

带影片名 / 自定义分集标题（推荐 $ 分隔符）：
[play name="影片名"]
第1集$视频URL1
第2集$视频URL2
[/play]

兼容旧格式（| 分隔符）：
[play name="影片名"]
第1集|视频URL1
第2集|视频URL2
[/play]

同一行逗号分隔多个分集：
[play name="影片名"]
第1集$视频URL1,第2集$视频URL2,第3集$视频URL3
[/play]

不使用解析地址（直连播放）：
[!play]
视频URL1
视频URL2
[/play]
```

- 每行一集，也支持 `标题|URL` 与纯 URL（无标题时显示「第N集」）
- 行内逗号仅在逗号后紧跟 `http(s)://` 或 `标题$http(s)://` 时才视为分集分隔，不会误拆 URL 中的逗号
- `[!play]` 强制不使用解析地址，`[play]` 是否使用取决于播放模式对应的解析地址配置

## 前端接口

PJAX 主题在页面切换完成后调用全局函数即可重新初始化播放器与分集按钮：

```js
initVideoCollectors();
```

## 工作原理

- **搜索代理**：后台搜索经 `/action/video-collect?do=search&keyword=&page=` 由服务器代理请求采集 API（**需登录**），规避跨域；cURL 优先、`file_get_contents` 兜底
- **短代码渲染**：content 钩子解析 `[play]` → 播放器 HTML 以 `\x01` 占位符暂存 → 保证 Markdown/AutoP 在整条插件链中只执行一次 → 还原播放器 HTML；excerpt 钩子同样渲染播放器（列表页可见）
- **ArtPlayer 模式**：分集数据存于隐藏 input（`\n` 分隔），JS 解析后初始化播放器；切集前销毁旧 hls/flv 实例并断开 `<video>`，再换源加载；同源 URL 做 HEAD 探测判断格式，跨域直接按 URL 后缀判断，无扩展名 URL 回落 mp4（原生 `video.src` 播放，规避 CORS 限制）
- **Iframe 模式**：切集时销毁旧 iframe 并重建，避免旧页面内存异步回收导致的叠加崩溃

## 文件结构

```
VideoCollector/
├── Plugin.php   # 插件主体：采集面板、短代码解析、前台依赖输出
├── Action.php   # 搜索代理接口（/action/video-collect，需登录）
├── script.js    # 前端：播放器初始化、分集切换、PJAX 适配
├── style.css    # 前端样式：播放器容器、分集按钮
└── README.md
```

## 注意事项

- 主题需在 `header.php` 调用 `$this->header()`、`footer.php` 调用 `$this->footer()`，否则播放器依赖不会输出
- ArtPlayer 模式列表页多视频时依赖懒加载控制流量；HLS 缓冲参数较大（`maxBufferLength: 300`），流量敏感场景可调小
- 无扩展名视频地址（如抖音 CDN）会被按 mp4 原生播放处理，这类地址必须能接受无 Referer 请求（插件注入的 `same-origin` referrer 已处理）
- 第三方解析地址与采集 API 均为外部服务，可用性与内容合规请自行把控
