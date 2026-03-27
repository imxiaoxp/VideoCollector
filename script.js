// VideoCollector 插件 JavaScript 代码

// 页面加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
    // 初始化视频切换功能
    initVideoTabs();
    // 初始化Play播放器
    initPlayPlayers();
});

// PJAX重载功能 （在PJAX加载完成后调用initVideoCollectors();）
function initVideoCollectors() {
    // 初始化视频切换功能
    initVideoTabs();
    // 初始化Play播放器
    initPlayPlayers();
}

/**
 * 初始化视频切换功能
 */
function initVideoTabs() {
    // 获取所有视频容器，只处理play-container
    var videoContainers = document.querySelectorAll('.play-container');
    
    // 使用传统的for循环替代forEach，确保兼容性
    for (var i = 0; i < videoContainers.length; i++) {
        var container = videoContainers[i];
        // 为每个视频容器初始化切换功能
        initVideoContainer(container);
    }
}

/**
 * 初始化所有Play播放器
 */
function initPlayPlayers() {
    // 获取所有Play播放器容器
    var playContainers = document.querySelectorAll('.play-container');
    
    // 使用传统的for循环，确保兼容性
    for (var i = 0; i < playContainers.length; i++) {
        var container = playContainers[i];
        // 检查是否使用iframe方式
        var iframeElement = container.querySelector('.artplayer-iframe');
        if (!iframeElement) {
            // 只在非iframe方式下初始化ArtPlayer播放器
            initializeArtPlayer(container);
        }
    }
}

/**
 * 解码URL编码的字符串
 * @param {string} str 编码的字符串
 * @returns {string} 解码后的字符串
 */
function decodeURIComponent(str) {
    // 创建一个临时textarea元素来解码HTML实体
    var textarea = document.createElement('textarea');
    textarea.innerHTML = str;
    return textarea.value;
}

/**
 * 初始化单个视频容器
 * @param {HTMLElement} container 视频容器元素
 */
function initVideoContainer(container) {
    // 检查是否已经初始化过，避免重复初始化
    if (container.dataset.videoInitialized === 'true') {
        return;
    }
    
    // 标记为已初始化
    container.dataset.videoInitialized = 'true';
    
    // 获取视频切换标签
    var tabs = container.querySelectorAll('.video-tab');
    
    // 如果没有标签，不需要初始化
    if (tabs.length === 0) {
        return;
    }
    
    // 为每个标签绑定点击事件
    for (var i = 0; i < tabs.length; i++) {
        (function(index) {
            var tab = tabs[index];
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                // 切换视频
                switchVideo(container, index);
            });
        })(i);
    }
}

/**
 * 切换视频
 * @param {HTMLElement} container 视频容器元素
 * @param {number} index 视频索引
 */
function switchVideo(container, index) {
    // 获取视频URL列表
    var urlsInput = container.querySelector('.video-urls');
    var titlesInput = container.querySelector('.video-titles');
    
    if (!urlsInput || !titlesInput) {
        return;
    }
    
    // 解码URL列表（处理可能存在的编码）
    var urlsStr = decodeURIComponent(urlsInput.value);
    var urls = urlsStr.split(',');
    
    // 解码标题列表
    var titlesStr = decodeURIComponent(titlesInput.value);
    var titles = titlesStr.split('|');
    
    // 获取所有标签
    var tabs = container.querySelectorAll('.video-tab');
    
    // 验证索引是否有效
    if (index < 0 || index >= urls.length) {
        return;
    }
    
    // 更新标签激活状态
    for (var i = 0; i < tabs.length; i++) {
        if (i === index) {
            tabs[i].classList.add('active');
        } else {
            tabs[i].classList.remove('active');
        }
    }
    
    // 获取当前选中的URL
    var currentUrl = urls[index].trim();
    
    // 获取解析器URL（已在PHP端根据视频类型设置，直接使用即可）
    var parserUrlInput = container.querySelector('.video-parser-url');
    var parserUrl = parserUrlInput ? parserUrlInput.value : (window.shortCodeIframeParserUrl || '');
    
    // 检查是否使用解析地址
    var useParserInput = container.querySelector('.video-use-parser');
    var useParser = useParserInput ? (useParserInput.value === 'true') : true;
    
    // 检查容器类型，使用对应的切换方法
    var containerType = container.getAttribute('data-type');
    if (containerType === 'play') {
        // 检查是否使用iframe方式
        var iframeElement = container.querySelector('.artplayer-iframe');
        if (iframeElement) {
            // 使用iframe方式切换视频
            switchIframeVideo(container, index, urls, parserUrl, useParser);
        } else {
            // 使用ArtPlayer方式切换视频
            switchPlayVideo(container, index, urls, parserUrl, useParser);
        }
    }
    
    // 更新浏览器标签标题
    updateBrowserTitle(index, titles, container);
}

