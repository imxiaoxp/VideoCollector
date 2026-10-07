<?php

namespace TypechoPlugin\VideoCollector;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 视频采集 - 从JSON API采集视频并插入编辑器
 *
 * @package 视频采集
 * @author xiao
 * @version 1.0.11
 * @link https://ma.us.ci
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Typecho\Plugin::factory('admin/write-post.php')->option = __CLASS__ . '::renderCollector';
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = __CLASS__ . '::renderScript';
        \Typecho\Plugin::factory('admin/write-page.php')->option = __CLASS__ . '::renderCollector';
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = __CLASS__ . '::renderScript';

        \Typecho\Plugin::factory('Widget_Abstract_Contents')->content = array(__CLASS__, 'parseContent');
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerpt = array(__CLASS__, 'parseExcerpt');
        \Typecho\Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'outputHeader');

        Helper::addAction('video-collect', 'TypechoPlugin\VideoCollector\Action');

        return _t('VideoCollector 插件已激活，现在可以使用 [play][/play] 短代码嵌入视频。');
    }

    public static function deactivate()
    {
        Helper::removeAction('video-collect');
        return _t('插件已禁用');
    }

    /**
     * 解析 [play] 短代码，播放器 HTML 以 \x01 占位符暂存，待 Markdown/AutoP
     * 转换完成后再还原（内联 HTML 会被自动链接/换行转换破坏分集数据）
     */
    private static function parseShortCode(string $content, array &$htmlStore): string
    {
        $playPattern = '/\[(!?play)\s*(?:id="([^"]*)")?\s*(?:name="([^"]*)")?\s*\](.*?)\[\/play\]/s';

        $htmlStore = [];
        return preg_replace_callback(
            $playPattern,
            function ($m) use (&$htmlStore) {
                $key = "\x01VCH" . count($htmlStore) . "\x01";
                $htmlStore[$key] = self::parsePlayShortCode($m);
                return $key;
            },
            $content
        );
    }

    /** 还原播放器 HTML，并剥离 Markdown 给独立成段占位符包裹的 <p>（避免 <p><div></div></p>） */
    private static function restorePlayerHtml(string $content, array $htmlStore): string
    {
        if (empty($htmlStore)) {
            return $content;
        }
        $content = preg_replace('/<p>\s*((?:\x01VCH\d+\x01\s*)+)<\/p>/s', '$1', $content);
        return str_replace(array_keys($htmlStore), array_values($htmlStore), $content);
    }

    /**
     * 解析单个 [play] 短代码为播放器 HTML。
     * 分集格式：标题$URL / 标题|URL / 纯 URL；同行逗号分隔多集时仅当逗号后
     * 紧跟 http(s):// 或 "标题$http(s)://"，避免误拆 URL 中合法的逗号
     */
    private static function parsePlayShortCode(array $matches): string
    {
        $shortCodeType = $matches[1];
        $id = $matches[2] ? $matches[2] : 'play_' . uniqid();
        $name = $matches[3] ? $matches[3] : '';
        $content = trim($matches[4]);

        $videos = array();
        $lines = preg_split('/[\r\n]+/', $content);
        foreach ($lines as $line) {
            $parts = preg_split('/,(?=\s*(?:https?:\/\/|[^\$,]*\$\s*https?:\/\/))/i', trim($line));
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (strpos($part, '$') !== false) {
                    list($title, $url) = explode('$', $part, 2);
                } elseif (strpos($part, '|') !== false) {
                    list($title, $url) = explode('|', $part, 2);
                } else {
                    $title = '';
                    $url = $part;
                }
                $url = trim($url);
                if ($url !== '') {
                    $videos[] = array('title' => trim($title), 'url' => $url);
                }
            }
        }

        if (empty($videos)) {
            return '';
        }

        $options = Helper::options()->plugin('VideoCollector');
        $parserUrl = $options->videoParserUrl ?? '';
        $iframeParserUrl = $options->iframeParserUrl ?? '';
        $playMode = $options->playMode ?? 'artplayer';

        $useParserUrl = substr($shortCodeType, 0, 1) !== '!'; // [!play] 不使用解析地址

        $videoUrls = array_column($videos, 'url');
        $videoTitles = array_column($videos, 'title');

        $html = '<div class="play-container" id="' . $id . '" data-use-parser-url="' . ($useParserUrl ? 'true' : 'false') . '" data-type="play">';
        if ($name !== '') {
            $html .= '<div class="play-title">' . $name . '</div>';
        }
        $html .= '<div class="play-player-wrapper">';

        if ($playMode === 'iframe') {
            $firstUrl = ($useParserUrl && $iframeParserUrl !== '') ? $iframeParserUrl . urlencode($videoUrls[0]) : $videoUrls[0];
            $html .= '<iframe id="artplayer-' . $id . '" class="artplayer-iframe" loading="lazy" frameborder="0" allowfullscreen allow="autoplay; fullscreen" src="' . htmlspecialchars($firstUrl) . '" style="width: 100%; height: 100%;"></iframe>';
            $html .= '<style>.play-player-wrapper { position: relative; width: 100%; padding-top: 56.25%; } .artplayer-iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }</style>';
        } else {
            $html .= '<div id="artplayer-' . $id . '" class="artplayer"></div>';
        }

        $html .= '</div>';

        if ($playMode !== 'iframe' || count($videos) > 1) {
            $dataParserUrl = $playMode === 'iframe' ? $iframeParserUrl : $parserUrl;
            $html .= '<input type="hidden" class="video-urls" value="' . htmlspecialchars(implode("\n", $videoUrls)) . '" />';
            $html .= '<input type="hidden" class="video-titles" value="' . htmlspecialchars(implode("\n", $videoTitles)) . '" />';
            $html .= '<input type="hidden" class="video-parser-url" value="' . htmlspecialchars($dataParserUrl) . '" />';
            $html .= '<input type="hidden" class="video-use-parser" value="' . ($useParserUrl ? 'true' : 'false') . '" />';
        }

        if (count($videos) > 1) {
            $html .= '<div class="video-tabs">';
            foreach ($videos as $index => $video) {
                $tabTitle = $video['title'] !== '' ? $video['title'] : '第' . ($index + 1) . '集';
                $html .= '<span class="video-tab ' . ($index === 0 ? 'active' : '') . '" data-index="' . $index . '" data-container="' . $id . '"><span class="video-tab-text">' . $tabTitle . '</span></span>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * 提取代码块为占位符（代码块内演示的 [play] 原样显示）。
     * 覆盖围栏/行内反引号（markdown 原文）与 <pre>/<code>（已渲染 HTML），
     * 围栏匹配与未闭合处理同 HyperDown
     */
    private static function protectCodeBlocks(string $content, array &$store): string
    {
        $store = [];
        $placeholder = function (string $text) use (&$store): string {
            $key = "\x01CB" . count($store) . "\x01";
            $store[$key] = $text;
            return $key;
        };

        $content = preg_replace_callback(
            '/<pre[^>]*>.*?<\/pre>/is',
            function ($m) use ($placeholder) {
                return $placeholder($m[0]);
            },
            $content
        );

        $lines = explode("\n", $content);
        $out = [];
        $buffer = [];
        $fence = null;
        foreach ($lines as $line) {
            if ($fence !== null) {
                $buffer[] = $line;
                if (preg_match("/^(\s*)(~{3,}|`{3,})([^`~]*)$/", $line, $m) && $m[2] === $fence) {
                    $out[] = $placeholder(implode("\n", $buffer));
                    $buffer = [];
                    $fence = null;
                }
                continue;
            }
            if (preg_match("/^(\s*)(~{3,}|`{3,})([^`~]*)$/", $line, $m)) {
                $fence = $m[2];
                $buffer = [$line];
                continue;
            }
            $out[] = $line;
        }
        if ($fence !== null) {
            // 未闭合的围栏：到文末均视为代码块
            $out[] = $placeholder(implode("\n", $buffer));
        }
        $content = implode("\n", $out);

        $content = preg_replace_callback(
            '/(^|[^\\\\])(`+)(.+?)\2/s',
            function ($m) use ($placeholder) {
                return $m[1] . $placeholder($m[2] . $m[3] . $m[2]);
            },
            $content
        );

        $content = preg_replace_callback(
            '/<code[^>]*>.*?<\/code>/is',
            function ($m) use ($placeholder) {
                return $placeholder($m[0]);
            },
            $content
        );

        return $content;
    }

    private static function restoreCodeBlocks(string $content, array $store): string
    {
        return empty($store) ? $content : str_replace(array_keys($store), array_values($store), $content);
    }

    /**
     * 保护其他插件尚未处理的短代码不被 Markdown/AutoP 破坏。正则刻意收紧，
     * 避免误伤 Markdown 链接语法（[文本](url)、[引用][1]、![](img)）
     */
    private static function protectRemainingShortCodes(string $content, array &$store): string
    {
        $store = [];
        return preg_replace_callback(
            '/\[(!?)([a-z][a-z0-9_]*)[^\]]*\](?!\().*?\[\/\2\]|\[!?[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))+\s*\](?![\(\[])/is',
            function ($m) use (&$store) {
                $key = "\x01SCPH" . count($store) . "\x01";
                $store[$key] = $m[0];
                return $key;
            },
            $content
        );
    }

    /** 还原被保护的短代码，并去掉 Markdown 给独立成段占位符包裹的 <p> */
    private static function restoreRemainingShortCodes(string $content, array $store): string
    {
        if (empty($store)) {
            return $content;
        }
        $content = str_replace(array_keys($store), array_values($store), $content);
        $content = preg_replace(
            '/<p>\s*(\[(!?)([a-z][a-z0-9_]*)[^\]]*\](?!\().*?\[\/\3\])\s*<\/p>/is',
            '$1',
            $content
        );
        $content = preg_replace(
            '/<p>\s*(\[!?[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))+\s*\])\s*<\/p>/is',
            '$1',
            $content
        );
        return $content;
    }

    public static function parseContent(string $content, $widget, ?string $lastResult): string
    {
        $content = $lastResult ?? $content;

        $codeStore = [];
        $content = self::protectCodeBlocks($content, $codeStore);
        $htmlStore = [];
        $content = self::parseShortCode($content, $htmlStore);
        $content = self::restoreCodeBlocks($content, $codeStore);

        // widget 标记保证 Markdown/AutoP 在整条插件链中只执行一次
        if (!isset($widget->__pluginShortcodeContentRendered)) {
            $widget->__pluginShortcodeContentRendered = true;

            $store = [];
            $content = self::protectRemainingShortCodes($content, $store);
            if ($widget->isMarkdown) {
                $content = \Utils\Markdown::convert($content);
            } else {
                static $parser;
                if (empty($parser)) {
                    $parser = new \Utils\AutoP();
                }
                $content = $parser->parse($content);
            }
            $content = self::restoreRemainingShortCodes($content, $store);
        }

        return self::restorePlayerHtml($content, $htmlStore);
    }

    public static function parseExcerpt(string $excerpt, $widget, ?string $lastResult): string
    {
        $excerpt = $lastResult ?? $excerpt;

        // excerpt 钩子的输入是 content 链已渲染的 HTML，绝不能再做 Markdown/AutoP 转换
        $codeStore = [];
        $excerpt = self::protectCodeBlocks($excerpt, $codeStore);
        $htmlStore = [];
        $excerpt = self::parseShortCode($excerpt, $htmlStore);
        $excerpt = self::restoreCodeBlocks($excerpt, $codeStore);

        return self::restorePlayerHtml($excerpt, $htmlStore);
    }

    public static function outputHeader(): void
    {
        // same-origin：跨域视频不带 Referer 避开防盗链 403；站内评论仍带 Referer，
        // 不影响 Typecho 评论来源校验；视频走原生 video.src，播放器控件不受影响
        echo '<meta name="referrer" content="same-origin">' . "\n";

        $pluginUrl = Helper::options()->pluginUrl;

        // npmmirror 固定版本，避免上游更新引入不兼容变更
        echo '<link rel="stylesheet" href="' . $pluginUrl . '/VideoCollector/style.css" type="text/css" />' . "\n";
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/artplayer/5.4.0/files/dist/artplayer.js"></script>';
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/hls.js/1.7.1/files/dist/hls.min.js"></script>';
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/flv.js/1.6.2/files/dist/flv.min.js"></script>';

        // 主题未自带 NProgress 时才动态加载
        echo '<script type="text/javascript">(function(){if(typeof NProgress==="undefined"){'
            . 'var c=document.createElement("link");c.rel="stylesheet";c.href="https://registry.npmmirror.com/nprogress/0.2.0/files/nprogress.css";document.head.appendChild(c);'
            . 'var s=document.createElement("script");s.src="https://registry.npmmirror.com/nprogress/0.2.0/files/nprogress.js";document.head.appendChild(s);'
            . '}})();</script>';

        $jsFile = __DIR__ . '/script.js';
        $jsVersion = is_file($jsFile) ? filemtime($jsFile) : '1'; // filemtime 版本号自动破缓存
        echo '<script type="text/javascript" src="' . $pluginUrl . '/VideoCollector/script.js?v=' . $jsVersion . '"></script>';
    }

    public static function config(Form $form)
    {
        $apiUrl = new Text(
            'apiUrl',
            null,
            'https://caiji.xgzyapi.com/api.php/provide/vod/?ac=detail&wd=',
            _t('采集API地址'),
            _t('视频采集的API地址，wd=后面会自动追加搜索关键词')
        );
        $form->addInput($apiUrl);

        $playMode = new \Typecho\Widget\Helper\Form\Element\Radio(
            'playMode',
            array(
                'artplayer' => _t('ArtPlayer'),
                'iframe' => _t('Iframe')
            ),
            'artplayer',
            _t('视频播放方式'),
            _t('<p style="margin: 15px 0; padding: 10px; background: #f5c0c0ff; border: 1px solid #f59696ff; border-radius: 4px; color: #290404ff;">ArtPlayer模式是加载JSON中的URL键值进行播放（请确保解析地址返回的JSON中包含URL键值，且URL键值为视频地址）<br>Iframe模式是嵌入第三方播放器URL进行播放（请确保Iframe解析地址返回的URL是一个视频播放器页面）</p>')
        );
        $form->addInput($playMode);

        $videoParserUrl = new Text(
            'videoParserUrl',
            null,
            '',
            _t('Json解析地址'),
            _t('请输入视频解析地址前缀（可选），例如：json.php?url=')
        );
        $form->addInput($videoParserUrl);

        $iframeParserUrl = new Text(
            'iframeParserUrl',
            null,
            'https://free.maccms.xyz/?url=',
            _t('Iframe解析地址'),
            _t('请输入Iframe解析地址前缀（可选），例如：iframe.php?url=')
        );
        $form->addInput($iframeParserUrl);

        echo '<div style="margin: 15px 0; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">';
        echo '<p style="margin: 10px 0;">自定义短代码使用示例：</p>';
        echo '<ul style="margin: 10px 0; padding-left: 20px;">';
        echo '<li>基本用法：<br><code>[play]<br>视频URL1<br>视频URL2<br>[/play]</code></li>';
        echo '<li>带影片名/自定义分集标题（推荐使用 $ 分隔符）：<br><code>[play name="影片名"]<br>第1集$视频URL1<br>第2集$视频URL2<br>[/play]</code></li>';
        echo '<li>兼容旧格式（使用 | 分隔符）：<br><code>[play name="影片名"]<br>第1集|视频URL1<br>第2集|视频URL2<br>[/play]</code></li>';
        echo '<li>同一行内逗号分隔多个分集：<br><code>[play name="影片名"]<br>第1集$视频URL1,第2集$视频URL2,第3集$视频URL3<br>[/play]</code></li>';
        echo '<li>不使用解析地址：<br><code>[!play]<br>视频URL1<br>视频URL2<br>[/play]</code></li>';
        echo '</ul>';
        echo '<p>支持PJAX主题，（在PJAX加载完成后调用<code>initVideoCollectors();</code>即可）</p>';
        echo '</div>';
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function renderCollector()
    {
        echo <<<HTML
<section class="typecho-post-option">
    <label class="typecho-label">视频采集</label>
    <p>
        <input type="text" id="video-search-keyword" class="w-100 text" placeholder="输入搜索关键词" />
    </p>
    <p>
        <button type="button" id="btn-video-collect" class="btn btn-s">搜索视频</button>
    </p>
    <div id="video-search-results" style="max-height: 300px; overflow-y: auto; margin-top: 10px;"></div>
</section>
HTML;
    }

    public static function renderScript()
    {
        $options = Options::alloc();

        echo <<<SCRIPT
    <script>
    (function() {
        var searchBtn = document.getElementById('btn-video-collect');
        var searchInput = document.getElementById('video-search-keyword');
        var resultsDiv = document.getElementById('video-search-results');
        var currentKeyword = '';

        if (!searchBtn) return;

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        function search(keyword, page) {
            if (!keyword) return;
            page = page || 1;

            searchBtn.disabled = true;
            searchBtn.textContent = '搜索中...';
            resultsDiv.innerHTML = '<p>正在搜索...</p>';

            fetch('{$options->index}/action/video-collect?do=search&keyword=' + encodeURIComponent(keyword) + '&page=' + page)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    searchBtn.disabled = false;
                    searchBtn.textContent = '搜索视频';

                    if (data.code !== 1 || !data.list || data.list.length === 0) {
                        resultsDiv.innerHTML = '<p style="color: #999;">未找到相关视频</p>';
                        return;
                    }

                    var currentPage = parseInt(data.page) || 1;
                    var totalPages = parseInt(data.pagecount) || 1;

                    var html = '<ul style="list-style: none; padding: 0; margin: 0;">';
                    data.list.forEach(function(item, index) {
                        html += '<li style="padding: 8px; border-bottom: 1px solid #eee; cursor: pointer;" class="video-item" data-index="' + index + '">';
                        html += '<strong>' + escapeHtml(item.vod_name) + '</strong>';
                        if (item.vod_remarks) {
                            html += ' <span style="color: #999; font-size: 12px;">(' + escapeHtml(item.vod_remarks) + ')</span>';
                        }
                        html += '</li>';
                    });
                    html += '</ul>';

                    if (totalPages > 1) {
                        html += '<div class="video-pagination" style="margin-top: 15px; text-align: center;">';
                        if (currentPage > 1) {
                            html += '<button type="button" class="btn btn-xs page-prev" data-page="' + (currentPage - 1) + '" style="margin: 0 5px;">上一页</button>';
                        }
                        html += '<span style="margin: 0 10px;">第 ' + currentPage + ' / ' + totalPages + ' 页</span>';
                        if (currentPage < totalPages) {
                            html += '<button type="button" class="btn btn-xs page-next" data-page="' + (currentPage + 1) + '" style="margin: 0 5px;">下一页</button>';
                        }
                        html += '</div>';
                    }

                    resultsDiv.innerHTML = html;
                    resultsDiv.videoData = data.list;

                    resultsDiv.querySelectorAll('.video-item').forEach(function(item) {
                        item.addEventListener('click', function() {
                            showPlatformSelect(resultsDiv.videoData[parseInt(this.getAttribute('data-index'))]);
                        });
                        item.addEventListener('mouseover', function() { this.style.backgroundColor = '#f5f5f5'; });
                        item.addEventListener('mouseout', function() { this.style.backgroundColor = ''; });
                    });

                    resultsDiv.querySelectorAll('.page-prev, .page-next').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            search(currentKeyword, parseInt(this.getAttribute('data-page')));
                        });
                    });
                })
                .catch(function(err) {
                    searchBtn.disabled = false;
                    searchBtn.textContent = '搜索视频';
                    resultsDiv.innerHTML = '<p style="color: red;">搜索失败: ' + err.message + '</p>';
                });
        }

        searchBtn.addEventListener('click', function() {
            var keyword = searchInput.value.trim();
            if (!keyword) {
                alert('请输入搜索关键词');
                return;
            }
            currentKeyword = keyword;
            search(currentKeyword, 1);
        });

        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchBtn.click();
            }
        });

        function showPlatformSelect(video) {
            var playFrom = video.vod_play_from || '';
            var playUrl = video.vod_play_url || '';

            if (!playUrl) {
                alert('该视频没有播放地址');
                return;
            }

            var platforms = playFrom.split('\$\$\$');
            var urlGroups = playUrl.split('\$\$\$');

            if (platforms.length <= 1) {
                insertVideoToEditor(video, 0);
                return;
            }

            var html = '<div style="padding: 10px; background: #f9f9f9; border-radius: 4px;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: bold;">' + escapeHtml(video.vod_name) + ' - 选择平台:</p>';
            platforms.forEach(function(platform, index) {
                if (platform && urlGroups[index]) {
                    html += '<button type="button" class="btn btn-s platform-btn" data-index="' + index + '" style="margin: 2px;">' + escapeHtml(platform) + '</button>';
                }
            });
            html += '</div>';

            resultsDiv.innerHTML = html;
            resultsDiv.currentVideo = video;

            resultsDiv.querySelectorAll('.platform-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    insertVideoToEditor(resultsDiv.currentVideo, parseInt(this.getAttribute('data-index')));
                });
            });
        }

        function insertVideoToEditor(video, platformIndex) {
            var playUrl = video.vod_play_url || '';
            if (!playUrl) {
                alert('该视频没有播放地址');
                return;
            }

            var urlGroups = playUrl.split('\$\$\$');
            var selectedGroup = urlGroups[platformIndex || 0] || urlGroups[0];

            // 播放地址格式: 第1集\$url1#第2集\$url2
            var lines = [];
            selectedGroup.split('#').forEach(function(ep) {
                var parts = ep.split('\$');
                if (parts.length >= 2) {
                    var title = parts[0];
                    var url = parts[parts.length - 1];
                    if (url && url.indexOf('http') === 0) {
                        lines.push(title + '\$' + url);
                    }
                }
            });

            if (lines.length === 0) {
                alert('未能解析出有效的播放地址');
                return;
            }

            var newline = String.fromCharCode(10);
            var content = '[play]' + newline + lines.join(newline) + newline + '[/play]';
            content += newline + newline + '## 简介' + newline + newline + (video.vod_content || '');

            var textarea = document.getElementById('text');
            if (textarea) {
                var start = textarea.selectionStart;
                var end = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, start) + content + textarea.value.substring(end);
                var newPos = start + content.length;
                textarea.setSelectionRange(newPos, newPos);
                textarea.focus();
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }

            var titleInput = document.getElementById('title');
            if (titleInput && !titleInput.value) {
                titleInput.value = video.vod_name || '';
            }

            alert('已插入 ' + lines.length + ' 个播放地址');
        }
    })();
    </script>
SCRIPT;
    }
}
