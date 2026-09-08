<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin;

/**
 * 能力自声明接口（可选扩展，能力探测式，照 QuestBatchStoreInterface 先例）：
 * 插件实现本接口即把自己的能力名交给 FeatureFlags 判定——开关关闭时 PluginRegistry::load
 * 跳过装配（不注册、不占用 Container/dispatcher,记入跳过名单可观测）,开启时行为不变。
 * 未实现本接口的插件（第三方/既有）不受开关体系约束,load 语义完全保持——渐进采纳,零破坏。
 * Optional self-declared feature capability (capability-probed, following the QuestBatchStoreInterface
 * precedent): a plugin implementing it hands its feature name to FeatureFlags — when the flag is off,
 * PluginRegistry::load skips assembly (no registration, no Container/dispatcher footprint, observable via
 * the skip list); when on, behavior is unchanged. Plugins not implementing it (third-party / existing)
 * stay outside the flag system entirely — gradual adoption, zero breakage.
 */
interface FeaturePluginInterface
{
    /**
     * 能力名（小写短横线,如 'mmorpg' / 'quest'）——与 NYTHROS_FEATURES 白名单及
     * NYTHROS_FEATURE_<大写名> 覆盖键对应。
     * The feature name (lowercase, dashed, e.g. 'mmorpg' / 'quest') — matched against the
     * NYTHROS_FEATURES whitelist and the NYTHROS_FEATURE_<UPPER> override keys.
     */
    public function featureName(): string;
}
