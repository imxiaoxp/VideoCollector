// VideoCollector 前端脚本
// 主题约定：PJAX 加载完成后调用 initVideoCollectors()

// 活动播放器注册表：PJAX 切换前统一销毁，防止旧实例及 hls/flv 解码器泄漏
var vcActivePlayers = [];
var vcJqueryPjaxBound = false;
var vcNativePjaxBound = false;

document.addEventListener('DOMContentLoaded', function() {
    bindVideoCollectorPjax(); // 主题的 jQuery 可能晚于本脚本加载，这里补绑
    initVideoTabs();
    initPlayPlayers();
});

// PJAX 完成后由主题回调（瀑布流追加时也可调用）
function initVideoCollectors() {
    initVideoTabs();
    initPlayPlayers();
}

function registerVideoCollectorPlayer(container, art) {
    vcActivePlayers.push({ container: container, art: art });
    art.on('destroy', function() {
        for (var i = 0; i < vcActivePlayers.length; i++) {
            if (vcActivePlayers[i].art === art) {
                vcActivePlayers.splice(i, 1);
                break;
            }
        }
    });
}

function destroyAllVideoCollectors() {
    for (var i = vcActivePlayers.length - 1; i >= 0; i--) {
        var item = vcActivePlayers[i];
        try { destroyArtPlayerMediaInstances(item.art); } catch (e) {}
        try { item.art.destroy(true); } catch (e) {}
        try { item.container.artPlayer = null; } catch (e) {}
    }
    vcActivePlayers.length = 0;
}

// 仅当 PJAX 替换区域包含播放器时才销毁；评论区的局部刷新不应打断正在播放的视频
function vcPjaxSwapsPlayers(options) {
    if (!options || !options.container || !window.jQuery) {
        return true;
    }
    try {
        return window.jQuery(options.container).find('.play-container').length > 0;
    } catch (e) {
        return true;
    }
}

function vcInitAfterPjax() {
    // 延迟到同批事件处理器执行完再初始化，避免与主题自身的 DOM 调整竞争
    setTimeout(initVideoCollectors, 0);
}

function bindVideoCollectorPjax() {
    // jquery-pjax：beforeReplace 时旧播放器仍在 DOM，是销毁的最佳时机
    if (window.jQuery && !vcJqueryPjaxBound) {
        vcJqueryPjaxBound = true;
        window.jQuery(document).on('pjax:beforeReplace', function(e, xhr, options) {
            if (vcPjaxSwapsPlayers(options)) {
                destroyAllVideoCollectors();
            }
        });
        window.jQuery(document).on('pjax:complete', vcInitAfterPjax);
    }
    // 原生事件（MoOx/pjax 等通过 document.dispatchEvent 派发的库）
    if (!vcNativePjaxBound) {
        vcNativePjaxBound = true;
        document.addEventListener('pjax:before-swap', destroyAllVideoCollectors);
        document.addEventListener('pjax:send', destroyAllVideoCollectors);
        document.addEventListener('pjax:complete', vcInitAfterPjax);
    }
}
bindVideoCollectorPjax();

function vcNProgress(method) {
    if (typeof NProgress !== 'undefined') {
        NProgress[method]();
    }
}

function vcSafeCall(obj, method) {
    try {
        if (obj && obj[method]) {
            obj[method]();
        }
    } catch (e) { /* ignore */ }
}

function initVideoTabs() {
    document.querySelectorAll('.play-container').forEach(initVideoContainer);
}

function initPlayPlayers() {
    document.querySelectorAll('.play-container').forEach(function(container) {
        if (!container.querySelector('.artplayer-iframe')) {
            // 全局懒加载：进入视口才初始化，避免一页多个播放器同时拉流缓冲
            lazyInitArtPlayer(container);
        }
    });
}

var vcLazyObserver = null;

