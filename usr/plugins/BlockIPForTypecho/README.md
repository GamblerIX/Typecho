# BlockIP For Typecho

综合安全防护插件，为 Typecho 博客提供全方位的安全保护。

## 许可证

**Eclipse Public License - v 2.0**

## 额外说明

本插件完全[开源](https://github.com/GamblerIX/BlockIPForTypecho)，保留商业授权许可权力。

未经本作者允许，任何人不得利用此插件从事商业活动和违法行为，否则后果自负。

## 快速开始

### 环境要求

- Typecho 1.2+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+ / SQLite 3.x / PostgreSQL 10+

### 安装
1. 将插件解压到 `usr/plugins/BlockIPForTypecho/` 目录
2. 在后台"插件管理"页面启用插件
3. 配置工作模式和基础规则

### 基础配置
```
1. 选择智能模式（推荐）
2. 添加管理员IP到白名单
3. 添加常用搜索引擎UA到UA白名单
4. 启用访客日志记录
```

## 目录结构

```
usr/plugins/BlockIPForTypecho/
├── Plugin.php                 # 插件主文件
├── README.md                  # 插件文档
├── LICENSE                    # 许可证
├── codes/
│   └── base/                  # 基础核心模块
│   └── extension              # 扩展模块（待开发）
├── assets/                    # 静态资源
├── ip2region/                 # IP地理位置库
└── docs/                      # 文档目录
```

### Base 模块说明

Base 模块包含插件的所有核心功能组件：

- **Adapter.php** - 数据库适配器，处理不同数据库系统的兼容性
- **AllowAdminIP.php** - 后台访问IP白名单管理，限制后台访问权限
- **BlockHandler.php** - 拦截页面渲染、HTTP响应处理
- **CaptchaAction.php** - 验证码生成和验证
- **CaptchaHelper.php** - 验证码辅助功能，UI注入和会话管理
- **Console.php** - 管理控制台界面、日志查看、统计图表
- **Database.php** - 数据库表创建、更新和基础操作
- **ErrorDiagnostic.php** - 错误诊断和调试信息收集
- **GeoLocation.php** - IP地理位置查询（基于ip2region）
- **IPAccessControl.php** - IP地址获取、黑白名单管理、访问频率控制
- **Logger.php** - 拦截日志、自动拉黑日志、访问日志记录
- **PathHelper.php** - 路径处理辅助类，统一路径管理
- **SecurityDetector.php** - SQL注入、XSS、CSRF等攻击检测
- **SecurityHelper.php** - 安全辅助功能，后台区域判断等
- **SelfCheck.php** - 插件自检功能，环境和配置验证
- **SmartDetector.php** - 智能威胁检测、访问频率异常检测
- **VisitorStats.php** - 访客日志记录、统计数据查询

## 文档

完整文档请查看 [docs](./docs/) 目录：

- [安装指南](./docs/installation.md)
- [配置指南](./docs/configuration.md)
- [后台白名单使用指南](./docs/admin-whitelist.md)
- [安全最佳实践](./docs/security-best-practices.md)
- [故障排查](./docs/troubleshooting.md)
- [API文档](./docs/api.md)


## 控制台

插件提供可视化控制台，包含四个功能模块：

- **安全日志** - 拦截记录、统计概览、趋势分析
- **访客日志** - 正常访客访问记录和IP搜索
- **机器人管理** - Bot IP列表管理
- **网站审计** - 访客统计和地理分布

## 配置示例

### IP规则
```
192.168.1.100        # 单个IP
192.168.1.1-50       # IP范围
192.168.1.*          # 通配符
192.168.1.0/24       # CIDR
```

### 地理位置拦截
```
美国                 # 拦截国家
广东省               # 拦截省份
深圳市               # 拦截城市
```

## 如何贡献

1. 提 Issues
2. 交 PR

