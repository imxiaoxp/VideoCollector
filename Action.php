<?php

namespace TypechoPlugin\VideoCollector;

use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 视频采集Action：代理搜索第三方采集API（需登录）
 */
class Action extends Widget implements ActionInterface
{
    public function execute()
    {
    }

    public function action()
    {
        if ($this->request->get('do', '') === 'search') {
            $this->search();
        }
        $this->response->setContentType('application/json');
        echo json_encode(['code' => 0, 'msg' => '未知操作']);
        exit;
    }

    private function fail(string $msg)
    {
        echo json_encode(['code' => 0, 'msg' => $msg]);
        exit;
    }

    /**
     * 搜索视频（支持分页）
     */
    public function search()
    {
        $this->response->setContentType('application/json');

        if (!\Widget\User::alloc()->hasLogin()) {
            $this->fail('请先登录');
        }

        $keyword = $this->request->get('keyword', '');
        if (empty($keyword)) {
            $this->fail('请输入搜索关键词');
        }

        $page = max(1, intval($this->request->get('page', 1)));

        $apiUrl = Options::alloc()->plugin('VideoCollector')->apiUrl;
        if (empty($apiUrl)) {
            $this->fail('请先在插件配置中填写采集API地址');
        }

        $result = $this->fetchUrl($this->buildApiUrl($apiUrl, $keyword, $page));
        if ($result === false) {
            $this->fail('请求API失败');
        }

        $data = json_decode($result, true);
        if (!$data) {
            $this->fail('解析JSON失败');
        }

        // 兼容多种字段名；API 未返回当前页时使用请求的页码
        echo json_encode([
            'code' => 1,
            'list' => $data['list'] ?? [],
            'page' => $data['pg'] ?? $data['page'] ?? $page,
            'pagecount' => $data['pagecount'] ?? $data['totalpages'] ?? 1,
            'total' => $data['total'] ?? 0
        ]);
        exit;
    }

    private function appendParam(string $url, string $param): string
    {
        return $url . (strpos($url, '?') !== false ? '&' : '?') . $param;
    }

    /**
     * 构建完整的API请求URL：统一 ac=detail，注入 wd（关键词）与 pg（页码）参数
     */
    private function buildApiUrl(string $apiUrl, string $keyword, int $page = 1): string
    {
        $wd = urlencode($keyword);

        $apiUrl = preg_replace('/([?&])ac=list/i', '$1ac=detail', $apiUrl);
        if (strpos($apiUrl, 'ac=') === false) {
            $apiUrl = $this->appendParam($apiUrl, 'ac=detail');
        }

        if (strpos($apiUrl, 'wd=') !== false) {
            $apiUrl = preg_replace('/(wd=)([^&]*)/i', '$1' . $wd, $apiUrl);
        } else {
            $apiUrl = $this->appendParam($apiUrl, 'wd=' . $wd);
        }

        if (preg_match('/[?&]pg=/i', $apiUrl)) {
            $apiUrl = preg_replace('/([?&]pg=)(\d+)/i', '$1' . $page, $apiUrl);
        } else {
            $apiUrl = $this->appendParam($apiUrl, 'pg=' . $page);
        }

        return $apiUrl;
    }

    /**
     * 请求URL（cURL 优先，退化为 file_get_contents）
     *
     * @param string $url
     * @return string|false
     */
    private function fetchUrl($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            return $error ? false : $result;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        return @file_get_contents($url, false, $context);
    }
}
