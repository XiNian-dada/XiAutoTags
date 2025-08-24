<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 昔年AI自动标签插件 - 安全增强版
 * 
 * @package XiAutoTags
 * @author XiNian-dada
 * @version 1.2.0
 * @link https://leeinx.com/
 */
class XiAutoTags_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('XiAutoTags_Plugin', 'addManualButton');
        Typecho_Plugin::factory('admin/write-post.php')->content = array('XiAutoTags_Plugin', 'addTagInputId');
        Helper::addAction('xinautotags-tags', 'XiAutoTags_Action');
        return _t('XiAutoTags插件激活成功！');
    }

    public static function deactivate()
    {
        Helper::removeAction('xinautotags-tags');
        return _t('XiAutoTags插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    { 
        // 安全设置分组
        $layout1 = new Typecho_Widget_Helper_Layout();
        $layout1->html(_t('<h3>安全设置</h3><hr>'));
        $form->addItem($layout1);
        
        // 允许的域名设置
        $allowedDomains = new Typecho_Widget_Helper_Form_Element_Textarea(
            'allowed_domains', 
            NULL, 
            '',
            _t('允许访问的域名'),
            _t('设置允许访问API的域名，每行一个或用逗号分隔<br>例如：<br>https://yourdomain.com<br>https://www.yourdomain.com,https://blog.yourdomain.com<br><strong>留空则允许当前域名访问</strong>')
        );
        $form->addInput($allowedDomains);
        
        // 启用IP白名单
        $enableIPWhitelist = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_ip_whitelist', 
            array(
                '0' => _t('关闭'),
                '1' => _t('启用')
            ),
            '0',
            _t('启用IP白名单'),
            _t('启用后只允许白名单内的IP访问API（谨慎使用）')
        );
        $form->addInput($enableIPWhitelist);
        
        // IP白名单
        $ipWhitelist = new Typecho_Widget_Helper_Form_Element_Textarea(
            'ip_whitelist', 
            NULL, 
            "127.0.0.1\n::1",
            _t('IP白名单'),
            _t('允许访问的IP地址，每行一个<br>默认包含本地IP，请谨慎修改')
        );
        $form->addInput($ipWhitelist);
        
        // 频率限制设置
        $rateLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'rate_limit', NULL, '20',
            _t('频率限制 (每10分钟)'), _t('每个IP在10分钟内最多允许的请求次数')
        );
        $form->addInput($rateLimit->addRule('isInteger', _t('请输入整数')));
        
        // 功能设置分组
        $layout2 = new Typecho_Widget_Helper_Layout();
        $layout2->html(_t('<h3>功能设置</h3><hr>'));
        $form->addItem($layout2);
        
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
        
        // 启用缓存
        $enableCache = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_cache', 
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('启用结果缓存'),
            _t('缓存相同内容的标签生成结果，提高响应速度')
        );
        $form->addInput($enableCache);
        
        // 优先使用已有标签
        $useExistingTags = new Typecho_Widget_Helper_Form_Element_Radio(
            'use_existing_tags', 
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('优先使用已有标签'),
            _t('启用后AI会优先从现有标签库中选择，减少重复标签')
        );
        $form->addInput($useExistingTags);
        
        // 已有标签数量限制
        $maxExistingTags = new Typecho_Widget_Helper_Form_Element_Text(
            'max_existing_tags', NULL, '50',
            _t('发送给AI的标签数量'), _t('发送给AI参考的已有标签最大数量（建议20-100）')
        );
        $form->addInput($maxExistingTags->addRule('isInteger', _t('请输入整数')));
        
        // 内容截取长度设置
        $contentLength = new Typecho_Widget_Helper_Form_Element_Text(
            'content_length', NULL, '3000',
            _t('文章内容截取长度'), _t('发送给AI的文章内容最大字符数（默认3000，建议1000-5000）')
        );
        $form->addInput($contentLength->addRule('isInteger', _t('请输入整数')));
        
        // 智能标签匹配
        $smartMatching = new Typecho_Widget_Helper_Form_Element_Radio(
            'smart_matching', 
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('智能标签匹配'),
            _t('启用后会在前端显示标签匹配状态（新建/已有/推荐）')
        );
        $form->addInput($smartMatching);
        
        // API配置分组
        $layout3 = new Typecho_Widget_Helper_Layout();
        $layout3->html(_t('<h3>API配置</h3><hr>'));
        $form->addItem($layout3);
        
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
            'openrouter_api_model', NULL, 'deepseek/deepseek-chat',
            _t('OpenRouter 模型名称'), _t('推荐模型: deepseek/deepseek-chat, qwen/qwen-2.5-72b-instruct')
        );
        $form->addInput($openrouter_api_model);
        
        $openrouter_priority = new Typecho_Widget_Helper_Form_Element_Text(
            'openrouter_priority', NULL, '2',
            _t('OpenRouter 优先级'), _t('数字越小优先级越高 (1 > 2)')
        );
        $form->addInput($openrouter_priority->addRule('isInteger', _t('请输入整数')));
        
        // 自定义API列表
        $custom_apis = new Typecho_Widget_Helper_Form_Element_Textarea(
            'custom_apis', 
            NULL, 
            '',
            _t('自定义API列表'),
            _t('每行一个API，格式：<br><strong>名称|优先级|API密钥|模型|端点URL|启用状态(1/0)</strong><br>示例：<br><code>MyAPI|1|sk-xxxx|gpt-4|https://api.example.com/v1/chat/completions|1</code>')
        );
        $form->addInput($custom_apis);
        
        // 使用说明
        $layout4 = new Typecho_Widget_Helper_Layout();
        $layout4->html(_t('<h3>使用说明</h3><hr>
            <div style="padding: 15px; background: #f9f9f9; border-radius: 5px; margin-bottom: 20px; line-height: 1.6;">
                <p><strong>🚀 快速开始：</strong></p>
                <ol>
                    <li>配置OpenRouter API密钥（推荐）或添加自定义API</li>
                    <li>调整标签生成数量和安全设置</li>
                    <li>保存配置后，在文章编辑页面即可看到AI标签生成器</li>
                    <li>点击"开始生成"按钮自动生成文章标签</li>
                </ol>
                
                <p><strong>🔒 安全特性：</strong></p>
                <ul>
                    <li>多重身份验证：Cookie + 管理员权限 + CSRF防护</li>
                    <li>可配置的域名白名单和IP白名单</li>
                    <li>智能频率限制防止滥用</li>
                    <li>详细的安全日志记录</li>
                </ul>
                
                <p><strong>⚡ 高级功能：</strong></p>
                <ul>
                    <li>智能标签优先：优先使用已有标签库，减少重复标签</li>
                    <li>结果缓存：相同内容缓存结果，提高响应速度</li>
                    <li>多API支持：支持OpenRouter和自定义API，自动故障转移</li>
                    <li>智能匹配显示：标签来源可视化（新建/已有/推荐）</li>
                </ul>
                
                <p><strong>💡 使用建议：</strong></p>
                <ul>
                    <li>建议先使用测试连接功能验证API配置</li>
                    <li>频率限制建议设置为10-30次/10分钟</li>
                    <li>内容截取长度建议1000-5000字符</li>
                    <li>定期检查安全日志，确保系统安全</li>
                </ul>
            </div>'));
        $form->addItem($layout4);
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
        
        // 生成CSRF Token
        $csrfToken = self::generateCSRFToken();
        
        // 获取标签数量设置
        $minTags = intval($options->min_tags ?? 3);
        $maxTags = intval($options->max_tags ?? 5);
        
        // 获取OpenRouter配置
        $openrouter_enabled = $options->openrouter_enabled ?? '1';
        $openrouter_api_key = $options->openrouter_api_key ?? '';
        $openrouter_api_model = $options->openrouter_api_model ?? '';
        
        // 解析自定义API
        $custom_apis = $options->custom_apis ?? '';
        
        // 计算启用的API数量
        $apiCount = 0;
        if ($openrouter_enabled === '1' && !empty($openrouter_api_key) && !empty($openrouter_api_model)) {
            $apiCount++;
        }
        if (!empty($custom_apis)) {
            $lines = explode("\n", $custom_apis);
            foreach ($lines as $line) {
                $parts = explode('|', trim($line));
                if (count($parts) >= 6 && trim($parts[5]) === '1') {
                    $apiCount++;
                }
            }
        }
        
        // 获取功能配置
        $useExistingTags = $options->use_existing_tags ?? '1';
        $smartMatching = $options->smart_matching ?? '1';
        ?>
        <script>
        // 配置常量
        const MIN_TAGS = <?php echo max(1, $minTags); ?>;
        const MAX_TAGS = <?php echo max(max(1, $minTags), $maxTags); ?>;
        const API_COUNT = <?php echo $apiCount; ?>;
        const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
        const ALL_EXISTING_TAGS = <?php echo $jsTags; ?>;
        const USE_EXISTING_TAGS = <?php echo $useExistingTags === '1' ? 'true' : 'false'; ?>;
        const SMART_MATCHING = <?php echo $smartMatching === '1' ? 'true' : 'false'; ?>;
        
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
            title.textContent = 'AI标签生成器 v1.2 (智能增强版)';
            title.style.marginTop = '0';
            title.style.marginBottom = '15px';
            title.style.paddingBottom = '10px';
            title.style.borderBottom = '1px solid #eee';
            title.style.color = '#2196F3'; 
            
            // 显示配置信息
            const configInfo = document.createElement('div');
            configInfo.style.marginBottom = '10px';
            configInfo.style.fontSize = '13px';
            configInfo.style.color = '#666';
            
            let configHTML = `<strong>配置信息:</strong> 标签数量 ${MIN_TAGS}-${MAX_TAGS} 个`;
            
            if (USE_EXISTING_TAGS) {
                configHTML += ` | 已有标签库 ${ALL_EXISTING_TAGS.length} 个`;
            }
            
            if (API_COUNT > 0) {
                configHTML += ` | ${API_COUNT} 个API提供者已启用`;
            } else {
                configHTML += ` | <span style="color:#F44336">无可用API提供者</span>`;
            }
            
            configInfo.innerHTML = configHTML;
            
            // 控制台容器
            const consoleContainer = document.createElement('div');
            consoleContainer.id = 'xinautotags-console';
            consoleContainer.style.height = '200px';
            consoleContainer.style.overflowY = 'auto';
            consoleContainer.style.backgroundColor = '#1e1e1e';
            consoleContainer.style.color = '#d4d4d4';
            consoleContainer.style.fontFamily = 'monospace';
            consoleContainer.style.fontSize = '12px';
            consoleContainer.style.padding = '10px';
            consoleContainer.style.borderRadius = '4px';
            consoleContainer.style.marginBottom = '15px';
            consoleContainer.style.whiteSpace = 'pre-wrap';
            consoleContainer.innerHTML = `<div>[系统] XiAutoTags标签生成器已就绪 v1.2</div><div>[配置] ${USE_EXISTING_TAGS ? '已启用标签库优先' : '仅生成新标签'}</div>`;
            
            // 按钮容器
            const btnContainer = document.createElement('div');
            btnContainer.style.display = 'flex';
            btnContainer.style.gap = '10px';
            btnContainer.style.flexWrap = 'wrap';
            
            // 主按钮
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '开始生成';
            btn.className = 'btn primary';
            btn.style.flex = '1';
            btn.disabled = API_COUNT === 0;
            btn.style.backgroundColor = '#2196F3'; 
            btn.style.borderColor = '#1976D2';
            
            // 清除日志按钮
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.textContent = '清除日志';
            clearBtn.className = 'btn';
            clearBtn.style.flex = 'none';
            
            // 测试连接按钮
            const testBtn = document.createElement('button');
            testBtn.type = 'button';
            testBtn.textContent = '测试连接';
            testBtn.className = 'btn';
            testBtn.style.flex = 'none';
            testBtn.title = '测试API连接和安全配置';
            testBtn.disabled = API_COUNT === 0;
            
            // 构建UI
            container.appendChild(title);
            container.appendChild(configInfo);
            container.appendChild(consoleContainer);
            btnContainer.appendChild(btn);
            btnContainer.appendChild(clearBtn);
            btnContainer.appendChild(testBtn);
            container.appendChild(btnContainer);
            
            // 找到标签输入框的父元素并插入
            const tagInput = document.querySelector('input[name=tags]');
            if (tagInput && tagInput.parentNode) {
                tagInput.parentNode.appendChild(container);
            } else {
                const form = document.querySelector('form');
                if (form) form.appendChild(container);
            }
    
            // 日志记录函数
            function logToConsole(message, type = 'info') {
                const consoleEl = document.getElementById('xinautotags-console');
                const now = new Date();
                const timestamp = `[${now.toLocaleTimeString()}]`;
                
                const colors = {
                    info: '#d4d4d4',
                    success: '#4CAF50',
                    error: '#F44336',
                    warning: '#FFC107',
                    request: '#64B5F6',
                    response: '#BA68C8',
                    debug: '#FF9800'
                };
                
                const color = colors[type] || colors.info;
                
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
    
            // 获取API URL
            function getApiUrl() {
                let baseUrl = window.location.origin;
                const pathname = window.location.pathname;
                const pathParts = pathname.split('/');
                
                for (let i = 0; i < pathParts.length; i++) {
                    if (pathParts[i] === 'admin') {
                        pathParts.splice(i);
                        break;
                    }
                }
                
                baseUrl += pathParts.join('/');
                if (baseUrl.endsWith('/')) {
                    baseUrl = baseUrl.slice(0, -1);
                }
                
                const apiUrl = baseUrl + '/usr/plugins/XiAutoTags/api.php';
                logToConsole(`API端点: ${apiUrl}`, 'debug');
                return apiUrl;
            }

            // 安全的API调用函数
            async function callSecureAPI(title, content, isTest = false, maxRetries = 2) {
                const apiUrl = getApiUrl();
                
                for (let retryCount = 0; retryCount <= maxRetries; retryCount++) {
                    try {
                        if (isTest) {
                            logToConsole(`测试API连接 (尝试 ${retryCount + 1}/${maxRetries + 1})`, 'request');
                        } else {
                            logToConsole(`调用标签生成API (尝试 ${retryCount + 1}/${maxRetries + 1})`, 'request');
                        }
                        
                        const startTime = Date.now();
                        
                        const formData = new FormData();
                        formData.append('title', title);
                        formData.append('content', content);
                        formData.append('csrf_token', CSRF_TOKEN);
                        if (isTest) {
                            formData.append('test_mode', '1');
                        }
                        
                        const response = await fetch(apiUrl, {
                            method: 'POST',
                            body: formData,
                            credentials: 'include',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        
                        const duration = Date.now() - startTime;
                        
                        if (!response.ok) {
                            const errorText = await response.text();
                            logToConsole(`HTTP错误 ${response.status}: ${response.statusText}`, 'error');
                            throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
                        }
                        
                        const result = await response.json();
                        
                        if (result.error) {
                            throw new Error(result.error + (result.code ? ` (错误码: ${result.code})` : ''));
                        }
                        
                        if (result.success && result.data) {
                            const provider = result.data.provider || 'Unknown';
                            logToConsole(`API调用成功 (${duration}ms) - 提供者: ${provider}`, 'success');
                            
                            if (result.data.has_existing_tags && result.data.existing_tags_count) {
                                logToConsole(`已有标签库: ${result.data.existing_tags_count} 个标签参与生成`, 'info');
                            }
                            
                            return result.data;
                        } else {
                            throw new Error('API响应格式无效: ' + JSON.stringify(result).substring(0, 100));
                        }
                        
                    } catch (error) {
                        logToConsole(`API调用失败: ${error.message}`, 'error');
                        
                        if (retryCount < maxRetries) {
                            const delay = 2000 + (retryCount * 1000);
                            logToConsole(`等待 ${delay/1000}秒后重试...`, 'warning');
                            await new Promise(resolve => setTimeout(resolve, delay));
                        }
                    }
                }
                
                throw new Error('所有API调用尝试均失败');
            }
            
            // 测试连接
            testBtn.addEventListener('click', function() {
                logToConsole('开始API连接测试...', 'debug');
                
                testBtn.disabled = true;
                const originalText = testBtn.textContent;
                testBtn.textContent = '测试中...';
                
                const testData = {
                    title: 'API连接测试',
                    content: '这是一个API连接和安全配置测试，请生成几个测试标签。测试内容包括：身份验证、CORS配置、频率限制、已有标签优先选择等功能。'
                };
                
                callSecureAPI(testData.title, testData.content, true)
                .then(result => {
                    logToConsole(`✓ 连接测试成功`, 'success');
                    logToConsole(`✓ 安全验证通过`, 'success');
                    logToConsole(`✓ API提供者: ${result.provider}`, 'success');
                    if (result.content) {
                        logToConsole(`✓ 响应预览: ${result.content.substring(0, 50)}...`, 'info');
                    }
                    if (result.has_existing_tags) {
                        logToConsole(`✓ 已有标签优先功能正常`, 'success');
                    }
                })
                .catch(error => {
                    logToConsole(`✗ 连接测试失败: ${error.message}`, 'error');
                    logToConsole(`请检查：1.API配置 2.网络连接 3.安全设置`, 'warning');
                })
                .finally(() => {
                    testBtn.disabled = API_COUNT === 0;
                    testBtn.textContent = originalText;
                });
            });
            
            // 处理标签结果
            function processTags(tagsStr) {
                tagsStr = tagsStr
                    .replace(/^(标签|tags):?\s*/i, '')
                    .replace(/[^\p{L}\p{N},，\s-]/gu, '');
                
                const tags = [...new Set(
                    tagsStr.split(/[,，]/)
                        .map(tag => tag.trim())
                        .filter(tag => tag.length > 0 && tag.length <= 20)
                )];
                
                if (tags.length < MIN_TAGS) {
                    logToConsole(`警告: 生成的标签数量(${tags.length})少于最小要求(${MIN_TAGS})`, 'warning');
                }
                
                return tags.slice(0, MAX_TAGS);
            }
            
            // 更新Typecho标签UI
            function updateTypechoTagUI(newTag) {
                const tokenList = document.querySelector('.token-input-list');
                if (!tokenList) return;
                
                const existingTags = Array.from(tokenList.querySelectorAll('.token-input-token p'))
                    .map(p => p.textContent.trim());
                
                if (existingTags.includes(newTag)) {
                    logToConsole(`标签已存在: ${newTag}`, 'warning');
                    return;
                }
                
                const newToken = document.createElement('li');
                newToken.className = 'token-input-token';
                newToken.innerHTML = `<p>${newTag}</p><span class="token-input-delete-token">×</span>`;
                
                const inputToken = tokenList.querySelector('.token-input-input-token');
                if (inputToken) {
                    tokenList.insertBefore(newToken, inputToken);
                } else {
                    tokenList.appendChild(newToken);
                }
                
                const deleteBtn = newToken.querySelector('.token-input-delete-token');
                deleteBtn.addEventListener('click', function() {
                    newToken.remove();
                    const hiddenInput = document.getElementById('xinautotags-tag-input');
                    if (hiddenInput) {
                        const tags = hiddenInput.value.split(',').map(t => t.trim()).filter(t => t !== newTag);
                        hiddenInput.value = tags.join(',');
                    }
                });
            }
            
            // 分析标签状态
            function analyzeTagStatus(tags) {
                let fromLibrary = 0;
                let newTags = 0;
                
                tags.forEach(tag => {
                    if (ALL_EXISTING_TAGS.includes(tag)) {
                        fromLibrary++;
                    } else {
                        newTags++;
                    }
                });
                
                return { fromLibrary, newTags };
            }
            
            // 生成标签图例
            function generateTagLegend() {
                return `
                    <div style="margin-bottom:10px; padding:8px; background:#f5f5f5; border-radius:4px; font-size:12px">
                        <span style="margin-right:15px">
                            <span class="xinautotags-legend-existing" style="display:inline-block; width:12px; height:12px; background:#FF9800; border-radius:2px; margin-right:5px"></span>
                            来自标签库
                        </span>
                        <span style="margin-right:15px">
                            <span class="xinautotags-legend-new" style="display:inline-block; width:12px; height:12px; background:#4CAF50; border-radius:2px; margin-right:5px"></span>
                            AI新建
                        </span>
                        <span>
                            <span class="xinautotags-legend-added" style="display:inline-block; width:12px; height:12px; background:#9E9E9E; border-radius:2px; margin-right:5px"></span>
                            已添加
                        </span>
                    </div>
                `;
            }
            
            // 生成单个标签元素
            function generateTagElement(tag, currentTags) {
                const existsInLibrary = ALL_EXISTING_TAGS.includes(tag);
                const existsInInput = currentTags.includes(tag);
                
                let tagClass, titleText, icon;
                
                if (existsInInput) {
                    tagClass = 'xinautotags-tag-added';
                    titleText = '已添加到文章';
                    icon = '✓';
                } else if (existsInLibrary) {
                    tagClass = 'xinautotags-tag-exists';
                    titleText = '来自标签库 - 点击添加';
                    icon = '📚';
                } else {
                    tagClass = 'xinautotags-tag-new';
                    titleText = 'AI新建标签 - 点击添加';
                    icon = '✨';
                }
                
                return `
                    <span class="${tagClass}" data-tag="${tag}" title="${titleText}" 
                          style="cursor:${existsInInput ? 'default' : 'pointer'}; 
                                 padding:6px 12px; border-radius:4px; 
                                 font-size:13px; font-weight:500; position:relative">
                        <span style="margin-right:5px">${icon}</span>${tag}
                    </span>
                `;
            }
            
            // 显示标签结果 - 增强版
            function showTagResults(tags, tagInput, hasExistingTags = false) {
                // 移除旧的结果容器
                const oldContainer = document.getElementById('xinautotags-result-container');
                if (oldContainer) {
                    oldContainer.remove();
                }
                
                const resultContainer = document.createElement('div');
                resultContainer.id = 'xinautotags-result-container';
                resultContainer.style.marginTop = '15px';
                resultContainer.style.padding = '15px';
                resultContainer.style.backgroundColor = '#e3f2fd';
                resultContainer.style.borderRadius = '5px';
                resultContainer.style.border = '1px solid #2196F3';
                
                const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                
                // 分析标签状态
                const tagAnalysis = analyzeTagStatus(tags);
                
                let headerText = 'AI生成的标签';
                if (hasExistingTags && USE_EXISTING_TAGS) {
                    const fromLibrary = tagAnalysis.fromLibrary;
                    const newTags = tagAnalysis.newTags;
                    headerText += ` (${fromLibrary}个来自标签库, ${newTags}个新建)`;
                }
                
                resultContainer.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
                        <h4 style="margin:0; color:#2196F3">${headerText}</h4>
                        <span style="font-size:12px; color:#666">${tags.length} 个标签 - 点击添加</span>
                    </div>
                    ${(hasExistingTags && USE_EXISTING_TAGS && SMART_MATCHING) ? generateTagLegend() : ''}
                    <div id="xinautotags-result" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:15px">
                        ${tags.map(tag => generateTagElement(tag, currentTags)).join('')}
                    </div>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                        <button class="btn btn-xs primary" id="xinautotags-apply-all" 
                                style="background:#2196F3; border-color:#1976D2">全部添加</button>
                        ${(hasExistingTags && USE_EXISTING_TAGS) ? `
                        <button class="btn btn-xs" id="xinautotags-apply-new" 
                                style="background:#4CAF50; border-color:#45a049; color:white">仅添加新标签</button>
                        <button class="btn btn-xs" id="xinautotags-apply-existing" 
                                style="background:#FF9800; border-color:#f57c00; color:white">仅添加已有标签</button>
                        ` : ''}
                        <button class="btn btn-xs" id="xinautotags-clear-results">关闭</button>
                        <span style="font-size:12px; color:#666; margin-left:auto">
                            ${tags.filter(tag => !currentTags.includes(tag)).length} 个可添加
                        </span>
                    </div>
                `;
                
                container.appendChild(resultContainer);
                
                // 绑定事件
                bindTagResultEvents(tags, tagInput);
            }
            
            // 绑定标签结果事件
            function bindTagResultEvents(tags, tagInput) {
                // 单个标签点击
                document.querySelectorAll('#xinautotags-result [data-tag]:not(.xinautotags-tag-added)').forEach(tagEl => {
                    tagEl.addEventListener('click', function() {
                        addSingleTag(this.getAttribute('data-tag'), tagInput);
                    });
                });
                
                // 全部添加
                document.getElementById('xinautotags-apply-all').addEventListener('click', function() {
                    addAllTags(tags, tagInput);
                });
                
                // 仅添加新标签
                const newTagBtn = document.getElementById('xinautotags-apply-new');
                if (newTagBtn) {
                    newTagBtn.addEventListener('click', function() {
                        const newTags = tags.filter(tag => !ALL_EXISTING_TAGS.includes(tag));
                        addSelectedTags(newTags, tagInput, '新建标签');
                    });
                }
                
                // 仅添加已有标签
                const existingTagBtn = document.getElementById('xinautotags-apply-existing');
                if (existingTagBtn) {
                    existingTagBtn.addEventListener('click', function() {
                        const existingTags = tags.filter(tag => ALL_EXISTING_TAGS.includes(tag));
                        addSelectedTags(existingTags, tagInput, '已有标签');
                    });
                }
                
                // 关闭结果
                document.getElementById('xinautotags-clear-results').addEventListener('click', function() {
                    document.getElementById('xinautotags-result-container').remove();
                });
            }
            
            // 添加选定的标签
            function addSelectedTags(tagsToAdd, tagInput, type) {
                const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                let addedCount = 0;
                
                tagsToAdd.forEach(tag => {
                    if (!currentTags.includes(tag)) {
                        currentTags.push(tag);
                        updateTypechoTagUI(tag);
                        addedCount++;
                    }
                });
                
                if (addedCount > 0) {
                    tagInput.value = currentTags.join(',');
                    
                    // 更新UI状态
                    tagsToAdd.forEach(tag => {
                        const tagEl = document.querySelector(`[data-tag="${tag}"]`);
                        if (tagEl && !tagEl.classList.contains('xinautotags-tag-added')) {
                            tagEl.classList.remove('xinautotags-tag-new', 'xinautotags-tag-exists');
                            tagEl.classList.add('xinautotags-tag-added');
                            tagEl.title = '已添加到文章';
                            tagEl.style.cursor = 'default';
                            tagEl.innerHTML = '<span style="margin-right:5px">✓</span>' + tag;
                        }
                    });
                    
                    logToConsole(`✓ 已添加 ${addedCount} 个${type}`, 'success');
                    updateAddableCount();
                } else {
                    logToConsole(`所有${type}都已存在`, 'warning');
                }
            }
            
            // 添加单个标签
            function addSingleTag(newTag, tagInput) {
                const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                
                if (!currentTags.includes(newTag)) {
                    tagInput.value = currentTags.length > 0 
                        ? currentTags.join(',') + ',' + newTag 
                        : newTag;
                    
                    updateTypechoTagUI(newTag);
                    
                    // 更新UI状态
                    const tagEl = document.querySelector(`[data-tag="${newTag}"]`);
                    if (tagEl) {
                        tagEl.classList.remove('xinautotags-tag-new', 'xinautotags-tag-exists');
                        tagEl.classList.add('xinautotags-tag-added');
                        tagEl.title = '已添加到文章';
                        tagEl.style.cursor = 'default';
                        tagEl.innerHTML = '<span style="margin-right:5px">✓</span>' + newTag;
                    }
                    
                    logToConsole(`✓ 已添加标签: ${newTag}`, 'success');
                    
                    // 更新计数
                    updateAddableCount();
                } else {
                    logToConsole(`标签已存在: ${newTag}`, 'warning');
                }
            }
            
            // 添加所有标签
            function addAllTags(tags, tagInput) {
                const currentTags = tagInput.value ? tagInput.value.split(',').map(t => t.trim()) : [];
                let addedCount = 0;
                
                tags.forEach(tag => {
                    if (!currentTags.includes(tag)) {
                        currentTags.push(tag);
                        updateTypechoTagUI(tag);
                        addedCount++;
                    }
                });
                
                if (addedCount > 0) {
                    tagInput.value = currentTags.join(',');
                    
                    // 更新所有标签UI状态
                    document.querySelectorAll('#xinautotags-result [data-tag]').forEach(tagEl => {
                        const tagValue = tagEl.getAttribute('data-tag');
                        if (currentTags.includes(tagValue)) {
                            tagEl.classList.remove('xinautotags-tag-new', 'xinautotags-tag-exists');
                            tagEl.classList.add('xinautotags-tag-added');
                            tagEl.title = '已添加到文章';
                            tagEl.style.cursor = 'default';
                            tagEl.innerHTML = '<span style="margin-right:5px">✓</span>' + tagValue;
                        }
                    });
                    
                    logToConsole(`✓ 已添加 ${addedCount} 个标签`, 'success');
                    updateAddableCount();
                } else {
                    logToConsole('所有标签都已存在', 'warning');
                }
            }
            
            // 更新可添加标签计数
            function updateAddableCount() {
                const countEl = document.querySelector('#xinautotags-result-container span[style*="margin-left:auto"]');
                if (countEl) {
                    const addableCount = document.querySelectorAll('#xinautotags-result [data-tag]:not(.xinautotags-tag-added)').length;
                    countEl.textContent = `${addableCount} 个可添加`;
                }
            }
            
            // 主生成按钮处理
            btn.addEventListener('click', async function() {
                try {
                    btn.disabled = true;
                    btn.textContent = '生成中...';
                    logToConsole('开始AI标签生成流程', 'info');
                    
                    // 获取页面元素
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
                    
                    // 验证元素
                    if (!titleEl) throw new Error('找不到标题输入框');
                    if (!contentEl) throw new Error('找不到内容输入框');
                    if (!tagInput) throw new Error('找不到标签输入框');
                    
                    // 获取内容
                    const title = titleEl.value.trim();
                    const content = contentEl.value.trim();
                    
                    if (!title) throw new Error('标题不能为空');
                    
                    logToConsole(`标题: "${title.substring(0, 30)}${title.length > 30 ? '...' : ''}"`, 'info');
                    logToConsole(`内容长度: ${content.length} 字符`, 'info');
                    if (!content) logToConsole('提示: 建议填写文章内容以获得更准确的标签', 'warning');
                    
                    if (USE_EXISTING_TAGS) {
                        logToConsole(`标签库模式: 优先选择已有标签 (${ALL_EXISTING_TAGS.length}个可选)`, 'info');
                    }
                    
                    // 调用安全API
                    const result = await callSecureAPI(title, content);
                    
                    if (result && result.content) {
                        const tags = processTags(result.content);
                        
                        if (tags && tags.length > 0) {
                            const tagsStr = tags.join(',');
                            logToConsole(`✓ 标签生成成功: ${tagsStr}`, 'success');
                            
                            // 显示结果UI - 传递是否使用了已有标签的信息
                            showTagResults(tags, tagInput, result.has_existing_tags);
                        } else {
                            logToConsole('未能解析出有效标签，请重试', 'error');
                        }
                    } else {
                        logToConsole('API返回的结果无效', 'error');
                    }
                    
                } catch (error) {
                    logToConsole(`生成失败: ${error.message}`, 'error');
                    console.error('标签生成错误:', error);
                } finally {
                    btn.disabled = API_COUNT === 0;
                    btn.textContent = '开始生成';
                    logToConsole('标签生成流程结束', 'info');
                }
            });
            
            // 监听标签删除事件，同步更新结果显示
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('token-input-delete-token')) {
                    setTimeout(() => {
                        const resultContainer = document.getElementById('xinautotags-result-container');
                        if (resultContainer) {
                            const deletedTag = e.target.previousElementSibling?.textContent.trim();
                            if (deletedTag) {
                                const tagEl = document.querySelector(`#xinautotags-result [data-tag="${deletedTag}"]`);
                                if (tagEl) {
                                    const existsInLibrary = ALL_EXISTING_TAGS.includes(deletedTag);
                                    tagEl.className = existsInLibrary ? 'xinautotags-tag-exists' : 'xinautotags-tag-new';
                                    tagEl.title = existsInLibrary ? '来自标签库 - 点击添加' : 'AI新建标签 - 点击添加';
                                    tagEl.style.cursor = 'pointer';
                                    
                                    const icon = existsInLibrary ? '📚' : '✨';
                                    tagEl.innerHTML = `<span style="margin-right:5px">${icon}</span>${deletedTag}`;
                                    
                                    // 重新添加点击事件
                                    tagEl.addEventListener('click', function() {
                                        addSingleTag(deletedTag, document.getElementById('xinautotags-tag-input'));
                                    });
                                    
                                    updateAddableCount();
                                    logToConsole(`标签已从结果中恢复: ${deletedTag}`, 'debug');
                                }
                            }
                        }
                    }, 100);
                }
            });
        });
        </script>
        <style>
        #xinautotags-container {
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            border-left: 4px solid #2196F3;
            transition: all 0.3s ease;
        }
        #xinautotags-container:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        #xinautotags-console {
            font-size: 11px;
            line-height: 1.4;
            border: 1px solid #333;
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
        
        /* AI新建标签 - 绿色渐变 */
        .xinautotags-tag-new {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);
            transition: all 0.3s ease;
            border: none;
        }
        .xinautotags-tag-new:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.4);
        }
        .xinautotags-tag-new::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, #66BB6A, #4CAF50);
            border-radius: 6px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .xinautotags-tag-new:hover::before {
            opacity: 1;
        }
        
        /* 原有标签库标签 - 橙色渐变 */
        .xinautotags-tag-exists {
            background: linear-gradient(135deg, #FF9800, #f57c00);
            color: white;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);
            transition: all 0.3s ease;
            border: none;
        }
        .xinautotags-tag-exists:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(255, 152, 0, 0.4);
        }
        .xinautotags-tag-exists::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, #FFB74D, #FF9800);
            border-radius: 6px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .xinautotags-tag-exists:hover::before {
            opacity: 1;
        }
        
        /* 已添加标签 - 灰色 */
        .xinautotags-tag-added {
            background: linear-gradient(135deg, #9E9E9E, #757575);
            color: white;
            cursor: default;
            box-shadow: 0 1px 3px rgba(158, 158, 158, 0.3);
            border: none;
        }
        
        /* 结果容器增强动画 */
        #xinautotags-result-container {
            animation: slideIn 0.3s ease-out;
            position: relative;
            overflow: hidden;
        }
        #xinautotags-result-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(33, 150, 243, 0.1), transparent);
            transition: left 0.5s ease;
        }
        #xinautotags-result-container:hover::before {
            left: 100%;
        }
        
        /* 按钮组样式 */
        #xinautotags-result-container button {
            transition: all 0.3s ease;
            border-radius: 4px;
            font-weight: 500;
            text-shadow: none;
        }
        #xinautotags-result-container button:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        /* 特殊按钮样式 */
        #xinautotags-apply-new {
            background: linear-gradient(135deg, #4CAF50, #45a049) !important;
            border-color: #45a049 !important;
            color: white !important;
        }
        #xinautotags-apply-existing {
            background: linear-gradient(135deg, #FF9800, #f57c00) !important;
            border-color: #f57c00 !important;
            color: white !important;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            #xinautotags-result {
                flex-direction: column;
            }
            
            #xinautotags-result [data-tag] {
                width: 100%;
                text-align: center;
                margin-bottom: 5px;
            }
            
            #xinautotags-result-container > div:last-child {
                flex-direction: column;
                gap: 8px;
            }
            
            #xinautotags-result-container button {
                width: 100%;
                margin: 0;
            }
        }
        
        /* 工具提示增强 */
        [data-tag] {
            position: relative;
        }
        [data-tag]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: -35px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(-50%) translateY(5px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        </style>
        <?php
    }
    
    /**
     * 生成CSRF Token
     */
    private static function generateCSRFToken()
    {
        if (!session_id()) {
            session_start();
        }
        
        if (!isset($_SESSION['xinautotags_csrf_token'])) {
            $_SESSION['xinautotags_csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['xinautotags_csrf_token'];
    }
    
    /**
     * 验证CSRF Token
     */
    public static function validateCSRFToken($token)
    {
        if (!session_id()) {
            session_start();
        }
        
        return isset($_SESSION['xinautotags_csrf_token']) && 
               hash_equals($_SESSION['xinautotags_csrf_token'], $token);
    }
    
    /**
     * 获取所有标签（带缓存）
     */
    private static function getAllTags()
    {
        $options = Helper::options()->plugin('XiAutoTags');
        $enableCache = $options->enable_cache ?? '1';
        
        $cacheKey = 'xinautotags_all_tags';
        $cacheTime = 300; // 5分钟缓存
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '_' . md5(__TYPECHO_ROOT_DIR__);
        
        // 如果启用缓存且缓存有效
        if ($enableCache === '1' && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            $cached = file_get_contents($cacheFile);
            if ($cached) {
                $data = json_decode($cached, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        
        $tags = [];
        
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $table = $prefix . 'metas';
            
            $query = $db->select('name')
                ->from($table)
                ->where('type = ?', 'tag')
                ->order('count', Typecho_Db::SORT_DESC)
                ->limit(500); // 限制数量避免性能问题
            
            $results = $db->fetchAll($query);
            
            foreach ($results as $row) {
                $tagName = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
                if (mb_strlen($tagName) <= 30) { // 限制标签长度
                    $tags[] = $tagName;
                }
            }
            
            // 保存到缓存
            if ($enableCache === '1') {
                file_put_contents($cacheFile, json_encode($tags));
            }
            
        } catch (Exception $e) {
            error_log("XiAutoTags获取标签失败: " . $e->getMessage());
        }
        
        return $tags;
    }
}