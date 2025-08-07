<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 昔年AI自动标签插件 - 多API支持版
 * 
 * @package XiAutoTags
 * @author XiNian-dada
 * @version 1.0.0
 * @link https://leeinx.com/
 */
class XiAutoTags_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('XiAutoTags_Plugin', 'addManualButton');
        Typecho_Plugin::factory('admin/write-post.php')->content = array('XiAutoTags_Plugin', 'addTagInputId');
        Helper::addAction('xinautotags-tags', 'XiAutoTags_Action');
    }

    public static function deactivate()
    {
        Helper::removeAction('xinautotags-tags');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    { 
        // 标签数量限制
        $minTags = new Typecho_Widget_Helper_Form_Element_Text(
            'min_tags', NULL, '3',
            _t('最少生成标签数'), _t('AI至少应该生成的标签数量')
        );
        $form->addInput($minTags->addRule('isInteger', _t('请输入整数')));
        
        $maxTags = new Typecho_Widget_Helper_Form_Element_Text(
            'max_tags', NULL, '5',
            _t('最多生成标签数'), _t('AI最多可以生成的标签数量')
        );
        $form->addInput($maxTags->addRule('isInteger', _t('请输入整数')));
        
        // OpenRouter 配置
        $openrouter_enabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'openrouter_enabled', 
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('启用 OpenRouter API'),
            _t('是否启用默认的OpenRouter API')
        );
        $form->addInput($openrouter_enabled);
        
        $openrouter_api_key = new Typecho_Widget_Helper_Form_Element_Text(
            'openrouter_api_key', NULL, '',
            _t('OpenRouter API Key'),
            _t('申请地址：<a href="https://openrouter.ai/settings/keys" target="_blank">点击申请</a>')
        );
        $form->addInput($openrouter_api_key);

        $openrouter_api_model = new Typecho_Widget_Helper_Form_Element_Text(
            'openrouter_api_model', NULL, 'deepseek/deepseek-chat-v3-0324:free',
            _t('OpenRouter 模型名称'), _t('推荐使用免费模型 deepseek/deepseek-chat-v3-0324:free')
        );
        $form->addInput($openrouter_api_model);
        
        $openrouter_priority = new Typecho_Widget_Helper_Form_Element_Text(
            'openrouter_priority', NULL, '2',
            _t('OpenRouter 优先级'), _t('数字越小优先级越高 (1 > 2)')
        );
        $form->addInput($openrouter_priority);
        
        // 自定义API列表
        $custom_apis = new Typecho_Widget_Helper_Form_Element_Textarea(
            'custom_apis', 
            NULL, 
            '',
            _t('基础自定义API列表'),
            _t('每行一个API，格式：<br><strong>名称|优先级|API密钥|模型|端点URL|启用状态(1/0)</strong><br>示例：<br><code>MyAPI|1|sk-xxxx|gpt-4|https://api.example.com|1</code>')
        );
        $form->addInput($custom_apis);
        
        // 高级自定义API配置
        $advanced_apis = new Typecho_Widget_Helper_Form_Element_Textarea(
            'advanced_apis', 
            NULL, 
            '',
            _t('高级自定义API配置'),
            _t('<div style="margin-bottom:15px">JSON格式配置，支持完全自定义请求和响应处理。</div>
                <div style="margin-bottom:10px"><strong>可用占位符：</strong></div>
                <ul style="margin-top:0; padding-left:20px">
                    <li><code>{{PROMPT}}</code>: 生成标签的提示词</li>
                    <li><code>{{TITLE}}</code>: 文章标题</li>
                    <li><code>{{CONTENT}}</code>: 文章内容（前3000字符）</li>
                    <li><code>{{EXISTING_TAGS}}</code>: 现有标签库（JSON字符串）</li>
                </ul>')
        );
        $form->addInput($advanced_apis);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    public static function addTagInputId()
    {
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const tagInput = document.querySelector("input[name=tags]");
                if (tagInput && !tagInput.id) {
                    tagInput.id = "xinautotags-tag-input";
                }
            });
        </script>';
    }

    public static function addManualButton()
    {
        $options = Helper::options()->plugin('XiAutoTags');
        $allTags = self::getAllTags();
        $jsTags = json_encode($allTags);
        
        // 获取标签数量设置
        $minTags = intval($options->min_tags ?? 3);
        $maxTags = intval($options->max_tags ?? 5);
        
        // 获取OpenRouter配置
        $openrouter_enabled = $options->openrouter_enabled ?? '1';
        $openrouter_api_key = $options->openrouter_api_key ?? '';
        $openrouter_api_model = $options->openrouter_api_model ?? '';
        $openrouter_priority = $options->openrouter_priority ?? '2';
        
        // 解析基础自定义API
        $custom_apis = [];
        if (!empty($options->custom_apis)) {
            $lines = explode("\n", $options->custom_apis);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $parts = explode('|', $line);
                    if (count($parts) >= 6) {
                        $custom_apis[] = [
                            'name' => trim($parts[0]),
                            'priority' => intval(trim($parts[1])),
                            'apiKey' => trim($parts[2]),
                            'apiModel' => trim($parts[3]),
                            'endpoint' => trim($parts[4]),
                            'enabled' => trim($parts[5]) === '1'
                        ];
                    }
                }
            }
        }
        
        // 解析高级自定义API
        $advanced_apis = [];
        
        if (!empty($options->advanced_apis)) {
            try {
                $advanced_apis = json_decode($options->advanced_apis, true);
                if (!is_array($advanced_apis)) {
                    $advanced_apis = [];
                }
            } catch (Exception $e) {
                $advanced_apis = [];
            }
        }
        ?>
        <script>
        // 标签数量限制
        const MIN_TAGS = <?php echo max(1, $minTags); ?>;
        const MAX_TAGS = <?php echo max(max(1, $minTags), $maxTags); ?>;
        
        // 定义API提供者列表
        const AI_PROVIDERS = [];
        
        <?php if ($openrouter_enabled === '1' && !empty($openrouter_api_key) && !empty($openrouter_api_model)): ?>
        // 添加OpenRouter提供者
        AI_PROVIDERS.push({
            name: "OpenRouter",
            priority: <?php echo intval($openrouter_priority); ?>,
            call: async function(prompt) {
                const config = {
                    apiKey: "<?php echo addslashes($openrouter_api_key); ?>",
                    apiModel: "<?php echo addslashes($openrouter_api_model); ?>",
                    endpoint: "https://openrouter.ai/api/v1/chat/completions"
                };
                
                const response = await fetch(config.endpoint, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${config.apiKey}`,
                        'Content-Type': 'application/json',
                        'HTTP-Referer': 'https://leeinx.com',
                        'X-Title': 'XiAutoTags by XiNian-dada'
                    },
                    body: JSON.stringify({
                        model: config.apiModel,
                        messages: [{ role: 'user', content: prompt }],
                        temperature: 0.3,
                        max_tokens: 100
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`OpenRouter错误: ${response.status} ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.choices && data.choices[0]?.message?.content) {
                    return data.choices[0].message.content.trim();
                }
                
                throw new Error('OpenRouter响应格式无效');
            }
        });
        <?php endif; ?>
        
        <?php if (!empty($custom_apis)): ?>
        // 添加基础自定义API提供者
        const CUSTOM_APIS = <?php echo json_encode($custom_apis); ?>;
        
        CUSTOM_APIS.forEach(api => {
            if (!api.enabled) return;
            
            AI_PROVIDERS.push({
                name: api.name,
                priority: api.priority,
                call: async function(prompt) {
                    const config = {
                        apiKey: api.apiKey,
                        apiModel: api.apiModel,
                        endpoint: api.endpoint
                    };
                    
                    try {
                        const response = await fetch(config.endpoint, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${config.apiKey}`,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                model: config.apiModel,
                                messages: [
                                    {
                                        "role": "system",
                                        "content": "你是一个有帮助的助手，擅长生成文章标签"
                                    },
                                    {
                                        "role": "user",
                                        "content": prompt
                                    }
                                ],
                                temperature: 0.3,
                                max_tokens: 100
                            })
                        });
                        
                        if (!response.ok) {
                            const errorDetail = await response.text();
                            throw new Error(`${api.name}错误: ${response.status} - ${errorDetail}`);
                        }
                        
                        const data = await response.json();
                        
                        if (data.choices && data.choices[0]?.message?.content) {
                            return data.choices[0].message.content.trim();
                        }
                        
                        throw new Error(`${api.name}响应格式无效: ` + JSON.stringify(data));
                    } catch (error) {
                        console.error(`${api.name}调用失败:`, error);
                        let errorMsg = error.message;
                        
                        // 检测 CORS 错误
                        if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
                            errorMsg += ' (可能原因: CORS 问题或网络连接失败)';
                        }
                        
                        throw new Error(errorMsg);
                    }
                }
            });
        });
        <?php endif; ?>

        <?php if (!empty($advanced_apis)): ?>
        // 添加高级自定义API提供者
        const ADVANCED_APIS = <?php echo json_encode($advanced_apis); ?>;
            
        ADVANCED_APIS.forEach(api => {
            if (!api.enabled) return;
            
            AI_PROVIDERS.push({
                name: api.name,
                priority: api.priority,
                isAdvanced: true,
                config: api,
                call: async function(prompt, title, content, existingTags) {
                    const config = JSON.parse(JSON.stringify(api.request)); // 深度拷贝
                    
                    try {
                        // 替换占位符
                        const replacePlaceholders = (obj) => {
                            if (typeof obj === 'string') {
                                return obj
                                    .replace('{{PROMPT}}', prompt)
                                    .replace('{{TITLE}}', title)
                                    .replace('{{CONTENT}}', content)
                                    .replace('{{EXISTING_TAGS}}', JSON.stringify(existingTags));
                            } else if (typeof obj === 'object' && obj !== null) {
                                for (let key in obj) {
                                    obj[key] = replacePlaceholders(obj[key]);
                                }
                            }
                            return obj;
                        };
                        
                        // 处理整个配置
                        config.endpoint = replacePlaceholders(config.endpoint);
                        config.headers = replacePlaceholders(config.headers);
                        config.body = replacePlaceholders(config.body);
                        
                        // 如果body是对象，转换为JSON字符串
                        let bodyData = config.body;
                        if (typeof bodyData === 'object') {
                            bodyData = JSON.stringify(bodyData);
                        }
                        
                        // 构建headers
                        const headers = new Headers();
                        if (config.headers) {
                            for (const [key, value] of Object.entries(config.headers)) {
                                if (value) headers.append(key, value);
                            }
                        }
                        
                        // 发送请求
                        const response = await fetch(config.endpoint, {
                            method: config.method || 'POST',
                            headers: headers,
                            body: bodyData
                        });
                        
                        if (!response.ok) {
                            const errorText = await response.text();
                            throw new Error(`${api.name}请求失败: ${response.status} - ${errorText}`);
                        }
                        
                        // 处理响应
                        let result;
                        const responseContentType = response.headers.get('content-type') || '';
                        
                        if (responseContentType.includes('application/json')) {
                            result = await response.json();
                        } else {
                            result = await response.text();
                        }
                        
                        // 使用自定义提取逻辑
                        const extractCode = api.response?.extract;
                        if (extractCode) {
                            // 创建安全沙箱环境执行代码
                            const sandbox = {
                                response: response,
                                jsonResponse: result,
                                responseText: typeof result === 'string' ? result : JSON.stringify(result),
                                existingTags: existingTags,
                                title: title,
                                content: content,
                                prompt: prompt
                            };
                            
                            const extractFunction = new Function(
                                'sandbox', 
                                `with(sandbox) { 
                                    try {
                                        return (${extractCode});
                                    } catch(e) {
                                        return '提取失败: ' + e.message;
                                    }
                                }`
                            );
                            
                            // 执行提取
                            const tagsResult = extractFunction(sandbox);
                            
                            if (typeof tagsResult === 'string') {
                                return tagsResult;
                            } else {
                                throw new Error('提取结果不是字符串');
                            }
                        } else {
                            throw new Error('未配置响应提取逻辑');
                        }
                    } catch (error) {
                        console.error(`${api.name}调用失败:`, error);
                        throw new Error(`${api.name}错误: ${error.message}`);
                    }
                }
            });
        });
        <?php endif; ?>

        // 按优先级排序
        AI_PROVIDERS.sort((a, b) => a.priority - b.priority);
        
        const ALL_EXISTING_TAGS = <?php echo $jsTags; ?>;
        document.addEventListener('DOMContentLoaded', function () {
            // 创建主容器
            const container = document.createElement('div');
            container.id = 'xinautotags-container';
            container.style.margin = '15px 0';
            container.style.padding = '15px';
            container.style.border = '1px solid #eaeaea';
            container.style.borderRadius = '5px';
            container.style.backgroundColor = '#f9f9f9';
            
            // 标题
            const title = document.createElement('h3');
            title.textContent = 'AI标签生成器';
            title.style.marginTop = '0';
            title.style.marginBottom = '15px';
            title.style.paddingBottom = '10px';
            title.style.borderBottom = '1px solid #eee';
            title.style.color = '#2196F3'; 
            
            // 显示标签数量设置
            const tagsConfig = document.createElement('div');
            tagsConfig.style.marginBottom = '10px';
            tagsConfig.style.fontSize = '13px';
            tagsConfig.style.color = '#666';
            tagsConfig.innerHTML = `<strong>标签数量:</strong> 最少 ${MIN_TAGS} 个，最多 ${MAX_TAGS} 个`;
            
            // 显示启用的API
            const apiList = document.createElement('div');
            apiList.style.marginBottom = '10px';
            apiList.style.fontSize = '13px';
            apiList.style.color = '#666';
            
            if (AI_PROVIDERS.length > 0) {
                apiList.innerHTML = '<strong>启用的API:</strong> ' + 
                    AI_PROVIDERS.map(p => `${p.name} (优先级: ${p.priority}${p.isAdvanced ? ' - 高级API' : ''})`).join(', ');
            } else {
                apiList.innerHTML = '<strong style="color:#F44336">警告: 没有启用任何API提供者</strong>';
            }
            
            // 控制台容器
            const consoleContainer = document.createElement('div');
            consoleContainer.id = 'xinautotags-console';
            consoleContainer.style.height = '200px';
            consoleContainer.style.overflowY = 'auto';
            consoleContainer.style.backgroundColor = '#1e1e1e';
            consoleContainer.style.color = '#d4d4d4';
            consoleContainer.style.fontFamily = 'monospace';
            consoleContainer.style.fontSize = '13px';
            consoleContainer.style.padding = '10px';
            consoleContainer.style.borderRadius = '4px';
            consoleContainer.style.marginBottom = '15px';
            consoleContainer.style.whiteSpace = 'pre-wrap';
            consoleContainer.style.display = 'block';
            
            // 初始消息
            consoleContainer.innerHTML = "<div>[系统] Xi'sAI标签生成器已就绪</div>";
            
            // 按钮容器
            const btnContainer = document.createElement('div');
            btnContainer.style.display = 'flex';
            btnContainer.style.gap = '10px';
            
            // 主按钮
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '开始提取';
            btn.className = 'btn primary';
            btn.style.flex = '1';
            btn.disabled = AI_PROVIDERS.length === 0;
            btn.style.backgroundColor = '#2196F3'; 
            btn.style.borderColor = '#1976D2';
            
            // 清除日志按钮
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.textContent = '清除日志';
            clearBtn.className = 'btn';
            clearBtn.style.flex = 'none';
            
            // 调试按钮
            const debugBtn = document.createElement('button');
            debugBtn.type = 'button';
            debugBtn.textContent = '测试连接';
            debugBtn.className = 'btn';
            debugBtn.style.flex = 'none';
            debugBtn.title = '测试到API服务器的连接';
            debugBtn.disabled = AI_PROVIDERS.length === 0;
            
            // 构建UI
            container.appendChild(title);
            container.appendChild(tagsConfig);
            container.appendChild(apiList);
            container.appendChild(consoleContainer);
            btnContainer.appendChild(btn);
            btnContainer.appendChild(clearBtn);
            btnContainer.appendChild(debugBtn);
            container.appendChild(btnContainer);
            
            // 找到标签输入框的父元素
            const tagInput = document.querySelector('input[name=tags]');
            if (tagInput && tagInput.parentNode) {
                tagInput.parentNode.appendChild(container);
            } else {
                // 如果找不到标签输入框，添加到表单底部
                const form = document.querySelector('form');
                if (form) form.appendChild(container);
            }
    
            // 日志记录函数
            function logToConsole(message, type = 'info') {
                const consoleEl = document.getElementById('xinautotags-console');
                const now = new Date();
                const timestamp = `[${now.toLocaleTimeString()}]`;
                
                let color = '#d4d4d4';
                if (type === 'success') color = '#4CAF50';
                if (type === 'error') color = '#F44336';
                if (type === 'warning') color = '#FFC107';
                if (type === 'request') color = '#64B5F6';
                if (type === 'response') color = '#BA68C8';
                if (type === 'debug') color = '#FF9800';
                
                const logEntry = document.createElement('div');
                logEntry.style.marginBottom = '5px';
                logEntry.innerHTML = `<span style="color:#9E9E9E">${timestamp}</span> <span style="color:${color}">${message}</span>`;
                
                consoleEl.appendChild(logEntry);
                consoleEl.scrollTop = consoleEl.scrollHeight;
            }
            
            // 清除日志
            clearBtn.addEventListener('click', function() {
                document.getElementById('xinautotags-console').innerHTML = '<div>[系统] 日志已清除</div>';
            });
    
            // 调试连接测试
            debugBtn.addEventListener('click', function() {
                logToConsole('开始API功能测试...', 'debug');
                
                // 禁用测试按钮避免重复点击
                debugBtn.disabled = true;
                const originalText = debugBtn.textContent;
                debugBtn.textContent = '测试中...';
                
                // 记录测试开始时间
                const testStartTime = Date.now();
                
                // 设置全局超时（15秒）
                const GLOBAL_TIMEOUT = 15000;
                const globalTimeout = setTimeout(() => {
                    logToConsole('警告: 全局测试超时 (15秒)，部分API可能无响应', 'warning');
                    debugBtn.disabled = false;
                    debugBtn.textContent = originalText;
                }, GLOBAL_TIMEOUT);
                
                // 测试所有API提供者的功能
                const testPromises = AI_PROVIDERS.map(provider => {
                    return new Promise((resolve) => {
                        const providerTestStart = Date.now();
                        const logPrefix = `[${provider.name}]`;
                        let timedOut = false;
                        
                        // 设置单个API超时（10秒）
                        const providerTimeout = setTimeout(() => {
                            timedOut = true;
                            logToConsole(`${logPrefix} 测试超时 (10秒)`, 'error');
                            resolve(false);
                        }, 10000);
                        
                        logToConsole(`${logPrefix} 开始功能测试`, 'debug');
                        
                        // 构建极简测试请求 - 最小化token消耗
                        const testPrompt = "连接测试: 请回复OK";
                        
                        // 清除超时并处理结果
                        const clearAndResolve = (success) => {
                            if (!timedOut) {
                                clearTimeout(providerTimeout);
                                resolve(success);
                            }
                        };
                        
                        try {
                            if (provider.isAdvanced) {
                                // 高级API测试 - 使用最小化请求
                                provider.call(testPrompt, "测试标题", "测试内容", [])
                                    .then(responseText => {
                                        const duration = Date.now() - providerTestStart;
                                        
                                        // 检查响应是否包含'OK'（不区分大小写）
                                        if (responseText && responseText.toUpperCase().includes('OK')) {
                                            logToConsole(`${logPrefix} 测试成功 (${duration}ms): ${responseText}`, 'success');
                                            clearAndResolve(true);
                                        } else {
                                            logToConsole(`${logPrefix} 测试失败: 响应内容无效 - ${responseText}`, 'error');
                                            clearAndResolve(false);
                                        }
                                    })
                                    .catch(error => {
                                        const duration = Date.now() - providerTestStart;
                                        logToConsole(`${logPrefix} 测试失败 (${duration}ms): ${error.message}`, 'error');
                                        clearAndResolve(false);
                                    });
                            } else {
                                // 标准API测试 - 使用最小化请求
                                provider.call(testPrompt)
                                    .then(responseText => {
                                        const duration = Date.now() - providerTestStart;
                                        
                                        // 检查响应是否包含'OK'（不区分大小写）
                                        if (responseText && responseText.toUpperCase().includes('OK')) {
                                            logToConsole(`${logPrefix} 测试成功 (${duration}ms): ${responseText}`, 'success');
                                            clearAndResolve(true);
                                        } else {
                                            logToConsole(`${logPrefix} 测试失败: 响应内容无效 - ${responseText}`, 'error');
                                            clearAndResolve(false);
                                        }
                                    })
                                    .catch(error => {
                                        const duration = Date.now() - providerTestStart;
                                        logToConsole(`${logPrefix} 测试失败 (${duration}ms): ${error.message}`, 'error');
                                        clearAndResolve(false);
                                    });
                            }
                        } catch (error) {
                            logToConsole(`${logPrefix} 测试初始化失败: ${error.message}`, 'error');
                            clearAndResolve(false);
                        }
                    });
                });
                
                // 所有测试完成后恢复按钮状态
                Promise.all(testPromises)
                    .then(results => {
                        clearTimeout(globalTimeout);
                        const totalDuration = Date.now() - testStartTime;
                        const successCount = results.filter(Boolean).length;
                        
                        logToConsole(`测试完成! 成功: ${successCount}/${AI_PROVIDERS.length} (${totalDuration}ms)`, 
                                     successCount === AI_PROVIDERS.length ? 'success' : 'info');
                    })
                    .catch(error => {
                        logToConsole(`测试错误: ${error.message}`, 'error');
                    })
                    .finally(() => {
                        debugBtn.disabled = false;
                        debugBtn.textContent = originalText;
                    });
            });

    
            // 通用AI调用函数
            async function callAI(prompt, title, content, existingTags, maxRetries = 2) {
                for (const provider of AI_PROVIDERS) {
                    let retryCount = 0;
                    
                    while (retryCount <= maxRetries) {
                        try {
                            logToConsole(`尝试 ${provider.name} API (尝试 ${retryCount + 1}/${maxRetries + 1})`, 'request');
                            const startTime = Date.now();
                            
                            let result;
                            if (provider.isAdvanced) {
                                result = await provider.call(prompt, title, content, existingTags);
                            } else {
                                result = await provider.call(prompt);
                            }
                            
                            const duration = Date.now() - startTime;
                            logToConsole(`${provider.name} 调用成功 (${duration}ms)`, 'success');
                            
                            return {
                                provider: provider.name,
                                content: result
                            };
                        } catch (error) {
                            logToConsole(`${provider.name} 调用失败: ${error.message}`, 'error');
                            retryCount++;
                            
                            if (retryCount <= maxRetries) {
                                const delay = 2000; // 2秒后重试
                                logToConsole(`等待 ${delay/1000}秒后重试 ${provider.name}...`, 'warning');
                                await new Promise(resolve => setTimeout(resolve, delay));
                            }
                        }
                    }
                    
                    logToConsole(`${provider.name} 所有尝试均失败，尝试下一个提供者`, 'warning');
                }
                
                throw new Error('所有API提供者均失败');
            }
            
            // 处理标签结果
            function processTags(tagsStr) {
                // 清理标签字符串
                tagsStr = tagsStr
                    .replace(/^(标签|tags):?\s*/i, '')
                    .replace(/[^\p{L}\p{N},，\s-]/gu, '');
                
                // 分割标签并确保唯一性
                const tags = [...new Set(
                    tagsStr.split(/[,，]/)
                        .map(tag => tag.trim())
                        .filter(tag => tag.length > 0)
                )];
                
                // 应用标签数量限制
                if (tags.length < MIN_TAGS) {
                    logToConsole(`警告: 生成的标签数量(${tags.length})少于最小要求(${MIN_TAGS})`, 'warning');
                }
                
                return tags.slice(0, MAX_TAGS);
            }
            
            // 标签生成函数
            async function generateTags(title, content) {
                // 1. 准备提示词
                const cleanContent = content.replace(/\n/g, '').substring(0, 3000);
                const prompt = `请从以下文章的标题和内容中提取${MIN_TAGS}-${MAX_TAGS}个最相关的标签。
要求：
1. 只返回逗号分隔的标签列表
2. 不要任何解释性文字
3. 标签可以是中文或英文
4. 标签应简短明确（最多4个汉字或2个英文单词）
5. 标签应具有代表性和区分度
6. 必须严格遵守上述的所有要求

标题：${title}
内容：${cleanContent}`;

                // 2. 获取全站标签库
                const existingTags = await fetchAllExistingTags();
                
                // 3. 调用AI API
                const aiResponse = await callAI(prompt, title, content, existingTags);
                
                // 4. 解析标签结果
                return processTags(aiResponse.content);
            }
            
            // 获取标签函数
            function fetchAllExistingTags() {
                try {
                    logToConsole('正在获取全站标签库...', 'info');
                    const tags = ALL_EXISTING_TAGS;
                    logToConsole(`获取标签库成功，共${tags.length}个标签`, 'success');
                    return tags;
                } catch (error) {
                    logToConsole(`获取标签库失败: ${error.message}`, 'error');
                    return [];
                }
            }
            
            // 更新Typecho标签UI的函数
            function updateTypechoTagUI(newTag) {
                // 1. 获取token-input列表元素
                const tokenList = document.querySelector('.token-input-list');
                if (!tokenList) {
                    logToConsole('找不到token-input列表元素', 'warning');
                    return;
                }
                
                // 2. 检查是否已存在该标签
                const existingTags = Array.from(tokenList.querySelectorAll('.token-input-token p'))
                    .map(p => p.textContent.trim());
                
                if (existingTags.includes(newTag)) {
                    logToConsole(`标签已存在: ${newTag}`, 'warning');
                    return;
                }
                
                // 3. 创建新的token元素
                const newToken = document.createElement('li');
                newToken.className = 'token-input-token';
                newToken.innerHTML = `<p>${newTag}</p><span class="token-input-delete-token">×</span>`;
                
                // 4. 找到输入框元素并插入到它前面
                const inputToken = tokenList.querySelector('.token-input-input-token');
                if (inputToken) {
                    tokenList.insertBefore(newToken, inputToken);
                } else {
                    tokenList.appendChild(newToken);
                }
                
                // 5. 添加删除事件处理
                const deleteBtn = newToken.querySelector('.token-input-delete-token');
                deleteBtn.addEventListener('click', function() {
                    newToken.remove();
                    
                    // 更新隐藏输入框的值
                    const hiddenInput = document.getElementById('xinautotags-tag-input');
                    if (hiddenInput) {
                        const tags = hiddenInput.value.split(',').map(t => t.trim()).filter(t => t !== newTag);
                        hiddenInput.value = tags.join(',');
                    }
                });
            }
            
            // 按钮点击处理
            btn.addEventListener('click', async function() {
                try {
                    // 重置UI状态
                    btn.disabled = true;
                    btn.textContent = '处理中...';
                    logToConsole('开始标签提取流程', 'info');
                    logToConsole(`标签数量要求: 最少 ${MIN_TAGS} 个，最多 ${MAX_TAGS} 个`, 'info');
                    
                    // 1. 获取必要元素
                    let titleEl = document.querySelector('input[name="title"]') || document.getElementById('title');
                    let contentEl = document.querySelector('textarea[name="text"]') || document.getElementById('text');
                    let tagInput = document.getElementById('xinautotags-tag-input');
                    
                    if (!tagInput) {
                        const tagInputFallback = document.querySelector('input[name="tags"]');
                        if (tagInputFallback) {
                            tagInputFallback.id = 'xinautotags-tag-input';
                            tagInput = document.getElementById('xinautotags-tag-input');
                        }
                    }
                    
                    // 检查元素是否存在
                    if (!titleEl) throw new Error('找不到标题输入框');
                    if (!contentEl) throw new Error('找不到内容输入框');
                    if (!tagInput) throw new Error('找不到标签输入框');
                    
                    // 2. 获取值
                    const title = titleEl.value.trim();
                    const content = contentEl.value.trim();
                    
                    // 3. 验证数据
                    if (!title) throw new Error('标题不能为空');
                    
                    // 4. 添加日志
                    logToConsole(`标题: "${title.substring(0, 30)}${title.length > 30 ? '...' : ''}"`, 'info');
                    logToConsole(`内容长度: ${content.length} 字符`, 'info');
                    if (!content) logToConsole('警告: 内容为空，将仅基于标题提取标签', 'warning');
                    
                    // 5. 调用API生成标签
                    logToConsole('正在调用AI API...', 'request');
                    const startTime = Date.now();
                    
                    const tags = await generateTags(title, content);
                    
                    const duration = Date.now() - startTime;
                    logToConsole(`API调用完成 (${duration}ms)`, 'response');
                    
                    if (tags && tags.length > 0) {
                        const tagsStr = tags.join(',');
                        logToConsole(`提取成功! 标签: ${tagsStr}`, 'success');
                        
                        // 创建结果容器
                        const resultContainer = document.createElement('div');
                        resultContainer.style.marginTop = '15px';
                        resultContainer.style.padding = '15px';
                        resultContainer.style.backgroundColor = '#e3f2fd';
                        resultContainer.style.borderRadius = '5px';
                        
                        // 获取当前标签值
                        const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                        
                        resultContainer.innerHTML = `
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
                                <h4 style="margin:0; color:#2196F3">AI建议标签</h4>
                                <span style="font-size:12px; color:#666">点击标签添加</span>
                            </div>
                            <div id="xinautotags-result" style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:15px">
                                ${tags.map(tag => {
                                    const existsInLibrary = ALL_EXISTING_TAGS.includes(tag);
                                    const existsInInput = currentTags.includes(tag);
                                    
                                    let tagClass = 'xinautotags-tag-new';  // 修改为 new 类
                                    let titleText = '点击添加';
                                    
                                    if (existsInLibrary) {
                                        tagClass = 'xinautotags-tag-exists';  // 已存在标签
                                        titleText = '标签库中已存在';
                                    }
                                    
                                    if (existsInInput) {
                                        tagClass = 'xinautotags-tag-added';  // 已添加标签
                                        titleText = '已添加';
                                    }
                                    
                                    return `<span class="${tagClass}" data-tag="${tag}" title="${titleText}" style="cursor:pointer; padding:3px 8px; border-radius:3px">${tag}</span>`;
                                }).join('')}
                            </div>
                            <div style="display:flex; gap:10px">
                                <button class="btn btn-xs primary" id="xinautotags-apply-all" style="background:#2196F3; border-color:#1976D2">全部应用</button>
                                <button class="btn btn-xs" id="xinautotags-cancel">关闭结果</button>
                            </div>
                        `;
                        
                        // 插入结果容器
                        if (!document.getElementById('xinautotags-result-container')) {
                            container.appendChild(resultContainer);
                            resultContainer.id = 'xinautotags-result-container';
                        } else {
                            document.getElementById('xinautotags-result-container').innerHTML = resultContainer.innerHTML;
                        }
                        
                        // 添加标签点击事件
                        document.querySelectorAll('.xinautotags-tag-new:not(.xinautotags-tag-added), .xinautotags-tag-exists:not(.xinautotags-tag-added)').forEach(tagEl => {
                            tagEl.addEventListener('click', function() {
                                const newTag = this.getAttribute('data-tag');
                                const tagInput = document.getElementById('xinautotags-tag-input');
                                if (!tagInput) return;
                                
                                const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                                
                                if (!currentTags.includes(newTag)) {
                                    // 更新输入框值
                                    tagInput.value = currentTags.length > 0 
                                        ? currentTags.join(',') + ',' + newTag 
                                        : newTag;
                                    
                                    // 更新Typecho标签UI
                                    updateTypechoTagUI(newTag);
                                    
                                    // 更新UI状态
                                    this.classList.remove('xinautotags-tag', 'xinautotags-tag-exists');
                                    this.classList.add('xinautotags-tag-added');
                                    this.title = '已添加';
                                    this.style.cursor = 'default';
                                    
                                    logToConsole(`已添加标签: ${newTag}`, 'success');
                                } else {
                                    logToConsole(`标签已存在: ${newTag}`, 'warning');
                                }
                            });
                        });
                        
                        // 添加全部应用事件
                        document.getElementById('xinautotags-apply-all').addEventListener('click', function() {
                            const tagInput = document.getElementById('xinautotags-tag-input');
                            if (!tagInput) return;
                            
                            const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                            
                            tags.forEach(tag => {
                                if (!currentTags.includes(tag)) {
                                    currentTags.push(tag);
                                    // 更新Typecho标签UI
                                    updateTypechoTagUI(tag);
                                }
                            });
                            
                            tagInput.value = currentTags.join(',');
                            const event = new Event('input', { bubbles: true });
                            tagInput.dispatchEvent(event);
                            
                            document.querySelectorAll('[data-tag]').forEach(tagEl => {
                                const tagValue = tagEl.getAttribute('data-tag');
                                if (currentTags.includes(tagValue)) {
                                    tagEl.classList.remove('xinautotags-tag', 'xinautotags-tag-exists');
                                    tagEl.classList.add('xinautotags-tag-added');
                                    tagEl.title = '已添加';
                                    tagEl.style.cursor = 'default';
                                }
                            });
                            
                            logToConsole('所有标签已应用到输入框', 'success');
                        });
                        
                        // 添加取消按钮事件
                        document.getElementById('xinautotags-cancel').addEventListener('click', function() {
                            document.getElementById('xinautotags-result-container').style.display = 'none';
                        });
                    } else {
                        logToConsole('标签提取失败，请重试', 'error');
                    }
                    
                } catch (error) {
                    logToConsole(`处理失败: ${error.message}`, 'error');
                    console.error('处理错误:', error);
                } finally {
                    btn.disabled = false;
                    btn.textContent = '开始提取标签';
                    logToConsole('处理流程结束', 'info');
                }
            });
            // 监听原生标签删除事件
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('token-input-delete-token')) {
                    const tokenElement = e.target.closest('.token-input-token');
                    if (tokenElement) {
                        const deletedTag = tokenElement.querySelector('p').textContent.trim();
                        
                        // 在插件标签建议区域中查找对应的标签元素
                        const tagElements = document.querySelectorAll('#xinautotags-result [data-tag]');
                        tagElements.forEach(tagEl => {
                            if (tagEl.getAttribute('data-tag') === deletedTag) {
                                // 检查该标签是否存在于全站标签库
                                const existsInLibrary = ALL_EXISTING_TAGS.includes(deletedTag);
                                
                                // 更新样式类和标题
                                tagEl.className = existsInLibrary ? 
                                    'xinautotags-tag-exists' : 
                                    'xinautotags-tag-new';
                                    
                                tagEl.title = existsInLibrary ? 
                                    '标签库中已存在' : 
                                    '点击添加';
                                    
                                tagEl.style.cursor = 'pointer';
                                
                                // 添加日志以便调试
                                logToConsole(`标签删除检测: ${deletedTag} 状态已更新`, 'debug');
                            }
                        });
                    }
                }
            });
        });
        </script>
        <style>
        #xinautotags-container {
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 3px solid #2196F3; 
        }
        #xinautotags-console {
            font-size: 12px;
            line-height: 1.4;
        }
        #xinautotags-console div {
            padding: 2px 0;
        }
        #xinautotags-console::-webkit-scrollbar {
            width: 8px;
        }
        #xinautotags-console::-webkit-scrollbar-track {
            background: #2d2d2d;
        }
        #xinautotags-console::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 4px;
        }
        #xinautotags-console::-webkit-scrollbar-thumb:hover {
            background: #777;
        }
        .xinautotags-tag-new {
            background: #4CAF50;  /* 新标签 - 绿色 */
            color: white;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .xinautotags-tag-new:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .xinautotags-tag-exists {
            background: #FFC107;  /* 已存在标签 - 黄色 */
            color: #333;          /* 深色文字提高可读性 */
            cursor: pointer;
        }
        .xinautotags-tag-exists:hover {
            opacity: 0.9;
        }
        .xinautotags-tag-added {
            background: #9E9E9E;  /* 已添加标签 - 灰色 */
            color: white;
            cursor: default;
        }
        </style>
        <?php
    }
    
    private static function getAllTags()
    {
        $tags = [];
        
        try {
            // 使用 Typecho 自带的数据库连接
            $db = Typecho_Db::get();
            
            // 获取带前缀的表名
            $prefix = $db->getPrefix();
            $table = $prefix . 'metas';
            
            // 使用 Typecho 的查询构建器
            $query = $db->select('name')
                ->from($table)
                ->where('type = ?', 'tag');
            
            $results = $db->fetchAll($query);
            
            foreach ($results as $row) {
                $tags[] = $row['name'];
            }
            
        } catch (Exception $e) {
            // 错误处理
            error_log("XiAutoTags插件错误: " . $e->getMessage());
        }
        
        return $tags;
    }
}