<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class XiAutoTags_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $this->response->setContentType('application/json');

        try {
            $this->widget('Widget_User')->pass('administrator');

            if (!$this->request->isPost()) {
                $this->error('只支持 POST 请求', 405);
                return;
            }

            $title = trim((string) $this->request->get('title'));
            $content = trim((string) $this->request->get('content'));

            if ($title === '') {
                $this->error('标题不能为空', 400);
                return;
            }

            $pluginOptions = Helper::options()->plugin('XiAutoTags');
            $apiEndpoint = trim((string) $pluginOptions->api_endpoint);
            $apiKey = trim((string) $pluginOptions->api_key);
            $model = trim((string) $pluginOptions->model);

            if ($apiEndpoint === '' || $apiKey === '' || $model === '') {
                $this->error('请先在插件设置中填写 API 端点、API Key 和模型', 400);
                return;
            }

            $maxTags = $this->intValue($pluginOptions->max_tags, 5, 1, 10);
            $maxContentLength = $this->intValue($pluginOptions->max_content_length, 2000, 200, 10000);
            $timeout = $this->intValue($pluginOptions->request_timeout, 20, 5, 60);

            $libraryTags = $this->getHotTags(300);
            $prompt = $this->buildPrompt(
                $title,
                $content,
                $maxTags,
                $maxContentLength,
                array_slice($libraryTags, 0, 20)
            );
            $raw = $this->callLlmApi($apiEndpoint, $apiKey, $model, $prompt, $timeout);
            $tags = $this->parseTags($raw, $maxTags);

            if (empty($tags)) {
                $this->error('AI 返回结果无法解析出标签，请重试', 500);
                return;
            }

            echo json_encode(array(
                'success' => true,
                'tags' => $tags,
                'raw' => $raw,
                'library_tags' => $libraryTags
            ), JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    private function buildPrompt($title, $content, $maxTags, $maxContentLength, $existingTags)
    {
        $title = strip_tags($title);
        $content = strip_tags($content);
        $content = $this->mbSubstrSafe($content, 0, $maxContentLength);

        $existingTagsText = '';
        if (!empty($existingTags)) {
            $existingTagsText = "已有标签参考：" . implode('、', $existingTags) . "\n";
        }

        return "请为文章生成 {$maxTags} 个标签。\n" .
            "要求：标签尽量短、准确，不重复；仅输出逗号分隔标签，不要解释。\n" .
            $existingTagsText .
            "标题：{$title}\n" .
            "内容：{$content}";
    }

    private function getHotTags($limit)
    {
        $tags = array();
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $rows = $db->fetchAll(
                $db->select('name')
                    ->from($prefix . 'metas')
                    ->where('type = ?', 'tag')
                    ->order('count', Typecho_Db::SORT_DESC)
                    ->limit($limit)
            );

            foreach ($rows as $row) {
                if (!empty($row['name'])) {
                    $tags[] = trim($row['name']);
                }
            }
        } catch (Exception $e) {
            return array();
        }

        return $tags;
    }

    private function callLlmApi($endpoint, $apiKey, $model, $prompt, $timeout)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('服务器未启用 cURL');
        }

        $payload = json_encode(array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => '你是文章标签助手，只返回逗号分隔的标签列表。'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.2,
            'max_tokens' => 120
        ), JSON_UNESCAPED_UNICODE);

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        );

        if (strpos($endpoint, 'openrouter.ai') !== false) {
            $headers[] = 'HTTP-Referer: ' . Helper::options()->siteUrl;
            $headers[] = 'X-Title: XiAutoTags';
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception('网络请求失败：' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('AI 接口请求失败，HTTP ' . $httpCode);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new Exception('AI 响应格式错误');
        }

        if (!empty($json['error'])) {
            if (is_array($json['error']) && !empty($json['error']['message'])) {
                throw new Exception('AI 接口错误：' . $json['error']['message']);
            }
            throw new Exception('AI 接口返回错误');
        }

        if (!empty($json['choices'][0]['message']['content'])) {
            return trim($json['choices'][0]['message']['content']);
        }

        throw new Exception('AI 没有返回可用内容');
    }

    private function parseTags($text, $maxTags)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^(标签|tags?)[:：]?\s*/iu', '', $text);
        $text = str_replace(array('，', '、', ';', '；', "\n", "\r", "\t", '|'), ',', $text);

        $parts = explode(',', $text);
        $tags = array();
        foreach ($parts as $part) {
            $tag = trim($part);
            $tag = trim($tag, "\"'` ");
            if ($tag === '') {
                continue;
            }
            if ($this->mbStrlenSafe($tag) > 20) {
                continue;
            }
            if (!in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= $maxTags) {
                break;
            }
        }

        return $tags;
    }

    private function intValue($value, $default, $min, $max)
    {
        $n = intval($value);
        if ($n <= 0) {
            $n = $default;
        }
        if ($n < $min) {
            $n = $min;
        }
        if ($n > $max) {
            $n = $max;
        }
        return $n;
    }

    private function mbSubstrSafe($text, $start, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }
        return substr($text, $start, $length);
    }

    private function mbStrlenSafe($text)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        return strlen($text);
    }

    private function error($message, $status)
    {
        $this->response->setStatus($status);
        echo json_encode(array(
            'success' => false,
            'message' => $message
        ), JSON_UNESCAPED_UNICODE);
    }
}