function lazyInitArtPlayer(container) {
    if (container.dataset.videoLazyBound === 'true') {
        return;
    }
    container.dataset.videoLazyBound = 'true';

    if (!('IntersectionObserver' in window)) {
        initializeArtPlayer(container);
        return;
    }

    if (!vcLazyObserver) {
        vcLazyObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    vcLazyObserver.unobserve(entry.target);
                    initializeArtPlayer(entry.target);
                }
            });
        }, { rootMargin: '200px' });
    }
    vcLazyObserver.observe(container);
}

// 绝不能命名为 decodeURIComponent——会覆盖原生全局函数，影响页面所有脚本的解码路径
function decodeHtmlEntities(str) {
    var textarea = document.createElement('textarea');
    textarea.innerHTML = str;
    return textarea.value;
}

// 分集数据用 \n 分隔（PHP 端 implode("\n")），避免误拆 URL 中的逗号
function parseVideoList(input) {
    return input
        ? decodeHtmlEntities(input.value).split('\n').map(function(s) { return s.trim(); }).filter(function(s) { return s !== ''; })
        : [];
}

function initVideoContainer(container) {
    if (container.dataset.videoInitialized === 'true') {
        return;
    }
    container.dataset.videoInitialized = 'true';

    var tabs = container.querySelectorAll('.video-tab');
    tabs.forEach(function(tab, index) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            switchVideo(container, index);
        });
        // 标题溢出时，悬停滚动显示完整标题
        setupTabTitleScroll(tab);
    });
}

/**
 * 分集标题超出按钮宽度时，鼠标悬停滚动显示完整标题
 * 依赖 PHP 端生成的 .video-tab-text 内层元素作为滚动目标
 * @param {HTMLElement} tab 分集按钮元素
 */
function setupTabTitleScroll(tab) {
    var textEl = tab.querySelector('.video-tab-text');
    if (!textEl) {
        return;
    }

    // 触屏设备没有悬停概念，不绑定（避免点按后动画残留）
    if (window.matchMedia && !window.matchMedia('(hover: hover)').matches) {
        return;
    }

    tab.addEventListener('mouseenter', function() {
        // 计算按钮内容区可用宽度与标题实际宽度
        var style = window.getComputedStyle(tab);
        var available = tab.clientWidth
            - parseFloat(style.paddingLeft)
            - parseFloat(style.paddingRight);
        var overflow = textEl.getBoundingClientRect().width - available;

        // 未溢出（或差距在2像素以内）时不滚动，保持居中省略号样式
        if (overflow <= 2) {
            tab.classList.remove('is-scrolling');
            return;
        }

        // 设置滚动距离与时长（约20px/秒的阅读速度，单个来回3~15秒）
        tab.style.setProperty('--tab-scroll-x', -overflow + 'px');
        var duration = Math.min(15, Math.max(3, overflow / 20 + 2));
        tab.style.setProperty('--tab-scroll-duration', duration + 's');
        tab.classList.add('is-scrolling');
    });

    tab.addEventListener('mouseleave', function() {
        // 移除后动画立即复位，恢复居中+省略号
        tab.classList.remove('is-scrolling');
    });
}

function switchVideo(container, index) {
    vcNProgress('start');

    var urls = parseVideoList(container.querySelector('.video-urls'));
    var titlesInput = container.querySelector('.video-titles');
    var titles = titlesInput ? decodeHtmlEntities(titlesInput.value).split('\n').map(function(t) { return t.trim(); }) : [];

    var tabs = container.querySelectorAll('.video-tab');
    if (index < 0 || index >= urls.length) {
        vcNProgress('done');
        return;
    }

    tabs.forEach(function(tab, i) {
        tab.classList.toggle('active', i === index);
    });

    // 浏览器标签标题：分集标题 + 原始页面标题
    var currentTitle = titles[index] || ('第' + (index + 1) + '集');
    if (!container.originalTitle) {
        container.originalTitle = document.title;
    }
    document.title = currentTitle + ' - ' + container.originalTitle;

    var parserUrlInput = container.querySelector('.video-parser-url');
    var useParserInput = container.querySelector('.video-use-parser');
    var parserUrl = parserUrlInput ? parserUrlInput.value : '';
    var useParser = useParserInput ? (useParserInput.value === 'true') : true;

    if (container.querySelector('.artplayer-iframe')) {
        switchIframeVideo(container, index, urls, parserUrl, useParser);
    } else {
        switchPlayVideo(container, index, urls, parserUrl, useParser);
    }
}