/**
 * 更新浏览器标签标题
 * @param {number} index 视频索引
 * @param {Array} titles 视频标题列表
 * @param {HTMLElement} container 视频容器元素
 */
function updateBrowserTitle(index, titles, container) {
    // 获取当前分集的标题
    var currentTitle = titles[index] ? titles[index].trim() : ('第' + (index + 1) + '集');
    
    // 获取页面原始标题（如果没有保存过，则从document.title获取）
    var originalTitle = container.originalTitle || document.title;
    container.originalTitle = originalTitle;
    
    // 构建新标题：分集标题 + 原始页面标题
    var newTitle = currentTitle + ' - ' + originalTitle;
    
    // 更新浏览器标题
    document.title = newTitle;
}

/**
 * 切换Iframe视频
 * @param {HTMLElement} container 视频容器元素
 * @param {number} index 视频索引
 * @param {Array} urls 视频URL列表
 * @param {string} parserUrl 解析器URL
 * @param {boolean} useParser 是否使用解析器
 */
function switchIframeVideo(container, index, urls, parserUrl, useParser) {
    var iframeElement = container.querySelector('.artplayer-iframe');
    var videoUrl = urls[index];
    
    if (!iframeElement || !videoUrl) {
        return;
    }
    
    // 确保使用正确的解析地址
    var finalUrl;
    if (useParser && parserUrl) {
        // 使用解析地址，直接拼接
        finalUrl = parserUrl + encodeURIComponent(videoUrl);
    } else {
        // 直接使用原始地址
        finalUrl = videoUrl;
    }
    
    // 更新iframe的src属性
    iframeElement.src = finalUrl;
    
    // 更新当前视频索引
    container.currentVideoIndex = index;
}

/**
 * 获取解析后的视频URL（异步版本）
 * @param {string} originalUrl - 原始视频URL
 * @param {string} parserUrl - 解析地址前缀
 * @param {boolean} useParser - 是否使用解析地址
 * @returns {Promise<string>} 解析后的视频URL
 */
async function getParsedVideoUrlAsync(originalUrl, parserUrl, useParser) {
    if (useParser && parserUrl) {
        // 使用解析地址，直接拼接
        return parserUrl + encodeURIComponent(originalUrl);
    }
    // 直接使用原始地址
    return originalUrl;
}

/**
 * 切换Play视频（ArtPlayer）
 * @param {HTMLElement} container 视频容器元素
 * @param {number} index 视频索引
 * @param {Array} urls 视频URL列表
 * @param {string} parserUrl 解析器URL
 * @param {boolean} useParser 是否使用解析器
 */
function switchPlayVideo(container, index, urls, parserUrl, useParser) {
    var artPlayer = container.artPlayer;
    var videoUrl = urls[index];
    
    if (!artPlayer || !videoUrl) {
        return;
    }
    
    // 获取解析后的视频URL
    getParsedVideoUrlAsync(videoUrl, parserUrl, useParser)
        .then(function(finalUrl) {
            var videoType = getVideoType(finalUrl);
            
            // 切换视频
            artPlayer.switchUrl(finalUrl, videoType);
            artPlayer.play();
            
            // 禁用弹幕功能，移除加载弹幕数据的逻辑
            
            // 更新当前视频索引
            container.currentVideoIndex = index;
            
            // 更新下一集按钮显示状态
            setTimeout(function() {
                var nextButton = container.querySelector('.art-icon-next');
                if (nextButton) {
                    var buttonContainer = nextButton.parentElement;
                    if (index >= urls.length - 1) {
                        // 当前是最后一集，隐藏下一集按钮及其容器
                        nextButton.style.display = 'none';
                        if (buttonContainer) {
                            buttonContainer.style.display = 'none';
                        }
                    } else {
                        // 当前不是最后一集，显示下一集按钮及其容器
                        nextButton.style.display = 'flex';
                        if (buttonContainer) {
                            buttonContainer.style.display = 'flex';
                        }
                    }
                }
            }, 100);
        })
        .catch(function(error) {
            console.error('Error getting parsed video URL:', error);
        });
}

