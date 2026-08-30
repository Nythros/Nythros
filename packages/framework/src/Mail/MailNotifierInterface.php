<?php

declare(strict_types=1);

namespace Nythros\Framework\Mail;

/**
 * 新邮件在线通知端口：ConnectionHubInterface::sendToUid 的等价抽象。
 * The new-mail online-notification port: an equivalent abstraction of ConnectionHubInterface::sendToUid.
 *
 * 为什么不直接依赖 ConnectionHubInterface：社交 hub 的下行是 JSON 字符串（社交层序列化器），
 * 而 Map 直连频道走二进制批量协议（帧类型/负载字段经词表压缩），sendToUid 的原始字符串消息
 * 无法在 Map 频道表达。本端口只声明「按 uid 定向通知、离线静默丢弃」的语义，由装配层绑定
 * 具体投递实现（demo 侧 MapServer 按 uid 解析实体后经 frameMerger 入队 mail:new 帧）。
 * Why not depend on ConnectionHubInterface directly: the social hub's downstream is a raw JSON string (the social
 * tier's serializer), while the Map direct channel speaks the binary batch protocol (frame types/payload keys
 * vocabulary-compressed) — sendToUid's raw string message cannot be expressed there. This port declares only the
 * "directed per-uid notice, silently dropped when offline" semantics; the assembly layer binds a concrete delivery
 * implementation (the demo's MapServer resolves the entity by uid and enqueues a mail:new frame via the frame merger).
 */
interface MailNotifierInterface
{
    /**
     * 通知 uid 有新邮件到达（离线时静默丢弃——邮件本身已持久化，登录后可拉取）。
     * Notifies the uid of a new mail (silently dropped when offline — the mail itself is persisted and pullable after login).
     */
    public function notifyNewMail(string $uid, string $mailId): void;
}
