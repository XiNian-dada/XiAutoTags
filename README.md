# XiAutoTags - 曦念AI自动标签插件

![XiAutoTags 界面预览](https://via.placeholder.com/800x400?text=XiAutoTags+UI+Preview)

## 简介

XiAutoTags 是一款专为Typecho设计的智能标签生成插件，由XiNian-dada团队开发。本插件利用先进的AI技术，根据文章标题和内容自动生成最相关的标签，大幅提升内容分类效率和SEO优化效果。

**核心功能：**
- 多AI API支持（OpenRouter及自定义API）
- 智能标签生成算法
- 实时标签建议与一键应用
- 高级自定义API配置
- 详细的执行日志和调试工具

## 技术特性

- **多API支持**：集成OpenRouter API，并支持自定义API配置
- **智能提示词**：优化的标签生成提示词确保高质量结果
- **标签库集成**：自动获取全站现有标签库，避免重复
- **优先级系统**：多API源按优先级自动切换
- **实时反馈**：详细的执行日志和控制台输出

## 安装指南

1. 下载插件文件 `XiAutoTags.php`
2. 上传到Typecho插件目录：`/usr/plugins/XiAutoTags/`
3. 在Typecho后台激活插件
4. 进入插件设置页面配置API参数

## 配置说明

### 基本配置
- **最少/最多生成标签数**：控制AI生成的标签数量范围
- **OpenRouter API设置**：
  - API密钥（从[OpenRouter](https://openrouter.ai/settings/keys)获取）
  - 模型选择（推荐免费模型：deepseek/deepseek-chat-v3-0324:free）
  - 优先级设置（数字越小优先级越高）

### 自定义API配置
1. **基础自定义API**：
   ```
   名称|优先级|API密钥|模型|端点URL|启用状态(1/0)
   示例：MyAPI|1|sk-xxxx|gpt-4|https://api.example.com|1
   ```

2. **高级自定义API**（JSON格式）：
   ```json
   [
     {
       "name": "My Advanced API",
       "priority": 1,
       "enabled": true,
       "request": {
         "method": "POST",
         "endpoint": "https://api.example.com/v1/chat",
         "headers": {
           "Authorization": "Bearer YOUR_API_KEY",
           "Content-Type": "application/json"
         },
         "body": {
           "model": "gpt-4",
           "messages": [
             {"role": "system", "content": "你是有帮助的助手"},
             {"role": "user", "content": "{{PROMPT}}"}
           ]
         }
       },
       "response": {
         "extract": "response.choices[0].message.content.trim()"
       }
     }
   ]
   ```

**可用占位符：**
- `{{PROMPT}}`：生成标签的提示词
- `{{TITLE}}`：文章标题
- `{{CONTENT}}`：文章内容（前3000字符）
- `{{EXISTING_TAGS}}`：现有标签库（JSON字符串）

## 使用指南

1. 在文章编辑页面右侧找到"AI标签生成器"面板
2. 点击"开始提取标签"按钮
3. 查看AI生成的标签建议
4. 选择操作：
   - 点击单个标签添加到输入框
   - 点击"全部应用"添加所有建议标签
   - 点击"关闭结果"隐藏建议面板


## 高级功能

### API连接测试
点击"测试连接"按钮，验证所有配置API的可达性

### 日志系统
- 实时显示插件执行日志
- 按信息类型着色（成功、错误、警告等）
- 支持日志清除功能

### 自定义样式
通过修改以下CSS类自定义外观：
```css
/* 主容器 */
#xinautotags-container

/* 控制台 */
#xinautotags-console

/* 标签样式 */
.xinautotags-tag          /* 普通标签 */
.xinautotags-tag-exists   /* 标签库中已存在的标签 */
.xinautotags-tag-added    /* 已添加的标签 */
```

## 注意事项

1. **API限制**：确保您的API账户有足够的配额
2. **内容长度**：仅使用文章前3000字符生成标签
3. **标签数量**：实际生成标签数可能少于设置的最小值
4. **性能影响**：大量标签库可能轻微影响加载速度

## 版权声明

XiAutoTags © 2025 XiNian-dada  
作者：XiNian-dada  
官方网站：[www.hairuosky.cn](https://www.hairuosky.cn)

本插件基于MIT许可证发布，欢迎在保留版权信息的前提下自由使用和修改。


欢迎提交Pull Request贡献代码！

## 更新日志

### v1.0.0 (2023-11-15)
- 初始发布版本
- 支持OpenRouter API
- 实现基础自定义API配置
- 添加高级自定义API支持
- 完成核心标签生成功能

---

**让AI为您的创作赋能，Xi'sAI自动标签插件 - 智能化内容管理的最佳伴侣！**