/**
 * 初始化ArtPlayer播放器
 * @param {HTMLElement} container - 视频播放器容器
 */
function initializeArtPlayer(container) {
    var id = container.id;
    var artPlayerId = 'artplayer-' + id;
    var artPlayerContainer = document.getElementById(artPlayerId);
    
    if (!artPlayerContainer) {
        console.error('ArtPlayer容器未找到: ' + artPlayerId);
        return;
    }
    
    // 检查容器是否已经有ArtPlayer实例，如果有则不再创建
    if (container.artPlayer) {
        return;
    }
    
    // 获取视频数据
    var videoUrlsInput = container.querySelector('.video-urls');
    var videoTitlesInput = container.querySelector('.video-titles');
    var parserUrlInput = container.querySelector('.video-parser-url');
    var useParserInput = container.querySelector('.video-use-parser');
    
    if (!videoUrlsInput) {
        console.error('视频URL数据未找到: ' + id);
        return;
    }
    
    var videoUrlsStr = decodeURIComponent(videoUrlsInput.value);
    var videoUrls = videoUrlsStr.split(',').filter(function(url) { return url.trim() !== ''; });
    
    var videoTitlesStr = videoTitlesInput ? decodeURIComponent(videoTitlesInput.value) : '';
    var videoTitles = videoTitlesStr.split('|');
    
    // 解析地址已在PHP端根据视频类型设置，直接使用即可
    var parserUrl = parserUrlInput ? parserUrlInput.value : (window.shortCodeIframeParserUrl || '');
    var useParser = useParserInput ? (useParserInput.value === 'true') : true;
    
    if (videoUrls.length === 0) {
        return;
    }
    
    // 获取第一个视频URL
    getParsedVideoUrlAsync(videoUrls[0], parserUrl, useParser)
        .then(function(firstVideoUrl) {
            var videoType = getVideoType(firstVideoUrl);
            
            // 检测是否为移动端设备
            var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            // 初始化函数
            function initPlayerWithDanmu() {
                // 再次检查容器是否已经有ArtPlayer实例，因为可能在异步操作期间被其他调用创建
                if (container.artPlayer) {
                    console.log('ArtPlayer实例已存在，跳过初始化: ' + id);
                    return;
                }
                
                // 构建插件数组
                var plugins = [];
                
                // 构建控件配置
                var controlsConfig = [];
                
                // 如果视频数量大于1且当前不是最后一集，才添加下一集按钮
                if (videoUrls.length > 1 && 0 < videoUrls.length - 1) {
                    controlsConfig.push({
                        position: 'left',
                        index: 11, 
                        html: '<i class="art-icon art-icon-next hint--rounded hint--top" aria-label="下一集" style="display: flex;"><svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z" fill="currentColor"></path></svg></i>',
                        click: function() {
                            var currentIndex = container.currentVideoIndex || 0;
                            var nextIndex = currentIndex + 1;
                            if (nextIndex < container.videoUrls.length) {
                                switchVideo(container, nextIndex);
                            }
                        }
                    });
                }
                
                // 初始化ArtPlayer
                var art = new Artplayer({
                    container: '#' + artPlayerId, // 播放器容器元素
                    url: firstVideoUrl, // 视频播放地址
                    type: videoType, // 视频类型（使用自动检测的类型）
                    autoplay: true, // 自动播放
                    autoSize: false, // 禁用自动大小调整，强制铺满容器
                    playbackRate: true, // 显示播放速度控制
                    fastForward: true, // 移动端添加长按视频快进功能
                    setting: true, // 显示设置菜单
                    pip: !isMobile, // 画中画：移动端不显示
                    fullscreen: true, // 启用视频全屏功能
                    fullscreenWeb: !isMobile, // 网页全屏：移动端不显示
                    playsInline: true, // 允许网页内播放（移动端）
                    autoPlayback: true, // 自动回放（记忆播放）
                    theme: '#23ade5', // 播放器主题颜色
                    lang: navigator.language.toLowerCase(), // 根据浏览器自动设置语言
                    mutex: true, // 互斥，阻止多个播放器同时播放
                    controls: controlsConfig,
                    customType: {
                        m3u8: function (video, url) {
                            if (window.Hls && window.Hls.isSupported()) {
                                var hls = new Hls({
                                    maxBufferLength: 300,       // 正常缓冲时长 (秒)
                                    maxMaxBufferLength: 600,    // 最大缓冲时长 (秒)
                                    fragLoadingSetup: function(xhr, frag) {
                                        // 为伪装成PNG的TS片段添加正确的请求头
                                        if (frag.url && frag.url.toLowerCase().endsWith('.png')) {
                                            xhr.setRequestHeader('Accept', '*/*');
                                            xhr.setRequestHeader('Content-Type', 'video/mp2t');
                                        }
                                    }
                                });
                                hls.loadSource(url);
                                hls.attachMedia(video);
                            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                                video.src = url;
                            }
                        },
                        flv: function (video, url) {
                            if (window.flvjs && window.flvjs.isSupported()) {
                                var flvPlayer = flvjs.createPlayer({
                                    type: 'flv',
                                    url: url
                                });
                                flvPlayer.attachMediaElement(video);
                                flvPlayer.load();
                            }
                        },
                        mp4: function (video, url) {
                            video.src = url;
                        }
                    },
                    plugins: plugins,
                });
                
                // 将播放器实例存储在容器上，以便后续切换视频使用
                container.artPlayer = art;
                container.videoUrls = videoUrls;
                container.videoTitles = videoTitles;
                container.parserUrl = parserUrl;
                container.useParser = useParser;
                container.currentVideoIndex = 0;
            }
            
            // 初始化播放器
            initPlayerWithDanmu();
        })
        .catch(function(error) {
            console.error('Error getting parsed video URL:', error);
        });
}

