# DPlayerMAX

强大的 Typecho 视频播放器插件，基于 DPlayer。

## 主要功能

- 🎥 支持多种视频格式
- 🎨 可自定义主题颜色
- 📲 响应式设计，支持移动端
- ⚡ 本地资源加载，无需依赖外部 CDN *新增！*
- 🔄 自更新检测 *新增！*
- 📺 **B站视频解析** - 支持免登录 720P 播放 *新增！*
- 💭 ~~支持弹幕功能~~  *已经移除*
- 📝 ~~支持字幕显示~~  *已经移除*

## 安装

1. 下载插件并解压到 `/usr/plugins/DPlayerMAX`
2. 在后台启用插件
3. 根据需要配置选项

## 使用方法

### 基础用法

```
[dplayer url="视频地址"]
```

### B站视频

```
[dplayer url="https://www.bilibili.com/video/BVxxxxx/"]
[dplayer url="https://www.bilibili.com/video/BVxxxxx/" page="2"]
[dplayer url="https://www.bilibili.com/video/BVxxxxx/" mode="iframe"]
```

### 参数说明

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `url` | 视频地址 | 必填 |
| `pic` | 封面图片 | 无 |
| `autoplay` | 自动播放 | false |
| `loop` | 循环播放 | false |
| `theme` | 主题颜色 | 插件设置 |
| `lazy` | 懒加载 | true |
| `page` | B站视频分P | 1 |
| `mode` | 播放模式 (dplayer/iframe) | dplayer |

## 缓存管理

插件会缓存视频信息以避免被B站封禁服务器IP，每半小时会自动清理，也可在设置页面手动清理缓存。

## 版权信息

- **作者**：[GamblerIX](https://github.com/GamblerIX)
- **仓库**：[DPlayerMAX](https://github.com/GamblerIX/DPlayerMAX)
- **许可证**：MIT LICENSE
- **鸣谢**：[DPlayer And Its Issue](https://github.com/MoePlayer/DPlayer-Typecho/issues/40)
