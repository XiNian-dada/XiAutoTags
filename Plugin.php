<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
require_once dirname(__FILE__) . '/Action.php';

/**
 * XiAutoTags 轻量版
 *
 * @package XiAutoTags
 * @author XiNian-dada
 * @version 2.1.0
 * @link https://leeinx.com/
 */
class XiAutoTags_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('XiAutoTags_Plugin', 'renderButton');
        Helper::addAction('xinautotags', 'XiAutoTags_Action');
        return _t('XiAutoTags 轻量版已启用');
    }

    public static function deactivate()
    {
        Helper::removeAction('xinautotags');
        return _t('XiAutoTags 已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiEndpoint = new Typecho_Widget_Helper_Form_Element_Text(
            'api_endpoint',
            null,
            'https://openrouter.ai/api/v1/chat/completions',
            _t('API 端点'),
            _t('填写完整接口地址，例如 /v1/chat/completions 或 /v1/responses')
        );
        $form->addInput($apiEndpoint);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'api_key',
            null,
            '',
            _t('API Key'),
            _t('必填，例如 sk-xxxx')
        );
        $form->addInput($apiKey);

        $model = new Typecho_Widget_Helper_Form_Element_Text(
            'model',
            null,
            'deepseek/deepseek-chat',
            _t('模型名称'),
            _t('例如 deepseek/deepseek-chat 或 gpt-4o-mini')
        );
        $form->addInput($model);

        $apiInterface = new Typecho_Widget_Helper_Form_Element_Radio(
            'api_interface',
            array(
                'auto' => _t('自动识别'),
                'chat_completions' => _t('Chat Completions'),
                'responses' => _t('Responses')
            ),
            'auto',
            _t('接口类型'),
            _t('默认自动识别：端点包含 /responses 时走 Responses 接口，否则走 Chat Completions')
        );
        $form->addInput($apiInterface);

        $maxTags = new Typecho_Widget_Helper_Form_Element_Text(
            'max_tags',
            null,
            '5',
            _t('最多标签数'),
            _t('建议 3-8')
        );
        $form->addInput($maxTags->addRule('isInteger', _t('请输入整数')));

        $maxContentLength = new Typecho_Widget_Helper_Form_Element_Text(
            'max_content_length',
            null,
            '2000',
            _t('最大内容长度'),
            _t('发送给 AI 的内容字符数上限')
        );
        $form->addInput($maxContentLength->addRule('isInteger', _t('请输入整数')));

        $requestTimeout = new Typecho_Widget_Helper_Form_Element_Text(
            'request_timeout',
            null,
            '20',
            _t('请求超时（秒）'),
            _t('默认 20 秒')
        );
        $form->addInput($requestTimeout->addRule('isInteger', _t('请输入整数')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function renderButton()
    {
        $actionUrl = Typecho_Common::url('action/xinautotags', Helper::options()->index);
        ?>
        <div id="xinautotags-box">
            <button type="button" class="btn primary" id="xinautotags-generate-btn">AI 生成标签</button>
            <span id="xinautotags-status">点击后会根据标题和正文自动生成标签</span>
            <div id="xinautotags-legend">
                <span><i class="xinautotags-dot xinautotags-dot-existing"></i>标签库已有</span>
                <span><i class="xinautotags-dot xinautotags-dot-new"></i>AI 新标签</span>
                <span><i class="xinautotags-dot xinautotags-dot-selected"></i>已选中</span>
            </div>
            <div id="xinautotags-results"></div>
        </div>

        <script>
        (function () {
            if (window.__xinautotagsLoaded) {
                return;
            }
            window.__xinautotagsLoaded = true;

            var ACTION_URL = <?php echo json_encode($actionUrl); ?>;
            var generatedTags = [];
            var libraryTagKeys = {};
            var tagInput = null;

            function text(el) {
                return el ? (el.value || '').trim() : '';
            }

            function key(tag) {
                return String(tag || '').trim().toLowerCase();
            }

            function splitTags(tagStr) {
                if (!tagStr) {
                    return [];
                }
                var raw = tagStr.replace(/[，、;\n\r\t]+/g, ',');
                var parts = raw.split(',');
                var list = [];
                var seen = {};
                for (var i = 0; i < parts.length; i++) {
                    var tag = parts[i].trim();
                    if (!tag) {
                        continue;
                    }
                    var k = key(tag);
                    if (!seen[k]) {
                        seen[k] = true;
                        list.push(tag);
                    }
                }
                return list;
            }

            function uniqueTagArray(tags) {
                var list = [];
                var seen = {};
                if (!Array.isArray(tags)) {
                    return list;
                }
                for (var i = 0; i < tags.length; i++) {
                    var tag = String(tags[i] || '').trim();
                    if (!tag) {
                        continue;
                    }
                    var k = key(tag);
                    if (!seen[k]) {
                        seen[k] = true;
                        list.push(tag);
                    }
                }
                return list;
            }

            function hasTag(list, tag) {
                var target = key(tag);
                for (var i = 0; i < list.length; i++) {
                    if (key(list[i]) === target) {
                        return true;
                    }
                }
                return false;
            }

            function setStatus(message, ok) {
                var status = document.getElementById('xinautotags-status');
                if (!status) {
                    return;
                }
                status.textContent = message;
                status.style.color = ok ? '#2f7d32' : '#555';
            }

            function setLibraryTags(tags) {
                libraryTagKeys = {};
                var list = uniqueTagArray(tags);
                for (var i = 0; i < list.length; i++) {
                    libraryTagKeys[key(list[i])] = true;
                }
            }

            function isLibraryTag(tag) {
                return !!libraryTagKeys[key(tag)];
            }

            function getSelectedTags() {
                if (!tagInput) {
                    return [];
                }
                return splitTags(tagInput.value);
            }

            function syncTagInput(tags) {
                if (!tagInput) {
                    return;
                }
                tagInput.value = tags.join(',');
                tagInput.dispatchEvent(new Event('input', {bubbles: true}));
                tagInput.dispatchEvent(new Event('change', {bubbles: true}));
            }

            function addTokenNode(tag) {
                var tokenList = document.querySelector('.token-input-list');
                if (!tokenList) {
                    return;
                }

                var tokenTexts = tokenList.querySelectorAll('.token-input-token p');
                for (var i = 0; i < tokenTexts.length; i++) {
                    if (key(tokenTexts[i].textContent) === key(tag)) {
                        return;
                    }
                }

                var li = document.createElement('li');
                li.className = 'token-input-token';

                var p = document.createElement('p');
                p.textContent = tag;
                li.appendChild(p);

                var del = document.createElement('span');
                del.className = 'token-input-delete-token';
                del.textContent = '×';
                del.addEventListener('click', function () {
                    if (li.parentNode) {
                        li.parentNode.removeChild(li);
                    }
                    var current = getSelectedTags();
                    var next = [];
                    for (var j = 0; j < current.length; j++) {
                        if (key(current[j]) !== key(tag)) {
                            next.push(current[j]);
                        }
                    }
                    syncTagInput(next);
                    renderTagButtons();
                });
                li.appendChild(del);

                var inputToken = tokenList.querySelector('.token-input-input-token');
                if (inputToken) {
                    tokenList.insertBefore(li, inputToken);
                } else {
                    tokenList.appendChild(li);
                }
            }

            function addTag(tag) {
                var current = getSelectedTags();
                if (hasTag(current, tag)) {
                    return false;
                }
                current.push(tag);
                syncTagInput(current);
                addTokenNode(tag);
                return true;
            }

            function renderTagButtons() {
                var result = document.getElementById('xinautotags-results');
                if (!result) {
                    return;
                }

                result.innerHTML = '';
                if (!generatedTags.length) {
                    return;
                }

                var selected = getSelectedTags();
                for (var i = 0; i < generatedTags.length; i++) {
                    (function (tag) {
                        var tagBtn = document.createElement('button');
                        tagBtn.type = 'button';
                        tagBtn.className = 'xinautotags-tag-btn';
                        tagBtn.textContent = tag;

                        if (hasTag(selected, tag)) {
                            tagBtn.className += ' is-selected';
                            tagBtn.disabled = true;
                            tagBtn.title = '该标签已选中';
                        } else if (isLibraryTag(tag)) {
                            tagBtn.className += ' is-existing';
                            tagBtn.title = '标签库已有，点击添加';
                            tagBtn.addEventListener('click', function () {
                                if (addTag(tag)) {
                                    setStatus('已添加标签：' + tag, true);
                                    renderTagButtons();
                                }
                            });
                        } else {
                            tagBtn.className += ' is-new';
                            tagBtn.title = 'AI 新标签，点击添加';
                            tagBtn.addEventListener('click', function () {
                                if (addTag(tag)) {
                                    setStatus('已添加标签：' + tag, true);
                                    renderTagButtons();
                                }
                            });
                        }

                        result.appendChild(tagBtn);
                    })(generatedTags[i]);
                }
            }

            function setup() {
                var btn = document.getElementById('xinautotags-generate-btn');
                tagInput = document.querySelector('input[name="tags"]');
                if (!btn || !tagInput) {
                    return;
                }

                var box = document.getElementById('xinautotags-box');
                if (box && tagInput.parentNode && box.parentNode !== tagInput.parentNode) {
                    tagInput.parentNode.appendChild(box);
                }

                btn.addEventListener('click', function () {
                    var titleInput = document.querySelector('input[name="title"]') || document.getElementById('title');
                    var contentInput = document.querySelector('textarea[name="text"]') || document.getElementById('text');

                    var title = text(titleInput);
                    var content = text(contentInput);

                    if (!title) {
                        setStatus('请先填写文章标题', false);
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = '生成中...';
                    setStatus('AI 正在生成标签...', false);

                    var body = new URLSearchParams();
                    body.append('title', title);
                    body.append('content', content);

                    fetch(ACTION_URL, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    }).then(function (res) {
                        return res.json();
                    }).then(function (json) {
                        if (!json || !json.success) {
                            throw new Error(json && json.message ? json.message : '生成失败');
                        }

                        generatedTags = uniqueTagArray(json.tags || []);
                        setLibraryTags(json.library_tags || []);

                        if (!generatedTags.length) {
                            throw new Error('AI 没有返回有效标签');
                        }

                        renderTagButtons();
                        setStatus('已生成 ' + generatedTags.length + ' 个标签，点击按钮即可添加', true);
                    }).catch(function (err) {
                        setStatus('生成失败：' + err.message, false);
                    }).finally(function () {
                        btn.disabled = false;
                        btn.textContent = 'AI 生成标签';
                    });
                });

                tagInput.addEventListener('input', renderTagButtons);
                tagInput.addEventListener('change', renderTagButtons);
                document.addEventListener('click', function (e) {
                    var target = e.target;
                    if (target && target.classList && target.classList.contains('token-input-delete-token')) {
                        setTimeout(renderTagButtons, 80);
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setup);
            } else {
                setup();
            }
        })();
        </script>

        <style>
        #xinautotags-box {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #e2e2e2;
            border-radius: 6px;
            background: #fafafa;
        }
        #xinautotags-box .btn {
            margin-right: 8px;
        }
        #xinautotags-status {
            display: inline-block;
            font-size: 12px;
            color: #555;
            margin-bottom: 8px;
        }
        #xinautotags-legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #666;
            margin: 8px 0;
        }
        #xinautotags-legend span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .xinautotags-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            display: inline-block;
        }
        .xinautotags-dot-existing {
            background: #f9a825;
        }
        .xinautotags-dot-new {
            background: #43a047;
        }
        .xinautotags-dot-selected {
            background: #9e9e9e;
        }
        #xinautotags-results {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .xinautotags-tag-btn {
            border: 0;
            border-radius: 4px;
            padding: 6px 10px;
            color: #fff;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
        }
        .xinautotags-tag-btn.is-existing {
            background: #f9a825;
        }
        .xinautotags-tag-btn.is-new {
            background: #43a047;
        }
        .xinautotags-tag-btn.is-selected {
            background: #9e9e9e;
            cursor: not-allowed;
            opacity: 0.95;
        }
        .xinautotags-tag-btn:not(.is-selected):hover {
            opacity: 0.9;
        }
        </style>
        <?php
    }
}