/**
 * 获取解析后的视频URL（异步版本）
 * @param {string} originalUrl - 原始视频URL
 * @param {string} parserUrl - 解析地址前缀
 * @param {boolean} useParser - 是否使用解析地址
 * @returns {Promise<string>} 解析后的视频URL
 */
async function getParsedVideoUrlAsync(originalUrl, parserUrl, useParser) {
    if (useParser && parserUrl) {
        // 使用解析地址，等待API响应
        try {
            const response = await fetch(parserUrl + encodeURIComponent(originalUrl));
            const data = await response.json();
            // 返回JSON中的url键值
            return data.url || originalUrl; // 如果没有url键，则返回原始URL
        } catch (error) {
            console.error('解析视频URL失败:', error);
            return originalUrl; // 出错时返回原始URL
        }
    }
    // 直接使用原始地址
    return originalUrl;
}

/**
 * 同步版本的获取解析后的视频URL（兼容旧代码）
 * @param {string} originalUrl - 原始视频URL
 * @param {string} parserUrl - 解析地址前缀
 * @param {boolean} useParser - 是否使用解析地址
 * @returns {string} 解析后的视频URL
 */
function getParsedVideoUrl(originalUrl, parserUrl, useParser) {
    // 对于同步调用，暂时直接返回拼接的URL，但理想情况下应该重构为异步调用
    if (useParser && parserUrl) {
        return parserUrl + encodeURIComponent(originalUrl);
    }
    // 直接使用原始地址
    return originalUrl;
}

/**
 * 获取视频类型
 * @param {string} url - 视频URL
 * @returns {string} 视频类型（m3u8、flv、mp4或auto）
 */
function getVideoType(url) {
    // 检查是否包含m3u8相关参数或路径
    if (url.toLowerCase().includes('.m3u8') || url.toLowerCase().includes('hls') || url.toLowerCase().includes('playlist')) {
        return 'm3u8';
    } else if (url.toLowerCase().endsWith('.flv')) {
        return 'flv';
    } else if (url.toLowerCase().endsWith('.mp4')) {
        return 'mp4';
    } else {
        return 'm3u8'; // 默认使用m3u8处理，确保请求头设置生效
    }
}

/**
 * 扩展ArtPlayer，添加切换URL方法
 * 如果ArtPlayer实例没有switchUrl方法，则添加该方法
 */
if (typeof Artplayer !== 'undefined') {
    // 检查switchUrl方法是否存在，不存在则添加
    if (typeof Artplayer.prototype.switchUrl === 'undefined') {
        Artplayer.prototype.switchUrl = function(url, type) {
            this.url = url;
            this.type = type;
            
            // 重新加载视频
            this.load();
            
            return this;
        };
    }
}
