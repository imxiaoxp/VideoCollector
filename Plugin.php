<?php

namespace TypechoPlugin\VideoCollector;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Plugin as Typecho_Plugin;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 视频采集 - 从JSON API采集视频并插入编辑器
 *
 * @package 官方视频采集
 * @author xiao
 * @version 1.0.4
 * @link https://ma.us.ci
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('admin/write-post.php')->option = __CLASS__ . '::renderCollector';
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = __CLASS__ . '::renderScript';
        \Typecho\Plugin::factory('admin/write-page.php')->option = __CLASS__ . '::renderCollector';
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = __CLASS__ . '::renderScript';
        
        // 注册内容过滤钩子，用于处理[play]短代码
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->content = array(__CLASS__, 'parseContent');
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerpt = array(__CLASS__, 'parseExcerpt');
        
        // 注册header钩子，用于输出CSS样式和JS库
        \Typecho\Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'outputHeader');
        
        Helper::addAction('video-collect', 'TypechoPlugin\VideoCollector\Action');
        
        return _t('VideoCollector 插件已激活，现在可以使用 [play][/play] 短代码嵌入视频。');
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        Helper::removeAction('video-collect');
        return _t('插件已禁用');
    }
    
    /**
     * 短代码处理函数
     *
     * @access private
     * @param string $content 原始内容
     * @return string 处理后的内容
     */
    private static function parseShortCode(string $content): string
    {
        // 定义play短代码正则表达式，支持多个视频和分集标识
        $playPattern = '/\[(!?play)\s*(?:id="([^"]*)")?\s*(?:name="([^"]*)")?\s*\](.*?)\[\/play\]/s';
        
        // 处理play短代码，支持多个视频
        $content = preg_replace_callback($playPattern, array(__CLASS__, 'parsePlayShortCode'), $content);
        
        return $content;
    }
    
    /**
     * 单个play短代码处理函数
     *
     * @access private
     * @param array $matches 匹配结果
     * @return string 处理后的HTML
     */
    private static function parsePlayShortCode(array $matches): string
    {
        $shortCodeType = $matches[1]; // 可能是 "play" 或 "!play"
        $id = $matches[2] ? $matches[2] : 'play_' . uniqid();
        $name = $matches[3] ? $matches[3] : '';
        $content = trim($matches[4]);
        
        // 解析视频数据，支持自定义分集标题
        $videos = array();
        $lines = preg_split('/[\r\n]+/', $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // 支持两种格式：
            // 1. URL（旧格式，兼容）
            // 2. 标题|URL（新格式，支持自定义标题）
            if (strpos($line, '|') !== false) {
                list($title, $url) = explode('|', $line, 2);
                $title = trim($title);
                $url = trim($url);
            } else {
                $title = '';
                $url = trim($line);
            }
            
            if (!empty($url)) {
                $videos[] = array('title' => $title, 'url' => $url);
            }
        }
        
        if (empty($videos)) {
            return '';
        }
        
        // 获取配置的解析地址和播放方式（如果有）
        $options = Helper::options();
        $parserUrl = $options->plugin('VideoCollector')->videoParserUrl ?? '';
        $iframeParserUrl = $options->plugin('VideoCollector')->iframeParserUrl ?? '';
        $playMode = $options->plugin('VideoCollector')->playMode ?? 'iframe';
        
        // 如果是 [!play] 格式，不使用解析地址
        $useParserUrl = substr($shortCodeType, 0, 1) !== '!';
        
        // 生成视频容器HTML
        $html = '<div class="play-container" id="' . $id . '" data-use-parser-url="' . ($useParserUrl ? 'true' : 'false') . '" data-type="play">';
        if (!empty($name)) {
            $html .= '<div class="play-title">' . $name . '</div>';
        }
        
        // 生成视频播放器
        $html .= '<div class="play-player-wrapper">';
        
        // 生成视频URL和标题数据
        $videoUrls = array();
        $videoTitles = array();
        foreach ($videos as $video) {
            $videoUrls[] = $video['url'];
            $videoTitles[] = $video['title'];
        }
        
        if ($playMode === 'iframe') {
            // 生成iframe播放器
            $firstUrl = $videoUrls[0];
            if ($useParserUrl && !empty($iframeParserUrl)) {
                $firstUrl = $iframeParserUrl . urlencode($firstUrl);
            }
            $html .= '<iframe id="artplayer-' . $id . '" class="artplayer-iframe" frameborder="0" allowfullscreen allow="autoplay; fullscreen" src="' . htmlspecialchars($firstUrl) . '" style="width: 100%; height: 100%;"></iframe>';
            $html .= '<style>.play-player-wrapper { position: relative; width: 100%; padding-top: 56.25%; } .artplayer-iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }</style>';
        } else {
            // 生成artplayer播放器
            $html .= '<div id="artplayer-' . $id . '" class="artplayer"></div>';
            $html .= '<input type="hidden" class="video-urls" value="' . htmlspecialchars(implode(',', $videoUrls)) . '" />';
            $html .= '<input type="hidden" class="video-titles" value="' . htmlspecialchars(implode('|', $videoTitles)) . '" />';
            $html .= '<input type="hidden" class="video-parser-url" value="' . htmlspecialchars($parserUrl) . '" />';
            $html .= '<input type="hidden" class="video-use-parser" value="' . ($useParserUrl ? 'true' : 'false') . '" />';
        }
        
        $html .= '</div>';
        
        // 为iframe方式添加视频切换数据
        if ($playMode === 'iframe' && count($videos) > 1) {
            $html .= '<input type="hidden" class="video-urls" value="' . htmlspecialchars(implode(',', $videoUrls)) . '" />';
            $html .= '<input type="hidden" class="video-titles" value="' . htmlspecialchars(implode('|', $videoTitles)) . '" />';
            $html .= '<input type="hidden" class="video-parser-url" value="' . htmlspecialchars($iframeParserUrl) . '" />';
            $html .= '<input type="hidden" class="video-use-parser" value="' . ($useParserUrl ? 'true' : 'false') . '" />';
        }
        
        // 生成视频切换菜单，放在播放器下面
        if (count($videos) > 1) {
            $html .= '<div class="video-tabs">';
            foreach ($videos as $index => $video) {
                $tabClass = $index == 0 ? 'active' : '';
                // 如果有自定义标题，使用自定义标题，否则使用默认标题
                $tabTitle = !empty($video['title']) ? $video['title'] : '第' . ($index + 1) . '集';
                $html .= '<span class="video-tab ' . $tabClass . '" data-index="' . $index . '" data-container="' . $id . '">' . $tabTitle . '</span>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * 处理文章内容
     *
     * @access public
     * @param string $content 文章内容
     * @param Widget_Abstract_Contents $widget 内容对象
     * @param string|null $lastResult 上一个插件处理结果
     * @return string 处理后的内容
     */
    public static function parseContent(string $content, $widget, ?string $lastResult): string
    {
        $content = $lastResult ? $lastResult : $content;
        $content = self::parseShortCode($content);
        if ($widget->isMarkdown) {
            return \Utils\Markdown::convert($content);
        }
        
        static $parser;
        if (empty($parser)) {
            $parser = new \Utils\AutoP();
        }
        return $parser->parse($content);
    }

    /**
     * 处理文章摘要
     *
     * @access public
     * @param string $excerpt 文章摘要
     * @param Widget_Abstract_Contents $widget 内容对象
     * @param string|null $lastResult 上一个插件处理结果
     * @return string 处理后的摘要
     */
    public static function parseExcerpt(string $excerpt, $widget, ?string $lastResult): string
    {
        $excerpt = $lastResult ? $lastResult : $excerpt;
        $excerpt = self::parseShortCode($excerpt);
        if ($widget->isMarkdown) {
            return \Utils\Markdown::convert($excerpt);
        }
        
        static $parser;
        if (empty($parser)) {
            $parser = new \Utils\AutoP();
        }
        return $parser->parse($excerpt);
    }
    
    /**
     * 输出插件CSS样式和JavaScript代码
     *
     * @access public
     * @return void
     */
    public static function outputHeader(): void
    {
        // 获取插件目录URL
        $pluginUrl = Helper::options()->pluginUrl;
        $cssUrl = $pluginUrl . '/VideoCollector/style.css';
        $jsUrl = $pluginUrl . '/VideoCollector/script.js';
        
        // 输出CSS链接
        echo '<link rel="stylesheet" href="' . $cssUrl . '" type="text/css" />';
        
        // 输出JavaScript库 - Artplayer相关库
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/artplayer/latest/files/dist/artplayer.js"></script>';
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/hls.js/latest/files/dist/hls.min.js"></script>';
        echo '<script type="text/javascript" src="https://registry.npmmirror.com/flv.js/latest/files/dist/flv.min.js"></script>';
        
        // 输出播放器初始化脚本
        echo '<script type="text/javascript" src="' . $jsUrl . '"></script>';
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form)
    {
        $apiUrl = new Text(
            'apiUrl',
            null,
            'https://www.caiji.cyou/api.php/provide/vod/?ac=detail&wd=',
            _t('采集API地址'),
            _t('视频采集的API地址，wd=后面会自动追加搜索关键词')
        );
        $form->addInput($apiUrl);
        
        // 播放方式选项
        $playMode = new \Typecho\Widget\Helper\Form\Element\Radio(
            'playMode',
            array(
                'artplayer' => _t('ArtPlayer'),
                'iframe' => _t('Iframe')
            ),
            'iframe',
            _t('视频播放方式'),
            _t('<p style="margin: 15px 0; padding: 10px; background: #f5c0c0ff; border: 1px solid #f59696ff; border-radius: 4px; color: #290404ff;">ArtPlayer模式是加载JSON中的URL键值进行播放（请确保解析地址返回的JSON中包含URL键值，且URL键值为视频地址）<br>Iframe模式是嵌入第三方播放器URL进行播放（请确保Iframe解析地址返回的URL是一个视频播放器页面）</p>')
        );
        $form->addInput($playMode);
        
        $videoParserUrl = new Text(
            'videoParserUrl',
            null,
            '',
            _t('视频解析地址'),
            _t('请输入视频解析地址前缀（可选），例如：v.php?url=')
        );
        $form->addInput($videoParserUrl);
        
        // iframe解析地址
        $iframeParserUrl = new Text(
            'iframeParserUrl',
            null,
            'https://jiexi.789jiexi.com/?url=',
            _t('Iframe解析地址'),
            _t('请输入Iframe解析地址前缀（可选），例如：iframe.php?url=')
        );
        $form->addInput($iframeParserUrl);
        
        // 使用说明
        echo '<div style="margin: 15px 0; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">';
        echo '<p style="margin: 10px 0;">自定义短代码使用示例：</p>';
        echo '<ul style="margin: 10px 0; padding-left: 20px;">';
        echo '<li>基本用法：<br><code>[play]<br>视频URL1<br>视频URL2<br>[/play]</code></li>';
        echo '<li>带影片名/自定义分集标题（可选）：<br><code>[play name="影片名"]<br>第1集|视频URL1<br>第2集|视频URL2<br>[/play]</code></li>';
        echo '<li>不使用解析地址：<br><code>[!play]<br>视频URL1<br>视频URL2<br>[/play]</code></li>';
        echo '</ul>';
        echo '<p>支持PJAX主题，（在PJAX加载完成后调用<code>initVideoCollectors();</code>即可）</p>';
        echo '</div>';

    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 渲染采集器UI
     */
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

    /**
     * 渲染JavaScript
     */
    public static function renderScript()
    {
        $options = Options::alloc();
        $apiUrl = $options->plugin('VideoCollector')->apiUrl;
        $securityToken = $options->security;
        
        echo <<<SCRIPT
<script>
(function() {
    var apiUrl = '{$apiUrl}';
    var searchBtn = document.getElementById('btn-video-collect');
    var searchInput = document.getElementById('video-search-keyword');
    var resultsDiv = document.getElementById('video-search-results');
    
    if (!searchBtn) return;
    
    searchBtn.addEventListener('click', function() {
        var keyword = searchInput.value.trim();
        if (!keyword) {
            alert('请输入搜索关键词');
            return;
        }
        
        searchBtn.disabled = true;
        searchBtn.textContent = '搜索中...';
        resultsDiv.innerHTML = '<p>正在搜索...</p>';
        
        // 使用PHP代理请求API
        fetch('{$options->index}/action/video-collect?do=search&keyword=' + encodeURIComponent(keyword))
            .then(function(response) { return response.json(); })
            .then(function(data) {
                searchBtn.disabled = false;
                searchBtn.textContent = '搜索视频';
                
                if (data.code !== 1 || !data.list || data.list.length === 0) {
                    resultsDiv.innerHTML = '<p style="color: #999;">未找到相关视频</p>';
                    return;
                }
                
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
                resultsDiv.innerHTML = html;
                
                // 存储数据供点击使用
                resultsDiv.videoData = data.list;
                
                // 绑定点击事件
                var items = resultsDiv.querySelectorAll('.video-item');
                items.forEach(function(item) {
                    item.addEventListener('click', function() {
                        var idx = parseInt(this.getAttribute('data-index'));
                        var video = resultsDiv.videoData[idx];
                        showPlatformSelect(video);
                    });
                    item.addEventListener('mouseover', function() {
                        this.style.backgroundColor = '#f5f5f5';
                    });
                    item.addEventListener('mouseout', function() {
                        this.style.backgroundColor = '';
                    });
                });
            })
            .catch(function(err) {
                searchBtn.disabled = false;
                searchBtn.textContent = '搜索视频';
                resultsDiv.innerHTML = '<p style="color: red;">搜索失败: ' + err.message + '</p>';
            });
    });
    
    // 回车搜索
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchBtn.click();
        }
    });
    
    // 显示平台选择
    function showPlatformSelect(video) {
        var playFrom = video.vod_play_from || '';
        var playUrl = video.vod_play_url || '';
        
        if (!playUrl) {
            alert('该视频没有播放地址');
            return;
        }
        
        var platforms = playFrom.split('$$$');
        var urlGroups = playUrl.split('$$$');
        
        if (platforms.length <= 1) {
            // 只有一个平台，直接插入
            insertVideoToEditor(video, 0);
            return;
        }
        
        // 多个平台，显示选择
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
        
        // 绑定平台按钮点击事件
        var platformBtns = resultsDiv.querySelectorAll('.platform-btn');
        platformBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var platformIndex = parseInt(this.getAttribute('data-index'));
                insertVideoToEditor(resultsDiv.currentVideo, platformIndex);
            });
        });
    }
    
    function insertVideoToEditor(video, platformIndex) {
        var playUrl = video.vod_play_url || '';
        if (!playUrl) {
            alert('该视频没有播放地址');
            return;
        }
        
        var urlGroups = playUrl.split('$$$');
        var selectedGroup = urlGroups[platformIndex || 0] || urlGroups[0];
        
        // 解析播放地址，格式: 第1集\$url1#第2集\$url2
        var lines = [];
        var episodes = selectedGroup.split('#');
        
        episodes.forEach(function(ep) {
            var parts = ep.split('$');
            if (parts.length >= 2) {
                var title = parts[0];
                var url = parts[parts.length - 1];
                if (url && url.indexOf('http') === 0) {
                    lines.push(title + '|' + url);
                }
            }
        });
        
        if (lines.length === 0) {
            alert('未能解析出有效的播放地址');
            return;
        }
        
        var newline = String.fromCharCode(10);
        var vodContent = video.vod_content || '';
        var content = '[play]' + newline + lines.join(newline) + newline + '[/play]';
        content += newline + newline + '## 简介' + newline + newline + vodContent;
        var textarea = document.getElementById('text');
        
        if (textarea) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var text = textarea.value;
            
            // 在光标位置插入内容
            textarea.value = text.substring(0, start) + content + text.substring(end);
            
            // 设置光标位置
            var newPos = start + content.length;
            textarea.setSelectionRange(newPos, newPos);
            textarea.focus();
            
            // 触发change事件
            var event = new Event('input', { bubbles: true });
            textarea.dispatchEvent(event);
        }
        
        // 同时填充标题
        var titleInput = document.getElementById('title');
        if (titleInput && !titleInput.value) {
            titleInput.value = video.vod_name || '';
        }
        
        alert('已插入 ' + lines.length + ' 个播放地址');
    }
    
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
})();
</script>
SCRIPT;
    }
}