function switchIframeVideo(container, index, urls, parserUrl, useParser) {
    var iframeElement = container.querySelector('.artplayer-iframe');
    var videoUrl = urls[index];
    if (!iframeElement || !videoUrl) {
        vcNProgress('done');
        return;
    }

    var finalUrl = (useParser && parserUrl) ? parserUrl + encodeURIComponent(videoUrl) : videoUrl;

    // 防抖：快速连续切换只加载最后点击的分集，避免多个重型解析页同时加载
    if (container.__iframeSwitchTimer) {
        clearTimeout(container.__iframeSwitchTimer);
    }
    container.__iframeSwitchTimer = setTimeout(function() {
        // 关键：销毁旧 iframe 并重建而非改 src——改 src 时旧页面内存回收是异步的，
        // 快速切换多集内存叠加会崩溃；销毁元素可立即触发旧文档卸载
        var newFrame = iframeElement.cloneNode(false);
        newFrame.onload = function() { vcNProgress('done'); };
        newFrame.src = finalUrl;
        if (iframeElement.parentNode) {
            iframeElement.parentNode.replaceChild(newFrame, iframeElement);
        }
    }, 250);

    container.currentVideoIndex = index;
}

function switchPlayVideo(container, index, urls, parserUrl, useParser) {
    var artPlayer = container.artPlayer;
    var videoUrl = urls[index];
    if (!artPlayer || !videoUrl) {
        vcNProgress('done');
        return;
    }

    // 切换前销毁旧 hls/flv 解码器，避免新旧实例抢占同一 <video>（表现为"切换仍播第一集"）
    destroyArtPlayerMediaInstances(artPlayer);

    getParsedVideoUrlAsync(videoUrl, parserUrl, useParser)
        .then(function(finalUrl) {
            return getVideoTypeAsync(finalUrl).then(function(videoType) {
                artPlayer.switchUrl(finalUrl, videoType);
                artPlayer.play();
                vcNProgress('done');
                container.currentVideoIndex = index;

                // 最后一集时隐藏「下一集」按钮及其容器
                setTimeout(function() {
                    var nextButton = container.querySelector('.art-icon-next');
                    if (!nextButton) return;
                    var last = index >= urls.length - 1;
                    nextButton.style.display = last ? 'none' : 'flex';
                    if (nextButton.parentElement) {
                        nextButton.parentElement.style.display = last ? 'none' : 'flex';
                    }
                }, 100);
            });
        })
        .catch(function(error) {
            console.error('Error getting parsed video URL:', error);
            vcNProgress('done');
        });
}

// 销毁 ArtPlayer 挂载的 hls/flv 实例并解除与 <video> 的绑定，让新源干净接管
function destroyArtPlayerMediaInstances(artPlayer) {
    if (!artPlayer) return;
    if (artPlayer.hls) {
        vcSafeCall(artPlayer.hls, 'stopLoad');
        vcSafeCall(artPlayer.hls, 'detachMedia');
        vcSafeCall(artPlayer.hls, 'destroy');
        delete artPlayer.hls;
    }
    if (artPlayer.flvPlayer) {
        vcSafeCall(artPlayer.flvPlayer, 'unload');
        vcSafeCall(artPlayer.flvPlayer, 'detachMediaElement');
        vcSafeCall(artPlayer.flvPlayer, 'destroy');
        delete artPlayer.flvPlayer;
    }
    var videoEl = artPlayer.template && artPlayer.template.$video;
    if (videoEl) {
        vcSafeCall(videoEl, 'pause');
        try { videoEl.removeAttribute('src'); } catch (e) { /* ignore */ }
        vcSafeCall(videoEl, 'load');
    }
}

