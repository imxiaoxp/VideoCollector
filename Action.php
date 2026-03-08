<?php

namespace TypechoPlugin\VideoCollector;

use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 视频采集Action
 */
class Action extends Widget implements ActionInterface
{
    /**
     * 执行函数
     */
    public function execute()
    {
    }

    /**
     * 搜索视频
     */
    public function search()
    {
        $this->response->setContentType('application/json');
        
        // 检查用户是否登录
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            echo json_encode(['code' => 0, 'msg' => '请先登录']);
            exit;
        }
        
        $keyword = $this->request->get('keyword', '');
        if (empty($keyword)) {
            echo json_encode(['code' => 0, 'msg' => '请输入搜索关键词']);
            exit;
        }
        
        $options = Options::alloc();
        $apiUrl = $options->plugin('VideoCollector')->apiUrl;
        
        if (empty($apiUrl)) {
            $apiUrl = 'https://www.caiji.cyou/api.php/provide/vod/?ac=detail&wd=';
        }
        
        $url = $apiUrl . urlencode($keyword);
        
        $result = $this->fetchUrl($url);
        
        if ($result === false) {
            echo json_encode(['code' => 0, 'msg' => '请求API失败']);
            exit;
        }
        
        $data = json_decode($result, true);
        
        if (!$data) {
            echo json_encode(['code' => 0, 'msg' => '解析JSON失败']);
            exit;
        }
        
        echo json_encode($data);
        exit;
    }

    /**
     * 请求URL
     *
     * @param string $url
     * @return string|false
     */
    private function fetchUrl($url)
    {
        // 优先使用curl
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return false;
            }
            
            return $result;
        }
        
        // 使用file_get_contents
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

    /**
     * Action入口
     */
    public function action()
    {
        $do = $this->request->get('do', '');
        
        switch ($do) {
            case 'search':
                $this->search();
                break;
            default:
                $this->response->setContentType('application/json');
                echo json_encode(['code' => 0, 'msg' => '未知操作']);
                exit;
        }
    }
}
