# VideoCollector
Typecho插件 - 官方视频采集

## 插件名称：VideoCollector

版本：1.0.3

兼容：typecho 1.1 - 1.3

要求：php7.2+ 且启用 `cURL` 扩展

## 预览

<img width="968" height="1013" alt="设置" src="https://github.com/user-attachments/assets/dd057062-2a35-43b8-9c82-861cfc76daff" />

<img width="1175" height="722" alt="采集" src="https://github.com/user-attachments/assets/66823b2e-874e-49ab-a876-c90c7fbad44b" />

<img width="788" height="986" alt="播放" src="https://github.com/user-attachments/assets/d30b14a0-0db6-4764-9d79-b09d2aac451c" />

## 演示

https://ma.us.ci

## 功能

在后台文章编辑/页面编辑页搜索国内各大视频网站的影视资源，一键将搜索的影视以短代码的形式插入到编辑器中，在前台文章/页面中直接播放；

支持 `artplayer` / `iframe` 两种模式播放（默认为iframe模式），ArtPlayer模式是加载JSON中的URL键值进行播放（请确保解析地址返回的JSON中包含URL键值，且URL键值为视频地址），Iframe模式是嵌入第三方播放器URL进行播放（请确保Iframe解析地址返回的URL是一个视频播放器页面）；

支持自定义采集API地址、解析地址；

带视频分集切换功能；

支持自定义短代码使用（后台插件设置中有详细说明）；

支持PJAX主题，（在PJAX加载完成后调用 `initVideoCollectors();` 即可）；

插件CSS包含暗色模式，可搭配暗色主题使用，元素标签类名： `body.dark` 。

## 使用方法

下载插件，上传到插件目录，文件夹改名为 `VideoCollector` ，在后台启用插件，进行相关设置（可选），在撰写新文章或页面的编辑器右侧视频采集输入框输入要插入的视频标题，点击搜索视频，选择平台（如果有），点击搜索结果的视频标题即可插入视频、分集按钮和视频简介。

## 特别注意

插件不提供解析功能，全部引用第三方解析API；

插件使用了短代码函数，可能会与其他短代码功能插件或主题产生冲突；

如果使用artplayer模式，请务必填写可用的视频解析地址，另外，在Iframe模式下如果默认的Iframe解析地址失效，视频也无法正常播放（当然，如果你以 `[!play]` 方式，不使用解析地址插入的另说）；

如果你是从旧版升级的，由于1.0.3版本更换了短代码变量名称，请将原文章或页面中的短代码 `[hls]...[/hls]` 改成 `[play]...[/play]` ，否则以前的视频将无法正常播放；

注意！！！由于采集的第三方视频无版权，使用此插件可能涉及盗版风险，若存在版权纠纷，与插件开发者无关！请酌情使用。