// 播放器宽度小于 438px 时隐藏数字时间（当前时间/总时长）
function updateArtTimeVisibility(art) {
    if (!art || !art.template || !art.template.$player) {
        return;
    }
    var w = (typeof art.width === 'number' && art.width > 0)
        ? art.width
        : (art.template.$player.clientWidth || 0);
    art.template.$player.classList.toggle('vc-hide-time', w > 0 && w < 438);
}

function initializeArtPlayer(container) {
    var id = container.id;
    var artPlayerId = 'artplayer-' + id;
    var artPlayerContainer = document.getElementById(artPlayerId);

    if (!artPlayerContainer) {
        console.error('ArtPlayer容器未找到: ' + artPlayerId);
        return;
    }
    if (container.artPlayer) {
        return;
    }

    var videoUrlsInput = container.querySelector('.video-urls');
    if (!videoUrlsInput) {
        console.error('视频URL数据未找到: ' + id);
        return;
    }

    var videoUrls = parseVideoList(videoUrlsInput);
    if (videoUrls.length === 0) {
        return;
    }

    var parserUrlInput = container.querySelector('.video-parser-url');
    var useParserInput = container.querySelector('.video-use-parser');
    var parserUrl = parserUrlInput ? parserUrlInput.value : '';
    var useParser = useParserInput ? (useParserInput.value === 'true') : true;

    var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

    getParsedVideoUrlAsync(videoUrls[0], parserUrl, useParser)
        .then(function(firstVideoUrl) {
            return getVideoTypeAsync(firstVideoUrl).then(function(videoType) {
                return { url: firstVideoUrl, type: videoType };
            });
        })
        .then(function(result) {
            if (container.artPlayer) {
                return; // 异步期间已被并发创建
            }

            var controlsConfig = [];
            if (videoUrls.length > 1) {
                controlsConfig.push({
                    position: 'left',
                    index: 11,
                    html: '<i class="art-icon art-icon-next hint--rounded hint--top" aria-label="下一集" style="display: flex;"><svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z" fill="currentColor"></path></svg></i>',
                    click: function() {
                        var nextIndex = (container.currentVideoIndex || 0) + 1;
                        if (nextIndex < container.videoUrls.length) {
                            switchVideo(container, nextIndex);
                        }
                    }
                });
            }

            var art = new Artplayer({
                container: '#' + artPlayerId,
                url: result.url,
                type: result.type,
                autoplay: false, // 进入页面一律不自动播放，避免多播放器同时出声；由用户手动播放
                autoSize: false,
                playbackRate: true,
                fastForward: true,
                setting: true,
                pip: !isMobile,
                fullscreen: true,
                fullscreenWeb: !isMobile,
                playsInline: true,
                autoPlayback: true,
                theme: '#23ade5',
                lang: navigator.language.toLowerCase(),
                mutex: true,
                controls: controlsConfig,
                customType: {
                    m3u8: function (video, url, art) {
                        // 创建新 Hls 前销毁旧实例，避免两实例同时 attachMedia 到同一 video
                        if (art.hls) {
                            vcSafeCall(art.hls, 'stopLoad');
                            vcSafeCall(art.hls, 'detachMedia');
                            vcSafeCall(art.hls, 'destroy');
                            delete art.hls;
                        }
                        if (window.Hls && Hls.isSupported()) {
                            var hls = new Hls({
                                maxBufferLength: 300,
                                maxMaxBufferLength: 600,
                            });
                            hls.loadSource(url);
                            hls.attachMedia(video);
                            art.hls = hls;
                        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                            video.src = url;
                        } else {
                            console.error('当前浏览器不支持 HLS 播放');
                        }
                    },
                    flv: function (video, url, art) {
                        if (art.flvPlayer) {
                            vcSafeCall(art.flvPlayer, 'unload');
                            vcSafeCall(art.flvPlayer, 'detachMediaElement');
                            vcSafeCall(art.flvPlayer, 'destroy');
                            delete art.flvPlayer;
                        }
                        if (window.flvjs && flvjs.isSupported()) {
                            var flvPlayer = flvjs.createPlayer({
                                type: 'flv',
                                url: url,
                                isLive: false,
                                enableWorker: true,
                            });
                            flvPlayer.attachMediaElement(video);
                            flvPlayer.load();
                            art.flvPlayer = flvPlayer;
                        } else {
                            console.error('当前浏览器不支持 FLV 播放');
                        }
                    },
                    mp4: function (video, url) {
                        video.src = url;
                    }
                },
                destroy: function () {
                    destroyArtPlayerMediaInstances(this);
                }
            });

            container.artPlayer = art;
            registerVideoCollectorPlayer(container, art);
            container.videoUrls = videoUrls;
            container.currentVideoIndex = 0;

            updateArtTimeVisibility(art);
            art.on('resize', function() {
                updateArtTimeVisibility(art);
            });
        })
        .catch(function(error) {
            console.error('Error getting parsed video URL:', error);
        });
}

async function getParsedVideoUrlAsync(originalUrl, parserUrl, useParser) {
    if (useParser && parserUrl) {
        try {
            const response = await fetch(parserUrl + encodeURIComponent(originalUrl));
            const data = await response.json();
            return data.url || originalUrl;
        } catch (error) {
            console.error('解析视频URL失败:', error);
            return originalUrl;
        }
    }
    return originalUrl;
}

// 仅同源 URL 做 HEAD 探测；跨域探测必被 CORS 拦截（如 302 不带 ACAO）且
// hls/flv 的 XHR 同样不可用，直接走 getVideoType → 原生 video.src (no-cors)
async function getVideoTypeAsync(url) {
    var sameOrigin = false;
    try {
        sameOrigin = new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) { /* URL 无法解析时按跨域处理 */ }
    if (sameOrigin) {
        try {
            const response = await fetch(url, { method: 'HEAD', redirect: 'follow' });
            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('mpegurl') !== -1) {
                return 'm3u8';
            }
            if (contentType.indexOf('flv') !== -1) {
                return 'flv';
            }
            if (contentType.indexOf('video/mp4') !== -1 || contentType.indexOf('video/mpeg') !== -1) {
                return 'mp4';
            }
            // Content-Type 不明确时按重定向后的最终 URL 后缀判断
            url = response.url;
        } catch (error) {
            console.warn('HEAD请求获取Content-Type失败，使用URL后缀检测:', error);
        }
    }
    return getVideoType(url);
}

function getVideoType(url) {
    var urlLower = url.toLowerCase();
    if (urlLower.includes('.m3u8') || urlLower.includes('hls') || urlLower.includes('playlist')) {
        return 'm3u8';
    } else if (urlLower.endsWith('.flv')) {
        return 'flv';
    } else if (urlLower.endsWith('.mp4')) {
        return 'mp4';
    } else {
        // 无扩展名 URL 多因 HEAD 探测失败（伴随 CORS 限制），hls/flv 的 XHR 同样不可用，
        // 唯一可行路径是原生 video.src；误判 m3u8 必然加载失败
        return 'mp4';
    }
}

// ArtPlayer 无 switchUrl 时补充（切换前销毁旧解码器再换源）
if (typeof Artplayer !== 'undefined' && typeof Artplayer.prototype.switchUrl === 'undefined') {
    Artplayer.prototype.switchUrl = function(url, type) {
        destroyArtPlayerMediaInstances(this);
        this.url = url;
        this.type = type;
        this.load();
        return this;
    };
}
